<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/app/bootstrap.php';

// Opt-in only: never called by production deployment. Proof of existing,
// non-empty, checksum-verified database backup is mandatory.
try {
 $apply=in_array('--apply',$argv,true);
 $dry=in_array('--dry-run',$argv,true);
 if($apply===$dry)throw new RuntimeException('use_exactly_one_of_apply_or_dry_run');
 $manifestArg='';
 foreach($argv as $arg)if(str_starts_with($arg,'--backup-manifest='))$manifestArg=substr($arg,18);
 $root=realpath(ROOT.'/storage/backups');
 $manifest=realpath($manifestArg)?:'';
 if(!$root||!str_starts_with($manifest,$root.DIRECTORY_SEPARATOR)||!is_readable($manifest))
  throw new RuntimeException('verified_backup_manifest_required');
 $contents=(string)file_get_contents($manifest);
 if(!preg_match('/^Arquivo do banco: (taskesc-predeploy-[A-Za-z0-9-]+\.sql\.gz)$/m',$contents,$f)
  ||!preg_match('/^SHA256: ([a-f0-9]{64})$/m',$contents,$hash)
  ||!preg_match('/^Schema do banco: (30)$/m',$contents))
  throw new RuntimeException('backup_manifest_invalid');
 $backup=dirname($manifest).DIRECTORY_SEPARATOR.$f[1];
 if(!is_file($backup)||is_link($backup)||filesize($backup)<1||
    !hash_equals($hash[1],hash_file('sha256',$backup)))
  throw new RuntimeException('backup_verification_failed');
 if((int)(DB::one("SELECT value FROM settings WHERE `key`='schema_version'")['value']??0)!==30)
  throw new RuntimeException('schema_30_required');
 if($dry){
  echo json(['mode'=>'dry-run','verified_backup'=>true,'issues'=>SaasOutboxMigration::issues(),
   'status'=>'staging_only','publisher_enabled'=>false]).PHP_EOL;
  exit(0);
 }
 echo json(['mode'=>'apply','verified_backup'=>true,'result'=>SaasOutboxMigration::apply()]).PHP_EOL;
}catch(Throwable $e){
 $msg=$e->getMessage();
 $code=preg_match('/\A[a-z0-9_]+\z/D',$msg)?$msg:'saas_outbox_migration_failed';
 fwrite(STDERR,json(['status'=>'error','code'=>$code]).PHP_EOL);exit(1);
}
