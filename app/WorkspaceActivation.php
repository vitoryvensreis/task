<?php
declare(strict_types=1);

/**
 * The single, explicitly approved Vitor/Gabriella cutover.
 * Business data is never copied. Resumable after a partial DROP DEFAULT.
 */
final class WorkspaceActivation {
 public const IN_PROGRESS='isolating';
 public const READY='ready';

 private static function state(): string {
  return (string)(DB::one('SELECT value FROM settings WHERE `key`=?',
    [WorkspaceTenantMode::MARKER])['value']??'');
 }
 private static function schema(string $table): ?array {
  return DB::one('SELECT COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?',[$table,'workspace_id']);
 }
 private static function candidate(int $original): array {
  $r=DB::one('SELECT u.id,u.email,u.role,u.active,p.id provisioning_id,
     p.status provisioning_status,p.source_workspace_id,p.proposed_slug
     FROM users u JOIN platform_tenant_provisioning p ON p.prospective_owner_id=u.id
     WHERE LOWER(u.email)=? AND p.proposed_slug=?',
     [PlatformTenantProvisioning::OWNER_EMAIL,PlatformTenantProvisioning::SLUG]);
  if(!$r||$r['role']!=='admin'||(int)$r['active']!==1||
     (int)$r['source_workspace_id']!==$original||
     $r['provisioning_status']!=='pending')
   throw new RuntimeException('Gabriella owner or pending identity not verified.');
  $id=(int)$r['id'];
  if((int)(DB::one('SELECT COUNT(*) n FROM workspace_members WHERE user_id=?',[$id])['n']??0)!==1)
   throw new RuntimeException('Gabriella must have one existing legacy membership.');
  $member=DB::one('SELECT role,status,workspace_id FROM workspace_members WHERE user_id=?',[$id]);
  if(!$member||$member['role']!=='admin'||$member['status']!=='active'||
     (int)$member['workspace_id']!==$original)
   throw new RuntimeException('Gabriella legacy membership unexpected.');
  if(DB::one("SELECT 1 FROM platform_master_identities WHERE user_id=? AND status='verified'",[$id]))
   throw new RuntimeException('Verified platform Master cannot become a tenant owner.');
  $ownership=[
   'demands'=>DB::one('SELECT COUNT(*) n FROM demands WHERE workspace_id=? AND (assigned_to=? OR created_by=?)',[$original,$id,$id]),
   'client_memberships'=>DB::one('SELECT COUNT(*) n FROM user_clients WHERE workspace_id=? AND user_id=?',[$original,$id]),
   'google_accounts'=>DB::one('SELECT COUNT(*) n FROM google_accounts WHERE workspace_id=? AND user_id=?',[$original,$id])
  ];
  foreach($ownership as $kind=>$row)
   if((int)($row['n']??0)>0)throw new RuntimeException('Gabriella has historical business ownership: '.$kind);
  return $r;
 }
 private static function original(): array {
  $row=DB::one('SELECT w.id,w.owner_user_id,w.status,u.email
    FROM workspaces w JOIN users u ON u.id=w.owner_user_id WHERE w.slug=?',
    [WorkspaceFoundation::SLUG]);
  if(!$row||$row['status']!==WorkspaceFoundation::STATUS||
     strtolower((string)$row['email'])!==WorkspaceFoundation::OWNER_EMAIL)
   throw new RuntimeException('Original Vitor workspace was not preserved.');
  return $row;
 }

