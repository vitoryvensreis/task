<?php
declare(strict_types=1);
final class WorkspaceAutomationCursorMigration {
 public static function apply(): array {
  if(PHP_SAPI!=='cli'||DB::conn()->inTransaction()||WorkspaceTenantMode::enabled())
   throw new RuntimeException('Cursor migration requires preactivation CLI mode.');
  if(!WorkspaceFoundationGuard::valid())throw new RuntimeException('Legacy workspace validation failed.');
  DB::run("CREATE TABLE IF NOT EXISTS automation_workspace_cursors (
    workspace_id BIGINT UNSIGNED NOT NULL,
    last_outbox_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
    heartbeat_at DATETIME(6) NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY(workspace_id),
    CONSTRAINT fk_auto_cursor_workspace FOREIGN KEY(workspace_id)
    REFERENCES workspaces(id) ON DELETE RESTRICT
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  return ['cursor_per_workspace_ready'=>true,'multiworkspace_enabled'=>false];
 }
}
