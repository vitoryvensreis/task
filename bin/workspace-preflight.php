<?php
declare(strict_types=1);
// Read-only diagnostic: also works via "cd DEPLOY_PATH && php < this-file".
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require getcwd().'/app/bootstrap.php';
try{
 $schema=(int)(DB::one("SELECT value FROM settings WHERE `key`='schema_version'")['value']??0);
 $master=DB::one('SELECT id,role,active FROM users WHERE LOWER(email)=? LIMIT 1',['escotilhaprodutora@gmail.com']);
 $owner=DB::one('SELECT id,role,active FROM users WHERE LOWER(email)=? LIMIT 1',['vitoryvensreisdeoliveira@gmail.com']);
 $staged=DB::all("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('workspaces','workspace_members','workspace_settings','workspace_support_sessions','accounts','account_members','account_settings')");
 $columns=DB::all("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND COLUMN_NAME IN ('workspace_id','tenant_account_id','is_platform_master')");
 $marker=DB::one("SELECT value FROM settings WHERE `key`='workspace_foundation_version'");
 $flags=DB::all("SELECT `key` FROM settings WHERE `key` LIKE 'saas%' OR `key` IN ('workspace_context_enabled','workspace_permissions_enabled','workspace_settings_enabled')");
 // Master e-mail is explicitly designated but not currently registered.
 // The migration will create a PENDING platform identity, never a login.
 $ok=$schema===30&&$owner
   &&$owner['role']==='admin'&&(int)$owner['active']===1
   &&(!$master||(int)$master['id']!==(int)$owner['id'])
   &&(!$master||($master['role']==='admin'&&(int)$master['active']===1))
   &&!$staged&&!$columns&&!$marker&&!$flags;
 $result=[
  'schema'=>$schema,'master_exists'=>(bool)$master,
  'master_active_admin'=>(bool)($master&&$master['role']==='admin'&&(int)$master['active']===1),
  'workspace_owner_exists'=>(bool)$owner,
  'workspace_owner_active_admin'=>(bool)($owner&&$owner['role']==='admin'&&(int)$owner['active']===1),
  'different_users'=>(bool)(!$master||($owner&&(int)$master['id']!==(int)$owner['id'])),
  'master_identity_status'=>$master?'existing_admin':'pending_invitation',
  'existing_workspace_tables'=>count($staged),
  'existing_workspace_or_tenant_columns'=>count($columns),
  'prior_saas_flags'=>count($flags),
  'migration_marker_present'=>(bool)$marker,
  'eligible_for_one_time_workspace_backfill'=>(bool)$ok
 ];
 echo json($result).PHP_EOL;
 if(!$ok)exit(3);
}catch(Throwable $e){
 fwrite(STDERR,json(['eligible_for_one_time_workspace_backfill'=>false,'error'=>'read_only_preflight_failed']).PHP_EOL);
 exit(4);
}
