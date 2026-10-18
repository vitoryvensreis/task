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

 /** Guard new rows at the database boundary when full isolation is enabled. */
 public static function insertData(string $table,array $data): array {
  if(!self::enabled()||!isset(WorkspaceFoundation::DATA_TABLES[$table]))return $data;
  $scope=(int)(Auth::$workspace['id']??0);
  if($scope<1){
   $scope=WorkspaceJobScope::currentId();
  }
  if($scope<1)throw new LogicException('Workspace context is required to insert '.$table);
  if(isset($data['workspace_id'])&&(int)$data['workspace_id']!==$scope)
   throw new LogicException('Cross-workspace insert blocked for '.$table);
  return $data+['workspace_id'=>$scope];
 }

 /** Raw SQL writes must never fall through to the old default when enabled. */
 public static function assertSql(string $sql): void {
  if(!self::enabled())return;
  if(!preg_match('/\A\s*INSERT\s+(?:IGNORE\s+)?INTO\s+`?([a-z_]+)`?\s*\(([^)]*)\)/i',$sql,$m))return;
  if(!isset(WorkspaceFoundation::DATA_TABLES[strtolower($m[1])]))return;
  if(!preg_match('/(?:\A|,)\s*`?workspace_id`?\s*(?:,|\z)/i',trim($m[2])))
   throw new LogicException('Scoped workspace_id required in raw SQL INSERT for '.$m[1]);
 }
}

/** Worker-originated scope; fail closed rather than guessing the default tenant. */
final class WorkspaceJobScope {
 private static int $id=0;
 public static function currentId(): int {return self::$id;}
 public static function forWorkspace(int $id,callable $work): mixed {
  if($id<1)throw new InvalidArgumentException('Invalid worker workspace.');
  $w=DB::one('SELECT id,status FROM workspaces WHERE id=?',[$id]);
  if(!$w||!in_array((string)$w['status'],[WorkspaceFoundation::STATUS,'active'],true))
   throw new RuntimeException('Worker workspace is unavailable.');
  if(self::$id!==0)throw new LogicException('Nested workspace worker contexts not supported.');
  self::$id=$id;
  try{return $work();}finally{self::$id=0;}
 }
}
