<?php
declare(strict_types=1);

// Integration test ONLY on the disposable database managed by GitHub Actions.
$port=getenv('OUTBOX_TEST_PORT');
if(!$port||!ctype_digit((string)$port))throw new RuntimeException('Only the disposable OUTBOX_TEST_PORT is accepted.');
define('ROOT',dirname(__DIR__));
$config=[
 'base_url'=>'http://localhost','timezone'=>'America/Fortaleza',
 'db'=>['host'=>'127.0.0.1','port'=>(int)$port,'name'=>'taskesc_test','user'=>'root','password'=>'taskesc-ci-only'],
 'app_key'=>bin2hex(random_bytes(32))
];
if(is_file(ROOT.'/config/config.php'))@unlink(ROOT.'/config/config.php');
file_put_contents(ROOT.'/config/config.php',"<?php\nreturn ".var_export($config,true).";\n");
require ROOT.'/app/bootstrap.php';
function b1check(bool $ok,string $message): void {if(!$ok)throw new RuntimeException($message);}
function b1reject(callable $callback,string $message): void {
 try{$callback();}catch(AppError|RuntimeException|LogicException){return;}
 throw new RuntimeException($message);
}
try{
 $db=DB::conn();
 $db->exec('SET FOREIGN_KEY_CHECKS=0');
 foreach(DB::all('SHOW TABLES') as $row){
  $table=(string)array_values($row)[0];
  if(!preg_match('/\A[A-Za-z0-9_]+\z/D',$table))throw new RuntimeException('Unexpected table name');
  $db->exec('DROP TABLE `'.$table.'`');
 }
 $db->exec('SET FOREIGN_KEY_CHECKS=1');
 $db->exec(file_get_contents(ROOT.'/database/schema.sql'));
 b1check((int)DB::one("SELECT value FROM settings WHERE `key`='schema_version'")['value']===30,'Schema 30 remains unchanged in foundation phase.');
 b1check(SaasFoundationMigration::issues()===[],'Fresh install must include structurally valid account tables.');

 $admin=DB::insert('users',['name'=>'Admin existente','email'=>'legacy-owner@ci.invalid','password_hash'=>'placeholder','role'=>'admin','created_at'=>now()]);
 $staff=DB::insert('users',['name'=>'Membro existente','email'=>'legacy-staff@ci.invalid','password_hash'=>'placeholder','role'=>'staff','created_at'=>now()]);
 $inactive=DB::insert('users',['name'=>'Inativo existente','email'=>'legacy-inactive@ci.invalid','password_hash'=>'placeholder','role'=>'client','active'=>0,'created_at'=>now()]);
 $client=DB::insert('clients',['name'=>'Cliente real já cadastrado','created_at'=>now()]);
 $initialUsers=(int)DB::one('SELECT COUNT(*) n FROM users')['n'];
 $initialClients=(int)DB::one('SELECT COUNT(*) n FROM clients')['n'];

 // Simulates the actual schema 30 deployment (no SaaS B1 tables installed yet).
 $db->exec('DROP TABLE account_settings');
 $db->exec('DROP TABLE account_members');
 $db->exec('DROP TABLE accounts');
 b1check(count(SaasFoundationMigration::issues())===3,'Migration must detect the three absent B1 tables.');
 $db->beginTransaction();
 b1reject(fn()=>SaasFoundationMigration::apply(),'Implicit DDL commit during business transaction must be rejected.');
 b1check($db->inTransaction(),'A rejected migration must preserve the caller transaction.');
 $db->rollBack();
 DB::run("UPDATE settings SET value='20' WHERE `key`='schema_version'");
 b1reject(fn()=>SaasFoundationMigration::apply(),'Removed SaaS schema 20 must never be accepted.');
 b1check((int)DB::one("SELECT COUNT(*) n FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='accounts'")['n']===0,'Refused schema 20 must not create any B1 tables.');
 DB::run("UPDATE settings SET value='30' WHERE `key`='schema_version'");

 $res=SaasFoundationMigration::apply();
 b1check($res['foundation_version']===1&&$res['multi_account_enabled']===false,'Foundation must start with multi-account access disabled.');
 $account=(int)$res['account_id'];
 b1check($account>0&&SaasFoundationMigration::issues()===[],'Migration must create valid account tables.');
 b1check((int)DB::one('SELECT COUNT(*) n FROM accounts')['n']===1,'Only one legacy internal account may exist.');
 b1check((int)DB::one('SELECT COUNT(*) n FROM users')['n']===$initialUsers&&(int)DB::one('SELECT COUNT(*) n FROM clients')['n']===$initialClients,'Migration must preserve all existing records.');
 b1check((int)DB::one('SELECT id FROM clients WHERE name=?',['Cliente real já cadastrado'])['id']===$client,'Client PK must remain unchanged.');
 b1check(AccountContext::legacyForUser($admin)['membership_role']==='owner','Legacy admin must own internal account.');
 b1check(AccountContext::legacyForUser($staff)['membership_role']==='member','Legacy staff must be a member.');
 b1reject(fn()=>AccountContext::legacyForUser($inactive),'Inactive user must be denied.');
 b1check((string)DB::one("SELECT value FROM settings WHERE `key`='schema_version'")['value']==='30','Original schema version must remain 30.');
 b1check((string)DB::one("SELECT value FROM account_settings WHERE account_id=? AND `key`='saas.external_signup_enabled'",[$account])['value']==='0','Customer registration must be disabled.');
 b1check((string)DB::one("SELECT value FROM account_settings WHERE account_id=? AND `key`='saas.multi_account_enabled'",[$account])['value']==='0','New tenants must not be exposed.');
 $again=SaasFoundationMigration::apply();
 b1check($again['account_id']===$account&&(int)DB::one('SELECT COUNT(*) n FROM account_members')['n']===3,'Repeat migration must not duplicate account or members.');

 // Cross-account access must always be explicitly authorized.
 $other=DB::insert('accounts',['slug'=>'ci-other-account','name'=>'Outra empresa de teste','account_type'=>'customer','status'=>'active','created_at'=>now(),'updated_at'=>now()]);
 b1reject(fn()=>AccountContext::forUser($admin,$other),'Original administrator cannot access a different account by guessing an id.');
 DB::insert('account_members',['account_id'=>$other,'user_id'=>$staff,'membership_role'=>'member','status'=>'active','joined_at'=>now()]);
 b1check(AccountContext::forUser($staff,$other)['account_id']===$other,'Explicit active memberships should resolve.');
 DB::run("UPDATE account_members SET status='inactive' WHERE account_id=? AND user_id=?",[$other,$staff]);
 b1reject(fn()=>AccountContext::forUser($staff,$other),'Inactive memberships must be denied.');
 DB::run("UPDATE accounts SET status='suspended' WHERE id=?",[$account]);
 b1reject(fn()=>AccountContext::forUser($admin,$account),'Suspended accounts must be denied.');
 echo "SaaS B1.1: legacy backfill, idempotence, privacy, disabled signup and retired-schema guard OK\n";
}catch(Throwable $error){
 fwrite(STDERR,$error->getMessage()."\n");exit(1);
}
