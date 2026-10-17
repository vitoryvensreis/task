<?php
declare(strict_types=1);
$port=getenv('OUTBOX_TEST_PORT');
if(!$port||!ctype_digit((string)$port))throw new RuntimeException('Only the disposable CI database may run these tests.');
define('ROOT',dirname(__DIR__));
@unlink(ROOT.'/config/config.php');
$config=[
 'base_url'=>'http://localhost','timezone'=>'America/Fortaleza',
 'db'=>['host'=>'127.0.0.1','port'=>(int)$port,'name'=>'taskesc_test','user'=>'root','password'=>'taskesc-ci-only'],
 'app_key'=>bin2hex(random_bytes(32))
];
file_put_contents(ROOT.'/config/config.php',"<?php\nreturn ".var_export($config,true).";\n");
require ROOT.'/app/bootstrap.php';
function b12(bool $ok,string $message): void {if(!$ok)throw new RuntimeException($message);}
function b12Reject(callable $callback,string $message): void {
 try{$callback();}catch(AppError|RuntimeException|LogicException){return;}
 throw new RuntimeException($message);
}
try {
 $db=DB::conn();
 $db->exec('SET FOREIGN_KEY_CHECKS=0');
 foreach(DB::all('SHOW TABLES') as $r){
  $table=(string)array_values($r)[0];
  if(!preg_match('/\A[A-Za-z0-9_]+\z/D',$table))throw new RuntimeException('Unsafe fixture table.');
  $db->exec('DROP TABLE `'.$table.'`');
 }
 $db->exec('SET FOREIGN_KEY_CHECKS=1');
 $db->exec(file_get_contents(ROOT.'/database/schema.sql'));
 b12(SaasDomainMigration::issues()===[],'Fresh schema root ownership columns/indexes must be complete.');
 b12(count(SaasDomainMigration::ROOT_TABLES)===15,'Expected 15 audited root domains.');

 $admin=DB::insert('users',['name'=>'Owner','email'=>'b12-owner@ci.invalid','role'=>'admin','password_hash'=>'fake','active'=>1,'created_at'=>now()]);
 $otherUser=DB::insert('users',['name'=>'Other User','email'=>'b12-guest@ci.invalid','role'=>'staff','password_hash'=>'fake','active'=>1,'created_at'=>now()]);
 $client=DB::insert('clients',['name'=>'Legacy client','created_at'=>now()]);
 $project=DB::insert('projects',['client_id'=>$client,'name'=>'Legacy project']);
 $demand=DB::insert('demands',[
  'client_id'=>$client,'project_id'=>$project,'category_id'=>1,'stage_id'=>1,
  'title'=>'Existing task','brief'=>'unchanged','details_json'=>'{}','due_at'=>now(),
  'created_by'=>$admin,'created_at'=>now(),'updated_at'=>now()
 ]);
 $invoice=DB::insert('invoices',['client_id'=>$client,'description'=>'Legacy invoice','amount_cents'=>100,'due_on'=>'2026-12-01','created_at'=>now()]);
 $initialIds=['client'=>$client,'project'=>$project,'demand'=>$demand,'invoice'=>$invoice];
 // Foundation B1.1 creates one internal account, but no SaaS tenants.
 $foundation=SaasFoundationMigration::apply();
 $account=(int)$foundation['account_id'];
 b12((int)(DB::one('SELECT COUNT(*) n FROM accounts')['n'])===1,'There should be a single internal account.');

 // Reproduce a schema-30 database whose B1.2a columns have not been installed.
 foreach(SaasDomainMigration::ROOT_TABLES as $table) {
  $db->exec('ALTER TABLE `'.$table.'` DROP INDEX `idx_saas_'.$table.'`');
  $db->exec('ALTER TABLE `'.$table.'` DROP COLUMN tenant_account_id');
 }
 b12(count(SaasDomainMigration::issues())===15,'Missing legacy ownership columns must be detected.');

 $db->beginTransaction();
 b12Reject(fn()=>SaasDomainMigration::apply(),'DDL must not run inside a caller transaction.');
 b12($db->inTransaction(),'Rejected migration must not commit caller transaction.');
 $db->rollBack();

 // A foreign account must prevent assigning old NULL ownership rows to a guess.
 $forbidden=DB::insert('accounts',['slug'=>'forbidden-before-migration','name'=>'Not allowed yet','account_type'=>'customer','status'=>'active','created_at'=>now(),'updated_at'=>now()]);
 b12Reject(fn()=>SaasDomainMigration::apply(),'Legacy backfill must refuse extra accounts.');
 b12(count(SaasDomainMigration::issues())===15,'Failed multi-account preflight must not mutate schema.');
 DB::run('DELETE FROM accounts WHERE id=?',[$forbidden]);

 $done=SaasDomainMigration::apply();
 b12($done['legacy_account_id']===$account&&$done['domain_version']===2,'Staged B1.2a migration should complete.');
 b12(SaasDomainMigration::issues(true)===[],'All fifteen root tables must be indexed and backfilled.');
 b12((int)(DB::one("SELECT value FROM settings WHERE `key`='schema_version'")['value'])===30,'Operational schema marker remains 30.');
 b12((int)(DB::one('SELECT value FROM settings WHERE `key`=?',[SaasDomainMigration::MARKER])['value'])===2,'Staged marker version 2 expected.');
 b12($done['multi_account_enabled']===false,'Migrating ownership must NOT enable SaaS signup.');
 foreach(['clients'=>$client,'projects'=>$project,'demands'=>$demand,'invoices'=>$invoice,'stages'=>1,'categories'=>1] as $table=>$id){
  b12((int)DB::one('SELECT tenant_account_id FROM `'.$table.'` WHERE id=?',[$id])['tenant_account_id']===$account,'Must preserve ownership and primary key of '.$table);
 }
 b12(SaasDomainMigration::apply()['legacy_account_id']===$account,'Re-run must be idempotent.');
 b12(AccountOwnership::row('demands',$demand,$account,$admin)['title']==='Existing task','Owner must read scoped legacy task.');
 b12(count(AccountOwnership::listing('clients',$account,$admin))===1,'Scoped listing of legacy clients works.');
 AccountOwnership::assertTaskParents($account,$admin,$client,1,1,$project);
 b12Reject(fn()=>AccountOwnership::row('users',$admin,$account,$admin),'Unscoped table must not be allowed.');

 $foreignAccount=DB::insert('accounts',['slug'=>'second-account-fixture','name'=>'Other company','account_type'=>'customer','status'=>'active','created_at'=>now(),'updated_at'=>now()]);
 DB::insert('account_members',['account_id'=>$foreignAccount,'user_id'=>$otherUser,'membership_role'=>'member','status'=>'active','joined_at'=>now()]);
 $foreignClient=DB::insert('clients',['tenant_account_id'=>$foreignAccount,'name'=>'Foreign client','created_at'=>now()]);
 $foreignStage=DB::insert('stages',['tenant_account_id'=>$foreignAccount,'name'=>'Foreign stage','kind'=>'inbox']);
 $foreignCategory=DB::insert('categories',['tenant_account_id'=>$foreignAccount,'name'=>'Foreign service','fields_json'=>'[]']);
 $foreignProject=DB::insert('projects',['tenant_account_id'=>$foreignAccount,'client_id'=>$foreignClient,'name'=>'Foreign project']);
 b12(count(AccountOwnership::listing('clients',$account,$admin))===1,'Legacy list must not include foreign client.');
 b12(count(AccountOwnership::listing('clients',$foreignAccount,$otherUser))===1,'Foreign list must see only own client.');
 b12Reject(fn()=>AccountOwnership::row('clients',$foreignClient,$account,$admin),'Cannot fetch foreign client with own account.');
 b12Reject(fn()=>AccountOwnership::row('clients',$client,$foreignAccount,$otherUser),'Cannot fetch legacy client with another account.');
 b12Reject(fn()=>AccountOwnership::row('clients',$client,$account,$otherUser),'Membership check must run before row lookup.');
 b12Reject(fn()=>AccountOwnership::assertTaskParents($account,$admin,$client,$foreignCategory,1),'Cross-company categories must be denied.');
 b12Reject(fn()=>AccountOwnership::assertTaskParents($account,$admin,$client,1,$foreignStage),'Cross-company stages must be denied.');
 b12Reject(fn()=>AccountOwnership::assertTaskParents($account,$admin,$client,1,1,$foreignProject),'Cross-company projects must be denied.');
 b12Reject(fn()=>AccountOwnership::assertTaskParents($account,$admin,$client,1,1,$project+10000),'Missing projects must be denied.');
 b12Reject(fn()=>SaasDomainMigration::apply(),'Legacy backfill cannot run after new accounts appear.');
 echo "SaaS B1.2a: staged ownership, legacy backfill, explicit account scope, A/B denial and schema preservation OK\n";
}catch(Throwable $error){fwrite(STDERR,$error->getMessage()."\n");exit(1);}
