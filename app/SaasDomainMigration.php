<?php
declare(strict_types=1);

/**
 * B1.2a — staging columns for independently-owned top-level records.
 *
 * Every real user-facing code path still runs in legacy single-account mode.
 * New tenant onboarding MUST remain disabled until child tables and all
 * APIs/workers/realtime/downloads are scoped and tested.
 *
 * IMPORTANT: `calendars.account_id` already points to google_accounts.id,
 * so the unambiguous column is `tenant_account_id` throughout the domain.
 */
final class SaasDomainMigration {
 public const VERSION=2;
 public const MARKER='saas_domain_version';
 public const ROOT_TABLES=[
  'clients','stages','categories','projects','contracts','demands',
  'invoices','automations','whatsapp_contacts','expense_categories',
  'expense_rules','expenses','templates','google_accounts','calendars'
 ];
 public const COLUMN='tenant_account_id';

 private static function hasColumn(string $table): bool {
  return (bool)DB::one(
   'SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=? LIMIT 1',
   [$table,self::COLUMN]
  );
 }
 private static function hasIndex(string $table): bool {
  return (bool)DB::one(
   'SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=? LIMIT 1',
   [$table,'idx_saas_'.$table]
  );
 }
 public static function issues(bool $verifyRows=false): array {
  $issues=[];
  foreach(self::ROOT_TABLES as $table){
   if(!self::hasColumn($table)){$issues[]='missing_column_'.$table;continue;}
   $column=DB::one(
    'SELECT DATA_TYPE,COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?',
    [$table,self::COLUMN]
   );
   if(($column['DATA_TYPE']??'')!=='bigint'||!str_contains(strtolower((string)($column['COLUMN_TYPE']??'')),'unsigned'))$issues[]='invalid_column_'.$table;
   if(!self::hasIndex($table))$issues[]='missing_index_'.$table;
   if($verifyRows&&((int)(DB::one('SELECT COUNT(*) n FROM `'.$table.'` WHERE tenant_account_id IS NULL')['n']??0)>0))
    $issues[]='orphan_records_'.$table;
  }
  return $issues;
 }

