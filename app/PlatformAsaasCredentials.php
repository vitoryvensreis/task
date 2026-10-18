<?php
declare(strict_types=1);

/** Master-only vault for future Asaas subscriptions; does not enable payments. */
final class PlatformAsaasCredentials {
 private static function guard(): void {
  Auth::load();
  if(!MasterSupport::isActor()||Auth::$origin!=='web')fail('Configuração exclusiva do ADM Master.',403);
 }
 public static function get(): array {
  self::guard();
  $env=setting('platform_asaas_env','sandbox');
  if(!in_array($env,['sandbox','production'],true))$env='sandbox';
  return [
   'environment'=>$env,
   'api_key_set'=>setting('platform_asaas_api_key_'.$env,'')!=='',
   'webhook_token_set'=>setting('platform_asaas_webhook_token_'.$env,'')!=='',
   'account_label'=>setting('platform_asaas_account_label',''),
   'integration_enabled'=>false,
   'webhook_enabled'=>false,
   'checkout_enabled'=>false,
   'message'=>'Credenciais armazenadas para integração futura. Nenhuma cobrança ou webhook é ativado automaticamente.'
  ];
 }
 public static function save(array $p): array {
  self::guard();Auth::rate('master-asaas-credentials',8,3600);
  $env=enumv($p['environment']??'sandbox',['sandbox','production'],'ambiente');
  $label=trim((string)($p['account_label']??''));
  if(mb_strlen($label)>100)fail('Identificação muito longa.',422);
  $key=trim((string)($p['api_key']??''));
  $token=trim((string)($p['webhook_auth_token']??''));
  if($key!==''&&(strlen($key)<12||strlen($key)>1024||preg_match('/\s/',$key)))fail('Chave Asaas inválida.',422);
  if($token!==''&&(strlen($token)<12||strlen($token)>1024||preg_match('/\s/',$token)))fail('Token de webhook inválido.',422);
  DB::tx(static function()use($env,$label,$key,$token):void {
   $fields=['platform_asaas_env'=>$env,'platform_asaas_account_label'=>$label];
   if($key!=='')$fields['platform_asaas_api_key_'.$env]=secretEncrypt($key);
   if($token!=='')$fields['platform_asaas_webhook_token_'.$env]=secretEncrypt($token);
   foreach($fields as $name=>$value)
    DB::run('INSERT INTO settings (`key`,value) VALUES (?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)',[$name,$value]);
   audit('platform',0,'master.asaas.credentials_updated',null,[
    'environment'=>$env,'account_label'=>$label,
    'api_key_updated'=>$key!=='','webhook_token_updated'=>$token!==''
   ]);
  });
  return self::get();
 }
}
