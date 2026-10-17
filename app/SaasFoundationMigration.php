<?php
declare(strict_types=1);

/**
 * B1.1 staged foundation only. Deliberately leaves settings.schema_version=30.
 * Run manually after a verified backup; no new tenant can be provisioned yet.
 */
final class SaasFoundationMigration {
 public const VERSION=1;
 public const MARKER='saas_foundation_version';
 private const SQL_FILE='/database/migrations/031_saas_accounts_foundation.sql';

 public static function issues(): array {
  $required=[
   'accounts'=>['id'=>'bigint','slug'=>'varchar','name'=>'varchar','account_type'=>'varchar','status'=>'varchar','created_at'=>'datetime','updated_at'=>'datetime'],
   'account_members'=>['account_id'=>'bigint','user_id'=>'int','membership_role'=>'varchar','status'=>'varchar','joined_at'=>'datetime'],
   'account_settings'=>['account_id'=>'bigint','key'=>'varchar','value'=>'longtext','updated_at'=>'datetime']
  ];
  $indexes=['accounts'=>['PRIMARY','uq_accounts_slug','idx_accounts_status'],
            'account_members'=>['PRIMARY','idx_account_members_user'],
            'account_settings'=>['PRIMARY']];
  $issues=[];
  foreach($required as $table=>$columns) {
   $info=DB::one('SELECT ENGINE FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',[$table]);
   if(!$info){$issues[]='missing_'.$table;continue;}
   if(strcasecmp((string)$info['ENGINE'],'InnoDB')!==0)$issues[]='engine_'.$table;
   $actual=[];
   foreach(DB::all('SELECT COLUMN_NAME,DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',[$table]) as $row)$actual[$row['COLUMN_NAME']]=$row['DATA_TYPE'];
   foreach($columns as $name=>$type)if(($actual[$name]??'')!==$type)$issues[]=$table.'_column_'.$name;
   $actualIndexes=array_column(DB::all('SELECT DISTINCT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',[$table]),'INDEX_NAME');
   foreach($indexes[$table] as $index)if(!in_array($index,$actualIndexes,true))$issues[]=$table.'_index_'.$index;
  }
  return $issues;
 }

 public static function apply(): array {
  $db=DB::conn();
  if($db->inTransaction())throw new LogicException('A migração SaaS B1.1 não pode ser executada dentro de uma transação.');
  $lock='taskesc-b1-'.(string)(DB::one('SELECT SHA1(DATABASE()) h')['h']??'');
  if((int)(DB::one('SELECT GET_LOCK(?,10) acquired',[$lock])['acquired']??0)!==1)throw new RuntimeException('A migração SaaS B1.1 está em execução.');
  try {
   $schema=(int)(DB::one("SELECT value FROM settings WHERE `key`='schema_version'")['value']??0);
   if($schema!==30)throw new RuntimeException('SaaS B1.1 exige schema 30. Schema 20 retirado nunca deve ser recriado.');
   $retired=DB::one("SELECT COUNT(*) n FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('workspaces','workspace_members','workspace_settings')");
   $retiredColumns=DB::one("SELECT COUNT(*) n FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND COLUMN_NAME='workspace_id'");
   if((int)($retired['n']??0)||(int)($retiredColumns['n']??0))throw new RuntimeException('Artefatos do SaaS antigo detectados; migração bloqueada.');
   $sql=file_get_contents(ROOT.self::SQL_FILE);
   if($sql===false)throw new RuntimeException('Arquivo SQL da fundação B1.1 ausente.');
   // The controlled SQL contains only CREATE TABLE DDL; statements separated by semicolons.
   foreach(preg_split('/;\s*(?:\r?\n|$)/',$sql)?:[] as $statement){
    $statement=trim($statement);if($statement==='')continue;
    $withoutComments=preg_replace('/^\s*--[^\n]*\n/m','',$statement);
    if(!preg_match('/^CREATE TABLE IF NOT EXISTS\s+[a-z_]+\s*\(/i',trim((string)$withoutComments)))
     throw new RuntimeException('Migração contém comando DDL inesperado.');
    $db->exec($statement);
   }
   $issues=self::issues();if($issues)throw new RuntimeException('Fundação SaaS inválida: '.implode(',',$issues));
   // All legacy records remain unchanged. One internal account owns the existing operation.
   $db->beginTransaction();
   try {
    $old=DB::one('SELECT id,account_type FROM accounts WHERE slug=? FOR UPDATE',[AccountContext::LEGACY_SLUG]);
    if(!$old){
     $accountId=DB::insert('accounts',['slug'=>AccountContext::LEGACY_SLUG,'name'=>'TaskEsc - operação existente','account_type'=>'internal','status'=>'active','created_at'=>now(),'updated_at'=>now()]);
    }else{
     if($old['account_type']!=='internal')throw new RuntimeException('Slug reservado da operação existente já pertence a outra conta.');
     $accountId=(int)$old['id'];
    }
    DB::run("INSERT IGNORE INTO account_members (account_id,user_id,membership_role,status,joined_at)
       SELECT ?,u.id,CASE WHEN u.role='admin' THEN 'owner' ELSE 'member' END,
              CASE WHEN u.active=1 THEN 'active' ELSE 'inactive' END,?
       FROM users u",[$accountId,now()]);
    foreach(['saas.external_signup_enabled'=>'0','saas.multi_account_enabled'=>'0','saas.ai_assistant_enabled'=>'1','saas.google_calendar_enabled'=>'1'] as $key=>$value){
     DB::run('INSERT IGNORE INTO account_settings (account_id,`key`,value,updated_at) VALUES (?,?,?,?)',[$accountId,$key,$value,now()]);
    }
    DB::run('INSERT INTO settings (`key`,value) VALUES (?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)',[self::MARKER,(string)self::VERSION]);
    $db->commit();
   }catch(Throwable $error){$db->rollBack();throw $error;}
   $unlinked=(int)(DB::one('SELECT COUNT(*) n FROM users u LEFT JOIN account_members m ON m.user_id=u.id AND m.account_id=? WHERE m.user_id IS NULL',[$accountId])['n']??0);
   if($unlinked)throw new RuntimeException('Há usuários existentes sem vínculo à conta interna.');
   return ['foundation_version'=>self::VERSION,'schema_version'=>$schema,'account_id'=>$accountId,'legacy_members'=>(int)(DB::one('SELECT COUNT(*) n FROM account_members WHERE account_id=?',[$accountId])['n']??0),'external_signup_enabled'=>false,'multi_account_enabled'=>false];
  }finally{DB::run('SELECT RELEASE_LOCK(?)',[$lock]);}
 }
}
