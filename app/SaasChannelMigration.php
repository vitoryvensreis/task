<?php
declare(strict_types=1);

/** Staged provider credential storage; never activates providers or workers. */
final class SaasChannelMigration {
 public const VERSION=7;
 public const MARKER='saas_channels_version';
 private const SQL='/database/migrations/035_saas_channel_secrets.sql';

 public static function issues(): array {
  $table='saas_channel_secrets';
  $info=DB::one('SELECT ENGINE FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',[$table]);
  if(!$info)return ['missing_saas_channel_secrets'];
  $issues=[];
  if(strcasecmp((string)$info['ENGINE'],'InnoDB')!==0)$issues[]='invalid_engine';
  $expected=[
   'id'=>'bigint','tenant_account_id'=>'bigint','channel'=>'varchar',
   'provider'=>'varchar','secret_envelope'=>'longtext','fingerprint'=>'char',
   'revision'=>'int','status'=>'varchar','updated_by'=>'int',
   'created_at'=>'datetime','updated_at'=>'datetime'
  ];
  $cols=[];
  foreach(DB::all('SELECT COLUMN_NAME,DATA_TYPE,COLUMN_TYPE,IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',[$table]) as $r)$cols[$r['COLUMN_NAME']]=$r;
  foreach($expected as $name=>$type){
   if(!isset($cols[$name])){$issues[]='missing_'.$name;continue;}
   if($cols[$name]['DATA_TYPE']!==$type)$issues[]='type_'.$name;
   if($cols[$name]['IS_NULLABLE']!=='NO')$issues[]='nullable_'.$name;
   if(in_array($name,['id','tenant_account_id','revision'],true)&&
      !str_contains(strtolower((string)$cols[$name]['COLUMN_TYPE']),'unsigned'))$issues[]='unsigned_'.$name;
  }
  foreach([
   'PRIMARY'=>['id'],
   'uq_saas_channel_owner'=>['tenant_account_id','channel'],
   'idx_saas_channel_account'=>['tenant_account_id','status','id']
  ] as $index=>$expectedColumns){
   $rows=DB::all('SELECT COLUMN_NAME,NON_UNIQUE FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=? ORDER BY SEQ_IN_INDEX',[$table,$index]);
   if(array_column($rows,'COLUMN_NAME')!==$expectedColumns)$issues[]='index_'.$index;
   if(in_array($index,['PRIMARY','uq_saas_channel_owner'],true)&&$rows&&(int)$rows[0]['NON_UNIQUE']!==0)$issues[]='nonunique_'.$index;
  }
  $refs=[];
  foreach(DB::all('SELECT COLUMN_NAME,REFERENCED_TABLE_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND REFERENCED_TABLE_NAME IS NOT NULL',[$table]) as $r)$refs[$r['COLUMN_NAME']]=$r['REFERENCED_TABLE_NAME'];
  if(($refs['tenant_account_id']??'')!=='accounts')$issues[]='account_fk';
  if(($refs['updated_by']??'')!=='users')$issues[]='editor_fk';
  return $issues;
 }

 public static function apply(): array {
  $db=DB::conn();
  if($db->inTransaction())throw new LogicException('SaaS vault DDL cannot run inside another transaction.');
  $lock='taskesc-b1vault-'.(string)(DB::one('SELECT SHA1(DATABASE()) h')['h']??'');
  if((int)(DB::one('SELECT GET_LOCK(?,10) acquired',[$lock])['acquired']??0)!==1)
   throw new RuntimeException('Concurrent vault migration refused.');
  try{
   if((int)(DB::one("SELECT value FROM settings WHERE `key`='schema_version'")['value']??0)!==30)
    throw new RuntimeException('Operational schema 30 required; old SaaS 20 is removed.');
   foreach([
    SaasFoundationMigration::MARKER=>1,SaasDomainMigration::MARKER=>2,
    SaasChildMigration::MARKER=>3,SaasOutboxMigration::MARKER=>4,
    SaasReceiptMigration::MARKER=>5,SaasDeliveryMigration::MARKER=>6
   ] as $key=>$expected)
    if((int)(DB::one('SELECT value FROM settings WHERE `key`=?',[$key])['value']??0)!==$expected)
     throw new RuntimeException('Earlier SaaS migration missing.');
   if((int)(DB::one('SELECT value FROM settings WHERE `key`=?',[self::MARKER])['value']??0)>self::VERSION)
    throw new RuntimeException('Unrecognized newer vault migration.');
   $account=DB::one('SELECT id FROM accounts WHERE slug=? AND account_type=? AND status=?',
    [AccountContext::LEGACY_SLUG,'internal','active']);
   if(!$account||(int)(DB::one('SELECT COUNT(*) n FROM accounts')['n']??0)!==1)
    throw new RuntimeException('Vault migration permits only the existing internal account.');
   $flags=DB::all('SELECT `key`,value FROM account_settings WHERE account_id=? AND `key` IN (?,?)',
    [(int)$account['id'],'saas.external_signup_enabled','saas.multi_account_enabled']);
   if(count($flags)!==2)throw new RuntimeException('Missing tenant activation guards.');
   foreach($flags as $flag)if((string)$flag['value']!=='0')throw new RuntimeException('SaaS activation must remain off.');
   if(SaasDeliveryMigration::issues())throw new RuntimeException('Delivery migration invalid.');
   $raw=file_get_contents(ROOT.self::SQL);
   if($raw===false)throw new RuntimeException('Vault SQL missing.');
   $sql=trim((string)preg_replace('/^\s*--[^\n]*\n/m','',$raw));
   if(!preg_match('/^CREATE TABLE IF NOT EXISTS saas_channel_secrets\s*\(/i',$sql)||
     substr_count($sql,';')!==1||!preg_match('/ENGINE=InnoDB[^;]*;\s*$/i',$sql))
    throw new RuntimeException('Unexpected vault DDL.');
   $db->exec($sql);
   if($issues=self::issues())throw new RuntimeException('Vault schema invalid: '.implode(',',$issues));
   DB::run('INSERT INTO settings (`key`,value) VALUES (?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)',
    [self::MARKER,(string)self::VERSION]);
   return ['vault_version'=>self::VERSION,'internal_account_id'=>(int)$account['id'],
    'live_transports_enabled'=>false,'external_tenants_enabled'=>false];
  }finally{DB::run('SELECT RELEASE_LOCK(?)',[$lock]);}
 }
}
