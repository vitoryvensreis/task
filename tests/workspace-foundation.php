<?php
declare(strict_types=1);
$port=getenv('OUTBOX_TEST_PORT');
if(!$port||!ctype_digit((string)$port))throw new RuntimeException('Disposable DB required.');
define('ROOT',dirname(__DIR__));
$pdo=new PDO('mysql:host=127.0.0.1;port='.(int)$port.';dbname=taskesc_test;charset=utf8mb4',
 'root','taskesc-ci-only',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE DATABASE IF NOT EXISTS taskesc_workspace_test CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
function config(?string $key=null,mixed $default=null):mixed {
 $c=['db'=>['host'=>'127.0.0.1','port'=>(int)getenv('OUTBOX_TEST_PORT'),
  'name'=>'taskesc_workspace_test','user'=>'root','password'=>'taskesc-ci-only']];
 return $key===null?$c:($c[$key]??$default);
}
require ROOT.'/app/DB.php';
require ROOT.'/app/WorkspaceFoundation.php';
require ROOT.'/app/WorkspaceFoundationGuard.php';
require ROOT.'/app/WorkspaceContext.php';
require ROOT.'/app/MasterSupport.php';
require ROOT.'/app/Notify.php';
require ROOT.'/app/Auth.php';
require ROOT.'/app/WorkspaceCatalog.php';
require ROOT.'/app/WorkspacePreferences.php';
require ROOT.'/app/PlatformPlans.php';
require ROOT.'/app/Catalog.php';
require ROOT.'/app/Demands.php';
function fail(string $message,int $status=422,array $details=[]):never {throw new RuntimeException($message,$status);}

function now():string {return gmdate('Y-m-d H:i:s');}
function decode(?string $raw,mixed $fallback=[]):mixed {return $raw?json_decode($raw,true,512,JSON_THROW_ON_ERROR):$fallback;}
function base(string $path=''):string{return 'https://task.escotilhaprodutora.com/'.ltrim($path,'/');}
function testws(bool $ok,string $message):void {if(!$ok)throw new RuntimeException($message);}
function refuses(callable $operation,string $reason):void {
 try{$operation();}catch(RuntimeException|LogicException){return;}
 throw new RuntimeException($reason);
}
try{
 DB::conn()->exec(file_get_contents(ROOT.'/database/schema.sql'));
 $owner=DB::insert('users',['name'=>'Workspace owner','email'=>WorkspaceFoundation::OWNER_EMAIL,
  'password_hash'=>'test','role'=>'admin','active'=>1,'created_at'=>gmdate('Y-m-d H:i:s')]);
 $staff=DB::insert('users',['name'=>'Staff','email'=>'staff-workspace@ci.invalid',
  'password_hash'=>'test','role'=>'staff','active'=>1,'created_at'=>gmdate('Y-m-d H:i:s')]);
 $customer=DB::insert('users',['name'=>'Customer','email'=>'client-workspace@ci.invalid',
  'password_hash'=>'test','role'=>'client','active'=>1,'created_at'=>gmdate('Y-m-d H:i:s')]);
 $client=DB::insert('clients',['name'=>'Preserved legacy client','created_at'=>gmdate('Y-m-d H:i:s')]);
 $project=DB::insert('projects',['client_id'=>$client,'name'=>'Preserved legacy project',
   'created_at'=>gmdate('Y-m-d H:i:s')]);
 $demand=DB::insert('demands',['client_id'=>$client,'project_id'=>$project,'category_id'=>1,'stage_id'=>1,
   'title'=>'Original video editing task','brief'=>'test','details_json'=>'{}','due_at'=>gmdate('Y-m-d H:i:s'),
   'assigned_to'=>$staff,'created_by'=>$owner,'created_at'=>gmdate('Y-m-d H:i:s'),'updated_at'=>gmdate('Y-m-d H:i:s')]);
 $invoice=DB::insert('invoices',['client_id'=>$client,'demand_id'=>$demand,'description'=>'Preserved invoice',
   'amount_cents'=>12000,'due_on'=>gmdate('Y-m-d'),'created_at'=>gmdate('Y-m-d H:i:s')]);
 DB::run('INSERT INTO user_clients (user_id,client_id,can_approve,can_finance) VALUES (?,?,1,0)',[$customer,$client]);
 $file=DB::insert('files',['demand_id'=>$demand,'author_id'=>$staff,'label'=>'Uploaded work',
   'created_at'=>gmdate('Y-m-d H:i:s')]);
 $result=WorkspaceFoundation::apply();
 testws($result['version']===1&&$result['tables_scoped']===count(WorkspaceFoundation::DATA_TABLES),
  'Workspace foundation was not applied.');
 $ws=(int)$result['workspace_id'];
 testws(WorkspaceFoundationGuard::valid(),'Staged foundation is not valid.');
 testws(!WorkspaceFoundation::issues(true),'Workspace issues detected.');
 $owners=DB::one('SELECT role FROM workspace_members WHERE workspace_id=? AND user_id=?',[$ws,$owner]);
 testws(($owners['role']??'')==='owner','Owner was not attached to own workspace.');
 testws((int)(DB::one('SELECT COUNT(*) n FROM workspace_members WHERE workspace_id=?',[$ws])['n']??0)===3,
  'Not all existing users were backfilled.');
 foreach(['clients'=>$client,'projects'=>$project,'demands'=>$demand,'invoices'=>$invoice,'files'=>$file] as $table=>$id)
  testws((int)(DB::one('SELECT workspace_id FROM `'.$table.'` WHERE id=?',[$id])['workspace_id']??0)===$ws,
   'Historical row was not associated with the original workspace: '.$table);
 testws(!DB::one('SELECT id FROM users WHERE LOWER(email)=?',[WorkspaceFoundation::MASTER_EMAIL]),
  'Preflight fixture unexpectedly has a master login.');
 testws((string)(DB::one('SELECT status FROM platform_master_identities WHERE email=?',
  [WorkspaceFoundation::MASTER_EMAIL])['status']??'')==='pending_verification',
  'Master identity must NOT be automatically activated.');
 testws(!DB::one("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND
    TABLE_NAME IN ('calendars','events') AND COLUMN_NAME='workspace_id'"),
  'Exclusive master calendar must stay outside the workspace data backfill.');
 testws((int)(DB::one('SELECT workspace_id FROM clients WHERE id=?',[$client])['workspace_id']??0)===$ws,
  'Original client not preserved.');
 testws((int)(DB::one('SELECT COUNT(*) n FROM demands')['n']??0)===1,'Original demand removed.');
 testws((int)(DB::one('SELECT COUNT(*) n FROM invoices')['n']??0)===1,'Original invoice removed.');
 testws((int)(DB::one('SELECT COUNT(*) n FROM files')['n']??0)===1,'Original attachment removed.');
 testws(WorkspaceFoundation::apply()['already_applied']===true,'Migration not idempotent.');
 // An orphan/unauthorized login (including a failed smoke fixture) must NOT
 // block future deploys and must never gain workspace access.
 $orphan=DB::insert('users',['name'=>'Unlinked fixture','email'=>'unlinked@ci.invalid',
  'password_hash'=>'test','role'=>'staff','active'=>1,'created_at'=>now()]);
 testws(WorkspaceFoundation::apply()['already_applied']===true,
  'A user without membership must not block replay of a completed schema migration.');
 refuses(fn()=>WorkspaceContext::forUser(DB::one('SELECT id,email,role,active FROM users WHERE id=?',[$orphan])),
  'An unlinked user must not be allowed to log in.');

 // B1: resolve the correct workspace from membership, never from caller input.
 $ownerIdentity=DB::one('SELECT id,email,role,active FROM users WHERE id=?',[$owner]);
 $ctx=WorkspaceContext::forUser($ownerIdentity);
 testws($ctx['id']===$ws&&$ctx['role']==='owner','Owner workspace context incorrect.');
 $_SESSION=['user_id'=>$owner];
 unset($_SERVER['HTTP_AUTHORIZATION'],$_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
 Auth::$user=null;Auth::$actor=null;Auth::$workspace=null;Auth::$origin='web';
 testws(Auth::workspaceId()===$ws,'Authenticated session did not resolve workspace.');
 testws((int)WorkspaceCatalog::requireRecord('clients',$client)['id']===$client,
  'Workspace catalog denied its original client.');
 testws((int)WorkspaceCatalog::requireRecord('projects',$project)['id']===$project,
  'Workspace catalog denied its original project.');
 testws(count(Catalog::clients(['q'=>'Preserved legacy client']))===1,
  'Workspace client list lost historical client.');
 $ciClient=DB::insert('clients',['name'=>'Different owner fixture',
  'workspace_id'=>$ws+991,'created_at'=>now()]);
 refuses(fn()=>WorkspaceCatalog::requireRecord('clients',$ciClient),
  'Client from another workspace must not be readable.');
 testws(count(Catalog::clients(['q'=>'Different owner fixture']))===0,
  'Client list exposed another workspace record.');
 DB::run('DELETE FROM clients WHERE id=?',[$ciClient]);
 WorkspacePreferences::save('brand_name','TaskEsc Workspace CI');
 testws(WorkspacePreferences::get('brand_name')==='TaskEsc Workspace CI',
  'Workspace preference not persisted.');
 testws((string)(DB::one("SELECT value FROM settings WHERE `key`='brand_name'")['value']??'')==='TaskEsc Workspace CI',
  'Legacy setting not mirrored during staged deployment.');

 testws((int)Auth::client($client)['id']===$client,'Existing client no longer accessible to owner.');
 [$clause,$arguments]=Auth::clientFilter('c.id');
 testws(str_contains($clause,'workspace_id')&&$arguments===[$ws],'Admin client filter must be workspace scoped.');
 [$clause,$arguments]=Auth::demandFilter('d');
 testws(str_contains($clause,'workspace_id')&&$arguments===[$ws],'Admin task filter must be workspace scoped.');
 testws(count(Demands::list(['include_closed'=>1]))===1,
  'Original task must remain visible in the original workspace.');

 refuses(fn()=>WorkspaceContext::syncUser($staff,$ws,'staff',true),'Out-of-transaction membership must fail.');
 $fresh=DB::tx(function()use($ws):int {
  $id=DB::insert('users',['name'=>'New staff','email'=>'new-staff@ci.invalid',
   'password_hash'=>'test','role'=>'staff','active'=>1,'created_at'=>now()]);
  WorkspaceContext::syncUser($id,$ws,'staff',true);
  return $id;
 });
 testws(WorkspaceContext::forUser(DB::one('SELECT id,email,role,active FROM users WHERE id=?',[$fresh]))['id']===$ws,
  'New user workspace binding is missing.');
 DB::run('UPDATE workspace_members SET status=? WHERE user_id=? AND workspace_id=?',['inactive',$fresh,$ws]);
 refuses(fn()=>WorkspaceContext::forUser(DB::one('SELECT id,email,role,active FROM users WHERE id=?',[$fresh])),
  'Revoked member must not resolve a workspace.');
 DB::tx(fn()=>WorkspaceContext::syncUser($fresh,$ws,'staff',true));
 testws((string)DB::one('SELECT status FROM workspace_members WHERE user_id=? AND workspace_id=?',
  [$fresh,$ws])['status']==='inactive','Editing an active user must not reactivate revocation.');
 DB::tx(fn()=>WorkspaceContext::syncUser($fresh,$ws,'staff',true,true));
 testws((string)DB::one('SELECT status FROM workspace_members WHERE user_id=? AND workspace_id=?',
  [$fresh,$ws])['status']==='active','Explicitly reactivating a disabled user should restore access.');


 // B9: Master may authenticate only after explicit verification; no tenant
 // data is available without an active, audited support session.
 Auth::$user=null;Auth::$actor=null;Auth::$workspace=null;Auth::$previewClientId=null;Auth::$origin='web';
 $_SESSION=['user_id'=>$owner];
 $invitation=MasterSupport::invite();
 testws($invitation['email']===WorkspaceFoundation::MASTER_EMAIL,'Master invite recipient differs from owner-approved email.');
 $queued=DB::one("SELECT body FROM mail_queue WHERE recipient=? ORDER BY id DESC LIMIT 1",[WorkspaceFoundation::MASTER_EMAIL]);
 testws((bool)$queued&&preg_match('/master\\.php\\?activate=([a-f0-9]{64})/',(string)$queued['body'],$match)===1,
   'Master invitation must contain a one-time activation token.');
 refuses(fn()=>MasterSupport::activate(['token'=>str_repeat('0',64),
   'password'=>'Strong CI Master Password 2026','confirm_password'=>'Strong CI Master Password 2026']),
   'Invalid activation token must be rejected.');
 $activated=MasterSupport::activate(['token'=>$match[1],
   'password'=>'Strong CI Master Password 2026','confirm_password'=>'Strong CI Master Password 2026']);
 testws(($activated['activated']??false)===true,'Master activation did not verify the invite.');
 refuses(fn()=>MasterSupport::activate(['token'=>$match[1],
   'password'=>'Strong CI Master Password 2026','confirm_password'=>'Strong CI Master Password 2026']),
   'Invitation token must not be reusable.');
 $master=(int)(DB::one('SELECT id FROM users WHERE email=? AND active=1',
    [WorkspaceFoundation::MASTER_EMAIL])['id']??0);
 testws($master>0&&!DB::one('SELECT 1 FROM workspace_members WHERE user_id=?',[$master]),
   'Verified Master must not be a tenant member.');
 $_SESSION=['user_id'=>$master];Auth::$user=null;Auth::$actor=null;Auth::$workspace=null;Auth::$previewClientId=null;Auth::$origin='web';
 testws(Auth::load()['role']==='master','Verified Master should start in platform-only mode.');
 refuses(fn()=>Auth::workspaceId(),'Master must not inherit owner workspace.');
 testws(MasterSupport::workspaceList()['workspaces'][0]['id']===$ws,
   'Master should see workspace metadata, not inherit its contents.');
 $support=DB::insert('workspace_support_sessions',['workspace_id'=>$ws,
   'master_user_id'=>$master,'reason'=>'Temporary CI support session',
   'started_at'=>now(),'expires_at'=>gmdate('Y-m-d H:i:s',time()+7200)]);
 $_SESSION['workspace_support_id']=$support;
 Auth::$user=null;Auth::$actor=null;Auth::$workspace=null;
 testws(Auth::load()['role']==='admin'&&(int)Auth::$user['id']===$owner
   &&(int)Auth::$actor['id']===$master&&MasterSupport::isSupport(),
   'Master must be attributed separately from selected workspace owner.');
 DB::run('UPDATE workspace_support_sessions SET expires_at=? WHERE id=?',
   [gmdate('Y-m-d H:i:s',time()-60),$support]);
 Auth::$user=null;Auth::$actor=null;Auth::$workspace=null;
 testws(Auth::load()['role']==='master'&&!MasterSupport::isSupport(),
   'Expired support session must immediately revoke tenant context.');
 $_SESSION=['user_id'=>$owner];Auth::$user=null;Auth::$actor=null;Auth::$workspace=null;Auth::$origin='web';
 $newClient=DB::insert('clients',['name'=>'Legacy user interface keeps default',
  'created_at'=>gmdate('Y-m-d H:i:s')]);
 testws((int)(DB::one('SELECT workspace_id FROM clients WHERE id=?',[$newClient])['workspace_id']??0)===$ws,
  'Legacy INSERT failed to assign staged workspace.');
 $extra=DB::insert('workspaces',['slug'=>'other-company','name'=>'Forbidden second workspace',
  'owner_user_id'=>$owner,'status'=>'staged','created_at'=>gmdate('Y-m-d H:i:s'),
  'updated_at'=>gmdate('Y-m-d H:i:s')]);
 testws(!WorkspaceFoundationGuard::valid(),'Second workspace must disable global controllers.');
 refuses(fn()=>WorkspaceFoundation::apply(),'Migration must reject an extra workspace.');
 DB::run('DELETE FROM workspaces WHERE id=?',[$extra]);
 testws(WorkspaceFoundationGuard::valid(),'Original single workspace not restored after test.');
 DB::run("INSERT INTO settings (`key`,value) VALUES ('saas_enabled','1')");
 testws(!WorkspaceFoundationGuard::valid(),'Old SaaS flag must be rejected.');
 DB::run("DELETE FROM settings WHERE `key`='saas_enabled'");
 testws(WorkspaceFoundationGuard::valid(),'Verification failed after removing forbidden flag.');
 // Lightweight access-plan DDL compatibility on the existing disposable CI database.
 $ddl=file_get_contents(ROOT.'/database/migrations/031_platform_access_plans.sql');
 testws($ddl!==false,'Access-plan migration missing.');
 foreach(preg_split('/;\\s*(?:\\r?\\n|$)/',$ddl) as $sql)
  if(trim($sql)!=='')DB::run($sql);
 DB::run("INSERT INTO platform_access_plans (name,code,price_cents,billing_period,features_json,team_limit,public_visible,active,created_at,updated_at)
  VALUES ('Legado ilimitado','legacy-unlimited',0,'monthly',?,NULL,0,1,?,?)",
  ['{"tasks":true,"automations":true,"contacts":true}',now(),now()]);
 $planId=(int)DB::conn()->lastInsertId();
 DB::run('INSERT INTO workspace_access_plans(workspace_id,plan_id,assigned_at) VALUES(?,?,?)',[$ws,$planId,now()]);
 DB::run("INSERT INTO settings (`key`,value) VALUES('platform_access_v1','1')");
 testws(PlatformPlans::workspacePlan($ws)['team_limit']===null,'Legacy plan was not unlimited.');
 testws(PlatformPlans::publicList()===[],'Private legacy plan must not appear publicly.');
 echo "Workspace schema30 CI OK: owner, memberships, ".count(WorkspaceFoundation::DATA_TABLES).
  " mapped tables, preserved client/task/invoice/file, pending Master, calendar excluded, idempotence, second tenant blocked.\n";
}catch(Throwable $e){fwrite(STDERR,'Workspace CI failed: '.$e->getMessage()."\n");exit(1);}
