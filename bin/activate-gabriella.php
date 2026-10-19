<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/app/bootstrap.php';
$input='';
foreach($argv as $part)if(str_starts_with($part,'--backup-manifest='))$input=substr($part,18);
try{
 if(!in_array('--apply',$argv,true))throw new RuntimeException('Explicit --apply required.');
 $dir=realpath(ROOT.'/storage/backups');
 $manifest=realpath($input);
 if(!$dir||!$manifest||dirname($manifest)!==$dir||is_link($manifest))
  throw new RuntimeException('Verified backup manifest required.');
 $body=(string)file_get_contents($manifest);
 if(!preg_match('/^Arquivo do banco: (taskesc-predeploy-[A-Za-z0-9-]+\.sql\.gz)$/m',$body,$m)
   ||!preg_match('/^SHA256: ([a-f0-9]{64})$/m',$body,$sha)
   ||!preg_match('/^Schema do banco: (30)$/m',$body))
  throw new RuntimeException('Backup manifest invalid.');
 $archive=$dir.'/'.$m[1];
 if(!is_file($archive)||is_link($archive)||filesize($archive)<1||
   !hash_equals($sha[1],(string)hash_file('sha256',$archive))||
   time()-filemtime($archive)>7200)
  throw new RuntimeException('Backup checksum invalid.');
 $result=WorkspaceActivation::apply();
 echo json($result).PHP_EOL;
}catch(Throwable $e){
 error_log('Workspace activation refused: '.$e->getMessage());
 fwrite(STDERR,json(['activated'=>false,'code'=>'workspace_activation_blocked']).PHP_EOL);
 exit(2);
}
