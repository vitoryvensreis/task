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
 taskCheck(SaasOutboxMigration::apply()['outbox_version']===4,'Missing isolated event outbox migration.');
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
 taskCheck((int)DB::one('SELECT COUNT(*) n FROM saas_event_outbox WHERE tenant_account_id=?',[$account])['n']===6,
  'Each successful task mutation must stage exactly one isolated tenant event.');
 taskCheck((int)DB::one('SELECT COUNT(*) n FROM event_outbox')['n']===0,
  'No staged SaaS event may leak into the legacy outbox.');
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
