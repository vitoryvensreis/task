<?php
declare(strict_types=1);
// One-time, fail-closed rollback of the UNUSED TaskEsc SaaS B1 schema.
// No table belonging to the original schema 30 is dropped; original rows
// (clients, demands, finance, media, users, etc.) are never deleted.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/app/bootstrap.php';

const REMOVED_SAAS_TABLES=[
 'saas_internal_inbox','saas_channel_secrets','saas_delivery_jobs',
 'saas_event_receipts','saas_event_outbox','account_settings',
 'account_members','accounts'
];
const REMOVED_SAAS_ROOTS=[
 'clients','stages','categories','projects','contracts','demands',
 'invoices','automations','whatsapp_contacts','expense_categories',
 'expense_rules','expenses','templates','google_accounts','calendars'
];
const REMOVED_SAAS_CHILDREN=[
 'contract_items','cycles','cycle_items','demand_usage','transitions',
 'comments','checklists','time_entries','files','reviews','change_requests',
 'costs','payments','events','automation_runs','automation_run_steps'
];
const REMOVED_SAAS_MARKERS=[
 'saas_foundation_version'=>1,'saas_domain_version'=>2,
 'saas_children_version'=>3,'saas_outbox_version'=>4,
 'saas_receipts_version'=>5,'saas_delivery_version'=>6,
 'saas_channels_version'=>7,'saas_internal_inbox_version'=>8
];

