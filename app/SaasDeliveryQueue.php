<?php
declare(strict_types=1);

/**
 * B1.2c-2b.2b.2a: safe delivery-INTENT planner and bounded failure state.
 *
 * DELIBERATELY NO: WhatsApp/push/mail/realtime provider, cron, HTTP endpoint,
 * publish success, shared automation worker, arbitrary destination or tenant.
 * Jobs remain held/retry/dead; none of these states imply external delivery.
 */
final class SaasDeliveryQueue {
 public const MAX_ATTEMPTS=5;
 private const CHANNEL='internal_event';
 private const BATCH_MAX=100;
 private const ERRORS=[
  'internal_validation_failed','destination_unavailable',
  'provider_not_configured','temporary_failure','temporary_timeout'
 ];

 private static function ready(int $accountId): void {
  SaasEventConsumer::assertInternalReady($accountId);
  $version=(int)(DB::one('SELECT value FROM settings WHERE `key`=?',
   [SaasDeliveryMigration::MARKER])['value']??0);
  if($version!==SaasDeliveryMigration::VERSION)
   throw new RuntimeException('saas_delivery_migration_missing');
 }

 /** Deterministic, server-generated delivery scope. Never derived from
  * clients, payload, a URL or a supplied WhatsApp/chat/realtime channel.
  */
 private static function scope(int $accountId): string {
  return 'saas.account.'.$accountId.'.events';
 }

