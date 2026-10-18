<?php
declare(strict_types=1);

/**
 * ADM Master: business metadata and administrator access.
 * Platform billing is intentionally not represented as paid without Asaas reconciliation.
 * Never returns tenant tasks/finance records or their content.
 */
final class MasterWorkspaceManagement {
 private static function guard(): void {
  Auth::load();
  if(Auth::$origin!=='web'||!MasterSupport::isActor()||MasterSupport::isSupport())
   fail('Disponível somente no painel do ADM Master.',403);
 }

 private static function operationalWorkspace(int $id,bool $lock=false): array {
  $w=DB::one('SELECT w.id,w.name,w.slug,w.owner_user_id,w.status,w.created_at,
    u.name AS owner_name,u.email AS owner_email,u.active AS owner_active
    FROM workspaces w JOIN users u ON u.id=w.owner_user_id WHERE w.id=?'.
    ($lock?' FOR UPDATE':''),[$id]);
  if(!$w)fail('Workspace não encontrado ou ainda não ativado.',404);
  if(!WorkspaceTenantMode::enabled()&&
     ($w['slug']!==WorkspaceFoundation::SLUG||$w['status']!==WorkspaceFoundation::STATUS))
   fail('Este workspace ainda não foi ativado.',409);
  if((int)$w['owner_active']!==1)fail('Proprietário indisponível.',409);
  return $w;
 }

 public static function directoryDetails(): array {
  self::guard();
  return ['billing_enabled'=>false,
    'billing_message'=>'Cobranças e pagamentos SaaS ainda não estão integrados ao Asaas.',
    'workspaces'=>DB::all('SELECT id,name,slug,status,owner_user_id FROM workspaces ORDER BY name')];
 }

