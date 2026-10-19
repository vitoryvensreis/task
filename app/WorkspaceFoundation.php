<?php
declare(strict_types=1);

/**
 * Additive WORKSPACE foundation for schema 30.
 *
 * Single original workspace only; legacy controllers still use global queries.
 * Never create a second workspace or enable external tenants until ALL
 * controllers, downloads, API, workers, realtime and logs are scoped.
 *
 * This is different from the retired SaaS / schema 20.
 */
final class WorkspaceFoundation {
 public const VERSION=1;
 public const MARKER='workspace_foundation_version';
 public const OWNER_EMAIL='vitoryvensreisdeoliveira@gmail.com';
 public const MASTER_EMAIL='escotilhaprodutora@gmail.com';
 public const SLUG='escotilha-produtora';
 public const STATUS='staged';

 // Legacy business rows get exactly one workspace. Global auth/session,
 // Process cursors, rate limits and platform errors stay global.
 // The array values are the verified legacy index column (not caller input).
 public const DATA_TABLES=[
  'event_outbox'=>'id',
  'clients'=>'id','user_clients'=>'client_id',
  'stages'=>'id','categories'=>'id','projects'=>'id',
  'contracts'=>'id','contract_items'=>'id','cycles'=>'id','cycle_items'=>'id',
  'demands'=>'id','demand_usage'=>'id','transitions'=>'id','comments'=>'id',
  'checklists'=>'id','time_entries'=>'id','files'=>'id','reviews'=>'id',
  'change_requests'=>'id','invoices'=>'id','payments'=>'id','costs'=>'id',
  'google_accounts'=>'id','audit_log'=>'id','notifications'=>'id',
  'chat_threads'=>'id','chat_messages'=>'id','chat_message_tasks'=>'message_id',
  'automations'=>'id','automation_runs'=>'id','automation_run_steps'=>'id',
  'whatsapp_messages'=>'id','mail_queue'=>'id',
  'push_subscriptions'=>'id','push_delivery_log'=>'id',
  'expense_categories'=>'id','expense_rules'=>'id','expenses'=>'id',
  'whatsapp_contacts'=>'id','api_tokens'=>'id','oauth_codes'=>'user_id',
  'idempotency'=>'user_id','templates'=>'id'
 ];
 public const NEW_TABLES=[
  'workspaces','workspace_members','workspace_settings',
  'workspace_support_sessions','platform_master_identities'
 ];

