<?php
declare(strict_types=1);

/**
 * Multiworkspace isolation switch.
 *
 * Intentionally disabled in production until all business queries, workers,
 * raw SQL writes and realtime broadcasts have been scoped. A second workspace
 * cannot be enabled merely by inserting it into the database.
 */
final class WorkspaceTenantMode {
 public const MARKER='workspace_multitenant_isolation_v2';
 public static function enabled(): bool {
  try {
   return (string)(DB::one('SELECT value FROM settings WHERE `key`=?',[self::MARKER])['value']??'')==='ready';
  }catch(Throwable){return false;}
 }

 /** Returns a verified company ID for any business table mutation. */
 public static function requireTableScope(string $table): int {
  if(!self::enabled())return 0;
  $table=strtolower($table);
  $scoped=in_array($table,['calendars','events'],true)||
     (class_exists('WorkspaceFoundation')&&isset(WorkspaceFoundation::DATA_TABLES[$table]));
  if(!$scoped)return 0;
  $ws=(int)(Auth::$workspace['id']??0);
  if($ws<1)$ws=WorkspaceJobScope::currentId();
  if($ws<1)throw new LogicException('Explicit workspace required for '.$table);
  return $ws;
 }

 /** Guard new rows at the database boundary when full isolation is enabled. */
 public static function insertData(string $table,array $data): array {
  $scope=self::requireTableScope($table);
  if($scope<1)return $data;
  if(isset($data['workspace_id'])&&(int)$data['workspace_id']!==$scope)
   throw new LogicException('Cross-workspace insert blocked for '.$table);
  return $data+['workspace_id'=>$scope];
 }

 /** Raw SQL writes must never fall through to the old default when enabled. */
 public static function assertSql(string $sql): void {
  // Never consult the tenant-mode marker while processing SELECT statements:
  // enabled() reads settings through DB::one(), which also calls this guard.
  if(!preg_match('/\A\s*(?:INSERT|UPDATE|DELETE|REPLACE)\b/i',$sql))return;
  if(!self::enabled())return;
  if(preg_match('/\A\s*INSERT\s+(?:IGNORE\s+)?INTO\s+`?([a-z_]+)`?\s*\(([^)]*)\)/i',$sql,$m)){
   if(self::requireTableScope((string)$m[1])>0 &&
      !preg_match('/(?:\A|,)\s*`?workspace_id`?\s*(?:,|\z)/i',trim($m[2])))
    throw new LogicException('Explicit workspace_id required for INSERT into '.$m[1]);
   return;
  }
  if(preg_match('/\A\s*(?:UPDATE|DELETE\s+FROM|REPLACE\s+INTO)\s+`?([a-z_]+)`?/i',$sql,$m)){
   if(self::requireTableScope((string)$m[1])>0 &&
      !preg_match('/\bworkspace_id\b/i',$sql))
    throw new LogicException('Raw SQL mutation must specify workspace_id for '.$m[1]);
   return;
  }
 }
}
