<?php
declare(strict_types=1);

/** Isolated delivery-intent schema. NO provider is enabled by this migration. */
final class SaasDeliveryMigration {
 public const VERSION=6;
 public const MARKER='saas_delivery_version';
 private const SQL='/database/migrations/034_saas_delivery_jobs.sql';

 public static function issues(): array {
  $table='saas_delivery_jobs';
  $info=DB::one('SELECT ENGINE FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',[$table]);
  if(!$info)return ['missing_saas_delivery_jobs'];
  $issues=[];
  if(strcasecmp((string)$info['ENGINE'],'InnoDB')!==0)$issues[]='invalid_engine';
  $columns=[
   'id'=>'bigint','tenant_account_id'=>'bigint','receipt_id'=>'bigint',
   'channel'=>'varchar','destination_scope'=>'varchar','event_hash'=>'char',
   'status'=>'varchar','attempts'=>'int','available_at'=>'datetime',
   'created_at'=>'datetime','updated_at'=>'datetime','last_error'=>'varchar'
  ];
  $actual=[];
  foreach(DB::all('SELECT COLUMN_NAME,DATA_TYPE,COLUMN_TYPE,IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',[$table]) as $row)
   $actual[$row['COLUMN_NAME']]=$row;
  foreach($columns as $name=>$type){
   if(!isset($actual[$name])){$issues[]='missing_column_'.$name;continue;}
   if((string)$actual[$name]['DATA_TYPE']!==$type)$issues[]='wrong_type_'.$name;
   if($name!=='last_error'&&$actual[$name]['IS_NULLABLE']!=='NO')$issues[]='nullable_'.$name;
   if(in_array($name,['id','tenant_account_id','receipt_id','attempts'],true)&&
    !str_contains(strtolower((string)$actual[$name]['COLUMN_TYPE']),'unsigned'))
    $issues[]='wrong_unsigned_'.$name;
  }
  foreach([
   'PRIMARY'=>['id'],
   'uq_saas_delivery_receipt_channel'=>['tenant_account_id','receipt_id','channel'],
   'idx_saas_delivery_due'=>['tenant_account_id','status','available_at','id'],
   'idx_saas_delivery_account'=>['tenant_account_id','id']
  ] as $name=>$expected){
   $index=DB::all('SELECT COLUMN_NAME,NON_UNIQUE FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=? ORDER BY SEQ_IN_INDEX',[$table,$name]);
   if(array_column($index,'COLUMN_NAME')!==$expected)$issues[]='index_'.$name;
   if(in_array($name,['PRIMARY','uq_saas_delivery_receipt_channel'],true)&&$index&&(int)$index[0]['NON_UNIQUE']!==0)$issues[]='unique_'.$name;
  }
  $refs=DB::all('SELECT COLUMN_NAME,REFERENCED_TABLE_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND REFERENCED_TABLE_NAME IS NOT NULL',[$table]);
  $foreign=[];foreach($refs as $ref)$foreign[$ref['COLUMN_NAME']]=$ref['REFERENCED_TABLE_NAME'];
  if(($foreign['tenant_account_id']??'')!=='accounts')$issues[]='account_fk';
  if(($foreign['receipt_id']??'')!=='saas_event_receipts')$issues[]='receipt_fk';
  return $issues;
 }

 public static function apply(): array {
  $db=DB::conn();
  if($db->inTransaction())throw new LogicException('Migration DDL must not run inside a transaction.');
  $lock='taskesc-b12dlv-'.(string)(DB::one('SELECT SHA1(DATABASE()) h')['h']??'');
  if((int)(DB::one('SELECT GET_LOCK(?,10) acquired',[$lock])['acquired']??0)!==1)
   throw new RuntimeException('Delivery migration already in progress.');
  try{
   $schema=(int)(DB::one("SELECT value FROM settings WHERE `key`='schema_version'")['value']??0);
   if($schema!==30)throw new RuntimeException('Expected operational schema 30 (not removed SaaS schema 20).');
   foreach([
    SaasFoundationMigration::MARKER=>1,
    SaasDomainMigration::MARKER=>2,
    SaasChildMigration::MARKER=>3,
    SaasOutboxMigration::MARKER=>4,
    SaasReceiptMigration::MARKER=>5
   ] as $key=>$v)
    if((int)(DB::one('SELECT value FROM settings WHERE `key`=?',[$key])['value']??0)!==$v)
     throw new RuntimeException('Prerequisite SaaS migration missing.');
   $current=(int)(DB::one('SELECT value FROM settings WHERE `key`=?',[self::MARKER])['value']??0);
   if($current>self::VERSION)throw new RuntimeException('Future migration marker detected.');
   $internal=DB::one('SELECT id FROM accounts WHERE slug=? AND account_type=?',
    [AccountContext::LEGACY_SLUG,'internal']);
   if(!$internal||(int)(DB::one('SELECT COUNT(*) n FROM accounts')['n']??0)!==1)
    throw new RuntimeException('Only the single internal account may migrate.');
   $flags=DB::all('SELECT `key`,value FROM account_settings WHERE account_id=? AND `key` IN (?,?)',
    [(int)$internal['id'],'saas.external_signup_enabled','saas.multi_account_enabled']);
   if(count($flags)!==2)throw new RuntimeException('Required feature gates missing.');
   foreach($flags as $flag)if((string)$flag['value']!=='0')
    throw new RuntimeException('Tenant onboarding must remain disabled.');
   if(SaasReceiptMigration::issues())throw new RuntimeException('Invalid receipt prerequisites.');
   $raw=file_get_contents(ROOT.self::SQL);
   if($raw===false)throw new RuntimeException('Delivery jobs migration SQL missing.');
   $sql=trim((string)preg_replace('/^\s*--[^\n]*\n/m','',$raw));
   if(!preg_match('/^CREATE TABLE IF NOT EXISTS saas_delivery_jobs\s*\(/i',$sql)||
      substr_count($sql,';')!==1||!preg_match('/ENGINE=InnoDB[^;]*;\s*$/i',$sql))
    throw new RuntimeException('Unexpected delivery migration DDL.');
   $db->exec($sql);
   if($issues=self::issues())throw new RuntimeException('Delivery jobs schema mismatch: '.implode(',',$issues));
   DB::run('INSERT INTO settings (`key`,value) VALUES (?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)',
    [self::MARKER,(string)self::VERSION]);
   return ['delivery_version'=>self::VERSION,'internal_account_id'=>(int)$internal['id'],
    'external_delivery_enabled'=>false,'external_tenants_enabled'=>false];
  }finally{
   DB::run('SELECT RELEASE_LOCK(?)',[$lock]);
  }
 }
}
