<?php
declare(strict_types=1);

/** ADM Master credential vault and explicit billing activation. */
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
   'integration_enabled'=>PlatformAsaasBilling::enabled($env),
   'webhook_enabled'=>PlatformAsaasBilling::enabled($env),
   'checkout_enabled'=>false,
   'schema_ready'=>PlatformAsaasBilling::ready(),
   'webhook_url'=>PlatformAsaasBilling::webhookUrl($env),
   'message'=>PlatformAsaasBilling::enabled($env)
    ?'Cobranças SaaS habilitadas para o ADM Master. Nenhuma cobrança é criada sem confirmação explícita.'
    :'Configure a chave e o token de webhook e habilite cobranças após verificar a conta Asaas.'
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
  if($token!==''&&(strlen($token)<32||strlen($token)>255||preg_match('/\s/',$token)))fail('Token de webhook inválido (32 a 255 caracteres).',422);
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
  // Enabling is an explicit Master choice and requires a working Asaas
  // account and a migrated, authenticated webhook. Saving credentials alone
  // never initiates a payment.
  $enable=!empty($p['enable_billing']);
  if($enable){
   if(!PlatformAsaasBilling::ready()||setting('platform_asaas_api_key_'.$env,'')===''||
      setting('platform_asaas_webhook_token_'.$env,'')==='')
    fail('Migração e credenciais Asaas necessárias antes de habilitar cobranças.',409);
   try{
    $account=PlatformAsaasBilling::api('GET','/v3/myAccount/status/',null,$env);
   }catch(Throwable $e){error_log('TaskEsc Asaas account check: '.$e->getMessage());fail('Não foi possível validar a chave e a conta no Asaas.',502);}
   if($env==='production'&&($account['general']??'')!=='APPROVED')
    fail('Sua conta Asaas de produção ainda não está aprovada para cobranças.',409);
  }
  DB::run('INSERT INTO settings (`key`,value) VALUES (?,?)
    ON DUPLICATE KEY UPDATE value=VALUES(value)', ['platform_asaas_enabled_'.$env,$enable?'1':'0']);
  return self::get();
 }
}
