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
 /** Categoria estável e segura, nunca expõe o valor da chave nem o corpo HTTP. */
 public static function diagnosticCode(Throwable $e): string {
  $msg=$e->getMessage();
  if(in_array($msg,['Invalid encrypted token','Cannot decrypt token'],true))
   return 'asaas_local_decrypt_failed';
  if($msg==='asaas_curl_unavailable')return 'asaas_local_curl_unavailable';
  if(preg_match('/\\Aasaas_transport_([0-9]{1,3})\\z/D',$msg,$m))
   return 'asaas_transport_'.$m[1];
  if(preg_match('/\\Aasaas_http_(400|401|402|403|404|409|422|429|5[0-9]{2})(?:_([a-z_]+))?\\z/D',$msg,$m)){
   $allowed=['invalid_environment','invalid_access_token','invalid_access_token_format',
    'access_token_not_found','ip_not_allowed','invalid_ip','invalid_request'];
   return 'asaas_http_'.$m[1].(isset($m[2])&&in_array($m[2],$allowed,true)?'_'.$m[2]:'');
  }
  if(in_array($msg,['asaas_key_not_configured','asaas_invalid_response'],true))return $msg;
  if($e instanceof PDOException)return 'asaas_local_database';
  if($e instanceof JsonException)return 'asaas_response_json_invalid';
  if($e instanceof InvalidArgumentException)return 'asaas_local_invalid_route';
  return 'asaas_local_unexpected';
 }
 /** Mensagens acionáveis, sem resposta bruta da API nem credenciais. */
 public static function diagnosticMessage(Throwable $e): string {
  $code=self::diagnosticCode($e);
  if($code==='asaas_local_decrypt_failed')
   return 'A chave Asaas está cadastrada, mas o TaskEsc não consegue descriptografá-la. Isso pode ocorrer se a chave de criptografia do servidor mudou. Informe novamente sua chave Asaas para testar e salvar sem alterar o ambiente.';
  if($code==='asaas_local_curl_unavailable')
   return 'A extensão PHP cURL não está disponível no servidor. Habilite cURL no PHP-FPM usado pelo TaskEsc.';
  if($code==='asaas_local_database')
   return 'Falha ao acessar as configurações da integração no banco. Consulte o log técnico do servidor.';
  if($code==='asaas_local_invalid_route')
   return 'A rota usada pela integração Asaas é inválida. É necessária correção no código.';
  if($code==='asaas_response_json_invalid')
   return 'O Asaas retornou um JSON inesperado. Verifique bloqueios de proxy ou mudanças na API.';
  if($code==='asaas_local_unexpected')
   return 'Falha interna antes de concluir a consulta ao Asaas. Categoria: asaas_local_unexpected. Verifique os logs do PHP-FPM; o servidor não gravou tokens no diagnóstico.';

  if(str_contains($code,'invalid_environment'))
   return 'Chave de ambiente incorreto: selecione Produção para uma chave de produção e Sandbox para uma chave de testes (HTTP 401).';
  if(str_contains($code,'invalid_access_token_format'))
   return 'Formato de chave Asaas inválido. Copie a chave completa da conta de produção, sem espaços (HTTP 401).';
  if(str_contains($code,'access_token_not_found'))
   return 'Asaas não recebeu o cabeçalho de autenticação. Verifique possíveis bloqueios no proxy (HTTP 401).';
  if(str_contains($code,'invalid_access_token')||str_contains($code,'asaas_http_401'))
   return 'A API do Asaas recusou a chave (HTTP 401). Confira se está ativa, completa e pertence à conta selecionada.';
  if(str_contains($code,'asaas_http_403'))
   return 'O Asaas bloqueou o acesso (HTTP 403). Confira a lista de IPs autorizados da chave e as restrições de segurança da conta.';
  if(str_contains($code,'asaas_http_404'))
   return 'O endpoint de consulta da conta Asaas não foi encontrado (HTTP 404).';
  if(str_contains($code,'asaas_http_429'))
   return 'O Asaas atingiu o limite de requisições (HTTP 429). Tente novamente mais tarde.';
  if(preg_match('/asaas_http_(5[0-9]{2})/',$code,$match))
   return 'O Asaas apresentou indisponibilidade temporária (HTTP '.$match[1].'). Tente novamente mais tarde.';
  if(str_contains($code,'asaas_transport_'))
   return 'Falha de comunicação da VPS com o Asaas (DNS, conexão, TLS ou tempo limite). Verifique a saída HTTPS do servidor.';
  if(str_contains($code,'asaas_key_not_configured'))
   return 'Nenhuma chave Asaas foi cadastrada para o ambiente escolhido.';
  if(str_contains($code,'asaas_invalid_response'))
   return 'O Asaas retornou uma resposta inesperada. Consulte o status do serviço.';
  return 'Não foi possível consultar a conta Asaas. Verifique a chave, o ambiente e a conexão da VPS.';
 }
 public static function save(array $p): array {
  self::guard();Auth::rate('master-asaas-credentials',8,3600);
  $env=enumv($p['environment']??'sandbox',['sandbox','production'],'ambiente');
  $label=trim((string)($p['account_label']??''));
  if(mb_strlen($label)>100)fail('Identificação muito longa.',422);
  $key=trim((string)($p['api_key']??''));
  $token=trim((string)($p['webhook_auth_token']??''));
  if($key!==''&&(strlen($key)<12||strlen($key)>1024||preg_match('/\\s/',$key)))fail('Chave Asaas inválida.',422);
  if($token!==''&&(strlen($token)<32||strlen($token)>255||preg_match('/\\s/',$token)))fail('Token de webhook inválido (32 a 255 caracteres).',422);
  $enable=!empty($p['enable_billing']);
  if($enable){
   if(!PlatformAsaasBilling::ready())fail('Migração Asaas necessária antes de habilitar cobranças.',409);
   $stored=setting('platform_asaas_api_key_'.$env,'');
   $webhookStored=setting('platform_asaas_webhook_token_'.$env,'');
   if(($key===''&&$stored==='')||($token===''&&$webhookStored===''))
    fail('Cadastre a chave API e o token de webhook para este ambiente antes de habilitar cobranças.',409);
   try{
    // Valide a nova chave ANTES de gravá-la: se for inválida, mantenha
    // intacta a configuração anterior e não habilite cobranças.
    $candidate=$key!==''?$key:secretDecrypt($stored);
    $account=PlatformAsaasBilling::api('GET','/v3/myAccount/status/',null,$env,$candidate);
   }catch(Throwable $e){
    error_log('TaskEsc Asaas validation failed: '.(preg_match('/^asaas_(?:http_[0-9]{3}(?:_[a-z_]+)?|transport_[0-9]+|key_not_configured|invalid_response)$/D',$e->getMessage())?$e->getMessage():'unknown'));
    fail(self::diagnosticMessage($e),502);
   }
   if($env==='production'&&($account['general']??'')!=='APPROVED'){
    $general=(string)($account['general']??'');
    $safe=in_array($general,['PENDING','AWAITING_APPROVAL','REJECTED'],true)?$general:'não confirmada';
    fail('Conta Asaas de produção ainda não aprovada. Situação: '.$safe,409);
   }
  }
  DB::tx(static function()use($env,$label,$key,$token,$enable):void {
   $fields=[
    'platform_asaas_env'=>$env,
    'platform_asaas_account_label'=>$label,
    'platform_asaas_enabled_'.$env=>$enable?'1':'0'
   ];
   if($key!=='')$fields['platform_asaas_api_key_'.$env]=secretEncrypt($key);
   if($token!=='')$fields['platform_asaas_webhook_token_'.$env]=secretEncrypt($token);
   foreach($fields as $name=>$value)
    DB::run('INSERT INTO settings (`key`,value) VALUES (?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)',[$name,$value]);
   audit('platform',0,'master.asaas.credentials_updated',null,[
    'environment'=>$env,'account_label'=>$label,'billing_enabled'=>$enable,
    'api_key_updated'=>$key!=='','webhook_token_updated'=>$token!==''
   ]);
  });
  return self::get();
 }
}