 public static function members(array $p): array {
  self::guard();
  $id=integer($p['workspace_id']??0,1);
  $w=self::operationalWorkspace($id);
  $members=DB::all("SELECT u.id,u.name,u.email,u.active,u.twofa_enabled,
    wm.role,wm.status,wm.joined_at,wm.custom_role_id,r.name AS custom_role_name
    FROM workspace_members wm JOIN users u ON u.id=wm.user_id
    LEFT JOIN workspace_role_definitions r ON r.id=wm.custom_role_id AND r.workspace_id=wm.workspace_id
    WHERE wm.workspace_id=? ORDER BY wm.role='owner' DESC,u.name",[$id]);
  $plan=DB::one('SELECT p.name,p.team_limit,p.features_json,p.price_cents,p.billing_period
    FROM workspace_access_plans a JOIN platform_access_plans p ON p.id=a.plan_id
    WHERE a.workspace_id=?',[$id]);
  $planEffective=$plan?PlatformPlans::workspacePlan($id):null;
  $used=0;
  foreach($members as $m){
   if($m['status']==='active'&&(int)$m['active']===1&&
     (int)$m['id']!==(int)$w['owner_user_id']&&in_array($m['role'],['admin','staff'],true))$used++;
  }
  $contact=DB::all("SELECT `key`,value FROM workspace_settings
    WHERE workspace_id=? AND `key` IN ('master.company_email','master.company_phone')",[$id]);
  $fields=[];foreach($contact as $row)$fields[$row['key']]=$row['value'];
  return [
   'workspace'=>['id'=>(int)$w['id'],'name'=>$w['name'],'slug'=>$w['slug'],
     'status'=>$w['status'],'owner_name'=>$w['owner_name'],'owner_email'=>$w['owner_email'],
     'company_email'=>$fields['master.company_email']??'',
     'company_phone'=>$fields['master.company_phone']??''],
   'members'=>$members,
   'team_used'=>$used,'team_limit'=>$planEffective['team_limit']??null,
   'plan_team_limit'=>$plan['team_limit']??null,
   'team_limit_override'=>$planEffective['team_limit_override']??null,
   'plan_name'=>$plan['name']??'Não atribuído',
   'integrations'=>WorkspaceIntegrationStatus::forMaster($id),
   'billing'=>['enabled'=>false,'invoices'=>[],'payments'=>[],
     'status'=>'not_integrated']
  ];
 }

 public static function saveProfile(array $p): array {
  self::guard();Auth::rate('master-workspace-profile',20,3600);
  $id=integer($p['workspace_id']??0,1);
  $name=required($p,'name',190);
  $email=strtolower(trim((string)($p['company_email']??'')));
  $phone=trim((string)($p['company_phone']??''));
  if($email!==''&&(strlen($email)>190||!filter_var($email,FILTER_VALIDATE_EMAIL)))
   fail('E-mail comercial inválido.',422);
  if($phone!==''&&(!preg_match('/\A\+?[0-9 ()-]{7,25}\z/D',$phone)))
   fail('Telefone comercial inválido.',422);
  return DB::tx(static function()use($id,$name,$email,$phone):array {
   $w=self::operationalWorkspace($id,true);
   $old=DB::all("SELECT `key`,value FROM workspace_settings
     WHERE workspace_id=? AND `key` IN ('master.company_email','master.company_phone')",[$id]);
   $oldFields=[];foreach($old as $f)$oldFields[$f['key']]=$f['value'];
   DB::run('UPDATE workspaces SET name=?,updated_at=? WHERE id=?',[$name,now(),$id]);
   foreach(['master.company_email'=>$email,'master.company_phone'=>$phone] as $key=>$value){
    DB::run('INSERT INTO workspace_settings (workspace_id,`key`,value,updated_at)
      VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE value=VALUES(value),updated_at=VALUES(updated_at)',
      [$id,$key,$value,now()]);
   }
   self::log($id,'workspace.profile_updated',[
    'name_before'=>$w['name'],'name_after'=>$name,
    'company_email_changed'=>($oldFields['master.company_email']??'')!==$email,
    'company_phone_changed'=>($oldFields['master.company_phone']??'')!==$phone
   ]);
   return ['saved'=>true,'workspace_id'=>$id,'name'=>$name,
     'company_email'=>$email,'company_phone'=>$phone];
  });
 }

 public static function saveLimit(array $p): array {
  self::guard();Auth::rate('master-workspace-limit',15,3600);
  $workspaceId=integer($p['workspace_id']??0,1);
  $raw=trim((string)($p['team_limit_override']??''));
  $limit=$raw===''?null:integer($raw,1,100000);
  return DB::tx(static function()use($workspaceId,$limit):array {
   $ws=self::operationalWorkspace($workspaceId,true);
   $assigned=DB::one('SELECT p.id,p.name,p.team_limit FROM workspace_access_plans a
     JOIN platform_access_plans p ON p.id=a.plan_id AND p.active=1 WHERE a.workspace_id=?',
     [$workspaceId]);
   if(!$assigned)fail('Atribua primeiro um plano ativo ao workspace.',409);
   $used=(int)(DB::one("SELECT COUNT(*) n FROM workspace_members wm
     JOIN users u ON u.id=wm.user_id WHERE wm.workspace_id=? AND wm.status='active'
     AND wm.role IN ('admin','staff') AND u.active=1 AND u.id<>?",[$workspaceId,(int)$ws['owner_user_id']])['n']??0);
   $effective=$limit??($assigned['team_limit']===null?null:(int)$assigned['team_limit']);
   if($effective!==null&&$used>$effective)
    fail('Não é possível aplicar limite inferior à equipe ativa.',409);
   DB::run("INSERT INTO workspace_settings (workspace_id,`key`,value,updated_at)
      VALUES (?,'master.team_limit_override',?,?)
      ON DUPLICATE KEY UPDATE value=VALUES(value),updated_at=VALUES(updated_at)",
      [$workspaceId,$limit===null?'':(string)$limit,now()]);
   self::log($workspaceId,'workspace.team_limit_override',[
     'override'=>$limit,'plan_limit'=>$assigned['team_limit'],'effective_limit'=>$effective
   ]);
   return ['saved'=>true,'team_limit_override'=>$limit,'team_limit'=>$effective,
     'team_used'=>$used];
  });
 }

 public static function adminAccess(array $p): array {
  self::guard();Auth::rate('master-admin-status',10,3600);
  $workspaceId=integer($p['workspace_id']??0,1);
  $userId=integer($p['user_id']??0,1);
  $active=(bool)($p['active']??false);
  $reason=required($p,'reason',190);
  if(mb_strlen($reason)<12)fail('Informe um motivo com pelo menos 12 caracteres.',422);
  $password=(string)($p['master_password']??'');
  $master=DB::one('SELECT password_hash FROM users WHERE id=? AND active=1',[(int)Auth::$actor['id']]);
  if(!$master||!password_verify($password,(string)$master['password_hash']))
   fail('Senha do ADM Master incorreta.',403);
  return DB::tx(static function()use($workspaceId,$userId,$active,$reason):array {
   $ws=self::operationalWorkspace($workspaceId,true);
   if((int)$ws['owner_user_id']===$userId)
    fail('Não é permitido suspender o proprietário do workspace.',409);
   $member=DB::one("SELECT u.id,u.role AS user_role,u.active AS user_active,wm.role,wm.status
     FROM workspace_members wm JOIN users u ON u.id=wm.user_id
     WHERE wm.workspace_id=? AND wm.user_id=? FOR UPDATE",[$workspaceId,$userId]);
   if(!$member||$member['role']!=='admin'||$member['user_role']!=='admin')
    fail('Este usuário não é administrador adicional deste workspace.',404);
   // A reserved future owner cannot be disabled while provisioning is pending.
   if(DB::one("SELECT 1 FROM INFORMATION_SCHEMA.TABLES
      WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='platform_tenant_provisioning'")&&
      DB::one("SELECT 1 FROM platform_tenant_provisioning
      WHERE prospective_owner_id=? AND status='pending'",[$userId]))
    fail('Administrador reservado para um novo workspace em preparação.',409);
   if(DB::one("SELECT 1 FROM platform_master_identities
      WHERE user_id=? AND status='verified'",[$userId]))
    fail('Identidade Master não pode ser modificada como administrador da empresa.',403);
   if($active)PlatformPlans::enforceTeamLimit($workspaceId,$userId,'admin',true);
   DB::run('UPDATE users SET active=? WHERE id=?',[$active?1:0,$userId]);
   DB::run('UPDATE workspace_members SET status=? WHERE user_id=? AND workspace_id=?',
     [$active?'active':'inactive',$userId,$workspaceId]);
   self::log($workspaceId,'workspace.admin_access_changed',[
     'user_id'=>$userId,'active_before'=>(int)$member['user_active'],
     'active_after'=>$active,'reason'=>$reason
   ]);
   return ['updated'=>true,'user_id'=>$userId,'active'=>$active];
  });
 }

 private static function log(int $workspaceId,string $action,array $details): void {
  DB::insert('platform_access_log',[
   'actor_user_id'=>(int)Auth::$actor['id'],'action'=>$action,
   'workspace_id'=>$workspaceId,'details_json'=>json($details),'created_at'=>now()
  ]);
 }
}