function cleanupAudit(): array {
 $errors=[];
 $schema=(int)(DB::one("SELECT value FROM settings WHERE `key`='schema_version'")['value']??0);
 if($schema!==30)$errors[]='schema_30_required';
 $expected=[...REMOVED_SAAS_ROOTS,...REMOVED_SAAS_CHILDREN];
 $foundTables=array_column(DB::all('SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE()'),'TABLE_NAME');
 $extras=array_values(array_diff(array_filter($foundTables,static fn(string $s):bool=>
   str_starts_with($s,'saas_')),REMOVED_SAAS_TABLES));
 if($extras)$errors[]='unknown_saas_tables';
 $present=array_values(array_intersect(REMOVED_SAAS_TABLES,$foundTables));
 $owned=DB::all("SELECT TABLE_NAME,COLUMN_NAME,DATA_TYPE,COLUMN_TYPE,IS_NULLABLE
    FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND COLUMN_NAME='tenant_account_id'");
 $ownedNames=array_column($owned,'TABLE_NAME');
 $unknownOwned=array_diff($ownedNames,[...$expected,...REMOVED_SAAS_TABLES]);
 if($unknownOwned)$errors[]='unexpected_tenant_columns';
 $markers=DB::all("SELECT `key`,value FROM settings WHERE `key` LIKE 'saas%'");
 $markerValues=[];
 foreach($markers as $row){
  $key=(string)$row['key'];
  $markerValues[$key]=(string)$row['value'];
  if(array_key_exists($key,REMOVED_SAAS_MARKERS)){
   if((int)$row['value']!==REMOVED_SAAS_MARKERS[$key])$errors[]='unknown_migration_version';
  }elseif(in_array($key,['saas.external_signup_enabled','saas.multi_account_enabled'],true)){
   if((string)$row['value']!=='0')$errors[]='tenant_activation_detected';
  }else $errors[]='unknown_saas_settings';
 }
 $isClean=$present===[]&&count($owned)===0&&$markers===[];
 if(!$isClean){
  if(count($present)!==count(REMOVED_SAAS_TABLES))$errors[]='partial_saas_tables';
  if(count($owned)!==count($expected))$errors[]='partial_saas_columns';
  foreach(REMOVED_SAAS_MARKERS as $k=>$v)
   if(!array_key_exists($k,$markerValues))$errors[]='missing_migration_marker';
  foreach($expected as $table){
   if(!in_array($table,$foundTables,true)){$errors[]='missing_legacy_table';continue;}
   $cols=array_values(array_filter($owned,static fn(array $r):bool=>$r['TABLE_NAME']===$table));
   if(count($cols)!==1||$cols[0]['DATA_TYPE']!=='bigint'||
      !str_contains(strtolower((string)$cols[0]['COLUMN_TYPE']),'unsigned')||
      $cols[0]['IS_NULLABLE']!=='YES')$errors[]='unexpected_tenant_column_layout';
   $idx=DB::all('SELECT INDEX_NAME,COLUMN_NAME,NON_UNIQUE FROM INFORMATION_SCHEMA.STATISTICS
       WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND (COLUMN_NAME=? OR INDEX_NAME=?)
       ORDER BY INDEX_NAME,SEQ_IN_INDEX',
       [$table,'tenant_account_id','idx_saas_'.$table]);
   if(count($idx)!==2||
      $idx[0]['INDEX_NAME']!=='idx_saas_'.$table||
      $idx[0]['COLUMN_NAME']!=='tenant_account_id'||
      $idx[1]['INDEX_NAME']!=='idx_saas_'.$table||
      $idx[1]['COLUMN_NAME']!=='id'||
      (int)$idx[0]['NON_UNIQUE']!==1)$errors[]='unexpected_tenant_index';
   $references=DB::one('SELECT COUNT(*) n FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
       WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?
         AND REFERENCED_TABLE_NAME IS NOT NULL',[$table,'tenant_account_id']);
   if((int)$references['n']>0)$errors[]='tenant_column_fk_dependency';
  }
  $foreign=DB::all('SELECT TABLE_NAME,REFERENCED_TABLE_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
       WHERE REFERENCED_TABLE_SCHEMA=DATABASE() AND TABLE_SCHEMA=DATABASE()
         AND REFERENCED_TABLE_NAME IS NOT NULL');
  foreach($foreign as $fk)
   if(in_array($fk['REFERENCED_TABLE_NAME'],REMOVED_SAAS_TABLES,true)&&
      !in_array($fk['TABLE_NAME'],REMOVED_SAAS_TABLES,true))
    $errors[]='external_table_references_saas';
  if(in_array('accounts',$present,true)){
   $accounts=DB::all('SELECT slug,account_type,status FROM accounts');
   if(count($accounts)!==1||$accounts[0]['slug']!=='taskesc-existing-operation'||
      $accounts[0]['account_type']!=='internal'||$accounts[0]['status']!=='active')
    $errors[]='noninternal_or_extra_accounts';
   $settings=DB::all('SELECT `key`,value FROM account_settings');
   foreach($settings as $row){
    if(!in_array($row['key'],[
      'saas.external_signup_enabled','saas.multi_account_enabled',
      'saas.ai_assistant_enabled','saas.google_calendar_enabled'
    ],true))$errors[]='unexpected_account_settings';
    if(in_array($row['key'],['saas.external_signup_enabled','saas.multi_account_enabled'],true)&&
       (string)$row['value']!=='0')$errors[]='external_saas_enabled';
   }
  }
  foreach([
   'saas_internal_inbox','saas_channel_secrets','saas_delivery_jobs',
   'saas_event_receipts','saas_event_outbox'
  ] as $table){
   if(in_array($table,$present,true)&&
      (int)(DB::one('SELECT COUNT(*) n FROM `'.$table.'`')['n']??0)!==0)
    $errors[]='saas_queue_or_credentials_not_empty';
  }
 }
 return ['state'=>$isClean?'already_clean':'staged_saas',
  'schema'=>$schema,'saas_tables_found'=>count($present),
  'tenant_columns_found'=>count($owned),
  'migration_markers_found'=>count(array_intersect(array_keys($markerValues),array_keys(REMOVED_SAAS_MARKERS))),
  'blockers'=>array_values(array_unique($errors))];
}

function cleanupVerifiedBackup(string $raw): void {
 $manifest=realpath($raw)?:'';
 $root=realpath(ROOT.'/storage/backups')?:'';
 if(!$manifest||!$root||!str_starts_with($manifest,$root.DIRECTORY_SEPARATOR)
    ||!is_file($manifest)||is_link($manifest)||!is_readable($manifest))
  throw new RuntimeException('verified_backup_manifest_required');
 $body=(string)file_get_contents($manifest);
 if(!preg_match('/^Arquivo do banco: (taskesc-predeploy-[A-Za-z0-9-]+\.sql\.gz)$/m',$body,$file)||
    !preg_match('/^SHA256: ([a-f0-9]{64})$/m',$body,$hash)||
    !preg_match('/^Schema do banco: (30)$/m',$body))
  throw new RuntimeException('invalid_backup_manifest');
 $backup=dirname($manifest).DIRECTORY_SEPARATOR.$file[1];
 if(!is_file($backup)||is_link($backup)||filesize($backup)<1||
    !hash_equals($hash[1],(string)hash_file('sha256',$backup)))
  throw new RuntimeException('backup_checksum_invalid');
 if(time()-(int)filemtime($backup)>7200||time()-(int)filemtime($manifest)>7200)
  throw new RuntimeException('backup_older_than_two_hours');
}

try{
 $apply=in_array('--apply',$argv,true);
 $check=in_array('--check',$argv,true);
 if($apply===$check||count(array_filter($argv,static fn(string $v):bool=>
    $v==='--apply'||$v==='--check'))!==1)
  throw new RuntimeException('choose_check_or_apply');
 $manifestArg='';
 foreach($argv as $arg)
  if(str_starts_with($arg,'--backup-manifest='))$manifestArg=substr($arg,18);
 if($apply)cleanupVerifiedBackup($manifestArg);
 if(DB::conn()->inTransaction())throw new RuntimeException('ddl_requires_no_transaction');
 $lock='taskesc-cleanup-'.(string)(DB::one('SELECT SHA1(DATABASE()) h')['h']??'');
 if((int)(DB::one('SELECT GET_LOCK(?,10) acquired',[$lock])['acquired']??0)!==1)
  throw new RuntimeException('cleanup_concurrent');
 try{
  $audit=cleanupAudit();
  if($audit['blockers'])throw new RuntimeException('preflight_blocked:'.implode(',',$audit['blockers']));
  if($check||$audit['state']==='already_clean'){
   echo json(['mode'=>$check?'check':'apply','backup_verified'=>$apply,'audit'=>$audit]).PHP_EOL;
   exit(0);
  }
  // DDL has implicit commits. An uninterrupted, verified full backup is
  // required. Foreign-key drop order follows the exact SaaS schema graph.
  foreach(REMOVED_SAAS_TABLES as $table)
   DB::run('DROP TABLE `'.$table.'`');
  foreach([...array_reverse(REMOVED_SAAS_CHILDREN),...REMOVED_SAAS_ROOTS] as $table)
   DB::run('ALTER TABLE `'.$table.'` DROP INDEX `idx_saas_'.$table.'`, DROP COLUMN `tenant_account_id`');
  foreach([...array_keys(REMOVED_SAAS_MARKERS),'saas.external_signup_enabled','saas.multi_account_enabled'] as $key)
   DB::run('DELETE FROM settings WHERE `key`=?',[$key]);
  $after=cleanupAudit();
  if($after['state']!=='already_clean'||$after['blockers'])
   throw new RuntimeException('post_cleanup_schema_mismatch');
  echo json(['mode'=>'apply','backup_verified'=>true,
   'removed_tables'=>count(REMOVED_SAAS_TABLES),
   'removed_ownership_columns'=>count(REMOVED_SAAS_ROOTS)+count(REMOVED_SAAS_CHILDREN),
   'removed_migration_markers'=>count(REMOVED_SAAS_MARKERS),
   'schema_version'=>30,'result'=>'clean']).PHP_EOL;
 }finally{DB::run('SELECT RELEASE_LOCK(?)',[$lock]);}
}catch(Throwable $e){
 $message=$e->getMessage();
 $code=preg_match('/\A[a-z0-9_]+\z/D',$message)?$message:'cleanup_blocked';
 fwrite(STDERR,json(['status'=>'blocked','code'=>$code]).PHP_EOL);
 exit(1);
}