 public static function apply(): array {
  $db=DB::conn();
  if($db->inTransaction())throw new LogicException('Migração B1.2a requer execução fora de transações.');
  $lock='taskesc-b12-'.(string)(DB::one('SELECT SHA1(DATABASE()) h')['h']??'');
  if((int)(DB::one('SELECT GET_LOCK(?,10) acquired',[$lock])['acquired']??0)!==1)throw new RuntimeException('Migração B1.2a já em execução.');
  try {
   $schema=(int)(DB::one("SELECT value FROM settings WHERE `key`='schema_version'")['value']??0);
   $foundation=(int)(DB::one('SELECT value FROM settings WHERE `key`=?',[SaasFoundationMigration::MARKER])['value']??0);
   if($schema!==30||$foundation!==1)throw new RuntimeException('B1.2a exige schema 30 e fundação B1.1 previamente verificada.');
   $version=(int)(DB::one('SELECT value FROM settings WHERE `key`=?',[self::MARKER])['value']??0);
   if($version>self::VERSION)throw new RuntimeException('Versão futura inesperada da migração B1.2a.');

   // Until every write path stamps its tenant, there can only be the internal account.
   $accountCount=(int)(DB::one('SELECT COUNT(*) n FROM accounts')['n']??0);
   $account=DB::one('SELECT id FROM accounts WHERE slug=? AND account_type=? LIMIT 1',
    [AccountContext::LEGACY_SLUG,'internal']);
   if(!$account||$accountCount!==1)throw new RuntimeException('Migração legada bloqueada: existe outra conta ou falta a conta interna.');
   $accountId=(int)$account['id'];
   $flags=DB::all('SELECT `key`,value FROM account_settings WHERE account_id=? AND `key` IN (?,?)',
    [$accountId,'saas.external_signup_enabled','saas.multi_account_enabled']);
   if(count($flags)!==2)throw new RuntimeException('Flags de segurança do SaaS ausentes.');
   foreach($flags as $flag)if($flag['value']!=='0')throw new RuntimeException('Migração proibida com SaaS habilitado.');

   // MySQL DDL implicitly commits; checkpoints rely on INFORMATION_SCHEMA,
   // so an interrupted run can be retried safely.
   foreach(self::ROOT_TABLES as $table){
    if(!self::hasColumn($table))
     DB::run('ALTER TABLE `'.$table.'` ADD COLUMN tenant_account_id BIGINT UNSIGNED NULL');
    if(!self::hasIndex($table))
     DB::run('ALTER TABLE `'.$table.'` ADD INDEX `idx_saas_'.$table.'` (tenant_account_id,id)');
   }

   // Inspect joins before assigning ownership, never repair cross-account
   // inconsistencies by overwriting existing values.
   $preflight=self::relationIssues($accountId);
   if($preflight)throw new RuntimeException('Vínculos entre recursos inválidos: '.implode(',',$preflight));

   return DB::tx(function()use($accountId,$schema):array {
    $affected=[];
    foreach(self::ROOT_TABLES as $table){
     $q=DB::run('UPDATE `'.$table.'` SET tenant_account_id=? WHERE tenant_account_id IS NULL',[$accountId]);
     $affected[$table]=$q->rowCount();
    }
    $issues=self::issues(true);
    if($issues)throw new RuntimeException('Migração incompleta: '.implode(',',$issues));
    $relations=self::relationIssues($accountId);
    if($relations)throw new RuntimeException('Inconsistência de domínio: '.implode(',',$relations));
    DB::run('INSERT INTO settings (`key`,value) VALUES (?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)',
     [self::MARKER,(string)self::VERSION]);
    return [
     'domain_version'=>self::VERSION,'schema_version'=>$schema,
     'legacy_account_id'=>$accountId,'backfilled'=>$affected,
     'multi_account_enabled'=>false
    ];
   });
  } finally {DB::run('SELECT RELEASE_LOCK(?)',[$lock]);}
 }

 /** Checks known multi-parent edges while staging; other modules follow in B1.2b. */
 public static function relationIssues(int $legacyId): array {
  $checks=[
   'projects.clients'=>"SELECT COUNT(*) n FROM projects p JOIN clients c ON c.id=p.client_id WHERE c.tenant_account_id IS NOT NULL AND c.tenant_account_id<>?",
   'contracts.clients'=>"SELECT COUNT(*) n FROM contracts x JOIN clients c ON c.id=x.client_id WHERE c.tenant_account_id IS NOT NULL AND c.tenant_account_id<>?",
   'demands.clients'=>"SELECT COUNT(*) n FROM demands d JOIN clients c ON c.id=d.client_id WHERE c.tenant_account_id IS NOT NULL AND c.tenant_account_id<>?",
   'demands.projects'=>"SELECT COUNT(*) n FROM demands d JOIN projects p ON p.id=d.project_id JOIN clients c ON c.id=d.client_id WHERE p.client_id<>c.id AND ?>0",
   'demands.categories'=>"SELECT COUNT(*) n FROM demands d JOIN categories c ON c.id=d.category_id WHERE c.tenant_account_id IS NOT NULL AND c.tenant_account_id<>?",
   'demands.stages'=>"SELECT COUNT(*) n FROM demands d JOIN stages s ON s.id=d.stage_id WHERE s.tenant_account_id IS NOT NULL AND s.tenant_account_id<>?",
   'invoices.clients'=>"SELECT COUNT(*) n FROM invoices i JOIN clients c ON c.id=i.client_id WHERE c.tenant_account_id IS NOT NULL AND c.tenant_account_id<>?"
  ];
  $issues=[];
  foreach($checks as $label=>$sql){
   $n=(int)(DB::one($sql,[$legacyId])['n']??0);
   if($n>0)$issues[]=$label;
  }
  return $issues;
 }
}
