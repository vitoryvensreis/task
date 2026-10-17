<?php
declare(strict_types=1);

/**
 * B1.2b — staged ownership for child records with a deterministic parent.
 *
 * This is NOT tenant activation. Existing controllers/workers still have
 * unscoped queries. The table mapping below is fixed in source code; never
 * interpolate a caller-supplied table, column or SQL expression.
 */
final class SaasChildMigration {
 public const VERSION=3;
 public const MARKER='saas_children_version';
 public const COLUMN='tenant_account_id';

 /** Ordered by parent dependency: parent must be backfilled first. */
 public const CHILDREN=[
  'contract_items'=>['parent'=>'contracts','fk'=>'contract_id'],
  'cycles'=>['parent'=>'contracts','fk'=>'contract_id'],
  'cycle_items'=>['parent'=>'cycles','fk'=>'cycle_id'],
  'demand_usage'=>['parent'=>'demands','fk'=>'demand_id'],
  'transitions'=>['parent'=>'demands','fk'=>'demand_id'],
  'comments'=>['parent'=>'demands','fk'=>'demand_id'],
  'checklists'=>['parent'=>'demands','fk'=>'demand_id'],
  'time_entries'=>['parent'=>'demands','fk'=>'demand_id'],
  'files'=>['parent'=>'demands','fk'=>'demand_id'],
  'reviews'=>['parent'=>'files','fk'=>'file_id'],
  'change_requests'=>['parent'=>'demands','fk'=>'demand_id'],
  'costs'=>['parent'=>'demands','fk'=>'demand_id'],
  'payments'=>['parent'=>'invoices','fk'=>'invoice_id'],
  'events'=>['parent'=>'calendars','fk'=>'calendar_id'],
  'automation_runs'=>['parent'=>'automations','fk'=>'automation_id'],
  'automation_run_steps'=>['parent'=>'automation_runs','fk'=>'run_id'],
 ];

 /**
  * Additional parents must agree with the canonical owner, if referenced.
  * e.g. a demand_usage belongs to both a task and its contract cycle item.
  */
 private const SECONDARY=[
  ['contract_items','category_id','categories'],
  ['cycle_items','category_id','categories'],
  ['demand_usage','cycle_item_id','cycle_items'],
  ['files','drive_account_id','google_accounts'],
  ['payments','refund_of','payments'],
  ['events','demand_id','demands'],
 ];

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

 public static function issues(bool $checkRows=false): array {
  $issues=[];
  foreach(self::CHILDREN as $table=>$parent){
   if(!self::hasColumn($table)){$issues[]='missing_column_'.$table;continue;}
   $column=DB::one(
    'SELECT DATA_TYPE,COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?',
    [$table,self::COLUMN]
   );
   if(($column['DATA_TYPE']??'')!=='bigint'||!str_contains(strtolower((string)($column['COLUMN_TYPE']??'')),'unsigned'))
    $issues[]='wrong_column_'.$table;
   if(!self::hasIndex($table))$issues[]='missing_index_'.$table;
   if($checkRows&&(int)(DB::one('SELECT COUNT(*) n FROM `'.$table.'` WHERE tenant_account_id IS NULL')['n']??0)>0)
    $issues[]='tenantless_'.$table;
  }
  return $issues;
 }

