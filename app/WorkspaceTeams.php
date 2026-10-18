<?php
declare(strict_types=1);

/** Invitation-only staff enrollment, bound to one operational workspace. */
final class WorkspaceTeams {
 private static function owner(): int {
  Auth::load();Auth::admin();
  if(Auth::isPreview()||MasterSupport::isSupport()||Auth::$origin!=='web'
    ||(string)(Auth::$workspace['role']??'')!=='owner')
   fail('Somente o proprietário pode convidar membros da equipe.',403);
  return Auth::workspaceId();
 }

 private static function available(): void {
  if(!(bool)DB::one("SELECT 1 FROM INFORMATION_SCHEMA.TABLES
   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='team_invitations' LIMIT 1"))
   fail('Convites de equipe ainda não foram instalados.',503);
 }

 public static function migrate(): array {
  if(DB::conn()->inTransaction())throw new LogicException('DDL fora de transações.');
  DB::run("CREATE TABLE IF NOT EXISTS team_invitations (
   id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
   workspace_id BIGINT UNSIGNED NOT NULL,
   user_id INT NOT NULL,
   email VARCHAR(190) NOT NULL,
   role_id BIGINT UNSIGNED NULL,
   token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
   status VARCHAR(20) NOT NULL DEFAULT 'pending',
   invited_by INT NOT NULL,
   expires_at DATETIME NOT NULL,
   accepted_at DATETIME NULL,
   created_at DATETIME NOT NULL,
   updated_at DATETIME NOT NULL,
   PRIMARY KEY(id),
   UNIQUE KEY uq_team_invite_user(workspace_id,user_id),
   UNIQUE KEY uq_team_invite_token(token_hash),
   KEY idx_team_invites_ws_status(workspace_id,status,expires_at),
   CONSTRAINT fk_team_invite_ws FOREIGN KEY(workspace_id) REFERENCES workspaces(id) ON DELETE RESTRICT,
   CONSTRAINT fk_team_invite_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE RESTRICT,
   CONSTRAINT fk_team_invite_author FOREIGN KEY(invited_by) REFERENCES users(id) ON DELETE RESTRICT
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  return ['installed'=>true,'table'=>'team_invitations'];
 }

 public static function list(): array {
  Auth::load();Auth::admin();if(Auth::isPreview())fail('Equipe indisponível durante visualização do cliente.',403);
  $ws=Auth::workspaceId();self::available();
  $plan=PlatformPlans::workspacePlan($ws);
  $members=DB::all("SELECT u.id,u.name,u.email,u.active,wm.role,wm.status,wm.custom_role_id,
    r.name AS custom_role_name
    FROM workspace_members wm JOIN users u ON u.id=wm.user_id
    LEFT JOIN workspace_role_definitions r ON r.id=wm.custom_role_id AND r.workspace_id=wm.workspace_id
    WHERE wm.workspace_id=? AND wm.role IN ('owner','admin','staff')
    ORDER BY wm.role='owner' DESC,u.name",[$ws]);
  $pending=DB::all("SELECT i.id,i.email,i.expires_at,i.created_at,u.name,
    r.name AS custom_role_name
    FROM team_invitations i JOIN users u ON u.id=i.user_id
    LEFT JOIN workspace_role_definitions r ON r.id=i.role_id AND r.workspace_id=i.workspace_id
    WHERE i.workspace_id=? AND i.status='pending' ORDER BY i.created_at DESC LIMIT 100",[$ws]);
  $used=0;
  foreach($members as $m)if($m['role']==='staff'&&$m['status']==='active'&&(int)$m['active']===1)$used++;
  return ['members'=>$members,'invites'=>$pending,'can_invite'=>(Auth::$origin==='web'&&!MasterSupport::isSupport()&&(Auth::$workspace['role']??'')==='owner'),'plan'=>$plan['name']??'',
    'team_limit'=>$plan['team_limit'],'team_used'=>$used];
 }

 public static function invite(array $p): array {
  $ws=self::owner();self::available();Auth::rate('team-invite-'.$ws,12,3600);
  $email=strtolower(trim((string)($p['email']??'')));
  if(strlen($email)>190||!filter_var($email,FILTER_VALIDATE_EMAIL))fail('E-mail inválido.',422);
  $name=required($p,'name',150);
  $roleId=integer($p['custom_role_id']??0,0);
  $token=bin2hex(random_bytes(32));$hash=hash('sha256',$token);
  $expires=gmdate('Y-m-d H:i:s',time()+48*3600);
  DB::tx(static function()use($ws,$email,$name,$roleId,$hash,$expires):void {
   $workspace=DB::one("SELECT id,status FROM workspaces WHERE id=? FOR UPDATE",[$ws]);
   if(!$workspace||!in_array($workspace['status'],[WorkspaceFoundation::STATUS,'active'],true))
    fail('Workspace indisponível.',409);
   $already=DB::one('SELECT id,active,role FROM users WHERE LOWER(email)=? FOR UPDATE',[$email]);
   if($already) {
    $member=DB::one('SELECT role,status FROM workspace_members WHERE user_id=? AND workspace_id=? FOR UPDATE',[(int)$already['id'],$ws]);
    $inv=DB::one('SELECT id,status FROM team_invitations WHERE workspace_id=? AND user_id=? FOR UPDATE',[$ws,(int)$already['id']]);
    if(!$member||!$inv||!in_array($inv['status'],['pending','revoked'],true)||$member['role']!=='staff'
       ||(int)$already['active']!==0)fail('E-mail já cadastrado. Utilize outro endereço ou edite o membro existente.',409);
    $userId=(int)$already['id'];
   } else {
    $userId=DB::insert('users',['name'=>$name,'email'=>$email,
      'password_hash'=>password_hash(bin2hex(random_bytes(32)),PASSWORD_DEFAULT),
      'role'=>'staff','active'=>0,'email_notifications'=>1,'created_at'=>now()]);
    WorkspaceContext::syncUser($userId,$ws,'staff',false);
   }
   PlatformPlans::enforceTeamLimit($ws,$userId,'staff',true);
   WorkspaceRoles::assignToUser($ws,$userId,'staff',$roleId);
   $data=['token_hash'=>$hash,'role_id'=>$roleId?:null,'status'=>'pending',
     'expires_at'=>$expires,'updated_at'=>now(),'invited_by'=>(int)Auth::$actor['id'],
     'accepted_at'=>null];
   $existing=DB::one('SELECT id FROM team_invitations WHERE workspace_id=? AND user_id=? FOR UPDATE',[$ws,$userId]);
   if($existing)DB::run('UPDATE team_invitations SET token_hash=?,role_id=?,status=?,expires_at=?,updated_at=?,invited_by=?,accepted_at=? WHERE id=?',
      [...array_values($data),(int)$existing['id']]);
   else DB::insert('team_invitations',$data+[
     'workspace_id'=>$ws,'user_id'=>$userId,'email'=>$email,'created_at'=>now()]);
   Notify::mail($email,'Convite para equipe — TaskEsc',
    "Você foi convidado(a) para fazer parte da equipe no TaskEsc.\n".
    "Defina sua senha neste link individual (válido por 48 horas):\n".
    base('convite.php?token='.$token)."\n\nSe não reconhece este convite, ignore.");
   audit('user',$userId,'team.invited',null,['email'=>$email,'workspace_id'=>$ws]);
  });
  return ['invited'=>true,'email'=>$email,'expires_in_hours'=>48];
 }

 public static function revoke(array $p): array {
  $ws=self::owner();self::available();
  $id=integer($p['id']??0,1);
  DB::tx(static function()use($ws,$id):void {
   $row=DB::one("SELECT id,user_id FROM team_invitations WHERE id=? AND workspace_id=? AND status='pending' FOR UPDATE",[$id,$ws]);
   if(!$row)fail('Convite pendente não encontrado.',404);
   DB::run("UPDATE team_invitations SET status='revoked',updated_at=? WHERE id=?",[now(),$id]);
   audit('user',(int)$row['user_id'],'team.invite_revoked',null,['workspace_id'=>$ws]);
  });
  return ['revoked'=>true];
 }

 public static function accept(array $p): array {
  self::available();Auth::rate('team-accept',12,900);
  $token=trim((string)($p['token']??''));
  if(!preg_match('/\A[a-f0-9]{64}\z/D',$token))fail('Convite inválido ou expirado.',422);
  $password=(string)($p['password']??'');
  if($password!==(string)($p['confirm_password']??''))fail('As senhas informadas não coincidem.',422);
  $hash=Auth::password($password);
  return DB::tx(static function()use($token,$hash):array {
   $invite=DB::one('SELECT * FROM team_invitations WHERE token_hash=? FOR UPDATE',[hash('sha256',$token)]);
   if(!$invite||$invite['status']!=='pending'||strtotime($invite['expires_at'])<time())
    fail('Convite inválido ou expirado.',422);
   $ws=(int)$invite['workspace_id'];$uid=(int)$invite['user_id'];
   $w=DB::one('SELECT id,status FROM workspaces WHERE id=? FOR UPDATE',[$ws]);
   if(!$w||!in_array($w['status'],[WorkspaceFoundation::STATUS,'active'],true))
    fail('Workspace indisponível.',409);
   $u=DB::one('SELECT id,active,role,email FROM users WHERE id=? FOR UPDATE',[$uid]);
   if(!$u||(int)$u['active']!==0||$u['role']!=='staff'||strcasecmp($u['email'],$invite['email'])!==0)
    fail('A conta do convite não está disponível.',409);
   $member=DB::one('SELECT role,status FROM workspace_members WHERE workspace_id=? AND user_id=? FOR UPDATE',[$ws,$uid]);
   if(!$member||$member['role']!=='staff'||$member['status']!=='inactive')
    fail('O vínculo da equipe não está pendente.',409);
   PlatformPlans::enforceTeamLimit($ws,$uid,'staff',true);
   DB::run('UPDATE users SET active=1,password_hash=? WHERE id=?',[$hash,$uid]);
   DB::run("UPDATE workspace_members SET status='active' WHERE workspace_id=? AND user_id=?",[$ws,$uid]);
   DB::run("UPDATE team_invitations SET status='accepted',accepted_at=?,updated_at=? WHERE id=?",[now(),now(),(int)$invite['id']]);
   WorkspaceJobScope::forWorkspace($ws,static function()use($ws,$uid):void {
    DB::insert('audit_log',['workspace_id'=>$ws,'user_id'=>$uid,'entity'=>'user',
     'entity_id'=>$uid,'action'=>'team.invite_accepted','before_json'=>null,
     'after_json'=>json(['workspace_id'=>$ws]),'origin'=>'web','created_at'=>now()]);
   });
   return ['accepted'=>true];
  });
 }
}
