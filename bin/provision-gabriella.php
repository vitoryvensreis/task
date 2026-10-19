<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/app/bootstrap.php';

try{
 if(!in_array('--apply',$argv,true))throw new RuntimeException('explicit_apply_required');
 $manifestArg='';
 foreach($argv as $arg)if(str_starts_with($arg,'--backup-manifest='))$manifestArg=substr($arg,18);
 $base=realpath(ROOT.'/storage/backups');
 $manifest=realpath($manifestArg);
 if(!$base||!$manifest||dirname($manifest)!==$base||!is_file($manifest))
  throw new RuntimeException('backup_manifest_required');
 $contents=(string)file_get_contents($manifest);
 if(!preg_match('/^Arquivo do banco: (taskesc-predeploy-[A-Za-z0-9-]+\.sql\.gz)$/m',$contents,$filename)||
    !preg_match('/^SHA256: ([a-f0-9]{64})$/m',$contents,$hash)||
    !preg_match('/^Schema do banco: 30$/m',$contents))
  throw new RuntimeException('backup_manifest_invalid');
 $archive=$base.'/'.$filename[1];
 if(!is_file($archive)||is_link($archive)||filesize($archive)<1||
    !hash_equals($hash[1],(string)hash_file('sha256',$archive))||
    time()-filemtime($archive)>7200)
  throw new RuntimeException('backup_not_verified');
 $result=PlatformTenantProvisioning::provision();
 echo json($result+['current_vitor_data'=>'unchanged','gabriella_access'=>(WorkspaceTenantMode::enabled()?'activated':'not_activated')]).PHP_EOL;
}catch(Throwable $e){
 error_log('Tenant provisioning deferred: '.$e->getMessage());
 fwrite(STDERR,json(['pending_created'=>false,'reason'=>'prerequisites_not_met']).PHP_EOL);
 exit(2);
}
