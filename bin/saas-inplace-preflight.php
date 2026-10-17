<?php
declare(strict_types=1);
// Purely READ-ONLY TaskEsc SaaS readiness audit for the EXISTING VPS + database.
// Does not run migrations, change files or enable multi-tenant features.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/app/bootstrap.php';

try{
 $allowed=['--check','--release-check'];
 $mode=$argv[1]??'';
 if(!in_array($mode,$allowed,true))
  throw new RuntimeException('Use --check or --release-check --backup-manifest=ABSOLUTE_PATH');
 if(count($argv)>3)throw new RuntimeException('Unknown arguments.');
 $report=SaasInPlacePreflight::inspect();
 if($mode==='--release-check'){
  $arg=$argv[2]??'';
  if(!str_starts_with($arg,'--backup-manifest='))throw new RuntimeException('Verified backup manifest required.');
  $root=realpath(ROOT.'/storage/backups');
  $manifest=realpath(substr($arg,18));
  if(!$root||!$manifest||!str_starts_with($manifest,$root.DIRECTORY_SEPARATOR)||
    is_link($manifest)||!is_readable($manifest))
   throw new RuntimeException('Backup manifest must be inside TaskEsc backups directory.');
  $content=(string)file_get_contents($manifest);
  if(!preg_match('/^Arquivo do banco: (taskesc-predeploy-[A-Za-z0-9-]+\.sql\.gz)$/m',$content,$file)||
     !preg_match('/^SHA256: ([a-f0-9]{64})$/m',$content,$hash)||
     !preg_match('/^Schema do banco: 30$/m',$content))
   throw new RuntimeException('Backup manifest format or schema invalid.');
  $backup=dirname($manifest).DIRECTORY_SEPARATOR.$file[1];
  if(!is_file($backup)||is_link($backup)||filesize($backup)<1||
     !hash_equals($hash[1],hash_file('sha256',$backup)))
   throw new RuntimeException('Backup integrity verification failed.');
  // A reused historic dump is not sufficient for same-database rollout.
  if(filemtime($manifest)===false||filemtime($backup)===false||
     time()-filemtime($manifest)>7200||time()-filemtime($backup)>7200)
   throw new RuntimeException('Backup older than 2 hours; create a new one.');
  $report['backup_verified']=true;
 }
 $report['mode']=$mode==='--release-check'?'release_preflight_only':'read_only';
 echo json($report).PHP_EOL;
 exit($report['ready_for_manual_review']&&($mode==='--check'||$report['backup_verified'])?0:1);
}catch(Throwable $e){
 fwrite(STDERR,json(['mode'=>'read_only','status'=>'blocked',
  'error_code'=>'saas_inplace_preflight_failed']).PHP_EOL);
 exit(1);
}
