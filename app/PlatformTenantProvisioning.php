<?php
declare(strict_types=1);

/**
 * Staged tenant provisioning only. No extra row is added to workspaces,
 * no member is moved, and no live tenant is activated in this release.
 */
final class PlatformTenantProvisioning {
 public const OWNER_EMAIL='gabriella-diasl@hotmail.com';
 public const SLUG='gabriella';
 public const NAME='Workspace Gabriella';

 private static function exists(): bool {
  return (bool)DB::one("SELECT 1 FROM INFORMATION_SCHEMA.TABLES
   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='platform_tenant_provisioning' LIMIT 1");
 }

 public static function provision(): array {
  if(PHP_SAPI!=='cli')throw new RuntimeException('Provisioning is CLI-only.');
  if(DB::conn()->inTransaction())throw new RuntimeException('Provisioning DDL cannot run inside a transaction.');
  if(WorkspaceTenantMode::enabled()){
   $row=DB::one('SELECT w.id,w.owner_user_id FROM workspaces w WHERE w.slug=?',[self::SLUG]);
   if(!$row||!WorkspaceFoundationGuard::valid())throw new RuntimeException('Activated Gabriella workspace invalid.');
   return ['activated'=>true,'workspace_id'=>(int)$row['id'],'real_workspace_created'=>true];
  }
  if(!WorkspaceFoundationGuard::valid())throw new RuntimeException('Existing workspace schema is not valid.');
  $lock='taskesc-gabriella-'.(string)(DB::one('SELECT SHA1(DATABASE()) h')['h']??'');
  if((int)(DB::one('SELECT GET_LOCK(?,10) acquired',[$lock])['acquired']??0)!==1)
   throw new RuntimeException('Gabriella provisioning is already running.');
  try {
   $original=DB::one("SELECT w.id,w.owner_user_id,w.status,u.email AS owner_email
     FROM workspaces w JOIN users u ON u.id=w.owner_user_id WHERE w.slug=?",
     [WorkspaceFoundation::SLUG]);
   if((int)(DB::one('SELECT COUNT(*) n FROM workspaces')['n']??0)!==1||
      !$original||$original['status']!==WorkspaceFoundation::STATUS||
      strtolower((string)$original['owner_email'])!==WorkspaceFoundation::OWNER_EMAIL)
    throw new RuntimeException('Original Vitor workspace not verified.');
   $candidate=DB::one("SELECT u.id,u.name,u.email,u.role,u.active,wm.role member_role,wm.status member_status
     FROM users u JOIN workspace_members wm ON wm.user_id=u.id AND wm.workspace_id=?
     WHERE LOWER(u.email)=?",[(int)$original['id'],self::OWNER_EMAIL]);
   if(!$candidate||$candidate['role']!=='admin'||(int)$candidate['active']!==1||
      $candidate['member_role']!=='admin'||$candidate['member_status']!=='active'||
      (int)(DB::one("SELECT COUNT(*) n FROM workspace_members WHERE user_id=?",[(int)$candidate['id']])['n']??0)!==1)
    throw new RuntimeException('Confirmed Gabriella account is not a single active administrator.');
   if(DB::one("SELECT 1 FROM platform_master_identities
     WHERE user_id=? AND status='verified' LIMIT 1",[(int)$candidate['id']]))
    throw new RuntimeException('Candidate cannot be a verified platform Master.');
   if(!self::exists()){
    $sql=file_get_contents(ROOT.'/database/migrations/036_platform_tenant_provisioning.sql');
    if($sql===false)throw new RuntimeException('Provisioning schema not found.');
    DB::run($sql);
   }

   // Only configuration metadata lives here. Business rows remain exclusively
   // in Vitor's active workspace until the isolated cutover.
   $config=[
    'modules'=>['tasks'=>true,'automations'=>true,'contacts'=>true],
    'kanban_columns'=>['A fazer','Em andamento','Em revisão','Concluído'],
    'start_empty'=>true,
    'copy_clients'=>false,'copy_demands'=>false,'copy_finance'=>false,
    'copy_files'=>false,'copy_google_accounts'=>false,
    'plan_assignment'=>'master_required_at_activation'
   ];
   return DB::tx(static function()use($original,$candidate,$config):array {
    $existing=DB::one('SELECT * FROM platform_tenant_provisioning
      WHERE prospective_owner_id=? FOR UPDATE',[(int)$candidate['id']]);
    if($existing){
     if($existing['proposed_slug']!==self::SLUG||$existing['status']!=='pending'||
        (int)$existing['source_workspace_id']!==(int)$original['id'])
      throw new RuntimeException('A conflicting or activated provisioning record already exists.');
     return ['pending'=>true,'already_prepared'=>true,'provisioning_id'=>(int)$existing['id'],
       'proposed_name'=>self::NAME,'real_workspace_created'=>false,'owner_email'=>self::OWNER_EMAIL];
    }
    if(DB::one('SELECT 1 FROM platform_tenant_provisioning WHERE proposed_slug=? LIMIT 1',[self::SLUG])||
       DB::one('SELECT 1 FROM workspaces WHERE slug=? LIMIT 1',[self::SLUG]))
     throw new RuntimeException('Gabriella slug already reserved.');
    $id=DB::insert('platform_tenant_provisioning',[
     'source_workspace_id'=>(int)$original['id'],
     'prospective_owner_id'=>(int)$candidate['id'],
     'proposed_name'=>self::NAME,'proposed_slug'=>self::SLUG,
     'initial_config_json'=>json($config),'status'=>'pending',
     'created_at'=>now(),'updated_at'=>now()
    ]);
    return ['pending'=>true,'already_prepared'=>false,'provisioning_id'=>$id,
      'proposed_name'=>self::NAME,'real_workspace_created'=>false,'owner_email'=>self::OWNER_EMAIL];
   });
  }finally{DB::run('SELECT RELEASE_LOCK(?)',[$lock]);}
 }

 /** Master-only metadata preview. Pending rows are never executable workspaces. */
 public static function pendingDirectory(string $query=''): array {
  if(!self::exists())return [];
  $where=" WHERE p.status='pending'";
  $args=[];
  if($query!==''){
   $where.=' AND (p.proposed_name LIKE ? OR u.name LIKE ? OR u.email LIKE ?)';
   $like='%'.$query.'%';$args=[$like,$like,$like];
  }
  $rows=DB::all("SELECT p.id,p.proposed_name,p.proposed_slug,p.initial_config_json,
     p.created_at,u.name owner_name,u.email owner_email,u.id owner_user_id
     FROM platform_tenant_provisioning p JOIN users u ON u.id=p.prospective_owner_id
     ".$where.' ORDER BY p.id DESC LIMIT 20',$args);
  $out=[];
  foreach($rows as $r){
   $out[]=[
    'id'=>'pending-'.(int)$r['id'],
    'name'=>(string)$r['proposed_name'],
    'slug'=>(string)$r['proposed_slug'],
    'status'=>'pending',
    'created_at'=>$r['created_at'],
    'owner_user_id'=>(int)$r['owner_user_id'],
    'owner_name'=>$r['owner_name'],'owner_email'=>$r['owner_email'],
    'owner_twofa'=>null,
    'plan_id'=>null,'plan_name'=>'Aguardando plano',
    'price_cents'=>null,'billing_period'=>null,'team_limit'=>null,
    'active_users'=>0,'team_users'=>0,'admins'=>0,'support_sessions'=>0,
    'provisioning'=>true,'initial_config'=>decode((string)$r['initial_config_json'],[])
   ];
  }
  return $out;
 }
}
