<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/app/bootstrap.php';

try{
 $manifestPath='';
 foreach($argv as $arg)if(str_starts_with($arg,'--backup-manifest='))$manifestPath=substr($arg,18);
 $manifestPath=realpath($manifestPath)?:'';
 $backupDir=realpath(ROOT.'/storage/backups');
 if(!$backupDir||!str_starts_with($manifestPath,$backupDir.'/')||!is_readable($manifestPath))throw new RuntimeException('backup_manifest_required');
 $manifest=file_get_contents($manifestPath);
 if(!preg_match('/^Arquivo do banco: (taskesc-predeploy-[A-Za-z0-9-]+\.sql\.gz)$/m',(string)$manifest,$file)
  || !preg_match('/^SHA256: ([a-f0-9]{64})$/m',(string)$manifest,$sha)
  || !preg_match('/^Schema do banco: (23|24|25|26|27|28|29|30)$/m',(string)$manifest,$backupSchema))throw new RuntimeException('backup_manifest_invalid');
 $backup=dirname($manifestPath).'/'.$file[1];
 if(!is_file($backup)||is_link($backup)||filesize($backup)===0||!hash_equals($sha[1],hash_file('sha256',$backup)))throw new RuntimeException('backup_verification_failed');

 $removedTables=DB::all("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('workspaces','workspace_members','workspace_settings')");
 $removedColumns=DB::all("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND COLUMN_NAME='workspace_id'");
 $removedFlags=DB::all("SELECT `key` FROM settings WHERE `key` IN ('saas_enabled','workspace_context_enabled','workspace_permissions_enabled','workspace_settings_enabled','signup_enabled','billing_enabled')");
 if($removedFlags||(($removedTables||$removedColumns)&&!WorkspaceFoundationGuard::valid()))throw new RuntimeException('removed_saas_detected');

 $before=(int)(DB::one("SELECT value FROM settings WHERE `key`='schema_version'")['value']??0);
 if(!in_array($before,[23,24,25,26,27,28,29,30],true))throw new RuntimeException('automation_schema_source_invalid');
 $migration=Migrations::automation();
 $issues=Migrations::automationIssues();
 if($issues)throw new RuntimeException('automation_schema_invalid');
 $after=(int)(DB::one("SELECT value FROM settings WHERE `key`='schema_version'")['value']??0);
 if(!in_array($after,[26,27,28,29,30],true))throw new RuntimeException('automation_schema_marker_invalid');

 echo json([
  'migration'=>$migration,
  'backup_verified'=>true,
  'backup_schema'=>(int)$backupSchema[1],
  'schema_before'=>$before,
  'schema_after'=>$after,
  'saas_absent'=>true
 ]).PHP_EOL;
}catch(Throwable $e){
 $code=$e instanceof RuntimeException&&preg_match('/\A[a-z0-9_]+\z/D',$e->getMessage())?$e->getMessage():'automation_migration_failed';
 fwrite(STDERR,json(['status'=>'error','code'=>$code]).PHP_EOL);
 exit(1);
}
