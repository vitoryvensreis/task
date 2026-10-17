<?php
declare(strict_types=1);

/**
 * SaaS B1.2c-2b.2b.1: a strictly INTERNAL, DB-only event preparation consumer.
 *
 * Not installed in cron, HTTP APIs or queues. NO provider calls, fanout,
 * published_at changes, retries or external side effects. A held receipt means
 * ONLY that the event passed consistency checks and was durably prepared.
 */
final class SaasEventConsumer {
 private const LIMIT_MAX=100;

 /** This is a deliberately closed gate until tenant authorization and B1.3. */
 private static function internalAccount(int $accountId): void {
  if($accountId<1)throw new InvalidArgumentException('Invalid tenant scope.');
  $account=DB::one('SELECT id FROM accounts WHERE id=? AND slug=? AND account_type=? AND status=?',
   [$accountId,AccountContext::LEGACY_SLUG,'internal','active']);
  if(!$account)throw new RuntimeException('saas_consumer_account_blocked');
  $flags=DB::all('SELECT `key`,value FROM account_settings WHERE account_id=? AND `key` IN (?,?)',
   [$accountId,'saas.external_signup_enabled','saas.multi_account_enabled']);
  if(count($flags)!==2)throw new RuntimeException('saas_consumer_flags_missing');
  foreach($flags as $flag)if((string)$flag['value']!=='0')
   throw new RuntimeException('saas_consumer_enabled_flag');
 }

 private static function ready(int $accountId): void {
  $schema=(int)(DB::one("SELECT value FROM settings WHERE `key`='schema_version'")['value']??0);
  if($schema!==30)throw new RuntimeException('saas_consumer_schema_blocked');
  foreach([
   SaasFoundationMigration::MARKER=>1,
   SaasDomainMigration::MARKER=>2,
   SaasChildMigration::MARKER=>3,
   SaasOutboxMigration::MARKER=>4,
   SaasReceiptMigration::MARKER=>5
  ] as $key=>$v)
   if((int)(DB::one('SELECT value FROM settings WHERE `key`=?',[$key])['value']??0)!==$v)
    throw new RuntimeException('saas_consumer_migration_missing');
  self::internalAccount($accountId);
 }

 /** Check the staged worker prerequisites without consuming any events. */
 public static function assertInternalReady(int $accountId): void {
  self::ready($accountId);
 }

 /** Verify a full outbox row before deriving any delivery intent. */
 public static function verifyEvent(array $event,int $accountId): void {
  self::ready($accountId);
  self::validate($event,$accountId);
 }

