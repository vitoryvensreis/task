<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/app/bootstrap.php';

try {
 $manifestPath='';
 foreach($argv as $arg)if(str_starts_with($arg,'--backup-manifest='))$manifestPath=substr($arg,18);
 $manifestPath=realpath($manifestPath)?:'';
 $backupDir=realpath(ROOT.'/storage/backups');
 if(!$backupDir||!str_starts_with($manifestPath,$backupDir.'/')||!is_readable($manifestPath))throw new RuntimeException('backup_manifest_required');
 $manifest=file_get_contents($manifestPath);
 if(!preg_match('/^Arquivo do banco: (taskesc-predeploy-[A-Za-z0-9-]+\.sql\.gz)$/m',(string)$manifest,$file)
  || !preg_match('/^SHA256: ([a-f0-9]{64})$/m',(string)$manifest,$sha)
  || !preg_match('/^Schema do banco: (29|30)$/m',(string)$manifest,$backupSchema))throw new RuntimeException('backup_manifest_invalid');
 $backup=dirname($manifestPath).'/'.$file[1];
 if(!is_file($backup)||is_link($backup)||filesize($backup)===0||!hash_equals($sha[1],hash_file('sha256',$backup)))throw new RuntimeException('backup_verification_failed');
 $saasTables=DB::all("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('workspaces','workspace_members','workspace_settings')");
 $saasColumns=DB::all("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND COLUMN_NAME='workspace_id'");
 if($saasTables||$saasColumns)throw new RuntimeException('removed_saas_detected');
 $before=(int)(DB::one("SELECT value FROM settings WHERE `key`='schema_version'")['value']??0);
 if(!in_array($before,[29,30],true))throw new RuntimeException('contacts_schema_source_invalid');
 $migration=Migrations::whatsappContacts();
 if(Migrations::whatsappContactsIssues())throw new RuntimeException('whatsapp_contacts_schema_invalid');
 echo json(['migration'=>$migration,'backup_verified'=>true,'backup_schema'=>(int)$backupSchema[1],'schema_before'=>$before,'schema_after'=>$migration['current']]).PHP_EOL;
} catch(Throwable $e){
 $code=preg_match('/\A[a-z0-9_]+\z/D',$e->getMessage())?$e->getMessage():'contacts_migration_failed';
 fwrite(STDERR,json(['status'=>'error','code'=>$code]).PHP_EOL);exit(1);
}
