<?php
declare(strict_types=1);
/** Explicit, irreversible retirement after checksum-verified database backup. */
final class RetireLegacyModules {
 public static function apply(): array {
  if(PHP_SAPI!=='cli'||DB::conn()->inTransaction())
   throw new RuntimeException('Module cleanup requires isolated CLI.');
  if(WorkspaceTenantMode::enabled()){
   if((string)(DB::one("SELECT value FROM settings WHERE `key`='modules_retired_v1'")['value']??'')!=='done')
    throw new RuntimeException('Legacy modules were not retired.');
   return ['assistant_removed'=>true,'agenda_removed'=>true,'already_retired'=>true];
  }
  $schema=(int)(DB::one("SELECT value FROM settings WHERE `key`='schema_version'")['value']??0);
  if($schema!==30)throw new RuntimeException('Schema 30 required.');
  if((int)(DB::one('SELECT COUNT(*) n FROM workspaces')['n']??0)!==1)
   throw new RuntimeException('Legacy single workspace required during cleanup.');
  $allowed=['ai_whatsapp_inbox','events','calendars'];
  foreach($allowed as $table)DB::run('DROP TABLE IF EXISTS `'.$table.'`');
  $column=DB::one("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='ai_assistant_enabled'");
  if($column)DB::run('ALTER TABLE users DROP COLUMN ai_assistant_enabled');
  DB::run("DELETE FROM settings WHERE `key` IN
    ('ai_usage_policy_v1','workspace.calendar_enabled')");
  DB::run('INSERT INTO settings (`key`,value) VALUES (?,?)
    ON DUPLICATE KEY UPDATE value=VALUES(value)',
    ['modules_retired_v1','done']);
  return ['assistant_removed'=>true,'agenda_removed'=>true,'google_drive_preserved'=>true];
 }
}
