<?php
declare(strict_types=1);

/**
 * Stage 1 of the migration to multiple workspaces.
 *
 * This stage is compatible with today's single-workspace application:
 * - verifies all 44 tenant columns, indexes and original data ownership;
 * - ensures a single tenant membership for each non-Master user;
 * - ensures each owner owns only one workspace;
 * - records that default retirement is PENDING application-scoped writers.
 *
 * Do not silently drop the legacy workspace defaults while any writer omits
 * workspace_id. The actual DROP DEFAULT migration is a separate stage.
 */
final class WorkspaceDatabaseStage1 {
 public const MARKER='workspace_database_stage1_version';
 public const VERSION=1;

 private static function indexExists(string $table,string $index): bool {
  return (bool)DB::one('SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=? LIMIT 1',
    [$table,$index]);
 }

 public static function apply(): array {
  if(DB::conn()->inTransaction())throw new LogicException('DDL cannot be run inside a transaction.');
  $lock='taskesc-workspace-db-stage1-'.(string)(DB::one('SELECT SHA1(DATABASE()) h')['h']??'');
  if((int)(DB::one('SELECT GET_LOCK(?,10) acquired',[$lock])['acquired']??0)!==1)
   throw new RuntimeException('Workspace stage1 migration is already running.');
  try {
   $version=(int)(DB::one('SELECT value FROM settings WHERE `key`=?',[self::MARKER])['value']??0);
   if($version>self::VERSION)throw new RuntimeException('Unexpected future workspace database version.');
   if(!WorkspaceFoundationGuard::valid())throw new RuntimeException('Current workspace schema is not valid.');
   $workspace=DB::one('SELECT id,owner_user_id,slug,status FROM workspaces WHERE slug=?',
     [WorkspaceFoundation::SLUG]);
   if(!$workspace||$workspace['status']!==WorkspaceFoundation::STATUS||
     (int)(DB::one('SELECT COUNT(*) n FROM workspaces')['n']??0)!==1)
    throw new RuntimeException('Stage 1 expects the existing Vitor workspace only.');
   $owner=DB::one('SELECT id,active,role FROM users WHERE LOWER(email)=?',
     [WorkspaceFoundation::OWNER_EMAIL]);
   if(!$owner||(int)$owner['id']!==(int)$workspace['owner_user_id']||
     (int)$owner['active']!==1||$owner['role']!=='admin')
    throw new RuntimeException('Original owner mismatch.');

   $duplicateOwners=DB::one('SELECT owner_user_id FROM workspaces GROUP BY owner_user_id HAVING COUNT(*)>1 LIMIT 1');
   $duplicateMembership=DB::one('SELECT user_id FROM workspace_members GROUP BY user_id HAVING COUNT(*)>1 LIMIT 1');
   if($duplicateOwners||$duplicateMembership)throw new RuntimeException('An owner or member has multiple workspace bindings.');
   $tables=WorkspaceFoundation::DATA_TABLES;
   foreach($tables as $table=>$key) {
    $definition=DB::one('SELECT DATA_TYPE,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT
      FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?',[$table,'workspace_id']);
    if(!$definition||$definition['DATA_TYPE']!=='bigint'||
       !str_contains(strtolower((string)$definition['COLUMN_TYPE']),'unsigned')||
       $definition['IS_NULLABLE']!=='NO'||
       (int)$definition['COLUMN_DEFAULT']!==(int)$workspace['id'])
      throw new RuntimeException('Unexpected workspace_id on '.$table);
    if((int)(DB::one('SELECT COUNT(*) n FROM `'.$table.'` WHERE workspace_id<>?',
        [(int)$workspace['id']])['n']??0)>0)
      throw new RuntimeException('Unexpected tenant data ownership on '.$table);
    if(!self::indexExists($table,'idx_ws_'.$table))
      throw new RuntimeException('Missing workspace index on '.$table);
   }
   // These constraints are compatible with existing data, and prevent an
   // accidental second membership/ownership before the scoped APIs arrive.
   if(!self::indexExists('workspaces','uq_ws_one_owner'))
    DB::run('ALTER TABLE workspaces ADD UNIQUE KEY uq_ws_one_owner (owner_user_id)');
   if(!self::indexExists('workspace_members','uq_ws_one_membership'))
    DB::run('ALTER TABLE workspace_members ADD UNIQUE KEY uq_ws_one_membership (user_id)');

   DB::run('INSERT INTO settings (`key`,value) VALUES (?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)',
    [self::MARKER,(string)self::VERSION]);
   return [
    'stage'=>self::VERSION,
    'workspace_count'=>1,
    'legacy_owner'=>'Vitor',
    'tables_checked'=>count($tables),
    'workspace_columns_type'=>'BIGINT UNSIGNED NOT NULL',
    'default_removal'=>'waiting_for_explicit_workspace_writers',
    'additional_workspaces_enabled'=>false,
    'new_workspace_created'=>false
   ];
  }finally{DB::run('SELECT RELEASE_LOCK(?)',[$lock]);}
 }
}
