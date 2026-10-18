<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/app/bootstrap.php';

try{
 if(!in_array('--apply',$argv,true))throw new RuntimeException('explicit_apply_required');
 $raw='';
 foreach($argv as $arg)if(str_starts_with($arg,'--backup-manifest='))$raw=substr($arg,18);
 $manifest=realpath($raw)?:'';
 $backupDir=realpath(ROOT.'/storage/backups')?:'';
 if(!$manifest||!$backupDir||!str_starts_with($manifest,$backupDir.DIRECTORY_SEPARATOR)
    ||!is_file($manifest)||is_link($manifest)||!is_readable($manifest))
  throw new RuntimeException('recent_backup_manifest_required');
 $text=(string)file_get_contents($manifest);
 if(!preg_match('/^Arquivo do banco: (taskesc-predeploy-[A-Za-z0-9-]+\.sql\.gz)$/m',$text,$file)||
    !preg_match('/^SHA256: ([a-f0-9]{64})$/m',$text,$hash)||
    !preg_match('/^Schema do banco: (30)$/m',$text))
  throw new RuntimeException('backup_manifest_invalid');
 $backup=dirname($manifest).DIRECTORY_SEPARATOR.$file[1];
 if(!is_file($backup)||is_link($backup)||filesize($backup)<1||
    !hash_equals($hash[1],(string)hash_file('sha256',$backup))||
    time()-(int)filemtime($backup)>7200||
    time()-(int)filemtime($manifest)>7200)
  throw new RuntimeException('backup_missing_stale_or_checksum_failed');

 $result=WorkspaceFoundation::apply();
 if(!WorkspaceFoundationGuard::valid())
  throw new RuntimeException('workspace_foundation_postcheck_failed');
 echo json(['migration'=>$result,'backup_verified'=>true,
  'schema_operational'=>30,'old_saas_enabled'=>false]).PHP_EOL;
}catch(Throwable $e){
 $msg=$e->getMessage();
 $code=preg_match('/\A[a-z0-9_]+\z/D',$msg)?$msg:'workspace_migration_blocked';
 fwrite(STDERR,json(['status'=>'blocked','code'=>$code]).PHP_EOL);
 exit(1);
}
