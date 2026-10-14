<?php
declare(strict_types=1);

/** No raw provider error, secret, prompt or stack trace is sent to WhatsApp. */
final class AiWhatsAppErrors
{
 public static function code(Throwable $e): string {
  $s=(string)$e->getMessage();
  $known=[
   'ai_provider_rate_limit','ai_provider_bad_credentials','ai_provider_model_unavailable',
   'ai_provider_unavailable','ai_connection_failed','ai_not_configured','ai_gemini_not_configured',
   'ai_invalid_response','ai_invalid_tool','ai_invalid_args','ai_multiple_tools',
   'ai_no_reply','ai_unauthorized_tool','ai_tool_not_allowed','ai_arguments_invalid',
   'ai_plan_invalid','ai_model_invalid','ai_tool_catalog_invalid','ai_unknown_result'
  ];
  return in_array($s,$known,true)?$s:'ai_internal_error';
 }
 public static function retryable(Throwable $e): bool {
  return in_array(self::code($e),['ai_provider_unavailable','ai_connection_failed'],true);
 }
 public static function message(Throwable $e): string {
  return match(self::code($e)){
   'ai_provider_rate_limit'=>'Não consegui responder porque o limite de requisições da IA foi atingido. Tente novamente quando a cota for renovada ou configure outro provedor.',
   'ai_provider_bad_credentials','ai_gemini_not_configured','ai_not_configured'=>'Não consegui responder porque o serviço de IA não está configurado corretamente. Peça ao administrador para verificar as chaves de API na VPS.',
   'ai_provider_model_unavailable','ai_model_invalid'=>'Não consegui responder porque o modelo de IA configurado não está disponível. Peça ao administrador para verificar o modelo.',
   'ai_provider_unavailable','ai_connection_failed'=>'Não consegui responder porque o serviço de IA está indisponível ou a conexão falhou. Tente novamente em alguns minutos.',
   'ai_unauthorized_tool','ai_tool_not_allowed'=>'Não posso executar essa operação neste chat porque ela não está autorizada para seu usuário ou para este grupo. Consulte as permissões no TaskEsc ou tente no privado, se aplicável.',
   'ai_invalid_response','ai_invalid_tool','ai_invalid_args','ai_multiple_tools','ai_no_reply','ai_arguments_invalid','ai_plan_invalid'=>'Não entendi a solicitação com segurança. Reformule o pedido informando a ação, a tarefa ou o compromisso e os dados necessários.',
   default=>'Não consegui concluir seu pedido devido a uma falha interna no TaskEsc. Nada foi confirmado por esta mensagem. Tente novamente ou peça ao administrador para verificar o diagnóstico.'
  };
 }
}
