<?php
declare(strict_types=1);

// Disposable CI database only. Never run against a production host/port.
$port=getenv('OUTBOX_TEST_PORT');
if(!$port||!ctype_digit((string)$port))throw new RuntimeException('Only disposable OUTBOX_TEST_PORT is allowed.');
$root=dirname(__DIR__);
@unlink($root.'/config/config.php');
$config=[
 'base_url'=>'http://localhost','timezone'=>'America/Fortaleza',
 'db'=>['host'=>'127.0.0.1','port'=>(int)$port,'name'=>'taskesc_test','user'=>'root','password'=>'taskesc-ci-only'],
 'app_key'=>bin2hex(random_bytes(32))
];
file_put_contents($root.'/config/config.php',"<?php\nreturn ".var_export($config,true).";\n");
require $root.'/app/bootstrap.php';

function swCheck(bool $ok,string $message): void {if(!$ok)throw new RuntimeException($message);}
function swReject(callable $fn,int $expected,string $message): void {
 try{$fn();}catch(AppError $error) {
  if($error->status!==$expected)throw new RuntimeException($message.' (received '.$error->status.' instead of '.$expected.')');
  return;
 }
 throw new RuntimeException($message.' (did not reject)');
}
try {
 $pdo=DB::conn();
 $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
 foreach(DB::all('SHOW TABLES') as $row){
  $table=(string)array_values($row)[0];
  if(!preg_match('/\A[A-Za-z0-9_]+\z/D',$table))throw new RuntimeException('Unexpected fixture table');
  $pdo->exec('DROP TABLE `'.$table.'`');
 }
 $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
 $pdo->exec(file_get_contents(ROOT.'/database/schema.sql'));

 $admin=DB::insert('users',['name'=>'Owner','email'=>'saas-write-admin@ci.invalid','password_hash'=>'fixture',
  'role'=>'admin','created_at'=>now()]);
 $member=DB::insert('users',['name'=>'Non-owner','email'=>'saas-write-member@ci.invalid','password_hash'=>'fixture',
  'role'=>'admin','created_at'=>now()]);
 $other=DB::insert('users',['name'=>'External owner','email'=>'saas-write-foreign@ci.invalid','password_hash'=>'fixture',
  'role'=>'admin','created_at'=>now()]);
 $first=SaasFoundationMigration::apply();
 $legacy=(int)$first['account_id'];
 swCheck(SaasDomainMigration::apply()['domain_version']===2,'Domain migration must precede writes.');
 swCheck(SaasChildMigration::apply()['children_version']===3,'Child migration must precede writes.');
 DB::run('UPDATE account_members SET membership_role=? WHERE account_id=? AND user_id=?',
  ['member',$legacy,$member]);
 if(session_status()!==PHP_SESSION_ACTIVE)session_start();
 $_SESSION=[];Auth::$origin='web';Auth::$previewClientId=null;
 Auth::$actor=['id'=>$admin,'role'=>'admin'];Auth::$user=['id'=>$admin,'role'=>'admin','is_preview'=>0];

 // Account must always come from active owner membership, never request data.
 swReject(fn()=>SaasWriteApi::clientSave(['name'=>'Hijack','account_id'=>100]),403,'Forged account_id accepted.');
 swReject(fn()=>SaasWriteApi::clientSave(['name'=>'Hijack','tenant_account_id'=>100]),403,'Forged tenant_account_id accepted.');
 swReject(fn()=>SaasWriteApi::projectSave(['name'=>'Hijack','client_id'=>1,'created_by'=>$other]),403,'Forged created_by accepted.');
 swCheck((int)DB::one('SELECT COUNT(*) n FROM clients')['n']===0,'Rejected spoof must not insert rows.');

 $created=SaasWriteApi::clientSave(['name'=>'Empresa atendida','brand'=>'Marca A',
  'email'=>'atendimento@ci.invalid','commercial_type'=>'monthly','monthly_amount_cents'=>22000,'monthly_payment_day'=>15]);
 $cid=(int)$created['id'];
 swCheck($cid>0&&$created['created']===true,'Client creation failed.');
 $stored=DB::one('SELECT * FROM clients WHERE id=?',[$cid]);
 swCheck((int)$stored['tenant_account_id']===$legacy&&$stored['name']==='Empresa atendida'&&$stored['monthly_amount_cents']==22000,'Client owner and billing fields not persisted.');
 swCheck(count(SaasReadApi::listing(['resource'=>'clients'])['rows'])===1,'Created client must show up in tenant-scoped read API.');
 swReject(fn()=>SaasWriteApi::clientSave(['id'=>$cid,'name'=>'Overwritten','email'=>'not-an-email']),422,'Invalid client email was accepted.');
 swCheck((string)DB::one('SELECT name FROM clients WHERE id=?',[$cid])['name']==='Empresa atendida','Failed client edit changed data.');
 $edited=SaasWriteApi::clientSave(['id'=>$cid,'name'=>'Empresa corrigida','brand'=>'Marca Nova']);
 swCheck($edited['created']===false,'Editing should not create a new client.');
 swCheck((string)DB::one('SELECT email FROM clients WHERE id=?',[$cid])['email']==='atendimento@ci.invalid','Client edit cleared omitted fields.');

 $foreignAccount=DB::insert('accounts',['slug'=>'saas-fixture-external','name'=>'Another company',
  'account_type'=>'customer','status'=>'active','created_at'=>now(),'updated_at'=>now()]);
 DB::insert('account_members',['account_id'=>$foreignAccount,'user_id'=>$other,
  'membership_role'=>'owner','status'=>'active','joined_at'=>now()]);
 $foreignClient=DB::insert('clients',['tenant_account_id'=>$foreignAccount,
  'name'=>'Confidential client B','created_at'=>now()]);
 $foreignProject=DB::insert('projects',['tenant_account_id'=>$foreignAccount,'client_id'=>$foreignClient,
  'name'=>'Foreign project','created_at'=>now()]);
 swReject(fn()=>SaasWriteApi::clientSave(['id'=>$foreignClient,'name'=>'Overwrite B']),404,'Foreign client update was not denied.');
 swReject(fn()=>SaasWriteApi::clientArchive(['id'=>$foreignClient]),404,'Foreign client archive was not denied.');
 swReject(fn()=>SaasWriteApi::projectSave(['client_id'=>$foreignClient,'name'=>'Foreign write']),404,'Foreign client as new project parent was not denied.');
 swReject(fn()=>SaasWriteApi::projectSave(['id'=>$foreignProject,'name'=>'Overwrite project']),404,'Foreign project update was not denied.');
 swReject(fn()=>SaasWriteApi::projectDelete(['id'=>$foreignProject]),404,'Foreign project delete was not denied.');
 swCheck(DB::one('SELECT name FROM clients WHERE id=?',[$foreignClient])['name']==='Confidential client B','Foreign records mutated.');

 $empty=SaasWriteApi::clientSave(['name'=>'Temporary empty client']);$emptyId=(int)$empty['id'];
 swCheck(SaasWriteApi::clientArchive(['id'=>$emptyId])['archived']===true,'Empty client should archive safely.');
 swReject(fn()=>SaasWriteApi::clientSave(['id'=>$emptyId,'name'=>'Revive']),409,'Inactive client unexpectedly editable.');
 swReject(fn()=>SaasWriteApi::clientArchive(['id'=>$emptyId]),409,'Double archive should fail.');

 $project=SaasWriteApi::projectSave(['client_id'=>$cid,'name'=>'Production campaign','description'=>'Initial work']);
 $pid=(int)$project['id'];
 swCheck((int)DB::one('SELECT tenant_account_id FROM projects WHERE id=?',[$pid])['tenant_account_id']===$legacy,'New project has wrong tenant.');
 swReject(fn()=>SaasWriteApi::clientArchive(['id'=>$cid]),409,'Client with a linked project must not archive.');
 $update=SaasWriteApi::projectSave(['id'=>$pid,'name'=>'Production campaign V2']);
 swCheck($update['created']===false,'Project edit created a new project.');
 swCheck(DB::one('SELECT description FROM projects WHERE id=?',[$pid])['description']==='Initial work','Project update cleared description.');
 swCheck(SaasWriteApi::projectDelete(['id'=>$pid])['deleted']===true,'Empty project must delete safely.');
 swReject(fn()=>SaasWriteApi::projectDelete(['id'=>$pid]),404,'Deleted project must no longer exist.');

 $liveProject=SaasWriteApi::projectSave(['client_id'=>$cid,'name'=>'Project with task']);
 $liveId=(int)$liveProject['id'];
 $category=(int)DB::one("SELECT id FROM categories WHERE tenant_account_id=? LIMIT 1",[$legacy])['id'];
 $stage=(int)DB::one("SELECT id FROM stages WHERE tenant_account_id=? LIMIT 1",[$legacy])['id'];
 $demand=DB::insert('demands',['tenant_account_id'=>$legacy,'client_id'=>$cid,
  'project_id'=>$liveId,'category_id'=>$category,'stage_id'=>$stage,'title'=>'Existing task',
  'brief'=>'Brief','details_json'=>'{}','due_at'=>now(),'created_by'=>$admin,
  'created_at'=>now(),'updated_at'=>now()]);
 $sameTenantClient=SaasWriteApi::clientSave(['name'=>'Another internal client']);
 swReject(fn()=>SaasWriteApi::projectSave(['id'=>$liveId,'client_id'=>$sameTenantClient['id'],
  'name'=>'Moved away']),409,'Project with tasks moved to another client.');
 swReject(fn()=>SaasWriteApi::projectDelete(['id'=>$liveId]),409,'Project with tasks deleted.');
 swCheck((int)DB::one('SELECT project_id FROM demands WHERE id=?',[$demand])['project_id']===$liveId,'Rejected edit damaged existing task.');

 $stagedAudit=DB::all("SELECT action FROM audit_log WHERE entity='project' AND entity_id=? ORDER BY id",[$pid]);
 swCheck(count($stagedAudit)===3,'Create, update and delete must write audit records.');

 // A global admin who is not the tenant owner must not mutate anything.
 Auth::$actor=['id'=>$member,'role'=>'admin'];Auth::$user=['id'=>$member,'role'=>'admin','is_preview'=>0];
 swReject(fn()=>SaasWriteApi::clientSave(['name'=>'Bypass']),403,'Non-owner global admin created client.');
 Auth::$actor=['id'=>$other,'role'=>'admin'];Auth::$user=['id'=>$other,'role'=>'admin','is_preview'=>0];
 swReject(fn()=>SaasWriteApi::clientSave(['name'=>'External account']),403,'External tenant bypassed hard gate.');
 Auth::$actor=['id'=>$admin,'role'=>'admin'];Auth::$user=['id'=>$admin,'role'=>'admin','is_preview'=>0];
 Auth::$origin='api';
 swReject(fn()=>SaasWriteApi::clientSave(['name'=>'Bearer bypass']),403,'Tenantless bearer token created client.');
 Auth::$origin='web';
 DB::run("UPDATE account_members SET status='inactive' WHERE account_id=? AND user_id=?",[$legacy,$admin]);
 swReject(fn()=>SaasWriteApi::clientSave(['name'=>'Revoked owner']),403,'Revoked membership created client.');
 DB::run("UPDATE account_members SET status='active' WHERE account_id=? AND user_id=?",[$legacy,$admin]);

 DB::run("UPDATE settings SET value='0' WHERE `key`=?",[SaasChildMigration::MARKER]);
 swReject(fn()=>SaasWriteApi::clientSave(['name'=>'Incomplete migration']),503,'Incomplete domain gate still permitted writing.');
 DB::run("UPDATE settings SET value='3' WHERE `key`=?",[SaasChildMigration::MARKER]);
 swCheck((int)(DB::one('SELECT COUNT(*) n FROM clients')['n']??0)===4,'Rejected operations must not create extra clients.');
 echo "SaaS B1.2c-2a: scoped writes, guarded deletes, owner/CSRF route contract and cross-tenant protection OK\n";
}catch(Throwable $error){fwrite(STDERR,$error->getMessage()."\n");exit(1);}
