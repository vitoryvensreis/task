<?php
declare(strict_types=1);

/** Workspace isolation boundary for local calendars and Google events. */
final class WorkspaceCalendarScope {
 public const MARKER='workspace_calendars_stage6_v1';

 public static function ready(): bool {
  try{return (string)(DB::one('SELECT value FROM settings WHERE `key`=?',[self::MARKER])['value']??'')==='1';}
  catch(Throwable){return false;}
 }

 public static function id(): int {
  if(!WorkspaceTenantMode::enabled())return 0;
  if(!self::ready())throw new RuntimeException('Calendar migration required before multiworkspace use.');
  $ws=WorkspaceJobScope::currentId();
  if($ws<1)$ws=(int)(Auth::$workspace['id']??0);
  if($ws<1)throw new RuntimeException('Explicit calendar workspace required.');
  return $ws;
 }

 public static function andSql(string $alias): string {
  if(!in_array($alias,['c','e','a'],true))throw new InvalidArgumentException('Invalid calendar alias.');
  $id=self::id();
  return $id>0?' AND '.$alias.'.workspace_id='.$id:'';
 }

 public static function assertCalendar(int $calendarId): void {
  $id=self::id();if(!$id)return;
  if(!DB::one('SELECT 1 FROM calendars WHERE id=? AND workspace_id=?',[$calendarId,$id]))
   fail('Agenda não encontrada neste workspace.',404);
 }

 public static function assertEvent(int $eventId): void {
  $id=self::id();if(!$id)return;
  if(!DB::one('SELECT 1 FROM events WHERE id=? AND workspace_id=?',[$eventId,$id]))
   fail('Evento não encontrado neste workspace.',404);
 }

 public static function assertGoogleAccount(int $accountId): void {
  $id=self::id();if(!$id)return;
  if(!DB::one('SELECT 1 FROM google_accounts WHERE id=? AND workspace_id=?',[$accountId,$id]))
   fail('Conta Google não encontrada neste workspace.',404);
 }

 /** Additive and resumable, no second tenant created or activated. */
 public static function migrate(): array {
  if(PHP_SAPI!=='cli'||DB::conn()->inTransaction())
   throw new RuntimeException('Calendar migration requires standalone CLI.');
  if(WorkspaceTenantMode::enabled())throw new RuntimeException('Cannot migrate calendars after tenant activation.');
  if(!WorkspaceFoundationGuard::valid())throw new RuntimeException('Invalid existing workspace foundation.');
  if((int)(DB::one('SELECT COUNT(*) n FROM workspaces')['n']??0)!==1)
   throw new RuntimeException('Expected one legacy workspace.');
  $w=DB::one('SELECT id FROM workspaces WHERE slug=?',[WorkspaceFoundation::SLUG]);
  if(!$w)throw new RuntimeException('Original workspace not found.');
  $ws=(int)$w['id'];
  foreach(['calendars','events'] as $table){
   $column=DB::one("SELECT DATA_TYPE,COLUMN_DEFAULT,IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME='workspace_id'",[$table]);
   if(!$column)
    DB::run('ALTER TABLE `'.$table.'` ADD COLUMN workspace_id BIGINT UNSIGNED NOT NULL DEFAULT '.$ws);
   elseif($column['DATA_TYPE']!=='bigint'||$column['IS_NULLABLE']!=='NO'||(int)$column['COLUMN_DEFAULT']!==$ws)
    throw new RuntimeException('Unexpected calendar workspace column '.$table);
   if(!DB::one('SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS
     WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?',[$table,'idx_ws_'.$table]))
    DB::run('ALTER TABLE `'.$table.'` ADD KEY `idx_ws_'.$table.'` (workspace_id,id)');
   if((int)(DB::one('SELECT COUNT(*) n FROM `'.$table.'` WHERE workspace_id<>?',[$ws])['n']??0)>0)
    throw new RuntimeException('Mixed calendar workspace data.');
  }
  if((int)(DB::one('SELECT COUNT(*) n FROM calendars c JOIN google_accounts a ON a.id=c.account_id
    WHERE c.workspace_id<>a.workspace_id')['n']??0)>0)
   throw new RuntimeException('Calendar Google account mismatch.');
  if((int)(DB::one('SELECT COUNT(*) n FROM events e JOIN demands d ON d.id=e.demand_id
    WHERE e.workspace_id<>d.workspace_id')['n']??0)>0)
   throw new RuntimeException('Calendar demand mismatch.');
  DB::run('INSERT INTO settings (`key`,value) VALUES (?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)',
   [self::MARKER,'1']);
  return ['stage'=>6,'calendars_scoped'=>true,'legacy_records_preserved'=>true,'multiworkspace_enabled'=>false];
 }
}
