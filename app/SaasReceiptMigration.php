<?php
declare(strict_types=1);

/**
 * B1.2c-2b.2b.1: staged event-preparation receipts.
 * Does not authorize publishing, external providers or tenant onboarding.
 */
final class SaasReceiptMigration {
 public const VERSION=5;
 public const MARKER='saas_receipts_version';
 private const SQL='/database/migrations/033_saas_event_receipts.sql';

 public static function issues(): array {
  $table='saas_event_receipts';
  $info=DB::one('SELECT ENGINE FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',[$table]);
  if(!$info)return ['missing_saas_event_receipts'];
  $issues=[];
  if(strcasecmp((string)$info['ENGINE'],'InnoDB')!==0)$issues[]='engine';
  $columns=[
   'id'=>'bigint','tenant_account_id'=>'bigint','outbox_id'=>'bigint',
   'event_hash'=>'char','event_type'=>'varchar','entity_id'=>'bigint',
   'status'=>'varchar','prepared_at'=>'datetime'
  ];
  $actual=[];
  foreach(DB::all('SELECT COLUMN_NAME,DATA_TYPE,COLUMN_TYPE,IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',[$table]) as $row)
   $actual[$row['COLUMN_NAME']]=$row;
  foreach($columns as $name=>$type){
   if(!isset($actual[$name])){$issues[]='column_'.$name;continue;}
   if($actual[$name]['DATA_TYPE']!==$type)$issues[]='type_'.$name;
   if($actual[$name]['IS_NULLABLE']!=='NO')$issues[]='nullable_'.$name;
   if(in_array($name,['id','tenant_account_id','outbox_id','entity_id'],true)&&
    !str_contains(strtolower((string)$actual[$name]['COLUMN_TYPE']),'unsigned'))
    $issues[]='unsigned_'.$name;
  }
  foreach([
   'PRIMARY'=>['id'],
   'uq_saas_receipt_event'=>['tenant_account_id','outbox_id'],
   'idx_saas_receipts_account'=>['tenant_account_id','status','id']
  ] as $name=>$expected){
   $idx=DB::all('SELECT COLUMN_NAME,NON_UNIQUE FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=? ORDER BY SEQ_IN_INDEX',[$table,$name]);
   if(array_column($idx,'COLUMN_NAME')!==$expected)$issues[]='index_'.$name;
   if(in_array($name,['PRIMARY','uq_saas_receipt_event'],true)&&$idx&&(int)$idx[0]['NON_UNIQUE']!==0)
    $issues[]='unique_'.$name;
  }
  $fks=DB::all('SELECT COLUMN_NAME,REFERENCED_TABLE_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND REFERENCED_TABLE_NAME IS NOT NULL',[$table]);
  $refs=[];foreach($fks as $fk)$refs[$fk['COLUMN_NAME']]=$fk['REFERENCED_TABLE_NAME'];
  if(($refs['tenant_account_id']??'')!=='accounts')$issues[]='account_fk';
  if(($refs['outbox_id']??'')!=='saas_event_outbox')$issues[]='outbox_fk';
  return $issues;
 }

 public static function apply(): array {
  $db=DB::conn();
  if($db->inTransaction())throw new LogicException('Migração de recibos não pode executar numa transação aberta.');
  $lock='taskesc-b12rcp-'.(string)(DB::one('SELECT SHA1(DATABASE()) h')['h']??'');
  if((int)(DB::one('SELECT GET_LOCK(?,10) acquired',[$lock])['acquired']??0)!==1)
   throw new RuntimeException('Migração de recibos já em execução.');
  try{
   $schema=(int)(DB::one("SELECT value FROM settings WHERE `key`='schema_version'")['value']??0);
   if($schema!==30)throw new RuntimeException('Requer schema operacional 30, não o SaaS antigo.');
   foreach([
    SaasFoundationMigration::MARKER=>1,
    SaasDomainMigration::MARKER=>2,
    SaasChildMigration::MARKER=>3,
    SaasOutboxMigration::MARKER=>4
   ] as $key=>$v)
    if((int)(DB::one('SELECT value FROM settings WHERE `key`=?',[$key])['value']??0)!==$v)
     throw new RuntimeException('Migração SaaS anterior pendente.');
   $next=(int)(DB::one('SELECT value FROM settings WHERE `key`=?',[self::MARKER])['value']??0);
   if($next>self::VERSION)throw new RuntimeException('Versão futura da migração encontrada.');
   $account=DB::one('SELECT id FROM accounts WHERE slug=? AND account_type=?',[AccountContext::LEGACY_SLUG,'internal']);
   if(!$account||(int)(DB::one('SELECT COUNT(*) n FROM accounts')['n']??0)!==1)
    throw new RuntimeException('Migração bloqueada enquanto existir outra empresa.');
   $flags=DB::all('SELECT `key`,value FROM account_settings WHERE account_id=? AND `key` IN (?,?)',
    [(int)$account['id'],'saas.external_signup_enabled','saas.multi_account_enabled']);
   if(count($flags)!==2)throw new RuntimeException('Flags de segurança ausentes.');
   foreach($flags as $flag)if((string)$flag['value']!=='0')throw new RuntimeException('SaaS ativado indevidamente.');
   if(SaasOutboxMigration::issues())throw new RuntimeException('Outbox SaaS incompatível.');
   $sql=file_get_contents(ROOT.self::SQL);
   if($sql===false)throw new RuntimeException('DDL dos recibos ausente.');
   $sql=trim((string)preg_replace('/^\s*--[^\n]*\n/m','',$sql));
   if(!preg_match('/^CREATE TABLE IF NOT EXISTS saas_event_receipts\s*\(/i',$sql)||
      substr_count($sql,';')!==1||
      !preg_match('/ENGINE=InnoDB[^;]*;\s*$/i',$sql))
    throw new RuntimeException('DDL inesperado.');
   $db->exec($sql);
   $issues=self::issues();
   if($issues)throw new RuntimeException('Recibos inválidos: '.implode(',',$issues));
   DB::run('INSERT INTO settings (`key`,value) VALUES (?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)',
    [self::MARKER,(string)self::VERSION]);
   return ['receipts_version'=>self::VERSION,'legacy_account_id'=>(int)$account['id'],
     'delivery_enabled'=>false,'external_tenants_enabled'=>false];
  }finally{DB::run('SELECT RELEASE_LOCK(?)',[$lock]);}
 }
}
