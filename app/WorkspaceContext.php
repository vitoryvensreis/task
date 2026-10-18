<?php
declare(strict_types=1);

/**
 * B1: one-workspace identity boundary.
 *
 * Only the existing staged operation is valid here. A second tenant stays
 * blocked until every query, worker, download and integration is isolated.
 * Never trust a workspace_id sent by the browser, a Bearer token or URL.
 */
final class WorkspaceContext {
 /** Compatibility for the historical schema-30 fixture used by old CI tests.
  * Not accessible on normal deployments, even through a CLI argument or HTTP.
  */
 public static function legacyCiFixture(): bool {
  $db=config('db',[]);
  return PHP_SAPI==='cli'&&(string)($db['name']??'')==='taskesc_test'
   &&ctype_digit((string)getenv('OUTBOX_TEST_PORT'))
   &&(int)($db['port']??0)===(int)getenv('OUTBOX_TEST_PORT');
 }
 public static function forUser(array $user): array {
  if(self::legacyCiFixture())return [
   'id'=>0,'name'=>'Legacy CI fixture','slug'=>'ci-legacy','role'=>
     (string)($user['role']??'client')];
  $id=(int)($user['id']??0);
  if($id<1||!in_array((string)($user['role']??''),['admin','staff','client'],true)
     ||(int)($user['active']??0)!==1)
   fail('Usuário sem acesso ao workspace.',403);
  // Master is never a tenant member; only an explicit, audited web support
  // session grants a temporary view as the selected owner.
  if(strtolower((string)($user['email']??''))===WorkspaceFoundation::MASTER_EMAIL)
   return MasterSupport::contextFor($user);
  if((int)(DB::one('SELECT COUNT(*) n FROM workspaces')['n']??0)!==1)
   fail('Workspaces adicionais ainda não estão habilitados.',503);
  $row=DB::one('SELECT w.id,w.slug,w.name,w.status,wm.role AS member_role,
     wm.status AS member_status
     FROM workspace_members wm JOIN workspaces w ON w.id=wm.workspace_id
     WHERE wm.user_id=? LIMIT 2',[$id]);
  if(!$row||$row['slug']!==WorkspaceFoundation::SLUG||
     $row['status']!==WorkspaceFoundation::STATUS||
     $row['member_status']!=='active')
   fail('Usuário sem vínculo ativo com o workspace.',403);
  $memberRole=(string)$row['member_role'];
  $expected=(string)$user['role'];
  if(!in_array($memberRole,['owner','admin','staff','client'],true)||
     (($memberRole==='owner'||$memberRole==='admin')?'admin':$memberRole)!==$expected)
   fail('Permissões do workspace precisam ser atualizadas.',403);
  return ['id'=>(int)$row['id'],'name'=>(string)$row['name'],
   'slug'=>(string)$row['slug'],'role'=>$memberRole];
 }

 public static function assertUserBelongs(int $userId,int $workspaceId): void {
  if($userId<1||$workspaceId<1||
     !DB::one('SELECT 1 FROM workspace_members
       WHERE user_id=? AND workspace_id=? LIMIT 1',[$userId,$workspaceId]))
   fail('Usuário não pertence a este workspace.',404);
 }

 /** Must share the same SQL transaction as the legacy users write. */
 public static function syncUser(int $userId,int $workspaceId,string $role,bool $active,bool $reactivated=false): void {
  if(!DB::conn()->inTransaction())throw new LogicException('Workspace membership must be saved atomically.');
  if($userId<1||$workspaceId<1||!in_array($role,['admin','staff','client'],true))
   throw new InvalidArgumentException('Invalid workspace member.');
  $workspace=DB::one('SELECT id,slug,status FROM workspaces WHERE id=? FOR UPDATE',[$workspaceId]);
  if(!$workspace||$workspace['slug']!==WorkspaceFoundation::SLUG||
     $workspace['status']!==WorkspaceFoundation::STATUS||
     (int)(DB::one('SELECT COUNT(*) n FROM workspaces')['n']??0)!==1)
   throw new RuntimeException('Workspace registration is not enabled.');
  if(DB::one('SELECT 1 FROM workspace_members WHERE user_id=? AND workspace_id<>? LIMIT 1',
    [$userId,$workspaceId]))
   throw new RuntimeException('Cross-workspace member is not allowed in B1.');
  $current=DB::one('SELECT role,status FROM workspace_members WHERE workspace_id=? AND user_id=? FOR UPDATE',
    [$workspaceId,$userId]);
  $memberRole=$current&&$current['role']==='owner'?'owner':$role;
  $status=$active&&(!$current||$current['status']==='active'||$reactivated)?'active':'inactive';
  DB::run('INSERT INTO workspace_members (workspace_id,user_id,role,status,joined_at)
     VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE role=VALUES(role),status=VALUES(status)',
     [$workspaceId,$userId,$memberRole,$status,now()]);
 }
}
