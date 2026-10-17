<?php
declare(strict_types=1);

/**
 * B1.2c-2b.2a: additive, inert staging outbox for SaaS events.
 * Nothing is published by this migration or the new staged task handlers.
 */
final class SaasOutboxMigration {
 public const VERSION=4;
 public const MARKER='saas_outbox_version';
 private const SQL='/database/migrations/032_saas_event_outbox.sql';

 public static function issues(): array {
  $table='saas_event_outbox';
  $info=DB::one('SELECT ENGINE FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',[$table]);
  if(!$info)return ['missing_saas_event_outbox'];
  $issues=[];
  if(strcasecmp((string)$info['ENGINE'],'InnoDB')!==0)$issues[]='invalid_saas_outbox_engine';
  $cols=[
   'id'=>'bigint','tenant_account_id'=>'bigint','event_key'=>'char','event_hash'=>'char',
   'event_type'=>'varchar','entity_type'=>'varchar','entity_id'=>'bigint',
   'payload_json'=>null,'created_by'=>'int','created_at'=>'datetime',
   'available_at'=>'datetime','published_at'=>'datetime','attempts'=>'int','last_error'=>'varchar'
  ];
  $actual=[];
  foreach(DB::all('SELECT COLUMN_NAME,DATA_TYPE,COLUMN_TYPE,IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',[$table]) as $row)
   $actual[$row['COLUMN_NAME']]=$row;
  foreach($cols as $name=>$type){
   if(!isset($actual[$name])){$issues[]='missing_column_'.$name;continue;}
   if($type!==null&&$actual[$name]['DATA_TYPE']!==$type)$issues[]='invalid_column_'.$name;
  }
  foreach(['id','tenant_account_id','entity_id'] as $column)
   if(isset($actual[$column])&&!str_contains(strtolower((string)$actual[$column]['COLUMN_TYPE']),'unsigned'))$issues[]='unsigned_'.$column;
  if(isset($actual['tenant_account_id'])&&$actual['tenant_account_id']['IS_NULLABLE']!=='NO')$issues[]='nullable_tenant';
  foreach(['event_key','event_hash'] as $column)
   if(isset($actual[$column])&&$actual[$column]['IS_NULLABLE']!=='NO')$issues[]='nullable_'.$column;
  $requiredIndexes=[
   'PRIMARY'=>['id'],'uq_saas_outbox_account_key'=>['tenant_account_id','event_key'],
   'idx_saas_outbox_pending'=>['tenant_account_id','published_at','available_at','id'],
   'idx_saas_outbox_entity'=>['tenant_account_id','entity_type','entity_id','id']
  ];
  foreach($requiredIndexes as $name=>$columns){
   $rows=DB::all('SELECT COLUMN_NAME,NON_UNIQUE FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=? ORDER BY SEQ_IN_INDEX',[$table,$name]);
   if(array_column($rows,'COLUMN_NAME')!==$columns)$issues[]='index_'.$name;
   if(in_array($name,['PRIMARY','uq_saas_outbox_account_key'],true)&&$rows&&(int)$rows[0]['NON_UNIQUE']!==0)$issues[]='unique_'.$name;
  }
  $fk=DB::all('SELECT COLUMN_NAME,REFERENCED_TABLE_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND REFERENCED_TABLE_NAME IS NOT NULL',[$table]);
  $refs=[];foreach($fk as $row)$refs[$row['COLUMN_NAME']]=$row['REFERENCED_TABLE_NAME'];
  if(($refs['tenant_account_id']??'')!=='accounts')$issues[]='account_fk';
  if(($refs['created_by']??'')!=='users')$issues[]='actor_fk';
  return $issues;
 }

 public static function apply(): array {
  $db=DB::conn();
  if($db->inTransaction())throw new LogicException('A migração B1.2c-2b.2a não pode executar dentro de transação.');
  $name='taskesc-b12evt-'.(string)(DB::one('SELECT SHA1(DATABASE()) h')['h']??'');
  if((int)(DB::one('SELECT GET_LOCK(?,10) acquired',[$name])['acquired']??0)!==1)
   throw new RuntimeException('A migração da Outbox SaaS já está em execução.');
  try {
   $schema=(int)(DB::one("SELECT value FROM settings WHERE `key`='schema_version'")['value']??0);
   if($schema!==30)throw new RuntimeException('Schema operacional 30 obrigatório; schema 20 foi removido.');
   foreach([
    SaasFoundationMigration::MARKER=>1,
    SaasDomainMigration::MARKER=>2,
    SaasChildMigration::MARKER=>3
   ] as $key=>$expected){
    if((int)(DB::one('SELECT value FROM settings WHERE `key`=?',[$key])['value']??0)!==$expected)
     throw new RuntimeException('A migração anterior da fundação SaaS está pendente.');
   }
   $current=(int)(DB::one('SELECT value FROM settings WHERE `key`=?',[self::MARKER])['value']??0);
   if($current>self::VERSION)throw new RuntimeException('Versão futura inesperada da Outbox SaaS.');
   $account=DB::one('SELECT id FROM accounts WHERE slug=? AND account_type=?',
    [AccountContext::LEGACY_SLUG,'internal']);
   if(!$account||(int)(DB::one('SELECT COUNT(*) n FROM accounts')['n']??0)!==1)
    throw new RuntimeException('Apenas a operação interna pode migrar durante B1.');
   $flags=DB::all('SELECT `key`,value FROM account_settings WHERE account_id=? AND `key` IN (?,?)',
    [(int)$account['id'],'saas.external_signup_enabled','saas.multi_account_enabled']);
   if(count($flags)!==2)throw new RuntimeException('Flags de bloqueio ausentes.');
   foreach($flags as $flag)if((string)$flag['value']!=='0')throw new RuntimeException('SaaS habilitado indevidamente.');
   if(SaasDomainMigration::issues(true)||SaasChildMigration::issues(true))
    throw new RuntimeException('Há registros sem ownership nas migrações anteriores.');
   $file=file_get_contents(ROOT.self::SQL);
   if($file===false)throw new RuntimeException('SQL da Outbox SaaS não encontrado.');
   $sql=trim((string)preg_replace('/^\s*--[^\n]*\n/m','',$file));
   if(!preg_match('/^CREATE TABLE IF NOT EXISTS saas_event_outbox\s*\(/i',$sql)||
      !preg_match('/ENGINE=InnoDB[^;]*;\s*$/i',$sql)||
      substr_count($sql,';')!==1)
    throw new RuntimeException('DDL inesperado na migração.');
   $db->exec($sql);
   $issues=self::issues();
   if($issues)throw new RuntimeException('Outbox SaaS incompatível: '.implode(',',$issues));
   DB::run('INSERT INTO settings (`key`,value) VALUES (?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)',
    [self::MARKER,(string)self::VERSION]);
   return ['outbox_version'=>self::VERSION,'schema_version'=>$schema,
     'internal_account_id'=>(int)$account['id'],'publishers_enabled'=>false,'external_tenants_enabled'=>false];
  } finally {DB::run('SELECT RELEASE_LOCK(?)',[$name]);}
 }
}
