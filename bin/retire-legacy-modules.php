<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/app/bootstrap.php';
$manifestArg='';foreach($argv as $part)
 if(str_starts_with($part,'--backup-manifest='))$manifestArg=substr($part,18);
try{
 if(!in_array('--apply',$argv,true))throw new RuntimeException('Explicit --apply required.');
 $dir=realpath(ROOT.'/storage/backups');$manifest=realpath($manifestArg);
 if(!$dir||!$manifest||dirname($manifest)!==$dir||is_link($manifest))
  throw new RuntimeException('Verified predeploy backup required.');
 $raw=(string)file_get_contents($manifest);
 if(!preg_match('/^Arquivo do banco: (taskesc-predeploy-[A-Za-z0-9-]+\.sql\.gz)$/m',$raw,$file)
   ||!preg_match('/^SHA256: ([a-f0-9]{64})$/m',$raw,$hash)
   ||!preg_match('/^Schema do banco: (30)$/m',$raw))
  throw new RuntimeException('Backup manifest invalid.');
 $archive=$dir.'/'.$file[1];
 if(!is_file($archive)||is_link($archive)||filesize($archive)<1
   ||!hash_equals($hash[1],(string)hash_file('sha256',$archive))
   ||time()-filemtime($archive)>7200)throw new RuntimeException('Backup verification refused.');
 echo json(RetireLegacyModules::apply()).PHP_EOL;
}catch(Throwable $e){
 error_log('Retired module cleanup refused: '.$e->getMessage());
 fwrite(STDERR,json(['retired'=>false,'code'=>'module_cleanup_blocked']).PHP_EOL);exit(2);
}
