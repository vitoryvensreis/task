<?php
declare(strict_types=1);

/** Additive tenant inbox migration, inert and guarded by verified backup. */
final class SaasInternalInboxMigration {
 public const VERSION=8;
 public const MARKER='saas_internal_inbox_version';
 private const SQL='/database/migrations/036_saas_internal_inbox.sql';

 public static function issues(): array {
  $table='saas_internal_inbox';
  $info=DB::one('SELECT ENGINE FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',[$table]);
  if(!$info)return ['missing_saas_internal_inbox'];
  $issues=[];
  if(strcasecmp((string)$info['ENGINE'],'InnoDB')!==0)$issues[]='engine';
  $required=['id'=>'bigint','tenant_account_id'=>'bigint','delivery_job_id'=>'bigint',
   'recipient_user_id'=>'int','event_type'=>'varchar','entity_type'=>'varchar',
   'entity_id'=>'bigint','created_at'=>'datetime'];
  $actual=[];
  foreach(DB::all('SELECT COLUMN_NAME,DATA_TYPE,COLUMN_TYPE,IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',[$table]) as $row)$actual[$row['COLUMN_NAME']]=$row;
  foreach($required as $column=>$type){
   if(!isset($actual[$column])){$issues[]='column_'.$column;continue;}
   if($actual[$column]['DATA_TYPE']!==$type)$issues[]='type_'.$column;
   if($actual[$column]['IS_NULLABLE']!=='NO')$issues[]='nullable_'.$column;
   if(in_array($column,['id','tenant_account_id','delivery_job_id','entity_id'],true)&&
    !str_contains(strtolower((string)$actual[$column]['COLUMN_TYPE']),'unsigned'))$issues[]='unsigned_'.$column;
  }
  foreach(['PRIMARY'=>['id'],
   'uq_saas_inbox_delivery_user'=>['tenant_account_id','delivery_job_id','recipient_user_id'],
   'idx_saas_inbox_user'=>['tenant_account_id','recipient_user_id','id'],
   'idx_saas_inbox_entity'=>['tenant_account_id','entity_type','entity_id']] as $name=>$expected){
   $index=DB::all('SELECT COLUMN_NAME,NON_UNIQUE FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=? ORDER BY SEQ_IN_INDEX',[$table,$name]);
   if(array_column($index,'COLUMN_NAME')!==$expected)$issues[]='index_'.$name;
   if(in_array($name,['PRIMARY','uq_saas_inbox_delivery_user'],true)&&$index&&(int)$index[0]['NON_UNIQUE']!==0)$issues[]='nonunique_'.$name;
  }
  $foreign=[];
  foreach(DB::all('SELECT COLUMN_NAME,REFERENCED_TABLE_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND REFERENCED_TABLE_NAME IS NOT NULL',[$table]) as $row)$foreign[$row['COLUMN_NAME']]=$row['REFERENCED_TABLE_NAME'];
  foreach(['tenant_account_id'=>'accounts','delivery_job_id'=>'saas_delivery_jobs','recipient_user_id'=>'users'] as $column=>$parent)
   if(($foreign[$column]??'')!==$parent)$issues[]='fk_'.$column;
  return $issues;
 }

 public static function apply(): array {
  $db=DB::conn();
  if($db->inTransaction())throw new LogicException('Inbox DDL cannot run inside a transaction.');
  $lock='taskesc-saas-inbox-'.(string)(DB::one('SELECT SHA1(DATABASE()) h')['h']??'');
  if((int)(DB::one('SELECT GET_LOCK(?,10) acquired',[$lock])['acquired']??0)!==1)
   throw new RuntimeException('Concurrent inbox migration refused.');
  try{
   if((int)(DB::one("SELECT value FROM settings WHERE `key`='schema_version'")['value']??0)!==30)
    throw new RuntimeException('Operational schema 30 is required.');
   foreach([
    SaasFoundationMigration::MARKER=>1,SaasDomainMigration::MARKER=>2,
    SaasChildMigration::MARKER=>3,SaasOutboxMigration::MARKER=>4,
    SaasReceiptMigration::MARKER=>5,SaasDeliveryMigration::MARKER=>6,
    SaasChannelMigration::MARKER=>7
   ] as $key=>$expected)
    if((int)(DB::one('SELECT value FROM settings WHERE `key`=?',[$key])['value']??0)!==$expected)
     throw new RuntimeException('SaaS prerequisite migration missing.');
   if((int)(DB::one('SELECT value FROM settings WHERE `key`=?',[self::MARKER])['value']??0)>self::VERSION)
    throw new RuntimeException('Unknown newer inbox version.');
   $account=DB::one('SELECT id FROM accounts WHERE slug=? AND account_type=? AND status=?',
    [AccountContext::LEGACY_SLUG,'internal','active']);
   if(!$account||(int)(DB::one('SELECT COUNT(*) n FROM accounts')['n']??0)!==1)
    throw new RuntimeException('Only the single internal tenant can migrate.');
   $flags=DB::all('SELECT `key`,value FROM account_settings WHERE account_id=? AND `key` IN (?,?)',
    [(int)$account['id'],'saas.external_signup_enabled','saas.multi_account_enabled']);
   if(count($flags)!==2)throw new RuntimeException('Missing SaaS safety flags.');
   foreach($flags as $flag)if((string)$flag['value']!=='0')throw new RuntimeException('External tenants must remain disabled.');
   if(SaasChannelMigration::issues())throw new RuntimeException('Encrypted channel vault invalid.');
   $raw=file_get_contents(ROOT.self::SQL);
   if($raw===false)throw new RuntimeException('Missing inbox DDL.');
   $sql=trim((string)preg_replace('/^\s*--[^\n]*\n/m','',$raw));
   if(!preg_match('/^CREATE TABLE IF NOT EXISTS saas_internal_inbox\s*\(/i',$sql)||
    substr_count($sql,';')!==1||!preg_match('/ENGINE=InnoDB[^;]*;\s*$/i',$sql))
    throw new RuntimeException('Unexpected inbox DDL.');
   $db->exec($sql);
   if($issues=self::issues())throw new RuntimeException('Inbox schema invalid: '.implode(',',$issues));
   DB::run('INSERT INTO settings (`key`,value) VALUES (?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)',
    [self::MARKER,(string)self::VERSION]);
   return ['inbox_version'=>self::VERSION,'internal_account_id'=>(int)$account['id'],
    'outbound_enabled'=>false,'external_tenants_enabled'=>false];
  }finally{DB::run('SELECT RELEASE_LOCK(?)',[$lock]);}
 }
}
