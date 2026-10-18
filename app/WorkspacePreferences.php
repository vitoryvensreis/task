<?php
declare(strict_types=1);

/**
 * Staged per-workspace preferences.
 * Until the complete multi-workspace rollout, writes are mirrored to legacy
 * global settings so existing services and workers keep the same behavior.
 * All callers must use an authenticated workspace; never take a workspace
 * identifier from HTTP input.
 */
final class WorkspacePreferences {
 public const KEYS=[
  'brand_name','brand_color','logo_url','upload_mb','retention_days',
  'min_delivery_business_hours','drive_account_id','drive_root_folder_id',
  'dashboard_default_period','timezone'
 ];

 public static function get(string $key,string $fallback=''): string {
  if(!in_array($key,self::KEYS,true))throw new InvalidArgumentException('Not a workspace preference.');
  if(WorkspaceContext::legacyCiFixture())return (string)setting($key,$fallback);
  $ws=Auth::workspaceId();
  $row=DB::one('SELECT value FROM workspace_settings WHERE workspace_id=? AND `key`=?',[$ws,$key]);
  return $row?(string)$row['value']:(string)setting($key,$fallback);
 }
 public static function all(): array {
  if(WorkspaceContext::legacyCiFixture())return [];
  $ws=Auth::workspaceId();
  $out=[];
  foreach(DB::all('SELECT `key`,value FROM workspace_settings WHERE workspace_id=?',[$ws]) as $item)
   if(in_array((string)$item['key'],self::KEYS,true))$out[$item['key']]=$item['value'];
  return $out;
 }
 public static function save(string $key,string $value): void {
  if(!in_array($key,self::KEYS,true))throw new InvalidArgumentException('Not a workspace preference.');
  if(!WorkspaceContext::legacyCiFixture()){
   $ws=Auth::workspaceId();
   DB::run('INSERT INTO workspace_settings (workspace_id,`key`,value,updated_at) VALUES (?,?,?,?)
    ON DUPLICATE KEY UPDATE value=VALUES(value),updated_at=VALUES(updated_at)',
    [$ws,$key,$value,now()]);
  }
  // Legacy code still reads the original key throughout the single workspace.
  DB::run('INSERT INTO settings (`key`,value) VALUES (?,?)
   ON DUPLICATE KEY UPDATE value=VALUES(value)',[$key,$value]);
 }
}
