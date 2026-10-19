<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/app/bootstrap.php';
$arg='';
foreach($argv as $part)if(str_starts_with($part,'--backup-manifest='))$arg=substr($part,18);
try{
 if(!in_array('--apply',$argv,true))throw new RuntimeException('Explicit --apply required.');
 $dir=realpath(ROOT.'/storage/backups');$manifest=realpath($arg);
 if(!$dir||!$manifest||dirname($manifest)!==$dir||is_link($manifest))
  throw new RuntimeException('Backup manifest required.');
 $body=file_get_contents($manifest);
 if(!$body||!preg_match('/^Arquivo do banco: (taskesc-predeploy-[^\r\n\/]+\.sql\.gz)$/m',$body,$m))
  throw new RuntimeException('Backup manifest invalid.');
 $zip=$dir.'/'.$m[1];$sha=$zip.'.sha256';
 if(!is_file($zip)||is_link($zip)||!is_file($sha)||is_link($sha))
  throw new RuntimeException('Backup missing.');
 $expected=preg_split('/\s+/',trim((string)file_get_contents($sha)))[0]??'';
 if(!preg_match('/\A[a-f0-9]{64}\z/D',$expected)||
    !hash_equals($expected,(string)hash_file('sha256',$zip))||
    (time()-filemtime($zip))>7200)
  throw new RuntimeException('Backup checksum invalid.');
 if((int)(DB::one("SELECT value FROM settings WHERE `key`='schema_version'")['value']??0)!==30)
  throw new RuntimeException('Schema 30 expected.');
 $ddl=file_get_contents(ROOT.'/database/migrations/041_platform_asaas_billing.sql');
 if(!$ddl)throw new RuntimeException('Migration unavailable.');
 DB::run($ddl);
 echo json(['platform_asaas_billing_ready'=>true,'tenant_data_unmodified'=>true]).PHP_EOL;
}catch(Throwable $e){
 error_log('Platform Asaas billing migration blocked: '.$e->getMessage());
 fwrite(STDERR,"Platform Asaas billing migration blocked.\n");exit(2);
}
