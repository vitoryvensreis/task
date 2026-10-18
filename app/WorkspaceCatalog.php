<?php
declare(strict_types=1);

/**
 * B2 central ownership checks for the workspace catalog.
 * The second workspace remains disabled until all TaskEsc modules are scoped.
 */
final class WorkspaceCatalog {
 public const TABLES=['clients','projects','categories','stages','templates',
   'contracts','google_accounts'];

 public static function id(): int {return Auth::workspaceId();}

 public static function requireRecord(string $table,int $id): array {
  if(!in_array($table,self::TABLES,true))throw new InvalidArgumentException('Unsupported workspace resource.');
  if($id<1)fail('Registro não encontrado.',404);
  $ci=WorkspaceContext::legacyCiFixture();
  $row=DB::one('SELECT * FROM `'.$table.'` WHERE id=?'.($ci?'':' AND workspace_id=?'),
    $ci?[$id]:[$id,self::id()]);
  if(!$row)fail('Registro não encontrado neste workspace.',404);
  return $row;
 }

 public static function requireUser(int $id,bool $active=false): void {
  if($id<1)fail('Usuário inválido.',404);
  if(WorkspaceContext::legacyCiFixture())return;
  $ws=self::id();
  $row=DB::one('SELECT u.id FROM users u JOIN workspace_members m
    ON m.user_id=u.id AND m.workspace_id=?
    WHERE u.id=?'.($active?" AND u.active=1 AND m.status='active'":''),[$ws,$id]);
  if(!$row)fail('Usuário não pertence a este workspace.',404);
 }

 public static function validateDrive(int $id): void {
  if($id<=0)return;
  if(WorkspaceContext::legacyCiFixture())return;
  $account=self::requireRecord('google_accounts',$id);
  // Company administrators may only select accounts connected by
  // themselves; support Master uses the effective workspace owner.
  if((Auth::$user['role']??'')==='admin' &&
     (int)$account['user_id']!==(int)(Auth::$user['id']??0))
   fail('Somente contas Google conectadas por você podem ser usadas.',403);
 }
}