 /** Return precise relation labels, never silently repair broken joins. */
 public static function relationIssues(bool $requireChildOwnership=false): array {
  $issues=[];
  foreach(self::CHILDREN as $table=>$map){
   $parent=$map['parent'];$fk=$map['fk'];
   if(!self::hasColumn($table)){$issues[]='column_missing_'.$table;continue;}
   // LEFT JOIN catches orphans even if a historical FK was disabled.
   // Child parents may still be NULL during the preflight: they are
   // backfilled in the dependency order. Roots must already be owned.
   $parentMustBeOwned=$requireChildOwnership||!array_key_exists($parent,self::CHILDREN);
   $q="SELECT COUNT(*) n FROM `$table` c LEFT JOIN `$parent` p ON p.id=c.`$fk`
    WHERE p.id IS NULL";
   if($parentMustBeOwned)$q.=' OR p.tenant_account_id IS NULL';
   $q.=' OR (c.tenant_account_id IS NOT NULL AND c.tenant_account_id<>p.tenant_account_id)';
   if($requireChildOwnership)$q.=' OR c.tenant_account_id IS NULL';
   if((int)(DB::one($q)['n']??0)>0)$issues[]='parent_'.$table;
  }
  foreach(self::SECONDARY as [$table,$fk,$other]){
   $map=self::CHILDREN[$table];
   // During preflight, the primary owner comes from the resolved parent.
   $parent=$map['parent'];$primaryFk=$map['fk'];
   $otherMustBeOwned=$requireChildOwnership||!array_key_exists($other,self::CHILDREN);
   $q="SELECT COUNT(*) n FROM `$table` c
    JOIN `$parent` p ON p.id=c.`$primaryFk`
    LEFT JOIN `$other` x ON x.id=c.`$fk`
    WHERE c.`$fk` IS NOT NULL AND (x.id IS NULL";
   if($otherMustBeOwned)$q.=' OR x.tenant_account_id IS NULL';
   $q.=' OR (p.tenant_account_id IS NOT NULL AND x.tenant_account_id IS NOT NULL AND p.tenant_account_id<>x.tenant_account_id))';
   if((int)(DB::one($q)['n']??0)>0)$issues[]='secondary_'.$table.'_'.$fk;
  }
  return $issues;
 }

 public static function apply(): array {
  $db=DB::conn();
  if($db->inTransaction())throw new LogicException('B1.2b requer migração fora de uma transação.');
  $lock='taskesc-b12b-'.(string)(DB::one('SELECT SHA1(DATABASE()) h')['h']??'');
  if((int)(DB::one('SELECT GET_LOCK(?,10) acquired',[$lock])['acquired']??0)!==1)
   throw new RuntimeException('Migração B1.2b concorrente.');
  try {
   $schema=(int)(DB::one("SELECT value FROM settings WHERE `key`='schema_version'")['value']??0);
   $foundation=(int)(DB::one('SELECT value FROM settings WHERE `key`=?',[SaasFoundationMigration::MARKER])['value']??0);
   $domain=(int)(DB::one('SELECT value FROM settings WHERE `key`=?',[SaasDomainMigration::MARKER])['value']??0);
   $version=(int)(DB::one('SELECT value FROM settings WHERE `key`=?',[self::MARKER])['value']??0);
   if($schema!==30||$foundation!==1||$domain!==2||$version>self::VERSION)
    throw new RuntimeException('B1.2b requer schema 30 e B1.1/B1.2a já aplicadas.');

   // Never backfill data after other tenants exist or SaaS is enabled.
   $account=DB::one('SELECT id FROM accounts WHERE slug=? AND account_type=?',
    [AccountContext::LEGACY_SLUG,'internal']);
   $count=(int)(DB::one('SELECT COUNT(*) n FROM accounts')['n']??0);
   if(!$account||$count!==1)throw new RuntimeException('Backfill proibido: mais de uma conta ou conta interna inexistente.');
   $id=(int)$account['id'];
   $flags=DB::all('SELECT `key`,value FROM account_settings WHERE account_id=? AND `key` IN (?,?)',
    [$id,'saas.external_signup_enabled','saas.multi_account_enabled']);
   if(count($flags)!==2)throw new RuntimeException('Flags de bloqueio ausentes.');
   foreach($flags as $flag)if((string)$flag['value']!=='0')throw new RuntimeException('SaaS não pode estar ativo.');

   $rootIssues=SaasDomainMigration::issues(true);
   if($rootIssues)throw new RuntimeException('B1.2a com raízes pendentes: '.implode(',',$rootIssues));
   // DDL implicitly commits: each operation can be safely retried.
   foreach(self::CHILDREN as $table=>$map){
    if(!self::hasColumn($table))DB::run('ALTER TABLE `'.$table.'` ADD COLUMN tenant_account_id BIGINT UNSIGNED NULL');
    if(!self::hasIndex($table))DB::run('ALTER TABLE `'.$table.'` ADD INDEX `idx_saas_'.$table.'` (tenant_account_id,id)');
   }
   $issues=self::issues();
   if($issues)throw new RuntimeException('Estrutura B1.2b incompleta: '.implode(',',$issues));

   // All children must have a resolvable parent and no conflicting ownership.
   $preflight=self::relationIssues();
   if($preflight)throw new RuntimeException('Relação multiempresa inválida: '.implode(',',$preflight));

   return DB::tx(static function()use($schema,$id):array{
    $updated=[];
    foreach(self::CHILDREN as $table=>$map){
     $parent=$map['parent'];$fk=$map['fk'];
     $q=DB::run(
      'UPDATE `'.$table.'` c JOIN `'.$parent.'` p ON p.id=c.`'.$fk.'`'.
      ' SET c.tenant_account_id=p.tenant_account_id WHERE c.tenant_account_id IS NULL'
     );
     $updated[$table]=$q->rowCount();
    }
    $issues=[...self::issues(true),...self::relationIssues(true)];
    if($issues)throw new RuntimeException('Backfill B1.2b incompleto: '.implode(',',$issues));
    DB::run('INSERT INTO settings (`key`,value) VALUES (?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)',
     [self::MARKER,(string)self::VERSION]);
    return ['children_version'=>self::VERSION,'schema_version'=>$schema,
      'internal_account_id'=>$id,'backfilled'=>$updated,'multi_account_enabled'=>false];
   });
  } finally {DB::run('SELECT RELEASE_LOCK(?)',[$lock]);}
 }
}
