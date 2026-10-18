<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/app/bootstrap.php';
$arg='';foreach($argv as $part)if(str_starts_with($part,'--backup-manifest='))$arg=substr($part,18);
try{
 if(!in_array('--apply',$argv,true))throw new RuntimeException('Explicit --apply required.');
 $dir=realpath(ROOT.'/storage/backups');$manifest=realpath($arg);
 if(!$dir||!$manifest||dirname($manifest)!==$dir||is_link($manifest))
  throw new RuntimeException('Validated backup manifest required.');
 $body=(string)file_get_contents($manifest);
 if(!preg_match('/^Arquivo do banco: (taskesc-predeploy-[A-Za-z0-9-]+\.sql\.gz)$/m',$body,$file)
  ||!preg_match('/^SHA256: ([a-f0-9]{64})$/m',$body,$sha)
  ||!preg_match('/^Schema do banco: (30)$/m',$body))
  throw new RuntimeException('Invalid backup manifest.');
 $archive=$dir.'/'.$file[1];
 if(!is_file($archive)||is_link($archive)||filesize($archive)<1||
   !hash_equals($sha[1],(string)hash_file('sha256',$archive))||
   time()-filemtime($archive)>7200)
  throw new RuntimeException('Backup not verified.');
 echo json(WorkspaceCalendarScope::migrate()).PHP_EOL;
}catch(Throwable $e){
 error_log('Calendar migration blocked: '.$e->getMessage());
 fwrite(STDERR,json(['migrated'=>false,'code'=>'workspace_calendars_migration_blocked']).PHP_EOL);
 exit(2);
}