 /**
  * Derive internal-only delivery intents from HELD and validated receipts.
  * All sources are constrained to the same tenant. The row lock and unique
  * index make repeat/racing calls safe. Does not acknowledge receipt/outbox.
  */
 public static function stage(int $accountId,int $limit=30): array {
  if($limit<1||$limit>self::BATCH_MAX)throw new InvalidArgumentException('Invalid delivery staging limit.');
  return DB::tx(static function()use($accountId,$limit):array{
   self::ready($accountId);
   $rows=DB::all('SELECT e.*,r.id AS receipt_id,r.event_hash AS receipt_hash'.
    ' FROM saas_event_receipts r'.
    ' INNER JOIN saas_event_outbox e ON e.id=r.outbox_id AND e.tenant_account_id=r.tenant_account_id'.
    ' WHERE r.tenant_account_id=? AND r.status=? AND e.published_at IS NULL'.
    ' AND NOT EXISTS (SELECT 1 FROM saas_delivery_jobs j'.
    ' WHERE j.tenant_account_id=r.tenant_account_id AND j.receipt_id=r.id AND j.channel=?)'.
    ' ORDER BY r.id LIMIT '.$limit.' FOR UPDATE',
    [$accountId,'held',self::CHANNEL]);
   $now=(new DateTimeImmutable('now',new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
   $staged=0;
   foreach($rows as $row){
    if(!hash_equals((string)$row['event_hash'],(string)$row['receipt_hash']))
     throw new RuntimeException('saas_delivery_receipt_hash_mismatch');
    // Verify event's canonical hash, tenant and task/parent ownership again:
    // the receipt might have been prepared long before this job.
    SaasEventConsumer::verifyEvent($row,$accountId);
    $insert=DB::run('INSERT INTO saas_delivery_jobs'.
     ' (tenant_account_id,receipt_id,channel,destination_scope,event_hash,status,attempts,available_at,created_at,updated_at)'.
     ' VALUES (?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE id=id',
     [$accountId,(int)$row['receipt_id'],self::CHANNEL,self::scope($accountId),
      (string)$row['event_hash'],'held',0,$now,$now,$now]);
    $job=DB::one('SELECT tenant_account_id,receipt_id,channel,destination_scope,event_hash,status'.
     ' FROM saas_delivery_jobs WHERE tenant_account_id=? AND receipt_id=? AND channel=? FOR UPDATE',
     [$accountId,(int)$row['receipt_id'],self::CHANNEL]);
    if(!$job||(int)$job['tenant_account_id']!==$accountId||
     $job['destination_scope']!==self::scope($accountId)||
     $job['channel']!==self::CHANNEL||
     !hash_equals((string)$row['event_hash'],(string)$job['event_hash']))
     throw new RuntimeException('saas_delivery_job_integrity_failure');
    $staged+=$insert->rowCount()>0?1:0;
   }
   return ['account_id'=>$accountId,'selected'=>count($rows),'staged'=>$staged,
    'channel'=>self::CHANNEL,'external_delivery_enabled'=>false,'delivered'=>0];
  });
 }

 /**
  * Record a validation/dry-run failure without ever attempting delivery.
  * CAS on attempts, due-time and account id prevents duplicate increments.
  * After five failures the job becomes a dead-letter, not silently dropped.
  */
 public static function recordFailure(int $accountId,int $jobId,int $expectedAttempts,string $code): array {
  if($jobId<1||$expectedAttempts<0||$expectedAttempts>=self::MAX_ATTEMPTS||
     !in_array($code,self::ERRORS,true))
   throw new InvalidArgumentException('Invalid bounded SaaS delivery failure.');
  return DB::tx(static function()use($accountId,$jobId,$expectedAttempts,$code):array {
   self::ready($accountId);
   $job=DB::one('SELECT j.*,r.tenant_account_id AS receipt_account,r.event_hash AS receipt_hash'.
    ' FROM saas_delivery_jobs j'.
    ' INNER JOIN saas_event_receipts r ON r.id=j.receipt_id AND r.tenant_account_id=j.tenant_account_id'.
    ' WHERE j.id=? AND j.tenant_account_id=? FOR UPDATE',[$jobId,$accountId]);
   if(!$job)throw new RuntimeException('saas_delivery_job_outside_account');
   if($job['channel']!==self::CHANNEL||$job['destination_scope']!==self::scope($accountId)||
     !hash_equals((string)$job['event_hash'],(string)$job['receipt_hash']))
    throw new RuntimeException('saas_delivery_job_integrity_failure');
   if(!in_array($job['status'],['held','retry'],true)|| (int)$job['attempts']!==$expectedAttempts)
    throw new RuntimeException('saas_delivery_stale_attempt');
   $now=new DateTimeImmutable('now',new DateTimeZone('UTC'));
   if(new DateTimeImmutable((string)$job['available_at'],new DateTimeZone('UTC'))>$now)
    throw new RuntimeException('saas_delivery_not_due');
   $next=$expectedAttempts+1;
   $status=$next>=self::MAX_ATTEMPTS?'dead':'retry';
   $delay=min(3600,30*(2**($next-1)));
   $date=$status==='dead'?$now:$now->modify('+'.$delay.' seconds');
   $changed=DB::run('UPDATE saas_delivery_jobs'.
    ' SET attempts=?,status=?,last_error=?,available_at=?,updated_at=?'.
    ' WHERE id=? AND tenant_account_id=? AND attempts=? AND status IN (?,?)',
    [$next,$status,$code,$date->format('Y-m-d H:i:s.u'),$now->format('Y-m-d H:i:s.u'),
     $jobId,$accountId,$expectedAttempts,'held','retry']);
   if($changed->rowCount()!==1)throw new RuntimeException('saas_delivery_attempt_conflict');
   return ['id'=>$jobId,'attempts'=>$next,'status'=>$status,'available_at'=>$date->format('Y-m-d H:i:s.u'),
    'external_delivery_enabled'=>false];
  });
 }

 /** Metadata-only inspection behind a verified, internal owner session. */
 public static function forOwner(int $limit=30): array {
  if($limit<1||$limit>100)throw new InvalidArgumentException('Invalid delivery preview limit.');
  $member=SaasRequestScope::owner();
  $accountId=(int)$member['account_id'];
  self::ready($accountId);
  return DB::all('SELECT id,receipt_id,channel,destination_scope,status,attempts,available_at,last_error'.
   ' FROM saas_delivery_jobs WHERE tenant_account_id=? ORDER BY id DESC LIMIT '.$limit,[$accountId]);
 }
}