 /** Hard fail on a corrupted, foreign or inconsistent event: NEVER silently
  * acknowledge it. Historic versions may be earlier than the current task.
  */
 private static function validate(array $event,int $accountId): void {
  $type=(string)$event['event_type'];
  $taskId=(int)$event['entity_id'];
  $creator=$event['created_by']===null?null:(int)$event['created_by'];
  if((int)$event['tenant_account_id']!==$accountId||
    (string)$event['entity_type']!=='demand'||
    !in_array($type,SaasEventOutbox::TASK_EVENTS,true)||
    $taskId<1||!is_int($creator)||$creator<1)
   throw new RuntimeException('saas_event_scope_invalid');

  $json=(string)$event['payload_json'];
  $payload=json_decode($json,true,32,JSON_THROW_ON_ERROR);
  if(!is_array($payload)||array_is_list($payload))
   throw new RuntimeException('saas_event_payload_invalid');
  $allowed=[
   'tenant_account_id','entity_id','version','client_id','project_id',
   'stage_id','previous_stage_id','previous_project_id'
  ];
  foreach(array_keys($payload) as $key)
   if(!in_array($key,$allowed,true))throw new RuntimeException('saas_event_payload_field');
  $required=['tenant_account_id','entity_id','version','client_id','project_id','stage_id'];
  foreach($required as $field)
   if(!array_key_exists($field,$payload))throw new RuntimeException('saas_event_payload_missing');
  foreach(['tenant_account_id','entity_id','version','client_id','stage_id'] as $field)
   if(!is_int($payload[$field])||$payload[$field]<1)
    throw new RuntimeException('saas_event_payload_type');
  foreach(['project_id','previous_project_id','previous_stage_id'] as $field)
   if(array_key_exists($field,$payload)&&$payload[$field]!==null&&
    (!is_int($payload[$field])||$payload[$field]<1))
    throw new RuntimeException('saas_event_payload_type');

  $version=$payload['version'];
  if($payload['tenant_account_id']!==$accountId||$payload['entity_id']!==$taskId)
   throw new RuntimeException('saas_event_tenant_mismatch');
  $expectedKey=hash('sha256','taskesc:saas:v1:'.$accountId.':'.$type.':'.$taskId.':'.$version);
  // MySQL 8 may normalize/reorder JSON object keys on storage, while
  // MariaDB keeps the original text. The producer hashes a key-sorted
  // representation: reconstruct EXACTLY that representation on both DBs.
  ksort($payload,SORT_STRING);
  $canonicalJson=json_encode($payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  $expectedHash=hash('sha256',json_encode([$accountId,$type,'demand',$taskId,$canonicalJson,$creator],JSON_THROW_ON_ERROR));
  if(!hash_equals($expectedKey,(string)$event['event_key'])||
    !hash_equals($expectedHash,(string)$event['event_hash']))
   throw new RuntimeException('saas_event_integrity_failure');

  $task=DB::one('SELECT d.id,d.version,d.tenant_account_id,d.client_id,d.project_id FROM demands d'.
   ' INNER JOIN clients c ON c.id=d.client_id AND c.tenant_account_id=d.tenant_account_id'.
   ' INNER JOIN categories k ON k.id=d.category_id AND k.tenant_account_id=d.tenant_account_id'.
   ' INNER JOIN stages s ON s.id=d.stage_id AND s.tenant_account_id=d.tenant_account_id'.
   ' LEFT JOIN projects p ON p.id=d.project_id AND p.tenant_account_id=d.tenant_account_id AND p.client_id=d.client_id'.
   ' WHERE d.id=? AND d.tenant_account_id=? AND (d.project_id IS NULL OR p.id IS NOT NULL) LIMIT 1',
   [$taskId,$accountId]);
  if(!$task||(int)$task['version']<$version||(int)$task['client_id']!==$payload['client_id'])
   throw new RuntimeException('saas_event_parent_invalid');
  if($payload['project_id']!==null){
   $proj=DB::one('SELECT 1 FROM projects WHERE id=? AND tenant_account_id=? AND client_id=?',
    [$payload['project_id'],$accountId,$payload['client_id']]);
   if(!$proj)throw new RuntimeException('saas_event_project_invalid');
  }
  foreach(['stage_id','previous_stage_id'] as $field)
   if(array_key_exists($field,$payload)&&$payload[$field]!==null&&
    !DB::one('SELECT 1 FROM stages WHERE id=? AND tenant_account_id=?',[$payload[$field],$accountId]))
    throw new RuntimeException('saas_event_stage_invalid');
 }

 /**
  * Prepare a bounded tenant-specific batch, with row locks, under one DB
  * transaction. No external publisher is injected or invoked. Replaying or
  * racing consumers never creates duplicate receipts (unique tenant,event).
  */
 public static function prepareBatch(int $accountId,int $limit=20): array {
  if($limit<1||$limit>self::LIMIT_MAX)throw new InvalidArgumentException('Invalid consumer batch limit.');
  return DB::tx(static function()use($accountId,$limit):array{
   self::ready($accountId);
   $now=(new DateTimeImmutable('now',new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
   $rows=DB::all('SELECT e.* FROM saas_event_outbox e'.
    ' WHERE e.tenant_account_id=? AND e.published_at IS NULL AND e.available_at<=?'.
    ' AND NOT EXISTS (SELECT 1 FROM saas_event_receipts r'.
    ' WHERE r.tenant_account_id=e.tenant_account_id AND r.outbox_id=e.id)'.
    ' ORDER BY e.available_at,e.id LIMIT '.$limit.' FOR UPDATE',[$accountId,$now]);
   $prepared=0;
   foreach($rows as $event){
    self::validate($event,$accountId);
    $written=DB::run('INSERT INTO saas_event_receipts'.
     ' (tenant_account_id,outbox_id,event_hash,event_type,entity_id,status,prepared_at)'.
     ' VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE id=id',
     [$accountId,(int)$event['id'],(string)$event['event_hash'],(string)$event['event_type'],
      (int)$event['entity_id'],'held',$now]);
    $receipt=DB::one('SELECT tenant_account_id,outbox_id,event_hash,status FROM saas_event_receipts'.
     ' WHERE tenant_account_id=? AND outbox_id=? FOR UPDATE',[$accountId,(int)$event['id']]);
    if(!$receipt||(int)$receipt['tenant_account_id']!==$accountId||
      !hash_equals((string)$event['event_hash'],(string)$receipt['event_hash'])||
      (string)$receipt['status']!=='held')
     throw new RuntimeException('saas_receipt_integrity_failure');
    $prepared+=$written->rowCount()>0?1:0;
   }
   return ['account_id'=>$accountId,'selected'=>count($rows),'prepared'=>$prepared,
    'external_delivery_enabled'=>false,'publish_status_changed'=>false];
  });
 }

 /** Operational inspection from a verified web owner only, no raw payload. */
 public static function heldForOwner(int $limit=30): array {
  if($limit<1||$limit>100)throw new InvalidArgumentException('Invalid receipt preview limit.');
  $member=SaasRequestScope::owner();
  $accountId=(int)$member['account_id'];
  self::ready($accountId);
  return DB::all('SELECT id,outbox_id,event_type,entity_id,status,prepared_at FROM saas_event_receipts'.
   ' WHERE tenant_account_id=? ORDER BY id DESC LIMIT '.$limit,[$accountId]);
 }
}
