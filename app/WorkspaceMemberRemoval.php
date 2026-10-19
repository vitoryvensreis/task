<?php
declare(strict_types=1);

/**
 * Remove a staff member's access while preserving the immutable audit trail,
 * task comments, files and private chat history. Work ownership moves to the
 * workspace owner; the removed login becomes a non-loginable tombstone.
 */
final class WorkspaceMemberRemoval {
 private static function owner(): array {
  Auth::load();
  if (Auth::isPreview() || Auth::$origin!=='web' || MasterSupport::isSupport() ||
      (Auth::$workspace['role']??'')!=='owner' ||
      (Auth::$user['role']??'')!=='admin') {
   fail('Somente o proprietário pode excluir membros da equipe.',403);
  }
  $ws=Auth::workspaceId();
  $workspace=DB::one('SELECT id,owner_user_id FROM workspaces WHERE id=?',[$ws]);
  $ownerId=(int)($workspace['owner_user_id']??0);
  if ($ownerId<1 || $ownerId!==(int)Auth::$actor['id'] ||
      !DB::one("SELECT 1 FROM users WHERE id=? AND role='admin' AND active=1",[$ownerId])) {
   fail('Proprietário do workspace indisponível.',403);
  }
  return ['workspace_id'=>$ws,'owner_id'=>$ownerId];
 }
 private static function scoped(string $sql,int $id,int $ws): array {
  if (WorkspaceContext::legacyCiFixture())return [$sql,[$id]];
  return [$sql.' AND workspace_id=?',[$id,$ws]];
 }
 private static function countRows(string $table,string $column,int $userId,int $ws): int {
  // The table and column names are fixed in this class, not supplied by a request.
  [$sql,$params]=self::scoped("SELECT COUNT(*) AS n FROM `$table` WHERE `$column`=?",$userId,$ws);
  return (int)(DB::one($sql,$params)['n']??0);
 }
 private static function member(int $id,int $ws,bool $lock=false): array {
  $row=DB::one('SELECT u.id,u.name,u.email,u.role,u.active,wm.role AS member_role
   FROM users u JOIN workspace_members wm ON wm.user_id=u.id
   WHERE wm.workspace_id=? AND u.id=?'.($lock?' FOR UPDATE':''),[$ws,$id]);
  if (!$row || $row['role']!=='staff' || $row['member_role']!=='staff') {
   fail('Membro da equipe não encontrado neste workspace.',404);
  }
  return $row;
 }
 private static function impact(int $id,int $ws): array {
  return [
   'tasks'=>self::countRows('demands','assigned_to',$id,$ws),
   'default_responsibilities'=>self::countRows('categories','default_assigned_to',$id,$ws),
   'automations'=>self::countRows('automations','created_by',$id,$ws),
   'expense_rules'=>self::countRows('expense_rules','created_by',$id,$ws),
   'google_accounts'=>self::countRows('google_accounts','user_id',$id,$ws)
  ];
 }
 private static function googleConnected(int $id,int $ws): bool {
  [$sql,$params]=self::scoped("SELECT COUNT(*) AS n FROM google_accounts WHERE user_id=? AND status='connected'",$id,$ws);
  return (int)(DB::one($sql,$params)['n']??0)>0;
 }
 public static function preview(array $p): array {
  $context=self::owner();$ws=$context['workspace_id'];
  $id=integer($p['id']??0,1);$user=self::member($id,$ws);
  $impact=self::impact($id,$ws);
  $blocked=self::googleConnected($id,$ws);
  return ['user'=>['id'=>$id,'name'=>$user['name'],'email'=>$user['email']],
   'transfer_to'=>$context['owner_id'],'impact'=>$impact,
   'can_delete'=>!$blocked,
   'block_reason'=>$blocked?'Desconecte as contas Google deste membro antes da exclusão para não interromper agendas e arquivos do Drive.':null];
 }
 private static function updateOwned(string $table,string $column,int $from,int $to,int $ws): void {
  $sql="UPDATE `$table` SET `$column`=? WHERE `$column`=?";
  $params=[$to,$from];
  if(!WorkspaceContext::legacyCiFixture()){$sql.=' AND workspace_id=?';$params[]=$ws;}
  DB::run($sql,$params);
 }
 private static function revoke(string $sql,array $args,int $ws): void {
  if(!WorkspaceContext::legacyCiFixture()){$sql.=' AND workspace_id=?';$args[]=$ws;}
  DB::run($sql,$args);
 }
 public static function delete(array $p): array {
  $ctx=self::owner();$ws=$ctx['workspace_id'];$owner=$ctx['owner_id'];
  Auth::rate('remove-staff-'.$ws,12,3600);
  $id=integer($p['id']??0,1);
  if($id===$owner||$id===(int)Auth::$actor['id'])fail('O administrador proprietário não pode ser excluído.',403);
  if(trim((string)($p['confirmation']??''))!=='EXCLUIR')fail('Digite EXCLUIR para confirmar.',422);
  return DB::tx(static function()use($ws,$owner,$id):array {
   $user=self::member($id,$ws,true);
   if(self::googleConnected($id,$ws))
    fail('Desconecte as integrações Google deste membro antes de excluí-lo.',409);
   $impact=self::impact($id,$ws);
   foreach([['demands','assigned_to'],['categories','default_assigned_to'],
     ['automations','created_by'],['expense_rules','created_by']] as [$table,$column]) {
    self::updateOwned($table,$column,$id,$owner,$ws);
   }
   // History stays correctly attributed to the original (now disabled) identity.
   self::revoke("UPDATE api_tokens SET revoked=1 WHERE user_id=?",[$id],$ws);
   self::revoke("UPDATE push_subscriptions SET active=0,updated_at=? WHERE user_id=?",[now(),$id],$ws);
   self::revoke("DELETE FROM oauth_codes WHERE user_id=?",[$id],$ws);
   self::revoke("DELETE FROM idempotency WHERE user_id=?",[$id],$ws);
   DB::run('DELETE FROM reset_tokens WHERE user_id=?',[$id]);
   DB::run("UPDATE team_invitations SET status='revoked',updated_at=? WHERE workspace_id=? AND user_id=? AND status='pending'",[now(),$ws,$id]);
   // Disconnect stale OAuth records but refuse to touch live Google connections.
   self::revoke("UPDATE google_accounts SET access_token='',refresh_token='',calendar_enabled=0,drive_enabled=0 WHERE user_id=? AND status<>'connected'",[$id],$ws);
   self::revoke("DELETE FROM user_clients WHERE user_id=?",[$id],$ws);
   DB::run("DELETE FROM workspace_members WHERE workspace_id=? AND user_id=? AND role='staff'",[$ws,$id]);
   // Retain the FK target for chat, comments, file authorship and financial audit.
   $placeholder='excluido-'.$id.'@taskesc.invalid';
   DB::run("UPDATE users SET name=?,email=?,whatsapp_phone=NULL,active=0,
     staff_all_clients=0,email_notifications=0,weekly_report=0,
     password_hash=?,twofa_secret=NULL,twofa_enabled=0,twofa_recovery_json=NULL WHERE id=?",
     ['Membro excluído #'.$id,$placeholder,password_hash(bin2hex(random_bytes(32)),PASSWORD_DEFAULT),$id]);
   audit('user',$id,'team.member_removed',null,[
    'member_name'=>$user['name'],'transferred_to'=>$owner,'transferred'=>$impact,
    'credentials_revoked'=>true,'history_preserved'=>true
   ]);
   return ['deleted'=>true,'id'=>$id,'transferred_to'=>$owner,'impact'=>$impact];
  });
 }
}
