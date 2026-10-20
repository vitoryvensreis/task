<?php
declare(strict_types=1);

/**
 * Notification inbox: always bind user + active workspace on a tenant-enabled
 * deployment. A failed or preview request never marks notifications as seen.
 */
final class NotificationInbox {
 private static function scope(): array {
  Auth::load();
  if (Auth::isPreview()) return ['preview'=>true];
  $id=(int)(Auth::$user['id']??0);
  if ($id<1) fail('Sessão inválida.',401);
  if (!WorkspaceTenantMode::enabled()) return ['user_id'=>$id,'multi'=>false];
  $ws=Auth::workspaceId();
  if($ws<1)fail('Workspace indisponível.',403);
  return ['user_id'=>$id,'multi'=>true,'workspace_id'=>$ws];
 }
 public static function list(): array {
  $scope=self::scope();
  if(!empty($scope['preview']))return [];
  $sql='SELECT * FROM notifications WHERE user_id=?';
  $args=[$scope['user_id']];
  if($scope['multi']){$sql.=' AND workspace_id=?';$args[]=$scope['workspace_id'];}
  return DB::all($sql.' ORDER BY id DESC LIMIT 50',$args);
 }
 public static function markAllSeen(): array {
  $scope=self::scope();
  if(!empty($scope['preview']))fail('Visualização de cliente é somente leitura.',403);
  $sql='UPDATE notifications SET seen=1 WHERE user_id=? AND seen=0';
  $args=[$scope['user_id']];
  if($scope['multi']){$sql.=' AND workspace_id=?';$args[]=$scope['workspace_id'];}
  $statement=DB::run($sql,$args);
  return ['saved'=>true,'updated'=>$statement->rowCount()];
 }
}
