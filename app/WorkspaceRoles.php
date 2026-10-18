<?php
declare(strict_types=1);

/**
 * Custom roles are limited to staff's workspace data capabilities.
 * They cannot grant platform/tenant-admin access or bypass subscriptions.
 */
final class WorkspaceRoles {
 public const ALLOWED=[
  'dashboard.view'=>'Ver dashboard',
  'tasks.read'=>'Ver tarefas',
  'tasks.create'=>'Criar tarefas',
  'tasks.edit'=>'Editar tarefas',
  'tasks.move'=>'Mover tarefas no Kanban',
  'tasks.delete'=>'Excluir tarefas',
  'tasks.comment'=>'Comentar em tarefas',
  'tasks.files'=>'Gerenciar arquivos das tarefas',
  'reports.read'=>'Ver relatórios'
 ];

 public static function catalog(): array {return self::ALLOWED;}

 /** Defense-in-depth for legacy endpoints that check staff role but not every ability. */
 public static function requireForAction(string $action,array $data=[]): void {
  Auth::load();
  if((Auth::$user['role']??'')!=='staff')return;
  if($action==='demands.save'){
   Auth::requireAbility((int)($data['id']??0)>0?'tasks.edit':'tasks.create');return;
  }
  $map=[
   'dashboard'=>'dashboard.view',
   'demands.list'=>'tasks.read','demands.get'=>'tasks.read',
   'demands.stage'=>'tasks.move',
   'demands.reorder'=>'tasks.move','demands.bulk'=>'tasks.edit',
   'demands.delete'=>'tasks.delete','demands.comment'=>'tasks.comment',
   'demands.checklist'=>'tasks.edit','demands.time'=>'tasks.edit',
   'demands.change'=>'tasks.edit','demands.quote'=>'tasks.edit',
   'files.add'=>'tasks.files','files.upload_start'=>'tasks.files',
   'files.upload_status'=>'tasks.files','files.delete'=>'tasks.files',
   'files.review'=>'tasks.files',
   'report'=>'reports.read','report.csv'=>'reports.read'
  ];
  if(isset($map[$action]))Auth::requireAbility($map[$action]);
 }


 private static function checkAdmin(): void {
  Auth::load();Auth::admin();
  if(Auth::isPreview())fail('Funções não podem ser editadas durante visualização do cliente.',403);
  if(Auth::workspaceId()<1)fail('Workspace não identificado.',403);
 }

 public static function all(): array {
  self::checkAdmin();
  $rows=DB::all('SELECT r.id,r.name,r.description,r.abilities_json,r.active,r.created_at,r.updated_at,
   (SELECT COUNT(*) FROM workspace_members wm WHERE wm.workspace_id=r.workspace_id AND wm.custom_role_id=r.id AND wm.status=?) AS member_count
   FROM workspace_role_definitions r WHERE r.workspace_id=? ORDER BY r.active DESC,r.name',
   ['active',Auth::workspaceId()]);
  foreach($rows as &$role){$role['abilities']=decode((string)$role['abilities_json'],[]);unset($role['abilities_json']);}
  unset($role);
  return ['catalog'=>self::catalog(),'roles'=>$rows];
 }

 public static function save(array $input): array {
  self::checkAdmin();Auth::rate('custom-roles',20,3600);
  $id=integer($input['id']??0,0);
  $name=required($input,'name',80);
  $description=mb_substr(trim((string)($input['description']??'')),0,255);
  $active=(int)(bool)($input['active']??1);
  $raw=$input['abilities']??[];
  if(!is_array($raw))fail('Permissões inválidas.',422);
  $abilities=array_values(array_unique(array_filter(array_map('strval',$raw),fn($v)=>isset(self::ALLOWED[$v]))));
  if(count($abilities)!==count(array_unique($raw)))
   fail('Uma das permissões solicitadas não pode ser concedida à equipe.',403);
  if(!$abilities)fail('Selecione pelo menos uma permissão.',422);
  if(in_array('tasks.create',$abilities,true)||in_array('tasks.edit',$abilities,true)||
   in_array('tasks.move',$abilities,true)||in_array('tasks.delete',$abilities,true)||
   in_array('tasks.comment',$abilities,true)||in_array('tasks.files',$abilities,true)){
    if(!in_array('tasks.read',$abilities,true))$abilities[]='tasks.read';
  }
  $ws=Auth::workspaceId();
  return DB::tx(static function()use($id,$name,$description,$active,$abilities,$ws):array {
   $old=$id?DB::one('SELECT id,active FROM workspace_role_definitions WHERE id=? AND workspace_id=? FOR UPDATE',[$id,$ws]):null;
   if($id&&!$old)fail('Função não encontrada.',404);
   $row=['name'=>$name,'description'=>$description,'abilities_json'=>json($abilities),'active'=>$active,'updated_at'=>now()];
   if($id)DB::update('workspace_role_definitions',$id,$row);
   else $id=DB::insert('workspace_role_definitions',$row+['workspace_id'=>$ws,'created_by'=>(int)Auth::$actor['id'],'created_at'=>now()]);
   audit('workspace_role',$id,'saved',$old,['name'=>$name,'active'=>$active,'abilities'=>$abilities]);
   return ['saved'=>true,'id'=>$id];
  });
 }

 public static function assignToUser(int $ws,int $userId,string $type,mixed $roleId): void {
  if(WorkspaceContext::legacyCiFixture())return;
  if(!DB::conn()->inTransaction())throw new LogicException('Role assignment must use user write transaction.');
  $rid=integer($roleId??0,0);
  if($type!=='staff'){
   if($rid!==0)fail('Funções personalizadas são exclusivas da equipe.',422);
   DB::run('UPDATE workspace_members SET custom_role_id=NULL WHERE workspace_id=? AND user_id=?',[$ws,$userId]);return;
  }
  if($rid>0&&!DB::one('SELECT 1 FROM workspace_role_definitions WHERE id=? AND workspace_id=? AND active=1',[$rid,$ws]))
   fail('Função não pertence a este workspace ou está inativa.',404);
  DB::run('UPDATE workspace_members SET custom_role_id=? WHERE workspace_id=? AND user_id=?',[$rid?:null,$ws,$userId]);
 }

 /** null means use the legacy default capabilities of this staff member. */
 public static function effectiveStaffCapabilities(int $ws,int $userId): ?array {
  if(WorkspaceContext::legacyCiFixture())return null;
  $row=DB::one('SELECT wm.custom_role_id,r.abilities_json,r.active
    FROM workspace_members wm
    LEFT JOIN workspace_role_definitions r ON r.id=wm.custom_role_id AND r.workspace_id=wm.workspace_id
    WHERE wm.workspace_id=? AND wm.user_id=? AND wm.role=? AND wm.status=? LIMIT 1',
    [$ws,$userId,'staff','active']);
  if(!$row)return []; // Revoked memberships deny capabilities.
  if(!$row['custom_role_id'])return null;
  if((int)($row['active']??0)!==1)return [];
  $fromDb=decode((string)$row['abilities_json'],[]);
  return array_values(array_intersect(is_array($fromDb)?$fromDb:[],array_keys(self::ALLOWED)));
 }
}
