<?php
declare(strict_types=1);

/**
 * Intent-aware, permission-preserving model router.
 * Deterministic PHP, context and cached read plans are evaluated by the worker
 * BEFORE this router. Only unresolved requests reach an LLM.
 */
final class AiWhatsAppSmartRouter
{
 private const WRITE='/\b(?:criar|crie|cadastre|cadastra|editar|edite|altere|alterar|mover|mova|excluir|exclua|apagar|apague|deletar|delete|remover|remova|pagar|pague|registre|registrar|cancelar|cancele|adicionar|adicione|agendar|agende|confirmar|confirmo|enviar|envie|cobrar|gere\s+uma?\s+cobranca)\b/u';
 private static function normalize(string $message): string {
  return AiWhatsAppFinancialConversation::normalize($message);
 }
 public static function select(string $message,array $catalog): array {
  $all=array_values($catalog);
  $t=self::normalize($message);
  // Mixed, dangerous or complex requests keep the full authorized catalog.
  // We NEVER decide to perform an action based on keywords here.
  if(mb_strlen($t)>260||preg_match(self::WRITE,$t)
   ||preg_match('/\b(?:novo|nova|novos|novas|inclua|incluir|lance|lancar|lancamento|marcar|baixa|liquidar|receba|receber|pago|paga|registra|registo)\b/u',$t))
   return ['mode'=>'complex','preference'=>null,'tools'=>$all];
  // Never narrow a tool catalog based only on a noun like "nova despesa".
  // A positive READ cue is required; uncertain requests keep full permissions
  // and explicit administrative confirmation handling.
  if(!preg_match('/\A(?:(?:me\s+)?(?:diga|fale|fala|mostre|mostra|liste|lista|listar|consulte|consultar|procure|encontre|ver|veja|busque|buscar|resuma|resumo|relatorio|quanto|quantos|quantas|qual|quais|quero\s+ver|me\s+manda|manda|mandar|pode\s+mostrar|mostra\s+pra\s+mim)\b)/u',$t))
   return ['mode'=>'general','preference'=>null,'tools'=>$all];
  $subjects=[
   'tasks'=>(bool)preg_match('/\b(?:tarefa|tarefas|task|tasks|kanban|atrasad[ao]s?|prazo|demandas?)\b/u',$t),
   'agenda'=>(bool)preg_match('/\b(?:agenda|compromissos?|calendarios?|reunioes?|eventos?|horarios?)\b/u',$t),
   'finance'=>(bool)preg_match('/\b(?:financeiro|financeiras?|despesas?|custos?|receber|cobrancas?|pagamentos?|faturamento|saldo|lucro)\b/u',$t),
   'directory'=>(bool)preg_match('/\b(?:clientes?|projetos?|equipe|usuarios?|pessoas?)\b/u',$t),
  ];
  $selected=array_keys(array_filter($subjects));
  if(count($selected)!==1)
   return ['mode'=>'general','preference'=>null,'tools'=>$all];
  $allowedByDomain=match($selected[0]){
   'tasks'=>['list_tasks','attention_report'],
   'agenda'=>['agenda_list','agenda_calendars','agenda_detail','agenda_conflicts'],
   'finance'=>['finance_summary','finance_receivables','finance_expenses'],
   'directory'=>['list_clients','list_projects','list_team'],
   default=>[]
  };
  $filtered=array_values(array_filter($all,static fn($tool)=>
   in_array($tool['function']['name']??'', $allowedByDomain,true)));
  if($filtered===[])return ['mode'=>'general','preference'=>null,'tools'=>$all];
  // Groq handles small single-domain read questions when configured;
  // Gemini remains primary for complex work. Both paths keep fallback.
  return ['mode'=>'focused_read','preference'=>'groq','tools'=>$filtered];
 }
 public static function plan(string $message,array $context,array $catalog,?callable $planner=null): array {
  $selected=self::select($message,$catalog);
  $tools=$selected['tools'];
  $planned=$planner?$planner($message,$context,$tools)
   :AiWhatsAppProvider::plan($message,$context,$tools,$selected['preference']);
  if(!is_array($planned))throw new RuntimeException('ai_plan_invalid');
  $toolName=$planned['tool']??null;
  if($toolName!==null&&!in_array($toolName,array_column(array_column($tools,'function'),'name'),true))
   throw new RuntimeException('ai_unauthorized_tool');
  return $planned;
 }
}
