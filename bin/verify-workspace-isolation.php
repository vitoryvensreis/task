<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/app/bootstrap.php';
try{
 if(!WorkspaceTenantMode::enabled()||!WorkspaceFoundationGuard::valid())
  throw new RuntimeException('Multiworkspace integrity failed.');
 $original=DB::one('SELECT id,owner_user_id FROM workspaces WHERE slug=?',[WorkspaceFoundation::SLUG]);
 $second=DB::one('SELECT id,owner_user_id FROM workspaces WHERE slug=?',[PlatformTenantProvisioning::SLUG]);
 if(!$original||!$second||(int)$original['id']===(int)$second['id']||
    (int)(DB::one('SELECT COUNT(*) n FROM workspaces')['n']??0)!==2)
  throw new RuntimeException('Exactly two distinct workspaces required.');
 foreach(WorkspaceFoundation::DATA_TABLES as $table=>$key){
  $column=DB::one('SELECT COLUMN_DEFAULT FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?',
    [$table,'workspace_id']);
  if(!$column||$column['COLUMN_DEFAULT']!==null)
   throw new RuntimeException('Legacy workspace default remains: '.$table);
  if((int)(DB::one('SELECT COUNT(*) n FROM `'.$table.'` WHERE workspace_id NOT IN (?,?)',
    [(int)$original['id'],(int)$second['id']])['n']??0)>0)
   throw new RuntimeException('Invalid owner of business rows: '.$table);
 }
 foreach(['clients','demands','projects','invoices','payments','files','contracts'] as $table)
  if((int)(DB::one('SELECT COUNT(*) n FROM `'.$table.'` WHERE workspace_id=?',
    [(int)$second['id']])['n']??0)!==0)
   throw new RuntimeException('New workspace is not empty: '.$table);
 $user=DB::one('SELECT id,name,email,role,active FROM users WHERE id=?',[(int)$second['owner_user_id']]);
 if(!$user||strtolower((string)$user['email'])!==PlatformTenantProvisioning::OWNER_EMAIL)
  throw new RuntimeException('Gabriella owner mismatch.');
 $ctx=WorkspaceContext::forUser($user);
 if((int)$ctx['id']!==(int)$second['id'])throw new RuntimeException('Workspace identity mismatch.');
 $gabMember=DB::one('SELECT workspace_id,role,status FROM workspace_members WHERE user_id=?',[(int)$user['id']]);
 if(!$gabMember||(int)$gabMember['workspace_id']!==(int)$second['id']||
   $gabMember['role']!=='owner'||$gabMember['status']!=='active')
  throw new RuntimeException('Gabriella membership mismatch.');
 $task=DB::one('SELECT id FROM demands WHERE workspace_id=? LIMIT 1',[(int)$original['id']]);
 $client=DB::one('SELECT id FROM clients WHERE workspace_id=? LIMIT 1',[(int)$original['id']]);
 Auth::$actor=$user;Auth::$user=$user;Auth::$workspace=$ctx;Auth::$origin='web';
 foreach([['client',$client],['demand',$task]] as [$type,$row]){
  if(!$row)continue;
  $denied=false;
  try{if($type==='client')Auth::client((int)$row['id']);
      else Auth::demand((int)$row['id']);}
  catch(Throwable){$denied=true;}
  if(!$denied)throw new RuntimeException('Cross-workspace read allowed: '.$type);
 }
 $plan=PlatformPlans::workspacePlan((int)$second['id']);
 if(($plan['team_limit']??'bad')!==null||($plan['name']??'')!=='Legado ilimitado')
  throw new RuntimeException('Legacy unlimited plan was not assigned.');
 echo json(['verified'=>true,'workspaces'=>2,'original_data_preserved'=>true,
    'gabriella_business_empty'=>true,'cross_company_reads_denied'=>true,
    'workspace_defaults_removed'=>count(WorkspaceFoundation::DATA_TABLES)]).PHP_EOL;
}catch(Throwable $e){
 error_log('Multiworkspace verification refused: '.$e->getMessage());
 fwrite(STDERR,json(['verified'=>false,'code'=>'workspace_isolation_unverified']).PHP_EOL);
 exit(2);
}