 private static function tableExists(string $table): bool {
  return (bool)DB::one('SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? LIMIT 1',[$table]);
 }
 private static function column(string $table,string $column): ?array {
  return DB::one('SELECT DATA_TYPE,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT
   FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?',[$table,$column]);
 }
 private static function indexColumns(string $table,string $name): array {
  return DB::all('SELECT COLUMN_NAME,NON_UNIQUE FROM INFORMATION_SCHEMA.STATISTICS
   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=? ORDER BY SEQ_IN_INDEX',[$table,$name]);
 }
 private static function expectedIndex(string $table,string $key): bool {
  $r=self::indexColumns($table,'idx_ws_'.$table);
  return count($r)===2&&$r[0]['COLUMN_NAME']==='workspace_id'&&
   $r[1]['COLUMN_NAME']===$key&&(int)$r[0]['NON_UNIQUE']===1;
 }

 public static function issues(bool $checkRows=false): array {
  $issues=[];
  if((int)(DB::one("SELECT value FROM settings WHERE `key`='schema_version'")['value']??0)!==30)$issues[]='schema30_required';
  foreach(self::NEW_TABLES as $table)if(!self::tableExists($table))$issues[]='missing_'.$table;
  if($issues)return $issues;
  $owner=DB::one('SELECT id,role,active FROM users WHERE LOWER(email)=?',[self::OWNER_EMAIL]);
  if(!$owner||$owner['role']!=='admin'||(int)$owner['active']!==1)$issues[]='owner_not_active_admin';
  $ws=DB::one('SELECT id,owner_user_id,status FROM workspaces WHERE slug=?',[self::SLUG]);
  if(!$ws||(int)($ws['owner_user_id']??0)!==(int)($owner['id']??0)||$ws['status']!==self::STATUS)
   $issues[]='workspace_owner_mismatch';
  $multi=WorkspaceTenantMode::enabled();
  $total=(int)(DB::one('SELECT COUNT(*) n FROM workspaces')['n']??0);
  if($multi){
   if($total!==2)$issues[]='unexpected_workspace_count';
   $second=DB::one('SELECT w.owner_user_id,u.email,w.status FROM workspaces w JOIN users u ON u.id=w.owner_user_id WHERE w.slug=?',[PlatformTenantProvisioning::SLUG]);
   if(!$second||strtolower((string)$second['email'])!==PlatformTenantProvisioning::OWNER_EMAIL||$second['status']!=='active')
    $issues[]='second_workspace_invalid';
  }elseif($total!==1)$issues[]='unexpected_extra_workspace';
  $member=$ws&&$owner?DB::one('SELECT role,status FROM workspace_members WHERE workspace_id=? AND user_id=?',
   [(int)$ws['id'],(int)$owner['id']]):null;
  if(!$member||$member['role']!=='owner'||$member['status']!=='active')$issues[]='owner_membership_missing';
  $master=DB::one('SELECT email,user_id,status FROM platform_master_identities WHERE email=?',
   [self::MASTER_EMAIL]);
  if(!$master||!in_array($master['status'],['pending_verification','verified'],true))
   $issues[]='master_identity_not_registered';
  if($master&&$master['status']==='verified'&&!$master['user_id'])
   $issues[]='master_marked_verified_without_user';
  foreach(self::DATA_TABLES as $table=>$key){
   $column=self::column($table,'workspace_id');
   if(!$column||$column['DATA_TYPE']!=='bigint'||
      !str_contains(strtolower((string)$column['COLUMN_TYPE']),'unsigned')||
      $column['IS_NULLABLE']!=='NO'){
    $issues[]='invalid_workspace_column_'.$table;continue;
   }
   if(!self::expectedIndex($table,$key))$issues[]='invalid_workspace_index_'.$table;
   if($ws&&($multi?($column['COLUMN_DEFAULT']!==null):
    ((int)$column['COLUMN_DEFAULT']!==(int)$ws['id'])))$issues[]='incorrect_workspace_default_'.$table;
   if($checkRows&&$ws&&
      (int)(DB::one('SELECT COUNT(*) n FROM `'.$table.'` WHERE workspace_id'.($multi?' NOT IN (SELECT id FROM workspaces)':'<>?'),
       $multi?[]:[(int)$ws['id']])['n']??0)!==0)
    $issues[]='foreign_workspace_rows_'.$table;
  }
  if($checkRows&&$ws){
   $unlinked=(int)(DB::one('SELECT COUNT(*) n FROM users u
     LEFT JOIN workspace_members wm ON wm.user_id=u.id AND wm.workspace_id=?
     WHERE u.email<>? AND wm.user_id IS NULL',
     [(int)$ws['id'],self::MASTER_EMAIL])['n']??0);
   if($unlinked)$issues[]='unlinked_legacy_users';
  }
  return array_values(array_unique($issues));
 }

 private static function ensureTables(): void {
  $ddl=[
   'workspaces'=>"CREATE TABLE workspaces (
     id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
     slug VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
     name VARCHAR(190) NOT NULL,
     owner_user_id INT NOT NULL,
     status VARCHAR(24) NOT NULL DEFAULT 'staged',
     created_at DATETIME NOT NULL,updated_at DATETIME NOT NULL,
     PRIMARY KEY(id),UNIQUE KEY uq_ws_slug(slug),
     KEY idx_ws_owner(owner_user_id),
     CONSTRAINT fk_ws_owner FOREIGN KEY(owner_user_id) REFERENCES users(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
   'workspace_members'=>"CREATE TABLE workspace_members (
     workspace_id BIGINT UNSIGNED NOT NULL,user_id INT NOT NULL,
     role VARCHAR(24) NOT NULL,status VARCHAR(24) NOT NULL DEFAULT 'active',
     joined_at DATETIME NOT NULL,
     PRIMARY KEY(workspace_id,user_id),
     UNIQUE KEY uq_ws_one_membership(user_id),
     KEY idx_ws_members_user(user_id,status,workspace_id),
     CONSTRAINT fk_ws_member_workspace FOREIGN KEY(workspace_id) REFERENCES workspaces(id) ON DELETE RESTRICT,
     CONSTRAINT fk_ws_member_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
   'workspace_settings'=>"CREATE TABLE workspace_settings (
     workspace_id BIGINT UNSIGNED NOT NULL,
     `key` VARCHAR(100) NOT NULL,value LONGTEXT NOT NULL,updated_at DATETIME NOT NULL,
     PRIMARY KEY(workspace_id,`key`),
     CONSTRAINT fk_ws_setting_workspace FOREIGN KEY(workspace_id) REFERENCES workspaces(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
   'workspace_support_sessions'=>"CREATE TABLE workspace_support_sessions (
     id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
     workspace_id BIGINT UNSIGNED NOT NULL,
     master_user_id INT NOT NULL,
     reason VARCHAR(190) NULL,
     started_at DATETIME NOT NULL,
     expires_at DATETIME NOT NULL,
     ended_at DATETIME NULL,
     PRIMARY KEY(id),
     KEY idx_ws_support_master(master_user_id,ended_at,id),
     KEY idx_ws_support_workspace(workspace_id,ended_at,id),
     CONSTRAINT fk_ws_support_workspace FOREIGN KEY(workspace_id) REFERENCES workspaces(id) ON DELETE RESTRICT,
     CONSTRAINT fk_ws_support_master FOREIGN KEY(master_user_id) REFERENCES users(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
   'platform_master_identities'=>"CREATE TABLE platform_master_identities (
     email VARCHAR(190) NOT NULL,
     user_id INT NULL,
     status VARCHAR(32) NOT NULL DEFAULT 'pending_verification',
     verified_at DATETIME NULL,
     created_at DATETIME NOT NULL,
     updated_at DATETIME NOT NULL,
     PRIMARY KEY(email),UNIQUE KEY uq_platform_master_user(user_id),
     CONSTRAINT fk_platform_master_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
  ];
  foreach($ddl as $table=>$sql)if(!self::tableExists($table))DB::run($sql);
  foreach(['workspaces'=>['id','slug','owner_user_id','status'],
   'workspace_members'=>['workspace_id','user_id','role','status'],
   'workspace_settings'=>['workspace_id','key','value'],
   'workspace_support_sessions'=>['id','workspace_id','master_user_id','expires_at'],
   'platform_master_identities'=>['email','user_id','status']] as $table=>$columns)
    foreach($columns as $column)
     if(!self::column($table,$column))throw new RuntimeException('Incomplete workspace table: '.$table);
 }

 public static function apply(): array {
  if(DB::conn()->inTransaction())throw new LogicException('Workspace DDL requires no open transaction.');
  $lock='taskesc-workspace30-'.(string)(DB::one('SELECT SHA1(DATABASE()) h')['h']??'');
  if((int)(DB::one('SELECT GET_LOCK(?,15) acquired',[$lock])['acquired']??0)!==1)
   throw new RuntimeException('Workspace migration already running.');
  try {
   $schema=(int)(DB::one("SELECT value FROM settings WHERE `key`='schema_version'")['value']??0);
   if($schema!==30)throw new RuntimeException('Workspace migration requires schema 30.');
   if((int)(DB::one('SELECT COUNT(*) n FROM INFORMATION_SCHEMA.COLUMNS
       WHERE TABLE_SCHEMA=DATABASE() AND COLUMN_NAME=?',['tenant_account_id'])['n']??0)>0)
    throw new RuntimeException('Retired SaaS columns detected.');
   if(DB::one("SELECT `key` FROM settings WHERE `key` LIKE 'saas%' LIMIT 1"))
    throw new RuntimeException('Retired SaaS markers detected.');
   $version=(int)(DB::one('SELECT value FROM settings WHERE `key`=?',
     [self::MARKER])['value']??0);
   if($version>self::VERSION)throw new RuntimeException('Future workspace migration version.');
   if($version===self::VERSION){
    // Initial migration backfilled every historical user. After B1 auth is
    // deployed, an intentionally unlinked or revoked user MUST be denied
    // login; such a user is not a structural migration error.
    $issues=array_values(array_diff(self::issues(true),['unlinked_legacy_users']));
    if($issues)throw new RuntimeException('Workspace schema drift: '.implode(',',$issues));
    return ['version'=>$version,'already_applied'=>true,'status'=>'staged'];
   }
   $owner=DB::one('SELECT id,role,active FROM users WHERE LOWER(email)=?',[self::OWNER_EMAIL]);
   if(!$owner||$owner['role']!=='admin'||(int)$owner['active']!==1)
    throw new RuntimeException('Workspace owner account missing or not an active admin.');
   $master=DB::one('SELECT id,role,active FROM users WHERE LOWER(email)=?',[self::MASTER_EMAIL]);
   if($master&&((int)$master['id']===(int)$owner['id']||
      $master['role']!=='admin'||(int)$master['active']!==1))
    throw new RuntimeException('Master must be a distinct active admin when registered.');
   $known=['workspaces','workspace_members','workspace_settings','workspace_support_sessions','platform_master_identities'];
   foreach(DB::all("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND
       (TABLE_NAME LIKE 'saas_%' OR TABLE_NAME IN ('accounts','account_members','account_settings'))") as $row)
    throw new RuntimeException('Old SaaS table exists: '.$row['TABLE_NAME']);
   $unknown=DB::all("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE()
      AND TABLE_NAME LIKE 'workspace%'");
   foreach($unknown as $row)if(!in_array($row['TABLE_NAME'],$known,true))
    throw new RuntimeException('Unknown workspace table present.');

   // Verify all legacy objects BEFORE creating any tables or changing columns.
   foreach(self::DATA_TABLES as $legacyTable=>$legacyKey)
    if(!self::tableExists($legacyTable)||!self::column($legacyTable,$legacyKey))
     throw new RuntimeException('Expected legacy schema30 table/key is missing: '.$legacyTable);
   $allowedOwners=[...array_keys(self::DATA_TABLES),'workspace_members','workspace_settings','workspace_support_sessions','team_invitations'];
   $existingOwners=DB::all("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA=DATABASE() AND COLUMN_NAME='workspace_id'");
   foreach($existingOwners as $row)
    if(!in_array($row['TABLE_NAME'],$allowedOwners,true))
     throw new RuntimeException('Unexpected workspace_id column: '.$row['TABLE_NAME']);

   self::ensureTables();
   $ws=DB::one('SELECT id,owner_user_id,status FROM workspaces WHERE slug=?',[self::SLUG]);
   if(!$ws){
    if((int)(DB::one('SELECT COUNT(*) n FROM workspaces')['n']??0)!==0)
     throw new RuntimeException('Other workspace detected: refusing reassignment.');
    $workspaceId=DB::insert('workspaces',['slug'=>self::SLUG,
      'name'=>'Escotilha Produtora','owner_user_id'=>(int)$owner['id'],
      'status'=>self::STATUS,'created_at'=>now(),'updated_at'=>now()]);
   }else{
    if((int)$ws['owner_user_id']!==(int)$owner['id']||$ws['status']!==self::STATUS
      ||(int)(DB::one('SELECT COUNT(*) n FROM workspaces')['n']??0)!==1)
     throw new RuntimeException('Existing workspace differs from expected owner.');
    $workspaceId=(int)$ws['id'];
   }
   // Preserve membership revocations on an interrupted migration retry.
   DB::run("INSERT IGNORE INTO workspace_members
       (workspace_id,user_id,role,status,joined_at)
       SELECT ?,u.id,
       CASE WHEN u.id=? THEN 'owner' WHEN u.role='admin' THEN 'admin'
            WHEN u.role='staff' THEN 'staff' ELSE 'client' END,
       CASE WHEN u.active=1 THEN 'active' ELSE 'inactive' END,?
       FROM users u WHERE LOWER(u.email)<>?",
       [$workspaceId,(int)$owner['id'],now(),self::MASTER_EMAIL]);
   $member=DB::one('SELECT role,status FROM workspace_members WHERE workspace_id=? AND user_id=?',
    [$workspaceId,(int)$owner['id']]);
   if(!$member||$member['role']!=='owner'||$member['status']!=='active')
    throw new RuntimeException('Owner membership missing or revoked.');
   if((int)(DB::one('SELECT COUNT(*) n FROM workspaces')['n']??0)!==1)
    throw new RuntimeException('Additional workspaces not permitted while legacy API is global.');

   DB::run('INSERT IGNORE INTO platform_master_identities
      (email,user_id,status,verified_at,created_at,updated_at)
       VALUES (?,? ,?,NULL,?,?)',
     [self::MASTER_EMAIL,$master?(int)$master['id']:null,'pending_verification',now(),now()]);
   $masterStatus=DB::one('SELECT status FROM platform_master_identities WHERE email=?',[self::MASTER_EMAIL]);
   if(!$masterStatus||$masterStatus['status']!=='pending_verification')
    throw new RuntimeException('Platform master must remain unverified in staging.');
   foreach(['workspace.activation_enabled'=>'0',
     'workspace.mode'=>'staged'] as $key=>$value)
    DB::run('INSERT IGNORE INTO workspace_settings (workspace_id,`key`,value,updated_at)
      VALUES (?,?,?,?)',[$workspaceId,$key,$value,now()]);
   // Replicate safe legacy workspace preferences; leave platform security,
   // OAuth tokens and cron state global; Google Drive preferences are tenant-specific.
   DB::run("INSERT IGNORE INTO workspace_settings (workspace_id,`key`,value,updated_at)
     SELECT ?,s.`key`,s.value,? FROM settings s WHERE s.`key` IN
       ('brand_name','brand_color','logo_url','timezone','upload_mb',
        'retention_days','min_delivery_business_hours','dashboard_default_period',
        'drive_account_id','drive_root_folder_id')",[$workspaceId,now()]);

   // Additive DDL, resumable after interruption. Defaults point only to the
   // initial, existing operation. Before onboarding a second tenant remove
   // defaults and roll out scoped controllers/queues in a separate release.
   foreach(self::DATA_TABLES as $table=>$key){
    if(!self::tableExists($table))throw new RuntimeException('Legacy table missing: '.$table);
    if(!self::column($table,$key))throw new RuntimeException('Expected table key missing: '.$table);
    $column=self::column($table,'workspace_id');
    if(!$column)
     DB::run('ALTER TABLE `'.$table.'` ADD COLUMN workspace_id BIGINT UNSIGNED NOT NULL DEFAULT '.$workspaceId);
    else if($column['DATA_TYPE']!=='bigint'||
      !str_contains(strtolower((string)$column['COLUMN_TYPE']),'unsigned')||
      $column['IS_NULLABLE']!=='NO'||(int)$column['COLUMN_DEFAULT']!==$workspaceId)
     throw new RuntimeException('Legacy workspace column has unexpected definition: '.$table);
    $idx=self::indexColumns($table,'idx_ws_'.$table);
    if(!$idx)
     DB::run('ALTER TABLE `'.$table.'` ADD INDEX `idx_ws_'.$table.'` (workspace_id,`'.$key.'`)');
    else if(!self::expectedIndex($table,$key))
     throw new RuntimeException('Legacy workspace index mismatch: '.$table);
   }
   $issues=self::issues(true);
   if($issues)throw new RuntimeException('Workspace data preflight failed: '.implode(',',$issues));
   DB::run('INSERT INTO settings (`key`,value) VALUES (?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)',
    [self::MARKER,(string)self::VERSION]);
   if($issues=self::issues(true))throw new RuntimeException('Workspace verification failed: '.implode(',',$issues));
   return ['version'=>self::VERSION,'workspace_id'=>$workspaceId,
     'owner_user_id'=>(int)$owner['id'],'tables_scoped'=>count(self::DATA_TABLES),
     'master_identity'=>'pending_verification',
     'external_tenants_enabled'=>false,'backend_isolation_enabled'=>false];
  }finally{DB::run('SELECT RELEASE_LOCK(?)',[$lock]);}
 }
}
