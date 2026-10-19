<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/app/bootstrap.php';
$arg='';
foreach($argv as $part)if(str_starts_with($part,'--backup-manifest='))$arg=substr($part,18);
try{
 if(!in_array('--apply',$argv,true))throw new RuntimeException('Explicit --apply required.');
 $dir=realpath(ROOT.'/storage/backups');$manifest=realpath($arg);
 if(!$dir||!$manifest||dirname($manifest)!==$dir)throw new RuntimeException('Validated backup required.');
 $body=file_get_contents($manifest);
 if(!$body||!preg_match('/^Arquivo do banco: (taskesc-predeploy-[^\r\n\/]+\.sql\.gz)$/m',$body,$m))
  throw new RuntimeException('Manifest invalid.');
 $zip=$dir.'/'.$m[1];$sha=$zip.'.sha256';
 if(!is_file($zip)||!is_file($sha))throw new RuntimeException('Backup unavailable.');
 $expect=preg_split('/\s+/',trim((string)file_get_contents($sha)))[0]??'';
 if(!preg_match('/\A[a-f0-9]{64}\z/D',$expect)||!hash_equals($expect,hash_file('sha256',$zip)))
  throw new RuntimeException('Backup checksum invalid.');
 if((int)(DB::one("SELECT value FROM settings WHERE `key`='schema_version'")['value']??0)!==30
    ||!WorkspaceFoundationGuard::valid())
  throw new RuntimeException('Single-workspace foundation unavailable.');
 $multi=WorkspaceTenantMode::enabled();
 $count=(int)(DB::one('SELECT COUNT(*) n FROM workspaces')['n']??0);
 if($count!==($multi?2:1))throw new RuntimeException('Unexpected workspace count.');
 $sql=file_get_contents(ROOT.'/database/migrations/033_workspace_custom_roles.sql');
 if($sql===false)throw new RuntimeException('Migration unavailable.');
 foreach(preg_split('/;\s*(?:\r?\n|$)/',$sql) as $q)if(trim($q)!=='')DB::run($q);
 $column=DB::one("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE()
   AND TABLE_NAME='workspace_members' AND COLUMN_NAME='custom_role_id' LIMIT 1");
 if(!$column)DB::run('ALTER TABLE workspace_members ADD COLUMN custom_role_id BIGINT UNSIGNED NULL');
 $idx=DB::one("SELECT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE()
   AND TABLE_NAME='workspace_members' AND INDEX_NAME='idx_workspace_custom_role' LIMIT 1");
 if(!$idx)DB::run('ALTER TABLE workspace_members ADD INDEX idx_workspace_custom_role (workspace_id,custom_role_id)');
 $fk=DB::one("SELECT CONSTRAINT_NAME FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE()
   AND TABLE_NAME='workspace_members' AND CONSTRAINT_NAME='fk_workspace_member_custom_role' LIMIT 1");
 if(!$fk)DB::run('ALTER TABLE workspace_members ADD CONSTRAINT fk_workspace_member_custom_role FOREIGN KEY (custom_role_id) REFERENCES workspace_role_definitions(id) ON DELETE SET NULL');

 DB::run("INSERT INTO settings(`key`,value) VALUES('workspace_custom_roles_v1','1') ON DUPLICATE KEY UPDATE value=VALUES(value)");
 echo json(['workspace_roles_ready'=>true,'workspace_count'=>$count,'multiworkspace_enabled'=>$multi]).PHP_EOL;
}catch(Throwable $e){error_log('TaskEsc support desk migration refused: '.$e->getMessage());fwrite(STDERR,"Workspace roles migration blocked.\n");exit(2);}
