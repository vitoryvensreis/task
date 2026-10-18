<?php
declare(strict_types=1);

/**
 * Read-only preparation for splitting the original Escotilha workspace.
 *
 * All historical records remain attached to the existing Vitor workspace.
 * Gabriella will become the owner of an EMPTY second workspace only AFTER
 * the multi-workspace isolation gate is homologated and a fresh backup exists.
 *
 * This script never issues INSERT/UPDATE/DELETE/ALTER and has no --apply mode.
 *
 * php bin/plan-second-workspace.php
 * php bin/plan-second-workspace.php --gabriella-email=<existing-email>
 */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/app/bootstrap.php';

function secondWorkspaceOutput(array $data,int $exitCode): never {
 echo json($data).PHP_EOL;
 exit($exitCode);
}

try {
 $original=DB::one('SELECT id,slug,name,owner_user_id,status FROM workspaces WHERE slug=?',
   [WorkspaceFoundation::SLUG]);
 $vitor=DB::one('SELECT id,role,active FROM users WHERE LOWER(email)=?',
   [WorkspaceFoundation::OWNER_EMAIL]);
 if(!$original||!$vitor||
   (int)$original['owner_user_id']!==(int)$vitor['id']||
   $original['status']!==WorkspaceFoundation::STATUS||
   (int)($vitor['active']??0)!==1||$vitor['role']!=='admin')
  throw new RuntimeException('original_vitor_workspace_not_verified');
 $count=(int)(DB::one('SELECT COUNT(*) n FROM workspaces')['n']??0);
 if($count!==1)throw new RuntimeException('unexpected_existing_workspaces');

 $email=null;
 foreach(array_slice($argv,1) as $arg) {
  if(!str_starts_with($arg,'--gabriella-email='))throw new RuntimeException('unsupported_argument');
  if($email!==null)throw new RuntimeException('duplicate_email_argument');
  $email=strtolower(trim(substr($arg,strlen('--gabriella-email='))));
  if(!filter_var($email,FILTER_VALIDATE_EMAIL))throw new RuntimeException('invalid_candidate_email');
 }
 $sql='SELECT u.id,u.name,u.email,u.active,u.role,m.role member_role,m.status member_status
     FROM users u JOIN workspace_members m ON m.user_id=u.id AND m.workspace_id=?
     WHERE ';
 $args=[(int)$original['id']];
 if($email!==null){$sql.='LOWER(u.email)=?';$args[]=$email;}
 else {$sql.='LOWER(u.name) LIKE ?';$args[]='gabriella%';}
 $matches=DB::all($sql.' LIMIT 3',$args);
 if(count($matches)!==1)throw new RuntimeException('gabriella_administrator_not_uniquely_found');
 $gabriella=$matches[0];
 if((int)$gabriella['id']===(int)$vitor['id']||
    (int)$gabriella['active']!==1||
    $gabriella['role']!=='admin'||$gabriella['member_role']!=='admin'||
    $gabriella['member_status']!=='active')
  throw new RuntimeException('gabriella_must_be_active_legacy_admin');

 if(DB::one("SELECT 1 FROM platform_master_identities
   WHERE user_id=? AND status='verified' LIMIT 1",[(int)$gabriella['id']]))
  throw new RuntimeException('candidate_is_platform_master');
 $memberships=(int)(DB::one('SELECT COUNT(*) n FROM workspace_members WHERE user_id=?',
   [(int)$gabriella['id']])['n']??0);
 if($memberships!==1)throw new RuntimeException('candidate_already_has_multiple_workspaces');

 // No data reassignment: even historical rows authored/assigned to Gabriella
 // stay with Vitor. The pending assignment counts surface access implications.
 $ws=(int)$original['id'];
 $assigned=(int)(DB::one('SELECT COUNT(*) n FROM demands WHERE workspace_id=?
   AND assigned_to=? AND deleted_at IS NULL',[$ws,(int)$gabriella['id']])['n']??0);
 $created=(int)(DB::one('SELECT COUNT(*) n FROM demands WHERE workspace_id=?
   AND created_by=?',[$ws,(int)$gabriella['id']])['n']??0);
 $google=(int)(DB::one('SELECT COUNT(*) n FROM google_accounts WHERE workspace_id=?
   AND user_id=?',[$ws,(int)$gabriella['id']])['n']??0);
 $clientLinks=(int)(DB::one('SELECT COUNT(*) n FROM user_clients
   WHERE workspace_id=? AND user_id=?',[$ws,(int)$gabriella['id']])['n']??0);

 $issues=array_values(array_diff(WorkspaceFoundation::issues(true),
   ['unlinked_legacy_users']));
 $legacyDefaults=(int)(DB::one("SELECT COUNT(*) n FROM INFORMATION_SCHEMA.COLUMNS
   WHERE TABLE_SCHEMA=DATABASE() AND COLUMN_NAME='workspace_id'
    AND TABLE_NAME IN (".implode(',',array_fill(0,count(WorkspaceFoundation::DATA_TABLES),'?')).")
    AND COLUMN_DEFAULT IS NOT NULL",
   array_keys(WorkspaceFoundation::DATA_TABLES))['n']??0);

 secondWorkspaceOutput([
  'operation'=>'read_only_workspace_split_preflight',
  'current_workspace'=>[
   'id'=>$ws,'slug'=>WorkspaceFoundation::SLUG,
   'owner'=>'Vitor','data_action'=>'retain_all_existing_rows_unchanged'
  ],
  'candidate'=>[
   'owner'=>'Gabriella','unique_active_admin'=>true,
   'current_memberships'=>$memberships,
   'assigned_open_legacy_demands'=>$assigned,
   'authored_legacy_demands'=>$created,
   'legacy_connected_google_accounts'=>$google,
   'legacy_client_memberships'=>$clientLinks
  ],
  'new_workspace'=>[
   'owner'=>'Gabriella','created'=>false,'initially_empty'=>true,
   'clients'=>0,'demands'=>0,'files'=>0,'finance_records'=>0,
   'google_accounts'=>0
  ],
  'schema'=>[
   'version'=>(int)(DB::one("SELECT value FROM settings WHERE `key`='schema_version'")['value']??0),
   'foundation_issues'=>$issues,
   'legacy_workspace_default_columns'=>$legacyDefaults
  ],
  'isolation'=>[
   'activation_allowed'=>false,
   'blockers'=>[
    'legacy_queries_and_writers_not_fully_scoped',
    'legacy_workspace_id_defaults_still_enabled',
    'auth_master_plans_support_and_workers_require_multiworkspace_changes',
    'cross_workspace_access_tests_not_homologated',
    'fresh_backup_and_atomic_cutover_not_executed'
   ]
  ],
  'migration_rule'=>'Original rows never move; remove Gabriella legacy membership only during fully audited cutover.',
  'next'=>'Implement and homologate every tenant data path, then execute backup-gated two-workspace migration.'
 ],$issues?3:0);
}catch(Throwable $e){
 $known=[
  'original_vitor_workspace_not_verified','unexpected_existing_workspaces',
  'unsupported_argument','duplicate_email_argument','invalid_candidate_email',
  'gabriella_administrator_not_uniquely_found','gabriella_must_be_active_legacy_admin',
  'candidate_is_platform_master','candidate_already_has_multiple_workspaces'
 ];
 $error=$e->getMessage();
 secondWorkspaceOutput([
  'operation'=>'read_only_workspace_split_preflight',
  'ready'=>false,'created'=>false,
  'error'=>in_array($error,$known,true)?$error:'preflight_unavailable'
 ],2);
}
