<?php
declare(strict_types=1);
// Migração aditiva sob backup verificado; sem alterações no SaaS antigo.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/app/bootstrap.php';
$manifestArg='';
foreach($argv as $arg)if(str_starts_with($arg,'--backup-manifest='))$manifestArg=substr($arg,18);
try{
 if(!in_array('--apply',$argv,true))throw new RuntimeException('Explicit --apply required');
 $dir=realpath(ROOT.'/storage/backups');$manifest=realpath($manifestArg);
 if(!$dir||!$manifest||dirname($manifest)!==$dir||is_link($manifest))
  throw new RuntimeException('Verified backup manifest required');
 $body=file_get_contents($manifest);
 if(!$body||!preg_match('/^Arquivo do banco: (taskesc-predeploy-[^\r\n\/]+\.sql\.gz)$/m',$body,$m))
  throw new RuntimeException('Invalid backup manifest');
 $zip=$dir.'/'.$m[1];$sha=$zip.'.sha256';
 if(!is_file($zip)||is_link($zip)||!is_file($sha)||is_link($sha))
  throw new RuntimeException('Backup or checksum missing');
 $expected=preg_split('/\s+/',trim((string)file_get_contents($sha)))[0]??'';
 if(!preg_match('/\A[a-f0-9]{64}\z/D',$expected)||
    !hash_equals($expected,(string)hash_file('sha256',$zip))||
    time()-filemtime($zip)>7200)
  throw new RuntimeException('Backup verification failed');
 if((int)(DB::one("SELECT value FROM settings WHERE `key`='schema_version'")['value']??0)!==30)
  throw new RuntimeException('Expected schema 30');
 foreach([
  ['platform_access_plans','extra_user_price_cents','ALTER TABLE platform_access_plans ADD COLUMN extra_user_price_cents BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER price_cents'],
  ['workspace_access_plans','extra_seats','ALTER TABLE workspace_access_plans ADD COLUMN extra_seats INT UNSIGNED NOT NULL DEFAULT 0 AFTER plan_id'],
  ['platform_access_plans','allow_extra_seats','ALTER TABLE platform_access_plans ADD COLUMN allow_extra_seats TINYINT(1) NOT NULL DEFAULT 1 AFTER extra_user_price_cents']
 ] as [$table,$column,$query]){
  if(!DB::one('SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?',[$table,$column]))
   DB::run($query);
 }
 echo json(['extra_seats_schema_ready'=>true,'existing_values_preserved'=>true]).PHP_EOL;
}catch(Throwable $e){
 error_log('TaskEsc extra seat migration blocked: '.$e->getMessage());
 fwrite(STDERR,"Extra seat migration blocked.\n");exit(2);
}
