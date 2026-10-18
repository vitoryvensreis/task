<?php
declare(strict_types=1);

/**
 * Platform subscriptions: metadata and feature entitlements only.
 * Payments and new tenants are intentionally NOT enabled here.
 */
final class PlatformPlans {
 private const FEATURES=['tasks','automations','contacts'];

 private static function ready(): bool {
  return (int)(DB::one("SELECT COUNT(*) n FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('platform_access_plans','workspace_access_plans','platform_access_log')")['n']??0)===3
   &&(string)(DB::one("SELECT value FROM settings WHERE `key`='platform_access_v1'")['value']??'')==='1';
 }

 private static function assertReady(): void {
  if(!self::ready())fail('Planos ainda não foram preparados com backup validado.',503);
 }

 private static function master(): void {
  Auth::load();if(Auth::$origin!=='web'||!MasterSupport::isActor())
   fail('Ação reservada ao ADM Master.',403);
  self::assertReady();
 }

 private static function features(mixed $raw): array {
  if(!is_array($raw))fail('Selecione os módulos do plano.',422);
  $out=[];foreach(self::FEATURES as $feature)$out[$feature]=!empty($raw[$feature]);
  return $out;
 }

 public static function all(): array {
  self::master();
  return DB::all('SELECT p.*,COUNT(a.workspace_id) AS assigned_workspaces
     FROM platform_access_plans p LEFT JOIN workspace_access_plans a ON a.plan_id=p.id
     GROUP BY p.id ORDER BY p.id DESC');
 }

 public static function publicList(): array {
  if(!self::ready())return [];
  return DB::all('SELECT id,name,code,price_cents,billing_period,features_json,team_limit
    FROM platform_access_plans WHERE active=1 AND public_visible=1 ORDER BY price_cents,id');
 }

 public static function save(array $p): array {
  self::master();Auth::rate('platform-plan-save',25,3600);
  $id=integer($p['id']??0,0);
  $name=required($p,'name',120);
  $code=strtolower(trim((string)($p['code']??'')));
  if(!preg_match('/\A[a-z0-9][a-z0-9-]{2,99}\z/D',$code))fail('Código do plano inválido.',422);
  $features=self::features($p['features']??[]);
  $limit=!empty($p['unlimited_team'])?null:integer($p['team_limit']??0,1,100000);
  $row=[
   'name'=>$name,'code'=>$code,
   'price_cents'=>integer($p['price_cents']??0,0,1000000000),
   'billing_period'=>enumv($p['billing_period']??'monthly',['monthly','yearly'],'periodicidade'),
   'features_json'=>json($features),'team_limit'=>$limit,
   'public_visible'=>(int)(bool)($p['public_visible']??0),
   'active'=>(int)(bool)($p['active']??1),
   'updated_at'=>now()
  ];
  return DB::tx(static function()use($id,$row):array {
   $old=$id?DB::one('SELECT * FROM platform_access_plans WHERE id=? FOR UPDATE',[$id]):null;
   if($id&&!$old)fail('Plano não encontrado.',404);
   if($old&&$old['code']==='legacy-unlimited'){
    if($row['code']!=='legacy-unlimited'||$row['team_limit']!==null||$row['active']!==1||$row['public_visible']!==0)
     fail('O plano legado precisa permanecer ilimitado, privado e ativo.',409);
    if(decode((string)$row['features_json'])!==['tasks'=>true,'automations'=>true,'contacts'=>true])
     fail('O plano legado deve manter todos os módulos ativos.',409);
   }
   if($old){
    $assigned=DB::all('SELECT workspace_id FROM workspace_access_plans WHERE plan_id=?',[$id]);
    if($assigned&&$row['active']!==1)fail('Não é possível desativar um plano atribuído a algum workspace.',409);
    if($row['team_limit']!==null)foreach($assigned as $a){
     $count=(int)(DB::one("SELECT COUNT(*) n FROM workspace_members wm
       JOIN users u ON u.id=wm.user_id JOIN workspaces w ON w.id=wm.workspace_id
       WHERE wm.workspace_id=? AND wm.role IN ('staff','admin') AND wm.status='active'
       AND u.active=1 AND u.id<>w.owner_user_id",[(int)$a['workspace_id']])['n']??0);
     if($count>$row['team_limit'])fail('O limite proposto é inferior à equipe de um workspace que utiliza este plano.',409);
    }
   }
   if($old)DB::update('platform_access_plans',$id,$row);
   else $id=DB::insert('platform_access_plans',$row+['created_at'=>now()]);
   self::audit('plan.saved',null,['plan_id'=>$id,'name'=>$row['name'],'features'=>decode($row['features_json']),
    'team_limit'=>$row['team_limit'],'public_visible'=>$row['public_visible']]);
   return ['saved'=>true,'id'=>$id];
  });
 }

 public static function assign(array $p): array {
  self::master();Auth::rate('platform-plan-assign',15,3600);
  $workspaceId=integer($p['workspace_id']??0,1);
  $planId=integer($p['plan_id']??0,1);
  return DB::tx(static function()use($workspaceId,$planId):array {
   $ws=DB::one('SELECT id,slug,status FROM workspaces WHERE id=? FOR UPDATE',[$workspaceId]);
   if(!$ws||$ws['slug']!==WorkspaceFoundation::SLUG||
      (int)(DB::one('SELECT COUNT(*) n FROM workspaces')['n']??0)!==1)
    fail('Ativação multiempresa ainda não homologada.',403);
   $plan=DB::one('SELECT id,active,team_limit FROM platform_access_plans WHERE id=?',[$planId]);
   if(!$plan||(int)$plan['active']!==1)fail('Plano inativo ou não encontrado.',404);
   $teamLimit=$plan['team_limit']===null?null:(int)$plan['team_limit'];
   if($teamLimit!==null){
    $current=(int)(DB::one("SELECT COUNT(*) n FROM workspace_members wm
      JOIN users u ON u.id=wm.user_id
      WHERE wm.workspace_id=? AND wm.role IN ('admin','staff') AND wm.status='active' AND u.active=1 AND u.id<>(SELECT owner_user_id FROM workspaces WHERE id=?)",[$workspaceId,$workspaceId])['n']??0);
    if($current>$teamLimit)fail('Este plano permite menos usuários do que a equipe atual. Reduza a equipe antes de trocar.',409);
   }
   DB::run('INSERT INTO workspace_access_plans (workspace_id,plan_id,assigned_by,assigned_at)
      VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE plan_id=VALUES(plan_id),assigned_by=VALUES(assigned_by),assigned_at=VALUES(assigned_at)',
      [$workspaceId,$planId,(int)Auth::$actor['id'],now()]);
   self::audit('workspace.plan_assigned',$workspaceId,['plan_id'=>$planId]);
   return ['assigned'=>true,'workspace_id'=>$workspaceId,'plan_id'=>$planId];
  });
 }

 private static function audit(string $action,?int $workspaceId,array $details): void {
  DB::insert('platform_access_log',[
    'actor_user_id'=>(int)Auth::$actor['id'],'action'=>$action,'workspace_id'=>$workspaceId,
    'details_json'=>json($details),'created_at'=>now()
  ]);
 }

 public static function workspacePlan(?int $id=null): array {
  if(!WorkspaceContext::legacyCiFixture())self::assertReady();
  $workspaceId=$id??Auth::workspaceId();
  if(WorkspaceContext::legacyCiFixture())return [
   'name'=>'Legacy CI','features'=>['tasks'=>true,'automations'=>true,'contacts'=>true],'team_limit'=>null];
  $plan=DB::one('SELECT p.id,p.name,p.features_json,p.team_limit,p.price_cents,p.billing_period
      FROM workspace_access_plans a JOIN platform_access_plans p ON p.id=a.plan_id
      WHERE a.workspace_id=? AND p.active=1 LIMIT 1',[$workspaceId]);
  if(!$plan)fail('O ADM Master precisa atribuir um plano ativo a este workspace.',403);
  $plan['features']=decode((string)$plan['features_json'],[]);
  unset($plan['features_json']);return $plan;
 }

 public static function requireModule(string $feature): void {
  if(!in_array($feature,self::FEATURES,true))throw new InvalidArgumentException('Module not found.');
  if(WorkspaceContext::legacyCiFixture()||MasterSupport::isSupport())return;
  $plan=self::workspacePlan();
  if(empty($plan['features'][$feature]))
   fail('Este módulo não está incluído no plano de acesso do workspace.',403);
 }

 /** Call only from the same transaction that creates/reactivates users. */
 public static function enforceTeamLimit(int $workspaceId,int $userId,string $role,bool $active): void {
  if(WorkspaceContext::legacyCiFixture()||!$active||!in_array($role,['staff','admin'],true))return;
  $plan=self::workspacePlan($workspaceId);
  if($plan['team_limit']===null)return;
  $owner=DB::one('SELECT owner_user_id FROM workspaces WHERE id=? FOR UPDATE',[$workspaceId]);
  if(!$owner)fail('Workspace não encontrado.',404);
  if($userId>0&&$userId===(int)$owner['owner_user_id'])return;
  $used=(int)(DB::one("SELECT COUNT(*) n FROM workspace_members wm
    JOIN users u ON u.id=wm.user_id
    WHERE wm.workspace_id=? AND wm.role IN ('staff','admin') AND wm.status='active'
      AND u.active=1 AND u.id<>? AND u.id<>?",[$workspaceId,$userId,(int)$owner['owner_user_id']])['n']??0);
  if($used>=(int)$plan['team_limit'])
   fail('Limite de '.(int)$plan['team_limit'].' usuário(s) de equipe atingido no plano atual.',409);
 }
}
