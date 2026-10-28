<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/app/bootstrap.php';
$manifestArg='';
foreach($argv as $arg)if(str_starts_with($arg,'--backup-manifest='))$manifestArg=substr($arg,18);
try{
 if(!in_array('--apply',$argv,true))throw new RuntimeException('Explicit --apply required');
 $dir=realpath(ROOT.'/storage/backups');$manifest=realpath($manifestArg);
 if(!$dir||!$manifest||dirname($manifest)!==$dir||is_link($manifest))throw new RuntimeException('Backup manifest required');
 $body=file_get_contents($manifest);
 if(!$body||!preg_match('/^Arquivo do banco: (taskesc-predeploy-[^\r\n\/]+\.sql\.gz)$/m',$body,$m))
  throw new RuntimeException('Invalid backup manifest');
 $file=$dir.'/'.$m[1];$checksum=$file.'.sha256';
 if(!is_file($file)||!is_file($checksum)||is_link($file)||is_link($checksum))
  throw new RuntimeException('Backup not found');
 $sha=preg_split('/\s+/',trim((string)file_get_contents($checksum)))[0]??'';
 if(!preg_match('/\A[a-f0-9]{64}\z/D',$sha)||!hash_equals($sha,(string)hash_file('sha256',$file))||
    time()-filemtime($file)>7200)throw new RuntimeException('Invalid backup hash');
 if((int)(DB::one("SELECT value FROM settings WHERE `key`='schema_version'")['value']??0)!==30)
  throw new RuntimeException('Schema mismatch');
 $sql=(string)file_get_contents(ROOT.'/database/migrations/044_platform_checkout.sql');
 if($sql==='')throw new RuntimeException('Checkout schema missing');
 foreach(preg_split('/;\s*(?:\r?\n|$)/',$sql) as $statement)
  if(trim($statement)!=='')DB::run($statement);
 echo json(['checkout_tables_ready'=>true,'existing_workspaces_preserved'=>true]).PHP_EOL;
}catch(Throwable $e){error_log('Checkout migration: '.$e->getMessage());fwrite(STDERR,"Checkout migration blocked.\n");exit(2);}
