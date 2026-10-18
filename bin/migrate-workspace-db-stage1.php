<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/app/bootstrap.php';

$manifestArg='';
foreach($argv as $argument)
 if(str_starts_with($argument,'--backup-manifest='))$manifestArg=substr($argument,18);

try {
 if(!in_array('--apply',$argv,true))throw new RuntimeException('explicit_apply_required');
 $folder=realpath(ROOT.'/storage/backups');
 $manifest=realpath($manifestArg);
 if(!$folder||!$manifest||dirname($manifest)!==$folder||is_link($manifest)||!is_file($manifest))
  throw new RuntimeException('backup_manifest_required');
 $text=(string)file_get_contents($manifest);
 if(!preg_match('/^Arquivo do banco: (taskesc-predeploy-[A-Za-z0-9-]+\.sql\.gz)$/m',$text,$match)||
    !preg_match('/^SHA256: ([a-f0-9]{64})$/m',$text,$hash)||
    !preg_match('/^Schema do banco: (30)$/m',$text))
  throw new RuntimeException('backup_manifest_invalid');
 $archive=$folder.'/'.$match[1];
 if(!is_file($archive)||is_link($archive)||filesize($archive)<1||
    !hash_equals($hash[1],(string)hash_file('sha256',$archive))||
    time()-filemtime($archive)>7200)
  throw new RuntimeException('backup_not_verified');
 echo json(WorkspaceDatabaseStage1::apply()).PHP_EOL;
}catch(Throwable $e){
 error_log('Workspace stage1 migration refused: '.$e->getMessage());
 $detail=(string)$e->getMessage();
 $detail=mb_substr((string)preg_replace('/[^A-Za-z0-9_:.\\- ]/','_',$detail),0,180);
 fwrite(STDERR,json(['stage1_applied'=>false,'reason'=>$detail,'exception'=>get_class($e)]).PHP_EOL);
 exit(2);
}
