<?php
declare(strict_types=1);

// B1.2c-2b stage-1 task mutation test — disposable CI MySQL/MariaDB only.
$port=getenv('OUTBOX_TEST_PORT');
if(!$port||!ctype_digit((string)$port))throw new RuntimeException('Disposable CI DB required.');
$root=dirname(__DIR__);
@unlink($root.'/config/config.php');
file_put_contents($root.'/config/config.php',"<?php\nreturn ".var_export([
 'base_url'=>'http://localhost','timezone'=>'America/Fortaleza',
 'db'=>['host'=>'127.0.0.1','port'=>(int)$port,'name'=>'taskesc_test','user'=>'root','password'=>'taskesc-ci-only'],
 'app_key'=>bin2hex(random_bytes(32))
],true).";\n");
require $root.'/app/bootstrap.php';

function taskCheck(bool $valid,string $what): void {if(!$valid)throw new RuntimeException($what);}
function taskDenied(callable $fn,int $status,string $what): void {
 try{$fn();}catch(AppError $error){
  if($error->status===$status)return;
  throw new RuntimeException($what.' (expected '.$status.', got '.$error->status.')');
 }
 throw new RuntimeException($what.' (not rejected)');
}
try{
 $db=DB::conn();$db->exec('SET FOREIGN_KEY_CHECKS=0');
 foreach(DB::all('SHOW TABLES') as $row){
  $table=(string)array_values($row)[0];
  if(!preg_match('/\A[A-Za-z0-9_]+\z/D',$table))throw new RuntimeException('Unsafe fixture table.');
  $db->exec('DROP TABLE `'.$table.'`');
 }
 $db->exec('SET FOREIGN_KEY_CHECKS=1');
 $db->exec(file_get_contents(ROOT.'/database/schema.sql'));
 $admin=DB::insert('users',['name'=>'Account owner','email'=>'task-owner@ci.invalid','role'=>'admin','password_hash'=>'x','created_at'=>now()]);
 $staff=DB::insert('users',['name'=>'Editor','email'=>'task-editor@ci.invalid','role'=>'staff','password_hash'=>'x','created_at'=>now()]);
 $outsider=DB::insert('users',['name'=>'Other company owner','email'=>'task-outsider@ci.invalid','role'=>'admin','password_hash'=>'x','created_at'=>now()]);
 $account=(int)SaasFoundationMigration::apply()['account_id'];
 taskCheck(SaasDomainMigration::apply()['domain_version']===2,'Missing domain migration.');
 taskCheck(SaasChildMigration::apply()['children_version']===3,'Missing child migration.');
 taskCheck(SaasOutboxMigration::issues()===[],'Fresh SaaS outbox schema must be valid.');
 $db->exec('DROP TABLE saas_delivery_jobs');
 $db->exec('DROP TABLE saas_event_receipts');
 $db->exec('DROP TABLE saas_event_outbox');
 taskCheck(SaasOutboxMigration::issues()===['missing_saas_event_outbox'],'Missing isolated event table must be detected.');
 taskCheck(SaasOutboxMigration::apply()['outbox_version']===4,'Missing isolated event outbox migration.');
 taskCheck(SaasOutboxMigration::issues()===[],'SaaS outbox migration must create correct schema.');
 taskCheck(SaasOutboxMigration::apply()['publishers_enabled']===false,'Migration must remain idempotent and inert.');
 taskCheck(SaasReceiptMigration::issues()===['missing_saas_event_receipts'],'Missing staged receipt table must be detected.');
 taskCheck(SaasReceiptMigration::apply()['receipts_version']===5,'Staged receipt migration must complete before another account exists.');
 taskCheck(SaasReceiptMigration::issues()===[],'Receipt schema must have tenant/unique keys and FKs.');
 taskCheck(SaasReceiptMigration::apply()['delivery_enabled']===false,'Receipt migration must remain idempotent and inert.');
 taskCheck(SaasDeliveryMigration::issues()===['missing_saas_delivery_jobs'],'Missing delivery staging table must be detected.');
 taskCheck(SaasDeliveryMigration::apply()['delivery_version']===6,'Delivery migration must apply before new accounts exist.');
 taskCheck(SaasDeliveryMigration::issues()===[],'Delivery table and its tenant FK/indexes must be valid.');
 taskCheck(SaasDeliveryMigration::apply()['external_delivery_enabled']===false,'Repeated migration must remain idempotent and inert.');
 $accountB=DB::insert('accounts',['slug'=>'ci-foreign','name'=>'Other company','account_type'=>'customer','status'=>'active','created_at'=>now(),'updated_at'=>now()]);
 DB::run('DELETE FROM account_members WHERE account_id=? AND user_id=?',[$account,$outsider]);
 DB::insert('account_members',['account_id'=>$accountB,'user_id'=>$outsider,'membership_role'=>'owner','status'=>'active','joined_at'=>now()]);
 $clientB=DB::insert('clients',['tenant_account_id'=>$accountB,'name'=>'Other company client','created_at'=>now()]);
 $categoryB=DB::insert('categories',['tenant_account_id'=>$accountB,'name'=>'Foreign service','fields_json'=>'[]']);
 $stageB=DB::insert('stages',['tenant_account_id'=>$accountB,'name'=>'Foreign stage','kind'=>'production','position'=>1]);
 $projectB=DB::insert('projects',['tenant_account_id'=>$accountB,'client_id'=>$clientB,'name'=>'Foreign project','created_at'=>now()]);
 $taskB=DB::insert('demands',['tenant_account_id'=>$accountB,'client_id'=>$clientB,'project_id'=>$projectB,'category_id'=>$categoryB,
  'stage_id'=>$stageB,'title'=>'Secret task B','brief'=>'Secret','details_json'=>'{}','due_at'=>now(),
  'created_by'=>$outsider,'created_at'=>now(),'updated_at'=>now()]);

 if(session_status()!==PHP_SESSION_ACTIVE)session_start();
 $_SESSION=[];Auth::$origin='web';Auth::$previewClientId=null;
 Auth::$actor=['id'=>$admin,'role'=>'admin'];Auth::$user=['id'=>$admin,'role'=>'admin','is_preview'=>0];
 $client=(int)SaasWriteApi::clientSave(['name'=>'Account A client'])['id'];
 $project=(int)SaasWriteApi::projectSave(['name'=>'Account A project','client_id'=>$client])['id'];
 DB::insert('user_clients',['user_id'=>$staff,'client_id'=>$client,'can_approve'=>1]);
 $base=['client_id'=>$client,'project_id'=>$project,'category_id'=>1,'title'=>'Interview recording','brief'=>'Record in studio'];
 $denied=[
  ['client_id'=>$clientB],['category_id'=>$categoryB],['project_id'=>$projectB],
  ['tenant_account_id'=>$account],['account_id'=>$accountB],
  ['stage_id'=>1],['amount_cents'=>2500],['whatsapp_recipient_mode'=>'custom'],
  ['created_by'=>$outsider],['template_id'=>1],['realtime'=>true]
 ];
 foreach($denied as $extra){
  $expected=in_array((string)array_key_first($extra),['client_id','category_id','project_id'],true)?404:422;
  // A project from tenant A cannot attach to another client, even within one company.
  taskDenied(fn()=>SaasTaskWriteApi::save(array_merge($base,$extra)),$expected,
   'Unsafe task input accepted: '.array_key_first($extra));
 }
 taskCheck((int)DB::one('SELECT COUNT(*) n FROM demands WHERE tenant_account_id=?',[$account])['n']===0,
  'Failed task input created rows.');

 taskDenied(fn()=>SaasTaskWriteApi::save($base+['assigned_to'=>$outsider]),422,'Foreign user assigned.');
 $create=SaasTaskWriteApi::save($base+['assigned_to'=>$staff]);
 $id=(int)$create['id'];
 taskCheck($id>0&&$create['version']===1&&$create['stage_id']===1,'Scoped create result invalid.');
 $stored=DB::one('SELECT * FROM demands WHERE id=?',[$id]);
 taskCheck((int)$stored['tenant_account_id']===$account&&(int)$stored['assigned_to']===$staff
   &&(int)$stored['created_by']===$admin&&$stored['whatsapp_recipient_mode']==='none'
   &&(int)$stored['amount_cents']===0,'Task created with forbidden attributes.');
 // Transactional idempotence: repeating the identical event must reuse the row.
 $sameId=DB::tx(fn()=>SaasEventOutbox::task($account,$id,1,'demand.created',$admin));
 taskCheck($sameId>0&&(int)DB::one('SELECT COUNT(*) n FROM saas_event_outbox WHERE tenant_account_id=?',[$account])['n']===1,
  'Idempotent retry of an identical event must not create a second row.');
 $outsideRejected=false;
 try{SaasEventOutbox::task($account,$id,1,'demand.created',$admin);}catch(LogicException){$outsideRejected=true;}
 taskCheck($outsideRejected,'Event write outside the shared transaction must be forbidden.');
 taskCheck((int)DB::one('SELECT COUNT(*) n FROM transitions WHERE demand_id=? AND tenant_account_id=?',[$id,$account])['n']===1,
  'Initial transition must be owned and atomic.');
 taskCheck((int)DB::one('SELECT COUNT(*) n FROM notifications')['n']===0
   &&(int)DB::one('SELECT COUNT(*) n FROM automation_runs')['n']===0
   &&(int)DB::one('SELECT COUNT(*) n FROM whatsapp_messages')['n']===0,
  'Staged task must not emit unsafe asynchronous side effects.');
 taskCheck(count(SaasReadApi::listing(['resource'=>'tasks'])['rows'])===1,'Scoped read must show created task.');

 taskDenied(fn()=>SaasTaskWriteApi::save($base),409,'Exact duplicate not rejected.');
 taskDenied(fn()=>SaasTaskWriteApi::save(['id'=>$id,'version'=>50,'title'=>'Stale']),409,'Stale edit accepted.');
 taskDenied(fn()=>SaasTaskWriteApi::save(['id'=>$id,'version'=>1,'category_id'=>$categoryB]),404,'Foreign category on edit.');
 taskDenied(fn()=>SaasTaskWriteApi::save(['id'=>$taskB,'version'=>1,'title'=>'Stolen']),404,'Foreign task edited.');
 taskDenied(fn()=>SaasTaskWriteApi::move(['id'=>$taskB,'version'=>1,'stage_id'=>2]),404,'Foreign task moved.');
 taskDenied(fn()=>SaasTaskWriteApi::archive(['id'=>$taskB,'version'=>1]),404,'Foreign task archived.');
 taskDenied(fn()=>SaasTaskWriteApi::move(['id'=>$id,'version'=>1,'stage_id'=>$stageB]),404,'Foreign stage accepted.');

 $edit=SaasTaskWriteApi::save(['id'=>$id,'version'=>1,'title'=>'Interview recording updated','priority'=>'high']);
 taskCheck($edit['version']===2&&$edit['created']===false,'Versioned task edit failed.');
 $edited=DB::one('SELECT * FROM demands WHERE id=?',[$id]);
 taskCheck($edited['title']==='Interview recording updated'&&$edited['brief']==='Record in studio'
   &&(int)$edited['stage_id']===1 && $edited['due_at']===$stored['due_at'],
  'Task edit must preserve omitted brief, due date and stage.');
 taskDenied(fn()=>SaasTaskWriteApi::move(['id'=>$id,'version'=>1,'stage_id'=>2]),409,'Outdated stage change accepted.');

 $moved=SaasTaskWriteApi::move(['id'=>$id,'version'=>2,'stage_id'=>4]);
 taskCheck($moved['version']===3&&$moved['stage_id']===4,'Stage movement failed.');
 $stageMove=DB::one('SELECT * FROM transitions WHERE demand_id=? ORDER BY id DESC LIMIT 1',[$id]);
 taskCheck((int)$stageMove['from_stage']===1&&(int)$stageMove['to_stage']===4
   &&(int)$stageMove['tenant_account_id']===$account,'Stage transition missing tenant ownership.');
 taskDenied(fn()=>SaasTaskWriteApi::move(['id'=>$id,'version'=>3,'stage_id'=>4]),409,'No-op stage move accepted.');
 taskDenied(fn()=>SaasTaskWriteApi::save(['id'=>$id,'version'=>3,'draft'=>1]),409,'Draft allowed in production stage.');

 $archived=SaasTaskWriteApi::archive(['id'=>$id,'version'=>3]);
 taskCheck($archived['archived']===true&&$archived['version']===4,'Safe task archive failed.');
 taskCheck(DB::one('SELECT deleted_at FROM demands WHERE id=?',[$id])['deleted_at']!==null,
  'Soft-delete timestamp missing.');
 taskCheck((int)DB::one('SELECT COUNT(*) n FROM transitions WHERE demand_id=?',[$id])['n']===2,
  'Historical transitions must survive task archive.');
 taskCheck(count(SaasReadApi::listing(['resource'=>'tasks'])['rows'])===0,'Archived task visible in listing.');
 taskDenied(fn()=>SaasTaskWriteApi::archive(['id'=>$id,'version'=>4]),404,'Second archive accepted.');

 // Financial linkage must block an archive, preserving accounting history.
 $live=SaasTaskWriteApi::save(array_merge($base,['title'=>'Paid task']));
 $liveId=(int)$live['id'];
 DB::insert('invoices',['tenant_account_id'=>$account,'client_id'=>$client,'demand_id'=>$liveId,
  'description'=>'Invoice','amount_cents'=>100,'due_on'=>'2026-11-30','created_at'=>now()]);
 taskDenied(fn()=>SaasTaskWriteApi::archive(['id'=>$liveId,'version'=>1]),409,'Invoiced task archived.');
 taskCheck(DB::one('SELECT deleted_at FROM demands WHERE id=?',[$liveId])['deleted_at']===null,
  'Denied archive modified task.');
 $fileTask=SaasTaskWriteApi::save(array_merge($base,['title'=>'Task with files']));$fileId=(int)$fileTask['id'];
 DB::insert('files',['tenant_account_id'=>$account,'demand_id'=>$fileId,'author_id'=>$admin,
  'label'=>'File','created_at'=>now()]);
 taskDenied(fn()=>SaasTaskWriteApi::archive(['id'=>$fileId,'version'=>1]),409,'Task with files archived.');
 // Failed mutations roll back both business records and staged outbox rows.
 $prior=(int)DB::one('SELECT COUNT(*) n FROM saas_event_outbox')['n'];
 $rolled=false;
 try{DB::tx(function()use($base){
   SaasTaskWriteApi::save(array_merge($base,['title'=>'Rolled back task']));
   throw new RuntimeException('intentional_rollback');
  });}catch(RuntimeException $e){$rolled=$e->getMessage()==='intentional_rollback';}
 taskCheck($rolled,'Rollback fixture must throw.');
 taskCheck((int)DB::one('SELECT COUNT(*) n FROM saas_event_outbox')['n']===$prior,
  'Business transaction rollback must remove the corresponding SaaS event.');
 taskCheck(!DB::one('SELECT id FROM demands WHERE title=?',['Rolled back task']),
  'Rollback must also remove the newly created task.');
 taskCheck((int)DB::one('SELECT COUNT(*) n FROM saas_event_outbox WHERE tenant_account_id=?',[$account])['n']===6,
  'Each successful task mutation must stage exactly one isolated tenant event.');
 $staged=SaasEventOutbox::preview(50);
 taskCheck(count($staged)===6,'Authenticated owner must see only own staged events.');
 foreach($staged as $ev) {
  taskCheck((int)$ev['tenant_account_id']===$account&&$ev['published_at']===null&&(int)$ev['attempts']===0,
   'Staged events must never be automatically claimed or published.');
  taskCheck(!str_contains((string)$ev['payload_json'],'Record in studio'),
   'Brief or private text must not be stored in staged event payloads.');
 }
 taskCheck((int)DB::one('SELECT COUNT(*) n FROM event_outbox')['n']===0,
  'No staged SaaS event may leak into the legacy outbox.');

 // Tenant-aware consumer does NOT publish messages. It ONLY creates held receipts.
 $foreignPayload=[
  'tenant_account_id'=>$accountB,'entity_id'=>$taskB,'version'=>1,
  'client_id'=>$clientB,'project_id'=>$projectB,'stage_id'=>$stageB
 ];
 ksort($foreignPayload,SORT_STRING);
 $foreignJson=json_encode($foreignPayload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
 $foreignType='demand.created';
 $foreignKey=hash('sha256','taskesc:saas:v1:'.$accountB.':'.$foreignType.':'.$taskB.':1');
 $foreignHash=hash('sha256',json_encode([$accountB,$foreignType,'demand',$taskB,$foreignJson,$outsider],JSON_THROW_ON_ERROR));
 $foreignId=DB::insert('saas_event_outbox',[
  'tenant_account_id'=>$accountB,'event_key'=>$foreignKey,'event_hash'=>$foreignHash,
  'event_type'=>$foreignType,'entity_type'=>'demand','entity_id'=>$taskB,
  'payload_json'=>$foreignJson,'created_by'=>$outsider,'created_at'=>now(),'available_at'=>now()
 ]);
 $firstBatch=SaasEventConsumer::prepareBatch($account,50);
 taskCheck($firstBatch['selected']===6&&$firstBatch['prepared']===6&&$firstBatch['external_delivery_enabled']===false,
  'Verified internal account should prepare six held receipts without external delivery.');
 taskCheck((int)DB::one('SELECT COUNT(*) n FROM saas_event_receipts WHERE tenant_account_id=?',[$accountB])['n']===0,
  'Preparing tenant A must NEVER read or acknowledge tenant B events.');
 $repeat=SaasEventConsumer::prepareBatch($account,50);
 taskCheck($repeat['selected']===0&&$repeat['prepared']===0,
  'Replay must not duplicate any held receipts.');
 $otherRejected=false;
 try{SaasEventConsumer::prepareBatch($accountB,20);}catch(RuntimeException){$otherRejected=true;}
 taskCheck($otherRejected,'External tenant worker must remain unavailable during B1.2.');
 taskCheck((int)DB::one('SELECT COUNT(*) n FROM saas_event_outbox WHERE published_at IS NOT NULL')['n']===0,
  'Preparing receipts must never acknowledge publication or consume held outbox.');
 taskCheck(count(SaasEventConsumer::heldForOwner(50))===6,
  'Verified owner should inspect six safe receipt projections.');


 // B1.2c-2b.2b.2a: only internal-event delivery intents may be staged.
 $queued=SaasDeliveryQueue::stage($account,50);
 taskCheck($queued['selected']===6&&$queued['staged']===6&&$queued['delivered']===0,
  'Six verified receipts should produce only six held internal delivery intents.');
 taskCheck(SaasDeliveryQueue::stage($account,50)['staged']===0,
  'Replaying the same receipts must not create duplicate delivery jobs.');
 taskCheck((int)DB::one('SELECT COUNT(*) n FROM saas_delivery_jobs WHERE tenant_account_id=?',[$accountB])['n']===0,
  'No delivery intent from account B may be staged during account A batch.');
 $otherRejected=false;
 try{SaasDeliveryQueue::stage($accountB,10);}catch(RuntimeException){$otherRejected=true;}
 taskCheck($otherRejected,'A customer account must be blocked from the unfinished delivery worker.');
 $jobs=SaasDeliveryQueue::forOwner(50);
 taskCheck(count($jobs)===6,'Only six tenant A jobs should appear to its owner.');
 foreach($jobs as $job)
  taskCheck($job['destination_scope']==='saas.account.'.$account.'.events'&&
   $job['channel']==='internal_event'&&$job['status']==='held'&&(int)$job['attempts']===0,
   'Job channel/scope/status must be derived by the server and never from untrusted input.');

 // B1.2c-2b.2b.2b.1: derive recipients from active memberships,
 // never an event payload, WhatsApp phone, email or arbitrary URL.
 $ownedJob=(int)DB::one(
  'SELECT j.id FROM saas_delivery_jobs j'.
  ' JOIN saas_event_receipts r ON r.id=j.receipt_id AND r.tenant_account_id=j.tenant_account_id'.
  ' JOIN saas_event_outbox e ON e.id=r.outbox_id AND e.tenant_account_id=j.tenant_account_id'.
  ' WHERE j.tenant_account_id=? AND e.entity_id=? LIMIT 1',
  [$account,$id])['id'];
 $routing=SaasDeliveryRouting::recipients($account,$ownedJob);
 taskCheck($routing['recipient_user_ids']===[$admin,$staff]&&
  $routing['recipient_count']===2&&$routing['external_delivery_enabled']===false,
  'Owner and assigned, client-authorized staff must be derived safely.');
 taskCheck(!in_array($outsider,$routing['recipient_user_ids'],true),
  'User from account B must never receive account A internal events.');
 $wrongAccount=false;
 try{SaasDeliveryRouting::recipients($accountB,$ownedJob);}catch(RuntimeException){$wrongAccount=true;}
 taskCheck($wrongAccount,'Guessing an account B scope must not reveal account A delivery.');
 DB::run("UPDATE account_members SET status='inactive' WHERE account_id=? AND user_id=?",[$account,$staff]);
 taskCheck(SaasDeliveryRouting::recipients($account,$ownedJob)['recipient_user_ids']===[$admin],
  'Revoking the assigned member must remove their delivery authorization immediately.');
 DB::run("UPDATE account_members SET status='active' WHERE account_id=? AND user_id=?",[$account,$staff]);
 DB::run('DELETE FROM user_clients WHERE user_id=? AND client_id=?',[$staff,$client]);
 taskCheck(SaasDeliveryRouting::recipients($account,$ownedJob)['recipient_user_ids']===[$admin],
  'Removing the staff-client relationship must remove routing rights.');
 DB::insert('user_clients',['user_id'=>$staff,'client_id'=>$client,'can_approve'=>1]);
 $scope=(string)DB::one('SELECT destination_scope FROM saas_delivery_jobs WHERE id=?',[$ownedJob])['destination_scope'];
 DB::run('UPDATE saas_delivery_jobs SET destination_scope=? WHERE id=?',['saas.account.999999.events',$ownedJob]);
 $badDestination=false;
 try{SaasDeliveryRouting::recipients($account,$ownedJob);}catch(RuntimeException){$badDestination=true;}
 taskCheck($badDestination,'Forged realtime/channel scope must be rejected.');
 DB::run('UPDATE saas_delivery_jobs SET destination_scope=? WHERE id=?',[$scope,$ownedJob]);
 $storedStatus=(string)DB::one('SELECT status FROM saas_delivery_jobs WHERE id=?',[$ownedJob])['status'];
 DB::run("UPDATE saas_delivery_jobs SET status='dead' WHERE id=?",[$ownedJob]);
 $deadRoute=false;
 try{SaasDeliveryRouting::recipients($account,$ownedJob);}catch(RuntimeException){$deadRoute=true;}
 taskCheck($deadRoute,'Dead-letter jobs must not route to recipients.');
 DB::run('UPDATE saas_delivery_jobs SET status=? WHERE id=?',[$storedStatus,$ownedJob]);
 DB::run("UPDATE accounts SET status='suspended' WHERE id=?",[$account]);
 $suspendedRoute=false;
 try{SaasDeliveryRouting::recipients($account,$ownedJob);}catch(RuntimeException){$suspendedRoute=true;}
 taskCheck($suspendedRoute,'Suspended account must not route events.');
 DB::run("UPDATE accounts SET status='active' WHERE id=?",[$account]);
 taskCheck(SaasDeliveryRouting::recipients($account,$ownedJob)['recipient_count']===2,
  'Routing must recover only after active account, membership and client assignment return.');
 taskCheck((int)DB::one('SELECT COUNT(*) n FROM notifications')['n']===0 &&
  (int)DB::one('SELECT COUNT(*) n FROM whatsapp_messages')['n']===0 &&
  (int)DB::one('SELECT COUNT(*) n FROM saas_event_outbox WHERE published_at IS NOT NULL')['n']===0,
  'Resolving recipients must not send notifications or publish any events.');

 // A changed receipt must fail BEFORE creating its delivery intent.
 $target=(int)$jobs[0]['receipt_id'];
 DB::run('DELETE FROM saas_delivery_jobs WHERE tenant_account_id=? AND receipt_id=?',[$account,$target]);
 $originalHash=(string)DB::one('SELECT event_hash FROM saas_event_receipts WHERE id=?',[$target])['event_hash'];
 DB::run('UPDATE saas_event_receipts SET event_hash=? WHERE id=?',[str_repeat('f',64),$target]);
 $integrityFailure=false;
 try{SaasDeliveryQueue::stage($account,50);}catch(RuntimeException){$integrityFailure=true;}
 taskCheck($integrityFailure,'Corrupted receipt hash must prevent staging.');
 taskCheck((int)DB::one('SELECT COUNT(*) n FROM saas_delivery_jobs WHERE tenant_account_id=?',[$account])['n']===5,
  'Failed staging batch must not leave a partial job.');
 DB::run('UPDATE saas_event_receipts SET event_hash=? WHERE id=?',[$originalHash,$target]);
 taskCheck(SaasDeliveryQueue::stage($account,50)['staged']===1,
  'Fixed receipt can be staged exactly once.');

 // Failure handling is a CAS transition; retry has exponential backoff,
 // and the fifth failed validation moves the job to the dead-letter state.
 $jobId=(int)DB::one('SELECT id FROM saas_delivery_jobs WHERE tenant_account_id=? ORDER BY id LIMIT 1',[$account])['id'];
 $foreignBlocked=false;
 try{SaasDeliveryQueue::recordFailure($accountB,$jobId,0,'temporary_timeout');}
 catch(RuntimeException){$foreignBlocked=true;}
 taskCheck($foreignBlocked,'Cannot modify another account delivery job by guessing an ID.');
 $firstFail=SaasDeliveryQueue::recordFailure($account,$jobId,0,'temporary_timeout');
 taskCheck($firstFail['status']==='retry'&&$firstFail['attempts']===1&&$firstFail['external_delivery_enabled']===false,
  'First validation failure must schedule retry without sending anything.');
 $staleBlocked=false;
 try{SaasDeliveryQueue::recordFailure($account,$jobId,0,'temporary_timeout');}
 catch(RuntimeException){$staleBlocked=true;}
 taskCheck($staleBlocked,'Stale retry attempt must not double-increment.');
 $earlyBlocked=false;
 try{SaasDeliveryQueue::recordFailure($account,$jobId,1,'temporary_timeout');}
 catch(RuntimeException){$earlyBlocked=true;}
 taskCheck($earlyBlocked,'Retry cannot run earlier than the backoff deadline.');
 for($attempt=1;$attempt<5;$attempt++){
  DB::run('UPDATE saas_delivery_jobs SET available_at=? WHERE id=?',
   ['2000-01-01 00:00:00.000000',$jobId]);
  $result=SaasDeliveryQueue::recordFailure($account,$jobId,$attempt,'temporary_timeout');
  taskCheck($result['attempts']===$attempt+1&&
   $result['status']===($attempt===4?'dead':'retry'),
   'Retry must eventually reach the dead-letter state.');
 }
 $deadBlocked=false;
 try{SaasDeliveryQueue::recordFailure($account,$jobId,5,'temporary_timeout');}
 catch(InvalidArgumentException){$deadBlocked=true;}
 taskCheck($deadBlocked,'Dead-letter jobs must not be retried without a separate recovery flow.');
 taskCheck((int)DB::one('SELECT COUNT(*) n FROM saas_delivery_jobs WHERE status=? AND tenant_account_id=?',
  ['dead',$account])['n']===1,'Expected exactly one dead-letter job.');
 taskCheck((int)DB::one('SELECT COUNT(*) n FROM saas_event_outbox WHERE published_at IS NOT NULL')['n']===0,
  'Delivery intent and retries must not mark an event as published.');
 taskCheck((int)DB::one('SELECT COUNT(*) n FROM whatsapp_messages')['n']===0&&
  (int)DB::one('SELECT COUNT(*) n FROM notifications')['n']===0,
  'No WhatsApp or notifications may be sent while delivery transport is disabled.');

 // Corrupt metadata is rejected and rolled back instead of silently consumed.
 $bad=DB::insert('saas_event_outbox',[
  'tenant_account_id'=>$account,'event_key'=>str_repeat('a',64),'event_hash'=>str_repeat('b',64),
  'event_type'=>'demand.created','entity_type'=>'demand','entity_id'=>$id,
  'payload_json'=>'{"tenant_account_id":999,"entity_id":999,"version":999}',
  'created_by'=>$admin,'created_at'=>now(),'available_at'=>now()
 ]);
 $invalidRejected=false;
 try{SaasEventConsumer::prepareBatch($account,10);}catch(RuntimeException|JsonException){$invalidRejected=true;}
 taskCheck($invalidRejected,'Corrupt event must be rejected before receipt.');
 taskCheck((int)DB::one('SELECT COUNT(*) n FROM saas_event_receipts WHERE tenant_account_id=?',[$account])['n']===6,
  'Invalid event must not create any receipts.');
 DB::run('DELETE FROM saas_event_outbox WHERE id=?',[$bad]);
 taskCheck((int)DB::one("SELECT COUNT(*) n FROM audit_log WHERE entity='demand' AND action LIKE 'saas.%'")['n']===6,
  'All successful task changes must appear in legacy audit (internal only).');

 // Status gates, stale sessions, and tokenless API are rejected on every write.
 Auth::$actor=['id'=>$staff,'role'=>'admin'];Auth::$user=['id'=>$staff,'role'=>'admin','is_preview'=>0];
 taskDenied(fn()=>SaasTaskWriteApi::save($base+['title'=>'Member bypass']),403,'Non-owner wrote task.');
 Auth::$actor=['id'=>$outsider,'role'=>'admin'];Auth::$user=['id'=>$outsider,'role'=>'admin','is_preview'=>0];
 taskDenied(fn()=>SaasTaskWriteApi::save($base+['title'=>'External bypass']),403,'Foreign owner wrote task.');
 Auth::$actor=['id'=>$admin,'role'=>'admin'];Auth::$user=['id'=>$admin,'role'=>'admin','is_preview'=>0];
 Auth::$origin='api';
 taskDenied(fn()=>SaasTaskWriteApi::save($base+['title'=>'Bearer bypass']),403,'Legacy API token wrote task.');
 Auth::$origin='web';
 DB::run("UPDATE account_members SET status='inactive' WHERE account_id=? AND user_id=?",[$account,$admin]);
 taskDenied(fn()=>SaasTaskWriteApi::save($base+['title'=>'Revoked owner']),403,'Revoked owner wrote task.');
 DB::run("UPDATE account_members SET status='active' WHERE account_id=? AND user_id=?",[$account,$admin]);
 DB::run("UPDATE settings SET value='0' WHERE `key`=?",[SaasChildMigration::MARKER]);
 taskDenied(fn()=>SaasTaskWriteApi::save($base+['title'=>'No migration']),503,'Incomplete migration allowed writes.');
 DB::run("UPDATE settings SET value='3' WHERE `key`=?",[SaasChildMigration::MARKER]);
 echo "SaaS B1.2c-2b.1: versioned tasks, scoped parents, transitions, archival, A/B and zero side effects OK\n";
}catch(Throwable $e){
 fwrite(STDERR,$e->getMessage()."\n");exit(1);
}
