<?php
final class Workspace {
 public static function feature(string $key,bool $default=false): bool {
  try{return setting($key,$default?'1':'0')==='1';}catch(Throwable){return $default;}
 }
 public static function setting(int $workspaceId,string $key,string $default=''): string {
  if($workspaceId<1||!self::feature('workspace_settings_enabled'))return setting($key,$default);
  try{
   $row=DB::one('SELECT value FROM workspace_settings WHERE workspace_id=? AND `key`=? LIMIT 1',[$workspaceId,$key]);
   return $row!==null?(string)$row['value']:setting($key,$default);
  }catch(Throwable){
   return setting($key,$default);
  }
 }
 public static function primaryId(): int {return 1;}
}
