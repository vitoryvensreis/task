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
require ROOT.'/app/WorkspaceDatabaseStage1.php';
require ROOT.'/app/WorkspaceActivation.php';
require ROOT.'/app/PlatformTenantProvisioning.php';
require ROOT.'/app/MasterSupport.php';
require ROOT.'/app/Notify.php';
require ROOT.'/app/Auth.php';
require ROOT.'/app/UserAvatars.php';
require ROOT.'/app/WorkspaceCatalog.php';
require ROOT.'/app/WorkspacePreferences.php';
require ROOT.'/app/WorkspaceRoles.php';
require ROOT.'/app/WorkspaceTeams.php';
require ROOT.'/app/WorkspaceMemberRemoval.php';
require ROOT.'/app/PlatformPlans.php';
require ROOT.'/app/SupportDesk.php';
require ROOT.'/app/Catalog.php';
require ROOT.'/app/Demands.php';
function fail(string $message,int $status=422,array $details=[]):never {throw new RuntimeException($message,$status);}
function integer(mixed $v,int $min=0,int $max=1000000000):int {
 if(filter_var($v,FILTER_VALIDATE_INT)===false||(int)$v<$min||(int)$v>$max)
  fail('Invalid test integer',422);
 return (int)$v;
}

function now():string {return gmdate('Y-m-d H:i:s');}
function json(mixed $value):string {return json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}
function audit(string $entity,int $id,string $action,mixed $before=null,mixed $after=null):void {
 $ws=(int)(Auth::$workspace['id']??0);
 DB::insert('audit_log',['workspace_id'=>$ws,'user_id'=>(int)(Auth::$user['id']??0)?:null,
  'entity'=>$entity,'entity_id'=>$id,'action'=>$action,
  'before_json'=>$before===null?null:json($before),'after_json'=>$after===null?null:json($after),
  'origin'=>'web','created_at'=>now()]);
}
function setting(string $key,string $default=''):string {
 return (string)(DB::one('SELECT value FROM settings WHERE `key`=?',[$key])['value']??$default);
}
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

 // Staff client scope: enforce in SQL and record reads, never grant finance.
 $restrictedClient=DB::insert('clients',['name'=>'Staff restricted fixture','workspace_id'=>$ws,'created_at'=>now()]);
 DB::insert('user_clients',['user_id'=>$staff,'client_id'=>$client,'workspace_id'=>$ws,'can_approve'=>1,'can_finance'=>0]);
 $_SESSION=['user_id'=>$staff];Auth::$user=null;Auth::$actor=null;Auth::$workspace=null;Auth::$origin='web';
 testws(Auth::load()['role']==='staff','Test user did not become staff.');
 testws(!Auth::staffAllClients(),'Staff must default to client-scoped visibility.');
 testws((int)Auth::client($client)['id']===$client,'Staff client link was not honored.');
 refuses(fn()=>Auth::client($restrictedClient),'Scoped staff must not see another client.');
 DB::run('UPDATE users SET staff_all_clients=1 WHERE id=?',[$staff]);
 testws(Auth::staffAllClients(),'Staff global task scope did not activate.');
 testws((int)Auth::client($restrictedClient)['id']===$restrictedClient,'Global staff must see active workspace clients.');
 [$taskScope,$taskArgs]=Auth::demandFilter('d');
 testws(!str_contains($taskScope,'user_clients')&&$taskArgs===[$ws],'Global task filter must stay workspace-scoped.');
 [$financeScope,$financeArgs]=Auth::clientFilter('client_id',true);
 testws(str_contains($financeScope,'can_finance=1')&&count($financeArgs)===2,'Global tasks must not grant financial access.');
 DB::run('UPDATE users SET staff_all_clients=0 WHERE id=?',[$staff]);
 DB::run('DELETE FROM user_clients WHERE user_id=? AND client_id=?',[$staff,$client]);
 $_SESSION=['user_id'=>$owner];Auth::$user=null;Auth::$actor=null;Auth::$workspace=null;Auth::$origin='web';
 DB::run('DELETE FROM clients WHERE id=?',[$restrictedClient]);

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

 // Member deletion is a transactional access revocation, not destruction of history.
 WorkspaceTeams::migrate();
 $removable=DB::tx(function()use($ws):int {
  $id=DB::insert('users',['name'=>'Removed staff fixture','email'=>'removed-staff-fixture@ci.invalid',
   'whatsapp_phone'=>'5585911111111','password_hash'=>'test','role'=>'staff','active'=>1,'created_at'=>now()]);
  WorkspaceContext::syncUser($id,$ws,'staff',true);
  return $id;
 });
 $savedComment=DB::insert('comments',['workspace_id'=>$ws,'demand_id'=>$demand,'author_id'=>$removable,
  'body'=>'Historic message must survive member removal','internal'=>0,'created_at'=>now()]);
 DB::run('UPDATE demands SET assigned_to=? WHERE id=? AND workspace_id=?',[$removable,$demand,$ws]);
 DB::run('UPDATE categories SET default_assigned_to=? WHERE id=? AND workspace_id=?',[$removable,1,$ws]);
 $preview=WorkspaceMemberRemoval::preview(['id'=>$removable]);
 testws($preview['can_delete']===true&&(int)$preview['impact']['tasks']===1,
  'Member removal preview must report the transferred task.');
 refuses(fn()=>WorkspaceMemberRemoval::delete(['id'=>$removable]),'A confirmation word is mandatory.');
 refuses(fn()=>WorkspaceMemberRemoval::delete(['id'=>$owner,'confirmation'=>'EXCLUIR']),
  'Workspace owner must never be removable.');
 $removed=WorkspaceMemberRemoval::delete(['id'=>$removable,'confirmation'=>'EXCLUIR']);
 testws($removed['deleted']===true&&(int)$removed['transferred_to']===$owner,'Member removal response is incorrect.');
 testws((int)(DB::one('SELECT assigned_to FROM demands WHERE id=?',[$demand])['assigned_to']??0)===$owner,
  'Assigned task was not transferred to workspace owner.');
 testws((int)(DB::one('SELECT default_assigned_to FROM categories WHERE id=1')['default_assigned_to']??0)===$owner,
  'Default assignee was not transferred to workspace owner.');
 testws(!DB::one('SELECT 1 FROM workspace_members WHERE user_id=?',[$removable]),
  'Deleted staff must no longer be a workspace member.');
 testws((int)(DB::one('SELECT active FROM users WHERE id=?',[$removable])['active']??1)===0,
  'Deleted staff login must be disabled.');
 testws((int)(DB::one('SELECT author_id FROM comments WHERE id=?',[$savedComment])['author_id']??0)===$removable,
  'Member removal must not falsify historic comment authorship.');
 refuses(fn()=>WorkspaceContext::forUser(DB::one('SELECT id,email,role,active FROM users WHERE id=?',[$removable])),
  'Removed staff must not be allowed back into workspace.');



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
 // Support messaging is tenant-user isolated. Only essential DDL/scope checks.
 $supportDdl=file_get_contents(ROOT.'/database/migrations/032_support_desk.sql');
 testws($supportDdl!==false,'Support migration missing.');
 foreach(preg_split('/;\\s*(?:\\r?\\n|$)/',$supportDdl) as $statement)
  if(trim($statement)!=='')DB::run($statement);
 DB::run("INSERT INTO workspace_support_messages
  (workspace_id,tenant_user_id,sender_user_id,sender_role,client_nonce,body,created_at)
  VALUES (?,?,?,?,?,?,?)",[$ws,$owner,$owner,'tenant',str_repeat('a',32),'Help CI',now()]);
 $supportThreads=SupportDesk::threads();
 testws(count($supportThreads)===1&&(int)$supportThreads[0]['tenant_user_id']===$owner,
  'Tenant chat exposed another admin.');
 $supportMessages=SupportDesk::messages(['tenant_user_id'=>$staff,'workspace_id'=>999]);
 testws(count($supportMessages['messages'])===1,
  'Tenant support message scoping rejected its own thread.');
 // B10 custom roles: only the scoped staff member receives the selected abilities.
 $rolesDdl=file_get_contents(ROOT.'/database/migrations/033_workspace_custom_roles.sql');
 testws($rolesDdl!==false,'Custom roles migration missing.');
 foreach(preg_split('/;\\s*(?:\\r?\\n|$)/',$rolesDdl) as $statement)
  if(trim($statement)!=='')DB::run($statement);
 DB::run('ALTER TABLE workspace_members ADD COLUMN custom_role_id BIGINT UNSIGNED NULL');
 $roleId=DB::insert('workspace_role_definitions',[
  'workspace_id'=>$ws,'name'=>'Editor de teste','description'=>'Equipe limitada',
  'abilities_json'=>'["dashboard.view","tasks.read","tasks.comment"]',
  'active'=>1,'created_by'=>$owner,'created_at'=>now(),'updated_at'=>now()
 ]);
 DB::run('UPDATE workspace_members SET custom_role_id=? WHERE workspace_id=? AND user_id=?',[$roleId,$ws,$staff]);
 $staffCaps=WorkspaceRoles::effectiveStaffCapabilities($ws,$staff);
 testws($staffCaps===['dashboard.view','tasks.read','tasks.comment'],
  'Custom role capabilities mismatch.');
 testws(!in_array('tasks.delete',$staffCaps,true),'Staff role granted forbidden delete permission.');
 // Reject the pending owner of a future company from opening the original company.
 $pendingOwner=DB::insert('users',['name'=>'Future workspace owner CI',
  'email'=>PlatformTenantProvisioning::OWNER_EMAIL,
  'password_hash'=>'test','role'=>'admin','active'=>1,'created_at'=>now()]);
 DB::insert('workspace_members',['workspace_id'=>$ws,'user_id'=>$pendingOwner,
  'role'=>'admin','status'=>'active','joined_at'=>now()]);
 refuses(fn()=>WorkspaceContext::forUser(DB::one(
   'SELECT id,name,email,role,active FROM users WHERE id=?',[$pendingOwner])),
   'Pending workspace owner must not inherit the original company.');
 // Two-workspace cutover: this is a disposable database, never production.
 $gabId=(int)$pendingOwner;
 testws($gabId>0,'Pending Gabriella candidate missing.');
 $ptp=file_get_contents(ROOT.'/database/migrations/036_platform_tenant_provisioning.sql');
 testws($ptp!==false,'Provisioning DDL missing.');
 foreach(preg_split('/;\\s*(?:\\r?\\n|$)/',$ptp) as $q)
   if(trim($q)!=='')DB::run($q);
 DB::run('INSERT INTO platform_tenant_provisioning
   (source_workspace_id,prospective_owner_id,proposed_name,proposed_slug,
     initial_config_json,status,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?)',
   [$ws,$gabId,PlatformTenantProvisioning::NAME,PlatformTenantProvisioning::SLUG,
    '{}','pending',now(),now()]);
 testws(WorkspaceDatabaseStage1::apply()['stage']===1,'Stage 1 migration failed.');
 // The production cutover is a fresh CLI invocation with no web actor.
 Auth::$user=null;Auth::$actor=null;Auth::$workspace=null;
 testws(WorkspaceJobScope::currentId()===0,'Test cutover must not inherit a workspace job.');
 $cut=WorkspaceActivation::apply();
 $newWs=(int)$cut['workspace_id'];
 testws($newWs!==$ws&&WorkspaceTenantMode::enabled(),'Multitenant activation failed.');
 testws(WorkspaceFoundationGuard::valid(),'Multiworkspace foundation check failed.');
 testws(WorkspaceActivation::apply()['already_active']===true,'Cutover must be idempotent.');
 testws(WorkspaceDatabaseStage1::apply()['multiworkspace_active']===true,
   'Subsequent deployments must accept two workspaces.');
 $moved=DB::one('SELECT role,workspace_id FROM workspace_members WHERE user_id=?',[$gabId]);
 testws($moved['role']==='owner'&&(int)$moved['workspace_id']===$newWs,
   'Gabriella must belong only to her own workspace.');
 foreach(['clients','demands','invoices','files','projects','payments'] as $table)
  testws((int)(DB::one('SELECT COUNT(*) n FROM `'.$table.'` WHERE workspace_id=?',[$newWs])['n']??0)===0,
   'New workspace must start with no historical business data: '.$table);
 testws((int)(DB::one('SELECT COUNT(*) n FROM stages WHERE workspace_id=?',[$newWs])['n']??0)>0,
   'Gabriella needs initial Kanban workflow definitions.');
 testws((int)(DB::one('SELECT COUNT(*) n FROM categories WHERE workspace_id=?',[$newWs])['n']??0)>0,
   'Gabriella needs initial service category definitions.');
 $gabUser=DB::one('SELECT id,name,email,role,active FROM users WHERE id=?',[$gabId]);
 $ctx=WorkspaceContext::forUser($gabUser);
 testws((int)$ctx['id']===$newWs,'Gabriella login must resolve only her workspace.');
 Auth::$user=$gabUser;Auth::$actor=$gabUser;Auth::$workspace=$ctx;
 refuses(fn()=>Auth::client($client),'Gabriella must not access Vitor client.');
 refuses(fn()=>Auth::demand($demand),'Gabriella must not access Vitor demand.');
 Auth::$user=null;Auth::$actor=null;Auth::$workspace=null;
 testws((int)(DB::one('SELECT workspace_id FROM clients WHERE id=?',[$client])['workspace_id']??0)===$ws,
  'Vitor historical clients cannot move during cutover.');
 testws((int)(DB::one('SELECT workspace_id FROM invoices WHERE id=?',[$invoice])['workspace_id']??0)===$ws,
  'Vitor historical finance cannot move during cutover.');

 // Regression: deleting a file must be workspace-scoped on comments, reviews
 // and files; otherwise the tenant SQL guard throws an internal error.
 require_once ROOT.'/app/Files.php';
 require_once ROOT.'/app/DemandRealtime.php';
 require_once ROOT.'/app/EventOutbox.php';
 $ownerSession=DB::one('SELECT id,name,email,role,active FROM users WHERE id=?',[$owner]);
 Auth::$user=$ownerSession;Auth::$actor=$ownerSession;
 Auth::$workspace=WorkspaceContext::forUser($ownerSession);Auth::$origin='web';
 $deletionFile=DB::insert('files',[
  'workspace_id'=>$ws,'demand_id'=>$demand,'author_id'=>$owner,
  'label'=>'file removal regression','original_name'=>'example.txt',
  'storage_provider'=>'url','url'=>'https://example.invalid/example.txt',
  'shared'=>0,'version_no'=>987,'created_at'=>now()
 ]);
 $deletionComment=DB::insert('comments',[
  'workspace_id'=>$ws,'demand_id'=>$demand,'author_id'=>$owner,
  'body'=>'historical review reference','internal'=>1,
  'version_id'=>$deletionFile,'created_at'=>now()
 ]);
 $deletionReview=DB::insert('reviews',[
  'workspace_id'=>$ws,'file_id'=>$deletionFile,'user_id'=>$owner,
  'decision'=>'approved','created_at'=>now()
 ]);
 $deletedFileResult=Files::delete(['file_id'=>$deletionFile]);
 testws(($deletedFileResult['deleted']??false)===true,'File delete should complete in tenant isolation mode.');
 testws(!DB::one('SELECT id FROM files WHERE id=?',[$deletionFile]),'File record was not removed.');
 testws(!DB::one('SELECT id FROM reviews WHERE id=?',[$deletionReview]),'File review was not cleaned up.');
 testws(DB::one('SELECT version_id FROM comments WHERE id=?',[$deletionComment])['version_id']===null,
  'Historic comment must survive with detached version.');
 testws((int)(DB::one('SELECT COUNT(*) n FROM event_outbox WHERE workspace_id=? AND entity_id=? AND event_type=?',
  [$ws,$demand,'demand.file_deleted'])['n']??0)>0,'File removal must enqueue a realtime event.');
 Auth::$user=null;Auth::$actor=null;Auth::$workspace=null;

 // Automation fanout must NEVER leak a trigger from one company to the other.
 require_once ROOT.'/app/Automation.php';
 require_once ROOT.'/app/AutomationWorker.php';
 require_once ROOT.'/app/EventOutbox.php';
 if(!function_exists('json')){function json(mixed $value):string{return json_encode($value,JSON_THROW_ON_ERROR);}}
 DB::run("CREATE TABLE IF NOT EXISTS automation_workspace_cursors (
  workspace_id BIGINT UNSIGNED PRIMARY KEY,last_outbox_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  heartbeat_at DATETIME(6) NULL,updated_at DATETIME(6) NOT NULL)");
 $rule=json(['version'=>1,'nodes'=>[
  ['id'=>'trigger','type'=>'trigger','config'=>['event'=>'demand.created']],
  ['id'=>'end','type'=>'end','config'=>[]]],
  'edges'=>[['from'=>'trigger','to'=>'end','branch'=>'next']]]);
 $storeRule=static function(int $workId,int $creator)use($rule):int {
  return WorkspaceJobScope::forWorkspace($workId,static function()use($creator,$rule):int {
   return DB::insert('automations',['name'=>'Scoped rule','trigger_event'=>'demand.created',
    'definition_json'=>$rule,'active'=>1,'version'=>1,'created_by'=>$creator,
    'created_at'=>now(),'updated_at'=>now()]);
  });
 };
 $oldRule=$storeRule($ws,$owner);$newRule=$storeRule($newWs,$gabId);
 foreach([$ws,$newWs] as $workId)
  DB::run('INSERT INTO automation_workspace_cursors
    (workspace_id,last_outbox_id,updated_at) VALUES (?,0,?)',[$workId,now()]);
 $emit=static function(int $workId,int $taskId):void {
  WorkspaceJobScope::forWorkspace($workId,static function()use($workId,$taskId):void {
   EventOutbox::transaction(static function()use($workId,$taskId):void {
    EventOutbox::enqueue('automation-ci-'.$workId.'-'.$taskId,
     'demand.created','demand',$taskId,'staff/ws:'.$workId,
     ['operation_id'=>bin2hex(random_bytes(16))]);
   });
  });
 };
 $emit($ws,$demand);
 $gotOld=WorkspaceJobScope::forWorkspace($ws,static fn()=>AutomationWorker::ingestOutbox(50));
 $gotGabi=WorkspaceJobScope::forWorkspace($newWs,static fn()=>AutomationWorker::ingestOutbox(50));
 testws($gotOld===1&&$gotGabi===0,'Vitor trigger must never create a Gabriella automation.');
 testws((int)(DB::one('SELECT COUNT(*) n FROM automation_runs WHERE workspace_id=?',[$newWs])['n']??0)===0,
  'Gabriella automation queue was contaminated by Vitor.');
 $st=DB::one('SELECT id FROM stages WHERE workspace_id=? ORDER BY position,id LIMIT 1',[$newWs]);
 $ca=DB::one('SELECT id FROM categories WHERE workspace_id=? ORDER BY id LIMIT 1',[$newWs]);
 $gabTask=WorkspaceJobScope::forWorkspace($newWs,static function()use($gabId,$st,$ca):int {
  $cid=DB::insert('clients',['name'=>'Gabriella isolated CI','created_at'=>now()]);
  return DB::insert('demands',['client_id'=>$cid,'category_id'=>(int)$ca['id'],
   'stage_id'=>(int)$st['id'],'title'=>'Gabi private task',
   'brief'=>'isolated fixture','details_json'=>'{}','due_at'=>now(),
   'created_by'=>$gabId,'created_at'=>now(),'updated_at'=>now()]);
 });
 $emit($newWs,$gabTask);
 $gotGabi=WorkspaceJobScope::forWorkspace($newWs,static fn()=>AutomationWorker::ingestOutbox(50));
 $gotOld=WorkspaceJobScope::forWorkspace($ws,static fn()=>AutomationWorker::ingestOutbox(50));
 testws($gotGabi===1&&$gotOld===0,'Gabriella trigger must never execute Vitor rules.');
 testws((int)(DB::one('SELECT COUNT(*) n FROM automation_runs WHERE workspace_id=? AND automation_id=?',[$ws,$newRule])['n']??0)===0,
  'Gabriella rule leaked into Vitor run queue.');
 testws((int)(DB::one('SELECT COUNT(*) n FROM automation_runs WHERE workspace_id=? AND automation_id=?',[$newWs,$oldRule])['n']??0)===0,
  'Vitor rule leaked into Gabriella run queue.');
 // Payment tables are created only after the workspace foundation.
 require_once ROOT.'/app/PlatformAsaasBilling.php';
 $asaasSql=file_get_contents(ROOT.'/database/migrations/041_platform_asaas_billing.sql');
 testws($asaasSql!==false,'Asaas migration missing.');
 foreach(preg_split('/;\\s*(?:\\r?\\n|$)/',$asaasSql) as $statement)
  if(trim($statement)!=='')DB::run($statement);
 testws(PlatformAsaasBilling::ready(),'Asaas billing schema missing.');
 testws(!PlatformAsaasBilling::enabled(),'Asaas billing must not activate without credentials and Master authorization.');
 echo "Workspace schema30 CI OK: owner, memberships, ".count(WorkspaceFoundation::DATA_TABLES).
  " mapped tables, preserved client/task/invoice/file, pending Master, calendar excluded, idempotence, second tenant blocked.\n";
}catch(Throwable $e){fwrite(STDERR,'Workspace CI failed: '.$e->getMessage()."\n");exit(1);}
