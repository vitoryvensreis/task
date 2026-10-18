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
require ROOT.'/app/Auth.php';
function fail(string $message,int $status=422,array $details=[]):never {throw new RuntimeException($message,$status);}

function now():string {return gmdate('Y-m-d H:i:s');}
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
 // B1: resolve the correct workspace from membership, never from caller input.
 $ownerIdentity=DB::one('SELECT id,email,role,active FROM users WHERE id=?',[$owner]);
 $ctx=WorkspaceContext::forUser($ownerIdentity);
 testws($ctx['id']===$ws&&$ctx['role']==='owner','Owner workspace context incorrect.');
 $_SESSION=['user_id'=>$owner];
 unset($_SERVER['HTTP_AUTHORIZATION'],$_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
 Auth::$user=null;Auth::$actor=null;Auth::$workspace=null;Auth::$origin='web';
 testws(Auth::workspaceId()===$ws,'Authenticated session did not resolve workspace.');
 testws((int)Auth::client($client)['id']===$client,'Existing client no longer accessible to owner.');
 [$clause,$arguments]=Auth::clientFilter('c.id');
 testws(str_contains($clause,'workspace_id')&&$arguments===[$ws],'Admin client filter must be workspace scoped.');
 [$clause,$arguments]=Auth::demandFilter('d');
 testws(str_contains($clause,'workspace_id')&&$arguments===[$ws],'Admin task filter must be workspace scoped.');
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
 echo "Workspace schema30 CI OK: owner, memberships, ".count(WorkspaceFoundation::DATA_TABLES).
  " mapped tables, preserved client/task/invoice/file, pending Master, calendar excluded, idempotence, second tenant blocked.\n";
}catch(Throwable $e){fwrite(STDERR,'Workspace CI failed: '.$e->getMessage()."\n");exit(1);}
