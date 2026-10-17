<?php
declare(strict_types=1);

/**
 * READ-ONLY preflight for evolving TaskEsc IN PLACE on its current VPS,
 * domain and database. Passing this audit is NOT deployment approval:
 * backup, maintenance planning, live validation and rollback still required.
 *
 * No DDL/DML, locks, feature activation or external side effects.
 */
final class SaasInPlacePreflight {
 public static function inspect(): array {
  $blocks=[];
  $schema=(int)(DB::one('SELECT value FROM settings WHERE `key`=?',['schema_version'])['value']??0);
  if($schema!==30)$blocks[]='schema_operacional_30_obrigatorio';

  // All SaaS features are disabled even on an in-place upgrade.
  $global=DB::all('SELECT `key`,value FROM settings WHERE `key` IN (?,?)',
   ['saas.external_signup_enabled','saas.multi_account_enabled']);
  foreach($global as $row){
   if((string)$row['value']!=='0')$blocks[]='flag_global_ativa_'.$row['key'];
  }

  $legacySql=DB::one("SELECT COUNT(*) n FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('workspaces','workspace_members','workspace_settings')");
  $legacyColumn=DB::one("SELECT COUNT(*) n FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND COLUMN_NAME='workspace_id'");
  if((int)($legacySql['n']??0)>0||(int)($legacyColumn['n']??0)>0)
   $blocks[]='saas_schema_20_removido_detectado';

  $migrations=[
   [SaasFoundationMigration::MARKER,1,SaasFoundationMigration::class],
   [SaasDomainMigration::MARKER,2,SaasDomainMigration::class],
   [SaasChildMigration::MARKER,3,SaasChildMigration::class],
   [SaasOutboxMigration::MARKER,4,SaasOutboxMigration::class],
   [SaasReceiptMigration::MARKER,5,SaasReceiptMigration::class],
   [SaasDeliveryMigration::MARKER,6,SaasDeliveryMigration::class],
   [SaasChannelMigration::MARKER,7,SaasChannelMigration::class],
   [SaasInternalInboxMigration::MARKER,8,SaasInternalInboxMigration::class]
  ];
  $rows=DB::all('SELECT `key`,value FROM settings WHERE `key` LIKE ?', ['saas_%_version']);
  $current=[];foreach($rows as $row)$current[$row['key']]=(int)$row['value'];
  $versions=[];
  $gap=false;
  foreach($migrations as [$key,$expected,$class]){
   $actual=$current[$key]??0;
   $versions[$key]=$actual;
   if($actual!==0&&$actual!==$expected)$blocks[]='marcador_invalido_'.$key;
   if($actual>0&&$gap)$blocks[]='sequencia_incompleta_'.$key;
   if($actual===0)$gap=true;
   if($actual===$expected){
    try {
     $issues=$class::issues();
     if($issues)$blocks[]='estrutura_divergente_'.$key;
    }catch(Throwable){$blocks[]='erro_ao_inspecionar_'.$key;}
   }
  }

  $accountsExists=(bool)DB::one('SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',['accounts']);
  $accountCount=0;
  if($accountsExists){
   $accountCount=(int)(DB::one('SELECT COUNT(*) n FROM accounts')['n']??0);
   if($accountCount>1)$blocks[]='outras_empresas_ja_cadastradas';
   if($accountCount===1){
    $account=DB::one('SELECT id,slug,account_type,status FROM accounts LIMIT 1');
    if(!$account||$account['slug']!==AccountContext::LEGACY_SLUG||
       $account['account_type']!=='internal'||$account['status']!=='active')
     $blocks[]='conta_unica_nao_e_interna_ativa';
    else {
     $flags=DB::all('SELECT `key`,value FROM account_settings WHERE account_id=? AND `key` IN (?,?)',
      [(int)$account['id'],'saas.external_signup_enabled','saas.multi_account_enabled']);
     if(count($flags)!==2)$blocks[]='flags_da_conta_interna_ausentes';
     foreach($flags as $flag)if((string)$flag['value']!=='0')$blocks[]='flag_interna_ativa_'.$flag['key'];
    }
   }elseif(($versions[SaasFoundationMigration::MARKER]??0)===1){
    $blocks[]='fundacao_sem_conta_interna';
   }
  }elseif(($versions[SaasFoundationMigration::MARKER]??0)===1){
   $blocks[]='fundacao_sem_tabela_accounts';
  }
  return [
   'mode'=>'read_only','schema_version'=>$schema,
   'account_count'=>$accountCount,'migration_markers'=>$versions,
   'external_signup_enabled'=>false,'multi_account_release_approved'=>false,
   'production_deployed'=>false,'backup_verified'=>false,
   'ready_for_manual_review'=>count($blocks)===0,
   'blockers'=>array_values(array_unique($blocks))
  ];
 }
}
