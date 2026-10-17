<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/app/bootstrap.php';

try {
 $apply=in_array('--apply',$argv,true);
 $dry=in_array('--dry-run',$argv,true);
 if($apply===$dry)throw new RuntimeException('use_exactly_one_of_apply_or_dry_run');
 $manifestArg='';
 foreach($argv as $arg)if(str_starts_with($arg,'--backup-manifest='))$manifestArg=substr($arg,18);
 $backupDir=realpath(ROOT.'/storage/backups');
 $manifestPath=realpath($manifestArg)?:'';
 if(!$backupDir||!str_starts_with($manifestPath,$backupDir.DIRECTORY_SEPARATOR)||!is_readable($manifestPath))throw new RuntimeException('verified_backup_manifest_required');
 $manifest=(string)file_get_contents($manifestPath);
 if(!preg_match('/^Arquivo do banco: (taskesc-predeploy-[A-Za-z0-9-]+\.sql\.gz)$/m',$manifest,$filename)
  ||!preg_match('/^SHA256: ([a-f0-9]{64})$/m',$manifest,$sha)
  ||!preg_match('/^Schema do banco: (30)$/m',$manifest))throw new RuntimeException('backup_manifest_invalid');
 $backup=dirname($manifestPath).DIRECTORY_SEPARATOR.$filename[1];
 if(!is_file($backup)||is_link($backup)||filesize($backup)<1||!hash_equals($sha[1],hash_file('sha256',$backup)))throw new RuntimeException('backup_verification_failed');
 if((int)(DB::one("SELECT value FROM settings WHERE `key`='schema_version'")['value']??0)!==30)throw new RuntimeException('schema_30_required');
 if($dry){
  echo json(['mode'=>'dry-run','verified_backup'=>true,'domain_issues'=>SaasDomainMigration::issues(),'new_accounts_allowed'=>false,'next'=>'Explicit --apply required for staged columns.']).PHP_EOL;
  exit(0);
 }
 echo json(['mode'=>'apply','verified_backup'=>true,'result'=>SaasDomainMigration::apply()]).PHP_EOL;
}catch(Throwable $e){
 $message=$e->getMessage();
 $code=preg_match('/\A[a-z0-9_]+\z/D',$message)?$message:'saas_domain_migration_failed';
 fwrite(STDERR,json(['status'=>'error','code'=>$code]).PHP_EOL);exit(1);
}
