<?php
final class Migrations {
 public static function run(): void {
  static $ran=false;if($ran)return;$ran=true;
  try{$version=(int)(DB::one("SELECT value FROM settings WHERE `key`='schema_version'")['value']??1);}catch(Throwable){return;}
  if($version<2){
   self::column('demands','deleted_at','ALTER TABLE demands ADD COLUMN deleted_at DATETIME NULL AFTER updated_at');
   self::column('demands','drive_folder_id','ALTER TABLE demands ADD COLUMN drive_folder_id VARCHAR(255) NULL AFTER deleted_at');
   self::column('files','storage_provider',"ALTER TABLE files ADD COLUMN storage_provider VARCHAR(20) NOT NULL DEFAULT 'local' AFTER disk_name");
   self::column('files','drive_file_id','ALTER TABLE files ADD COLUMN drive_file_id VARCHAR(255) NULL AFTER storage_provider');
   self::column('files','drive_account_id','ALTER TABLE files ADD COLUMN drive_account_id INT NULL AFTER drive_file_id');
   self::column('files','drive_web_view_url','ALTER TABLE files ADD COLUMN drive_web_view_url TEXT NULL AFTER drive_account_id');
   self::column('google_accounts','scopes','ALTER TABLE google_accounts ADD COLUMN scopes TEXT NULL AFTER refresh_token');
   DB::run("INSERT INTO settings (`key`,value) VALUES ('drive_account_id','') ON DUPLICATE KEY UPDATE value=value");
   DB::run("INSERT INTO settings (`key`,value) VALUES ('drive_root_folder_id','') ON DUPLICATE KEY UPDATE value=value");
   DB::run("UPDATE settings SET value='TaskEsc' WHERE `key`='brand_name' AND value='StudioFlow'");
   DB::run("UPDATE settings SET value='#ffffff' WHERE `key`='brand_color' AND value IN ('#4263eb','#5b6df6')");
   DB::run("UPDATE stages SET color=CASE color WHEN '#7c8a9e' THEN '#666666' WHEN '#8b5cf6' THEN '#777777' WHEN '#f59e0b' THEN '#8a8a8a' WHEN '#06b6d4' THEN '#9a9a9a' WHEN '#4263eb' THEN '#b8b8b8' WHEN '#ec4899' THEN '#d1d1d1' WHEN '#f97316' THEN '#a7a7a7' WHEN '#16a34a' THEN '#f0f0f0' WHEN '#94a3b8' THEN '#5a5a5a' WHEN '#64748b' THEN '#444444' ELSE color END WHERE color IN ('#7c8a9e','#8b5cf6','#f59e0b','#06b6d4','#4263eb','#ec4899','#f97316','#16a34a','#94a3b8','#64748b')");
   DB::run("INSERT INTO settings (`key`,value) VALUES ('schema_version','2') ON DUPLICATE KEY UPDATE value='2'");
   $version=2;
  }
  if($version<3){
   self::column('clients','commercial_type',"ALTER TABLE clients ADD COLUMN commercial_type VARCHAR(20) NOT NULL DEFAULT 'one_off' AFTER brand");
   self::column('clients','monthly_amount_cents',"ALTER TABLE clients ADD COLUMN monthly_amount_cents BIGINT NOT NULL DEFAULT 0 AFTER commercial_type");
   DB::run("UPDATE clients SET commercial_type='one_off' WHERE commercial_type NOT IN ('one_off','monthly') OR commercial_type IS NULL");
   DB::run("UPDATE clients c SET commercial_type='monthly', monthly_amount_cents=COALESCE((SELECT ct.amount_cents FROM contracts ct WHERE ct.client_id=c.id AND ct.status='active' ORDER BY ct.id DESC LIMIT 1),0) WHERE EXISTS (SELECT 1 FROM contracts ct2 WHERE ct2.client_id=c.id AND ct2.status='active')");
   DB::run("UPDATE categories SET fields_json='[]'");
   if(self::hasColumn('demands','requested_due'))DB::run("UPDATE demands SET due_at=COALESCE(due_at,CONCAT(requested_due,' 18:00:00'),DATE_ADD(created_at,INTERVAL 1 DAY),UTC_TIMESTAMP())");
   if(self::hasColumn('demands','extra_cents')&&self::hasColumn('demands','discount_cents'))DB::run("UPDATE demands SET amount_cents=CASE WHEN billing_mode='included' THEN 0 ELSE GREATEST(0,COALESCE(amount_cents,0)+COALESCE(extra_cents,0)-COALESCE(discount_cents,0)) END");
   if(self::hasColumn('demands','billing_mode'))DB::run("UPDATE demands SET billing_mode=CASE WHEN billing_mode='included' THEN 'included' ELSE 'one_off' END, amount_cents=COALESCE(amount_cents,0)");
   foreach(['requested_due','requested_priority','original_due_at','extra_cents','discount_cents','contract_id'] as $column)self::dropColumn('demands',$column);
   DB::run("ALTER TABLE demands MODIFY due_at DATETIME NOT NULL");
   DB::run("INSERT INTO settings (`key`,value) VALUES ('min_delivery_business_hours','24') ON DUPLICATE KEY UPDATE value=value");
   DB::run("INSERT INTO settings (`key`,value) VALUES ('schema_version','3') ON DUPLICATE KEY UPDATE value='3'");
   $version=3;
  }
  if($version<4){
   self::column('clients','monthly_payment_day',"ALTER TABLE clients ADD COLUMN monthly_payment_day TINYINT UNSIGNED NOT NULL DEFAULT 10 AFTER monthly_amount_cents");
   DB::run("UPDATE clients SET monthly_payment_day=10 WHERE monthly_payment_day<1 OR monthly_payment_day>31 OR monthly_payment_day IS NULL");
   DB::run("INSERT INTO settings (`key`,value) VALUES ('schema_version','4') ON DUPLICATE KEY UPDATE value='4'");
   $version=4;
  }
  if($version<5){
   self::column('invoices','source_key',"ALTER TABLE invoices ADD COLUMN source_key VARCHAR(120) NULL UNIQUE AFTER status");
   DB::run("INSERT INTO settings (`key`,value) VALUES ('upload_mb','0') ON DUPLICATE KEY UPDATE value='0'");
   DB::run("UPDATE contracts ct JOIN clients c ON c.id=ct.client_id SET ct.auto_bill=0 WHERE c.commercial_type='monthly' AND ct.status='active'");
   DB::run("UPDATE invoices SET source_key=CONCAT('task:',demand_id) WHERE demand_id IS NOT NULL AND source_key IS NULL AND status<>'cancelled' AND id IN (SELECT chosen.id FROM (SELECT MIN(id) AS id FROM invoices WHERE demand_id IS NOT NULL AND status<>'cancelled' GROUP BY demand_id) chosen)");
   DB::run("INSERT INTO settings (`key`,value) VALUES ('schema_version','5') ON DUPLICATE KEY UPDATE value='5'");$version=5;
  }
  if($version<6){
   DB::run("INSERT INTO settings (`key`,value) VALUES ('schema_version','6') ON DUPLICATE KEY UPDATE value='6'");$version=6;
  }
  if($version<7){
   self::column('clients','drive_account_id',"ALTER TABLE clients ADD COLUMN drive_account_id INT NULL AFTER monthly_payment_day");
   self::column('clients','drive_folder_id',"ALTER TABLE clients ADD COLUMN drive_folder_id VARCHAR(255) NULL AFTER drive_account_id");
   self::column('google_accounts','calendar_enabled',"ALTER TABLE google_accounts ADD COLUMN calendar_enabled TINYINT NOT NULL DEFAULT 0 AFTER status");
   self::column('google_accounts','drive_enabled',"ALTER TABLE google_accounts ADD COLUMN drive_enabled TINYINT NOT NULL DEFAULT 0 AFTER calendar_enabled");
   try{DB::run('ALTER TABLE clients ADD INDEX idx_clients_drive_account (drive_account_id)');}catch(Throwable){}
   DB::run("UPDATE google_accounts ga SET calendar_enabled=1 WHERE EXISTS (SELECT 1 FROM calendars c WHERE c.account_id=ga.id)");
   DB::run("UPDATE google_accounts SET drive_enabled=1 WHERE scopes LIKE ?",['%https://www.googleapis.com/auth/drive.file%']);
   DB::run("INSERT INTO settings (`key`,value) VALUES ('schema_version','7') ON DUPLICATE KEY UPDATE value='7'");$version=7;
  }
  if($version<8){
   self::column('mail_queue','sent_at',"ALTER TABLE mail_queue ADD COLUMN sent_at DATETIME NULL AFTER status");
   self::column('mail_queue','last_attempt_at',"ALTER TABLE mail_queue ADD COLUMN last_attempt_at DATETIME NULL AFTER attempts");
   try{DB::run('ALTER TABLE mail_queue ADD INDEX idx_mail_queue_status_next (status,next_attempt)');}catch(Throwable){}
   DB::run("INSERT INTO settings (`key`,value) VALUES ('schema_version','8') ON DUPLICATE KEY UPDATE value='8'");$version=8;
  }
  if($version<9){
   self::column('invoices','deleted_at',"ALTER TABLE invoices ADD COLUMN deleted_at DATETIME NULL AFTER source_key");
   self::column('invoices','deleted_by',"ALTER TABLE invoices ADD COLUMN deleted_by INT NULL AFTER deleted_at");
   self::column('invoices','delete_reason',"ALTER TABLE invoices ADD COLUMN delete_reason VARCHAR(500) NULL AFTER deleted_by");
   try{DB::run('ALTER TABLE invoices ADD INDEX idx_invoices_deleted_at (deleted_at)');}catch(Throwable){}
   DB::run("INSERT INTO settings (`key`,value) VALUES ('schema_version','9') ON DUPLICATE KEY UPDATE value='9'");$version=9;
  }
  if($version<10){
   self::column('users','twofa_secret',"ALTER TABLE users ADD COLUMN twofa_secret VARCHAR(190) NULL AFTER weekly_report");
   self::column('users','twofa_enabled',"ALTER TABLE users ADD COLUMN twofa_enabled TINYINT NOT NULL DEFAULT 0 AFTER twofa_secret");
   self::column('users','twofa_recovery_json',"ALTER TABLE users ADD COLUMN twofa_recovery_json TEXT NULL AFTER twofa_enabled");
   self::column('users','last_login_at',"ALTER TABLE users ADD COLUMN last_login_at DATETIME NULL AFTER twofa_recovery_json");
   self::column('users','last_login_ip',"ALTER TABLE users ADD COLUMN last_login_ip VARCHAR(45) NULL AFTER last_login_at");

   self::column('categories','default_amount_cents',"ALTER TABLE categories ADD COLUMN default_amount_cents BIGINT NOT NULL DEFAULT 0 AFTER fields_json");
   self::column('categories','default_due_hours',"ALTER TABLE categories ADD COLUMN default_due_hours INT NOT NULL DEFAULT 24 AFTER default_amount_cents");
   self::column('categories','default_revisions',"ALTER TABLE categories ADD COLUMN default_revisions INT NOT NULL DEFAULT 2 AFTER default_due_hours");
   self::column('categories','default_priority',"ALTER TABLE categories ADD COLUMN default_priority VARCHAR(20) NOT NULL DEFAULT 'normal' AFTER default_revisions");
   self::column('categories','default_assigned_to',"ALTER TABLE categories ADD COLUMN default_assigned_to INT NULL AFTER default_priority");
   self::column('categories','checklist_json',"ALTER TABLE categories ADD COLUMN checklist_json TEXT NULL AFTER default_assigned_to");
   DB::run("UPDATE categories SET checklist_json='[]' WHERE checklist_json IS NULL OR checklist_json=''");

   DB::run("CREATE TABLE IF NOT EXISTS push_subscriptions (
    id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    endpoint TEXT NOT NULL,
    endpoint_hash CHAR(64) NOT NULL,
    p256dh TEXT NULL,
    auth_key TEXT NULL,
    user_agent VARCHAR(255) NULL,
    active TINYINT NOT NULL DEFAULT 1,
    last_error TEXT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_push_endpoint_hash (endpoint_hash),
    KEY idx_push_user_active (user_id,active)
   ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

   DB::run("CREATE TABLE IF NOT EXISTS system_errors (
    id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    level VARCHAR(20) NOT NULL DEFAULT 'error',
    context VARCHAR(100) NOT NULL DEFAULT 'app',
    message TEXT NOT NULL,
    request_uri TEXT NULL,
    details_json LONGTEXT NULL,
    resolved_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    KEY idx_system_errors_created (created_at),
    KEY idx_system_errors_open (resolved_at,created_at)
   ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

   self::index('demands','idx_demands_ops',"ALTER TABLE demands ADD INDEX idx_demands_ops (deleted_at,stage_id,client_id,category_id,assigned_to,due_at)");
   self::index('invoices','idx_invoices_ops',"ALTER TABLE invoices ADD INDEX idx_invoices_ops (deleted_at,client_id,due_on,status)");
   self::index('audit_log','idx_audit_entity',"ALTER TABLE audit_log ADD INDEX idx_audit_entity (entity,entity_id,created_at)");
   self::index('notifications','idx_notifications_user',"ALTER TABLE notifications ADD INDEX idx_notifications_user (user_id,seen,created_at)");
   self::index('events','idx_events_calendar_time',"ALTER TABLE events ADD INDEX idx_events_calendar_time (calendar_id,starts_at,status)");

   foreach([
    'last_cron_at'=>'',
    'last_cron_error'=>'',
    'monitor_email'=>'',
    'browser_notifications'=>'1',
    'dashboard_default_period'=>'month'
   ] as $k=>$v)DB::run('INSERT INTO settings (`key`,value) VALUES (?,?) ON DUPLICATE KEY UPDATE value=value',[$k,$v]);

   DB::run("INSERT INTO settings (`key`,value) VALUES ('schema_version','10') ON DUPLICATE KEY UPDATE value='10'");$version=10;
  }
 }
 private static function hasColumn(string $table,string $column): bool {return (bool)DB::one('SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?',[$table,$column]);}
 private static function column(string $table,string $column,string $sql): void {if(!self::hasColumn($table,$column))DB::run($sql);}
 private static function hasIndex(string $table,string $index): bool {return (bool)DB::one('SELECT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?',[$table,$index]);}
 private static function index(string $table,string $index,string $sql): void {if(!self::hasIndex($table,$index))DB::run($sql);}
 private static function dropColumn(string $table,string $column): void {
  if(!self::hasColumn($table,$column))return;
  $fks=DB::all('SELECT CONSTRAINT_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=? AND REFERENCED_TABLE_NAME IS NOT NULL',[$table,$column]);
  foreach($fks as $fk){$name=str_replace('`','``',(string)$fk['CONSTRAINT_NAME']);DB::run("ALTER TABLE `$table` DROP FOREIGN KEY `$name`");}
  DB::run("ALTER TABLE `$table` DROP COLUMN `$column`");
 }
}

