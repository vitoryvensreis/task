<?php
declare(strict_types=1);

/**
 * B1.2c-2b.2a staging queue. Do NOT expose to legacy EventOutbox,
 * AutomationWorker, RealtimeOutboxWorker, Centrifugo, WhatsApp or Push.
 *
 * Events are durable but deliberately UNPUBLISHED until future tenant-aware
 * consumers and per-tenant credentials pass adversarial integration tests.
 */
final class SaasEventOutbox {
 public const TASK_EVENTS=['demand.created','demand.updated','demand.stage_changed','demand.archived'];

 public static function ready(): void {
  SaasRequestScope::ready();
  $version=(int)(DB::one('SELECT value FROM settings WHERE `key`=?',[SaasOutboxMigration::MARKER])['value']??0);
  if($version!==SaasOutboxMigration::VERSION)
   fail('A Outbox SaaS transacional ainda não foi migrada.',503);
 }

 /**
  * Requires active transaction shared with the business mutation. Uses
  * task owner and exact CURRENT version, not an untrusted request account id.
  */
 public static function task(int $accountId,int $taskId,int $version,string $type,int $actorId,array $change=[]): int {
  if(!DB::conn()->inTransaction())throw new LogicException('SaaS event requires the business transaction.');
  self::ready();
  $member=SaasRequestScope::owner();
  if($accountId<1||$accountId!==(int)$member['account_id'])
   fail('Conta de origem do evento inválida.',403);
  if(!in_array($type,self::TASK_EVENTS,true))
   throw new InvalidArgumentException('Unsupported staged event type.');
  if($taskId<1||$version<1||$actorId<1||(int)(Auth::$actor['id']??0)!==$actorId)
   throw new InvalidArgumentException('Invalid staged event identity.');
  $task=DB::one('SELECT id,tenant_account_id,client_id,project_id,stage_id,version,deleted_at FROM demands WHERE id=? AND tenant_account_id=? FOR UPDATE',
   [$taskId,$accountId]);
  if(!$task)fail('Task não pertence à empresa deste evento.',404);
  if((int)$task['version']!==$version)fail('Versão de task divergente do evento.',409);
  if($type==='demand.archived'&&$task['deleted_at']===null)fail('Não é possível emitir arquivamento sem exclusão lógica.',409);
  if($type!=='demand.archived'&&$task['deleted_at']!==null)fail('Task arquivada não pode emitir outro evento.',409);

  // Payload is a strict server-derived projection: no brief, phone, finance,
  // OAuth credentials, user text or raw request data enters the queue.
  $payload=[
   'tenant_account_id'=>$accountId,
   'entity_id'=>$taskId,
   'version'=>$version,
   'client_id'=>(int)$task['client_id'],
   'project_id'=>$task['project_id']===null?null:(int)$task['project_id'],
   'stage_id'=>(int)$task['stage_id']
  ];
  foreach($change as $name=>$value){
   if(!in_array($name,['previous_stage_id','previous_project_id'],true))
    throw new InvalidArgumentException('Unexpected change in staged SaaS event.');
   if($value!==null&&(!is_int($value)||$value<1))
    throw new InvalidArgumentException('Staged SaaS event change must be a positive ID.');
   $payload[$name]=$value;
  }
  ksort($payload,SORT_STRING);
  $json=json_encode($payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  $key=hash('sha256','taskesc:saas:v1:'.$accountId.':'.$type.':'.$taskId.':'.$version);
  $hash=hash('sha256',json_encode([$accountId,$type,'demand',$taskId,$json,$actorId],JSON_THROW_ON_ERROR));
  $now=(new DateTimeImmutable('now',new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
  $stmt=DB::run(
   'INSERT INTO saas_event_outbox (tenant_account_id,event_key,event_hash,event_type,entity_type,entity_id,payload_json,created_by,created_at,available_at)'.
   ' VALUES (?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)',
   [$accountId,$key,$hash,$type,'demand',$taskId,$json,$actorId,$now,$now]
  );
  $id=(int)DB::conn()->lastInsertId();
  $stored=DB::one('SELECT event_hash,tenant_account_id FROM saas_event_outbox WHERE id=? FOR UPDATE',[$id]);
  if(!$stored||(int)$stored['tenant_account_id']!==$accountId||
     !hash_equals($hash,(string)$stored['event_hash']))
   throw new LogicException('SaaS event idempotency collision.');
  return $id;
 }


 /** Transactional, internal-only catalog event; no external delivery. */
 public static function catalog(int $accountId,string $entity,int $entityId,string $type,int $actorId): int {
  if(!DB::conn()->inTransaction())throw new LogicException('SaaS catalog event requires a transaction.');
  self::ready();
  $member=SaasRequestScope::owner();
  if($accountId<1 || $accountId!==(int)$member['account_id'] ||
     $actorId<1 || $actorId!==(int)(Auth::$actor['id']??0))
   fail('Identidade de evento SaaS inválida.',403);
  $events=['client'=>['client.saved','client.archived'],'project'=>['project.saved','project.deleted']];
  $tables=['client'=>'clients','project'=>'projects'];
  if(!isset($events[$entity])||!in_array($type,$events[$entity],true)||$entityId<1)
   throw new InvalidArgumentException('Invalid SaaS catalog event.');
  $row=DB::one('SELECT id FROM `'.$tables[$entity].'` WHERE id=? AND tenant_account_id=? LIMIT 1',
   [$entityId,$accountId]);
  // Deletion is validated by the scoped mutation itself before emitting.
  if(!$row && $type!=='project.deleted')fail('Registro de evento fora da empresa.',404);
  $payload=json_encode(['tenant_account_id'=>$accountId,'entity_id'=>$entityId],JSON_THROW_ON_ERROR);
  $key=hash('sha256','taskesc:saas:catalog:'.bin2hex(random_bytes(16)));
  $hash=hash('sha256',json_encode([$accountId,$type,$entity,$entityId,$payload,$actorId],JSON_THROW_ON_ERROR));
  $now=(new DateTimeImmutable('now',new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
  return DB::insert('saas_event_outbox',[
   'tenant_account_id'=>$accountId,'event_key'=>$key,'event_hash'=>$hash,
   'event_type'=>$type,'entity_type'=>$entity,'entity_id'=>$entityId,
   'payload_json'=>$payload,'created_by'=>$actorId,'created_at'=>$now,'available_at'=>$now
  ]);
 }

 /** Read-only inspection, NOT a claim/publish/consume method. */
 public static function preview(int $limit=30): array {
  self::ready();
  $owner=SaasRequestScope::owner();
  $limit=max(1,min(100,$limit));
  return DB::all('SELECT id,tenant_account_id,event_type,entity_type,entity_id,payload_json,created_at,available_at,attempts,published_at'.
   ' FROM saas_event_outbox WHERE tenant_account_id=? ORDER BY id DESC LIMIT '.$limit,
   [(int)$owner['account_id']]);
 }
}
