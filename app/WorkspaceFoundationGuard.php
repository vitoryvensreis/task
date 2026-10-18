<?php
declare(strict_types=1);

/**
 * Only the new, explicitly staged workspace foundation is allowed through
 * legacy schema-30 migration and audit guards. This does NOT grant access
 * to any customer workspace and does not re-enable the retired SaaS.
 */
final class WorkspaceFoundationGuard {
 public static function valid(): bool {
  try {
   $version=(int)(DB::one('SELECT value FROM settings WHERE `key`=?',
     [WorkspaceFoundation::MARKER])['value']??0);
   if($version!==WorkspaceFoundation::VERSION)return false;
   if(DB::one("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES
       WHERE TABLE_SCHEMA=DATABASE() AND (TABLE_NAME LIKE 'saas_%'
         OR TABLE_NAME IN ('accounts','account_members','account_settings')) LIMIT 1"))return false;
   if(DB::one("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE()
       AND COLUMN_NAME='tenant_account_id' LIMIT 1"))return false;
   if(DB::one("SELECT `key` FROM settings WHERE `key` IN
     ('saas_enabled','workspace_context_enabled','workspace_permissions_enabled',
      'workspace_settings_enabled','signup_enabled','billing_enabled') LIMIT 1"))return false;
   // Structural guard stays valid for a legacy user created after migration.
   // Full row/membership checks run during the backup-gated migration itself.
   return WorkspaceFoundation::issues(false)===[];
  }catch(Throwable){return false;}
 }
}