 public static function apply(): array {
  if(PHP_SAPI!=='cli'||DB::conn()->inTransaction())
   throw new RuntimeException('Multiworkspace activation requires isolated CLI.');
  $lock='taskesc-activate-'.(string)(DB::one('SELECT SHA1(DATABASE()) hash')['hash']??'');
  if((int)(DB::one('SELECT GET_LOCK(?,20) acquired',[$lock])['acquired']??0)!==1)
   throw new RuntimeException('Workspace activation already in progress.');
  try {
   $state=self::state();
   if($state===self::READY){
    if(!WorkspaceFoundationGuard::valid())
     throw new RuntimeException('Activated schema consistency failed.');
    $new=DB::one('SELECT id FROM workspaces WHERE slug=?',[PlatformTenantProvisioning::SLUG]);
    if(!$new)throw new RuntimeException('Activated workspace missing.');
    return ['activated'=>true,'already_active'=>true,'workspace_id'=>(int)$new['id']];
   }
   if(!in_array($state,['',self::IN_PROGRESS],true))
    throw new RuntimeException('Unsupported multiworkspace cutover state.');
   if((int)(DB::one('SELECT COUNT(*) n FROM workspaces')['n']??0)!==1)
    throw new RuntimeException('Unexpected workspaces during first cutover.');
   $old=self::original();$oldId=(int)$old['id'];
   if($state===''){
    if(!WorkspaceFoundationGuard::valid())
     throw new RuntimeException('Legacy workspace schema not ready.');
    if((string)(DB::one('SELECT value FROM settings WHERE `key`=?',
         [WorkspaceDatabaseStage1::MARKER])['value']??'')!=='1')
     throw new RuntimeException('Database stage1 must be complete.');
   }
   $owner=self::candidate($oldId);
   $plan=DB::one("SELECT id FROM platform_access_plans WHERE code='legacy-unlimited' AND active=1 AND team_limit IS NULL");
   if(!$plan||!DB::one('SELECT 1 FROM workspace_access_plans WHERE workspace_id=? AND plan_id=?',
      [$oldId,(int)$plan['id']]))
    throw new RuntimeException('Legacy unlimited plan was not confirmed.');
   // Guard business ownership and schema for all historical tables before
   // removing a single legacy default.
   foreach(WorkspaceFoundation::DATA_TABLES as $table=>$key){
    $col=self::schema($table);
    if(!$col||$col['IS_NULLABLE']!=='NO'||!str_contains(strtolower((string)$col['COLUMN_TYPE']),'bigint')
       ||!str_contains(strtolower((string)$col['COLUMN_TYPE']),'unsigned'))
     throw new RuntimeException('Workspace column mismatch for '.$table);
    if($col['COLUMN_DEFAULT']!==null&&(int)$col['COLUMN_DEFAULT']!==$oldId)
     throw new RuntimeException('Unsafe workspace default for '.$table);
    if((int)(DB::one('SELECT COUNT(*) n FROM `'.$table.'` WHERE workspace_id<>?',[$oldId])['n']??0)>0)
     throw new RuntimeException('Unexpected historical workspace ownership: '.$table);
   }
   // The isolated state enables explicit workspace data writes for the
   // original tenant during metadata-only ALTERs. On interruption, rerunning
   // this script resumes safely before any additional workspace exists.
   if($state===''){
    DB::run('INSERT INTO settings (`key`,value) VALUES (?,?)
      ON DUPLICATE KEY UPDATE value=VALUES(value)',
      [WorkspaceTenantMode::MARKER,self::IN_PROGRESS]);
   }
   $count=0;
   foreach(WorkspaceFoundation::DATA_TABLES as $table=>$key){
    if(self::schema($table)['COLUMN_DEFAULT']!==null){
     DB::run('ALTER TABLE `'.$table.'` ALTER COLUMN workspace_id DROP DEFAULT');
     $count++;
    }
   }
   $id=DB::tx(static function()use($oldId,$owner,$plan):int {
    $gabId=DB::insert('workspaces',[
      'slug'=>PlatformTenantProvisioning::SLUG,
      'name'=>PlatformTenantProvisioning::NAME,
      'owner_user_id'=>(int)$owner['id'],
      'status'=>'active','created_at'=>now(),'updated_at'=>now()
    ]);
    DB::run('DELETE FROM workspace_members WHERE workspace_id=? AND user_id=?',
      [$oldId,(int)$owner['id']]);
    DB::run('INSERT INTO workspace_members (workspace_id,user_id,role,status,joined_at)
      VALUES (?,?,?,?,?)',[$gabId,(int)$owner['id'],'owner','active',now()]);
    DB::run('INSERT INTO workspace_access_plans (workspace_id,plan_id,assigned_by,assigned_at)
      VALUES (?,?,NULL,?)',[$gabId,(int)$plan['id'],now()]);
    DB::run('INSERT INTO workspace_settings (workspace_id,`key`,value,updated_at)
      VALUES (?,?,?,?)',
      [$gabId,'brand_name','TaskEsc — Gabriella',now()]);
    // Workflow *definitions* are safe to provision. Do not copy business
    // tasks, clients, contracts, file metadata or financial records.
    WorkspaceJobScope::forWorkspace($gabId,static function()use($oldId):void {
     $stages=DB::all('SELECT name,kind,color,position,active FROM stages
       WHERE workspace_id=? ORDER BY position,id',[$oldId]);
     if(!$stages)throw new RuntimeException('Original Kanban stages missing.');
     foreach($stages as $stage)DB::insert('stages',$stage);
     $categories=DB::all('SELECT name,fields_json,default_amount_cents,
       default_due_hours,default_revisions,default_priority,checklist_json,active
       FROM categories WHERE workspace_id=? ORDER BY id',[$oldId]);
     if(!$categories)throw new RuntimeException('Original service categories missing.');
     foreach($categories as $category)
      DB::insert('categories',$category+['default_assigned_to'=>null]);
    });
    DB::run('UPDATE platform_tenant_provisioning SET status=?,activated_workspace_id=?,
      activated_at=?,updated_at=? WHERE id=? AND status=?',
      ['activated',$gabId,now(),now(),(int)$owner['provisioning_id'],'pending']);
    DB::run('UPDATE settings SET value=? WHERE `key`=?',
      [self::READY,WorkspaceTenantMode::MARKER]);
    return $gabId;
   });
   if(!WorkspaceFoundationGuard::valid())
    throw new RuntimeException('Postactivation schema audit failed.');
   foreach(['clients','demands','projects','invoices','files','payments','contracts'] as $table)
    if((int)(DB::one('SELECT COUNT(*) n FROM `'.$table.'` WHERE workspace_id=?',[$id])['n']??0)>0)
     throw new RuntimeException('New tenant unexpectedly has historical business data.');
   return ['activated'=>true,'workspace_id'=>$id,
     'original_workspace_id'=>$oldId,'legacy_defaults_removed'=>$count,
     'plan'=>'legacy-unlimited','business_data_copied'=>false,
     'owner_email'=>PlatformTenantProvisioning::OWNER_EMAIL];
  }finally{
   DB::run('SELECT RELEASE_LOCK(?)',[$lock]);
  }
 }
}
