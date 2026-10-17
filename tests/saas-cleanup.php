<?php
declare(strict_types=1);
$port=getenv('OUTBOX_TEST_PORT');
if(!$port||!ctype_digit((string)$port))throw new RuntimeException('Disposable database required');
$root=dirname(__DIR__);
@unlink($root.'/config/config.php');
file_put_contents($root.'/config/config.php',"<?php\nreturn ".var_export([
 'base_url'=>'http://localhost','timezone'=>'America/Fortaleza',
 'db'=>['host'=>'127.0.0.1','port'=>(int)$port,'name'=>'taskesc_test','user'=>'root','password'=>'taskesc-ci-only'],
 'app_key'=>bin2hex(random_bytes(32))],true).";\n");
require $root.'/app/bootstrap.php';
function verifyClean(bool $ok,string $message):void {if(!$ok)throw new RuntimeException($message);}
function cleanupCLI(array $args):array {
 $cmd=escapeshellarg(PHP_BINARY).' '.escapeshellarg(ROOT.'/bin/cleanup-saas-one-time.php');
 foreach($args as $arg)$cmd.=' '.escapeshellarg($arg);
 $lines=[];$code=0;exec($cmd.' 2>&1',$lines,$code);
 return [$code,implode("\n",$lines)];
}
try {
 $db=DB::conn();$db->exec('SET FOREIGN_KEY_CHECKS=0');
 foreach(DB::all('SHOW TABLES') as $r){
  $table=(string)array_values($r)[0];if(!preg_match('/\A[a-zA-Z0-9_]+\z/D',$table))throw new RuntimeException('Unsafe fixture');
  $db->exec('DROP TABLE `'.$table.'`');
 }
 $db->exec('SET FOREIGN_KEY_CHECKS=1');
 $db->exec(file_get_contents(ROOT.'/database/schema.sql'));
 $user=DB::insert('users',['name'=>'CI Legacy Owner','email'=>'ci-legacy-owner@ci.invalid',
  'password_hash'=>'test','role'=>'admin','created_at'=>now()]);
 $client=DB::insert('clients',['name'=>'Preserved Client','created_at'=>now()]);
 $rootTables=['clients','stages','categories','projects','contracts','demands','invoices',
  'automations','whatsapp_contacts','expense_categories','expense_rules','expenses',
  'templates','google_accounts','calendars'];
 $childTables=['contract_items','cycles','cycle_items','demand_usage','transitions',
  'comments','checklists','time_entries','files','reviews','change_requests',
  'costs','payments','events','automation_runs','automation_run_steps'];
 foreach([...$rootTables,...$childTables] as $table){
  $db->exec('ALTER TABLE `'.$table.'` ADD COLUMN tenant_account_id BIGINT UNSIGNED NULL');
  $db->exec('ALTER TABLE `'.$table.'` ADD INDEX `idx_saas_'.$table.'` (tenant_account_id,id)');
 }
 $db->exec('CREATE TABLE accounts (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,slug VARCHAR(100),account_type VARCHAR(20),status VARCHAR(20)) ENGINE=InnoDB');
 $db->exec('CREATE TABLE account_members (account_id BIGINT UNSIGNED,user_id INT,membership_role VARCHAR(20),status VARCHAR(20)) ENGINE=InnoDB');
 $db->exec('CREATE TABLE account_settings (account_id BIGINT UNSIGNED,`key` VARCHAR(100),value LONGTEXT) ENGINE=InnoDB');
 foreach(['saas_event_outbox','saas_event_receipts','saas_delivery_jobs','saas_channel_secrets','saas_internal_inbox'] as $table)
  $db->exec('CREATE TABLE `'.$table.'` (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,tenant_account_id BIGINT UNSIGNED NOT NULL) ENGINE=InnoDB');
 DB::insert('accounts',['slug'=>'taskesc-existing-operation','account_type'=>'internal','status'=>'active']);
 DB::run('INSERT INTO account_members (account_id,user_id,membership_role,status) VALUES (1,?,?,?)',[$user,'owner','active']);
 DB::run('INSERT INTO account_settings (account_id,`key`,value) VALUES (1,?,?)',['saas.multi_account_enabled','0']);
 $markers=['saas_foundation_version'=>1,'saas_domain_version'=>2,
  'saas_children_version'=>3,'saas_outbox_version'=>4,'saas_receipts_version'=>5,
  'saas_delivery_version'=>6,'saas_channels_version'=>7,'saas_internal_inbox_version'=>8];
 foreach($markers as $k=>$v)DB::run('INSERT INTO settings (`key`,value) VALUES (?,?)',[$k,(string)$v]);
 DB::run('INSERT INTO settings (`key`,value) VALUES (?,?)',['saas.multi_account_enabled','0']);
 [$status,$out]=cleanupCLI(['--check']);
 verifyClean($status===0&&str_contains($out,'"state":"staged_saas"'),'Preflight failed: '.$out);
 DB::insert('accounts',['slug'=>'other-company','account_type'=>'customer','status'=>'active']);
 [$status]=cleanupCLI(['--check']);
 verifyClean($status!==0,'Customer account must block cleanup');
 DB::run("DELETE FROM accounts WHERE slug='other-company'");
 $dir=ROOT.'/storage/backups';if(!is_dir($dir))mkdir($dir,0700,true);
 $name='taskesc-predeploy-ci-'.bin2hex(random_bytes(4)).'.sql.gz';
 $file=$dir.'/'.$name;file_put_contents($file,gzencode('ci-mock-backup',6));
 $manifest=$dir.'/'.substr($name,0,-7).'.txt';
 file_put_contents($manifest,"Arquivo do banco: $name\nSHA256: ".hash_file('sha256',$file)."\nSchema do banco: 30\n");
 [$status,$out]=cleanupCLI(['--apply','--backup-manifest='.$manifest]);
 verifyClean($status===0&&str_contains($out,'"result":"clean"'),'Clean failed: '.$out);
 verifyClean((int)DB::one('SELECT COUNT(*) n FROM users WHERE id=?',[$user])['n']===1,'Lost legacy user');
 verifyClean((int)DB::one('SELECT COUNT(*) n FROM clients WHERE id=?',[$client])['n']===1,'Lost legacy client');
 verifyClean((int)DB::one("SELECT value FROM settings WHERE `key`='schema_version'")['value']===30,'Schema version changed');
 verifyClean((int)DB::one("SELECT COUNT(*) n FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND COLUMN_NAME='tenant_account_id'")['n']===0,'Tenant columns remain');
 [$status,$out]=cleanupCLI(['--apply','--backup-manifest='.$manifest]);
 verifyClean($status===0&&str_contains($out,'"already_clean"'),'Not idempotent: '.$out);
 @unlink($manifest);@unlink($file);
 echo "SaaS cleanup OK: 8 tables, 31 columns, backup guard, no cross-tenant loss, existing users/clients preserved.\n";
}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n");exit(1);}
