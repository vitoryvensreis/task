<?php
declare(strict_types=1);

/**
 * First tenant-isolated INTERNAL database inbox. No external provider,
 * push, email, WhatsApp, realtime or background worker is activated.
 */
final class SaasInternalInbox {
 private static function ready(int $account): void {
  SaasEventConsumer::assertInternalReady($account);
  if((int)(DB::one('SELECT value FROM settings WHERE `key`=?',
   [SaasInternalInboxMigration::MARKER])['value']??0)!==SaasInternalInboxMigration::VERSION)
   throw new RuntimeException('saas_inbox_migration_missing');
 }

 /** Idempotently stage internal inbox entries for CURRENTLY authorized recipients.
  * Does NOT mark event/job as sent or published. Caller must be trusted backend.
  */
 public static function stage(int $accountId,int $jobId): array {
  if($jobId<1)throw new InvalidArgumentException('Invalid delivery job id.');
  return DB::tx(static function()use($accountId,$jobId):array {
   self::ready($accountId);
   $job=DB::one('SELECT j.id,j.status,e.event_type,e.entity_type,e.entity_id FROM saas_delivery_jobs j'.
    ' INNER JOIN saas_event_receipts r ON r.id=j.receipt_id AND r.tenant_account_id=j.tenant_account_id'.
    ' INNER JOIN saas_event_outbox e ON e.id=r.outbox_id AND e.tenant_account_id=j.tenant_account_id'.
    ' WHERE j.id=? AND j.tenant_account_id=? FOR UPDATE',[$jobId,$accountId]);
   if(!$job||$job['status']!=='held')throw new RuntimeException('saas_inbox_job_unavailable');
   $routed=SaasDeliveryRouting::recipients($accountId,$jobId);
   $now=(new DateTimeImmutable('now',new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
   $created=0;
   foreach($routed['recipient_user_ids'] as $userId){
    $insert=DB::run('INSERT INTO saas_internal_inbox'.
     ' (tenant_account_id,delivery_job_id,recipient_user_id,event_type,entity_type,entity_id,created_at)'.
     ' VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE id=id',
     [$accountId,$jobId,$userId,$job['event_type'],$job['entity_type'],$job['entity_id'],$now]);
    $created+=($insert->rowCount()===1)?1:0;
   }
   return ['account_id'=>$accountId,'job_id'=>$jobId,'eligible'=>count($routed['recipient_user_ids']),
    'new_inbox_items'=>$created,'external_delivery_enabled'=>false,'published'=>false];
  });
 }

 /** Metadata-only inbox of the authenticated CURRENT user, with live ACL.
  * A revoked membership or client link cannot read previously staged items.
  */
 public static function forCurrentUser(int $limit=30): array {
  if($limit<1||$limit>100)throw new InvalidArgumentException('Invalid inbox limit.');
  $member=SaasRequestScope::resolve();
  $account=(int)$member['account_id'];
  self::ready($account);
  Auth::load();
  $id=(int)(Auth::$actor['id']??0);
  if($id<1)fail('Usuário inválido.',403);
  $user=DB::one('SELECT id,role,active FROM users WHERE id=?',[$id]);
  if(!$user||(int)$user['active']!==1||!in_array($user['role'],['admin','staff'],true))
   fail('Acesso ao histórico interno indisponível.',403);
  return DB::all('SELECT i.id,i.event_type,i.entity_type,i.entity_id,i.created_at'.
   ' FROM saas_internal_inbox i'.
   ' JOIN saas_delivery_jobs j ON j.id=i.delivery_job_id AND j.tenant_account_id=i.tenant_account_id'.
   ' JOIN saas_event_receipts r ON r.id=j.receipt_id AND r.tenant_account_id=i.tenant_account_id'.
   ' JOIN saas_event_outbox e ON e.id=r.outbox_id AND e.tenant_account_id=i.tenant_account_id'.
   ' JOIN demands d ON d.id=e.entity_id AND d.tenant_account_id=i.tenant_account_id'.
   ' JOIN clients c ON c.id=d.client_id AND c.tenant_account_id=i.tenant_account_id'.
   ' JOIN account_members m ON m.account_id=i.tenant_account_id AND m.user_id=i.recipient_user_id'.
   ' WHERE i.tenant_account_id=? AND i.recipient_user_id=? AND m.status=?'.
   ' AND ( (m.membership_role=? AND ?=?)'.
   ' OR (d.assigned_to=? AND ? IN (?,?) AND'.
   ' (?=? OR EXISTS(SELECT 1 FROM user_clients uc WHERE uc.user_id=? AND uc.client_id=d.client_id))) )'.
   ' ORDER BY i.id DESC LIMIT '.$limit,
   [$account,$id,'active','owner',$user['role'],'admin',$id,$user['role'],'admin','staff',
    $user['role'],'admin',$id]);
 }
}
