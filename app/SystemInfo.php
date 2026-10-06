<?php
final class SystemInfo {
 public static function release(): array {
  $file=ROOT.'/storage/release.json';
  if(!is_file($file))return [];
  try{$v=json_decode((string)file_get_contents($file),true,16,JSON_THROW_ON_ERROR);return is_array($v)?$v:[];}catch(Throwable){return [];}
 }
 public static function info(): array {
  $release=self::release();
  $schema='unknown';
  try{$schema=setting('schema_version','unknown');}catch(Throwable){}
  $commit=trim((string)($release['commit']??''));
  return [
   'version'=>TASKESC_VERSION,
   'schema'=>(string)$schema,
   'commit'=>$commit!==''?$commit:'unknown',
   'commit_short'=>$commit!==''?substr($commit,0,12):'unknown',
   'deployed_at'=>(string)($release['deployed_at']??''),
  ];
 }
 public static function flags(): array {
  $keys=['saas_enabled','workspace_context_enabled','workspace_permissions_enabled','workspace_settings_enabled','signup_enabled','billing_enabled'];
  $out=[];foreach($keys as $key)$out[$key]=Workspace::feature($key,false);return $out;
 }
}
