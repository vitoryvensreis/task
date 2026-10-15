<?php
declare(strict_types=1);

/**
 * Conversational UX for narrow, unambiguous requests.
 * Local clarification is a TEXT reply only: never an implied business action.
 * No chat contents, customer data or model prompts are persisted here.
 */
final class AiWhatsAppConversationUX
{
 private static function normalize(string $message): string {
  $t=AiWhatsAppFinancialConversation::normalize($message);
  return preg_replace('/\s+/u',' ',trim($t))??$t;
 }
 private static function supports(array $catalog,string $name): bool {
  return in_array($name,array_column(array_column($catalog,'function'),'name'),true);
 }
 /** Friendly and permission-scoped examples instead of claiming universal access. */
 public static function help(array $catalog): string {
  $examples=[];
  if(self::supports($catalog,'list_tasks'))$examples[]='• "Liste minhas tarefas"';
  if(self::supports($catalog,'attention_report'))$examples[]='• "Quais projetos precisam da minha atenção?"';
  if(self::supports($catalog,'agenda_list'))$examples[]='• "Mostre meus compromissos de hoje"';
  if(self::supports($catalog,'finance_expenses'))$examples[]='• "Total das despesas pendentes deste mês"';
  if(self::supports($catalog,'create_expense'))$examples[]='• "Adiciona uma despesa de R$ 22,60 Uber" (pede confirmação)';
  if(self::supports($catalog,'register_receipt'))$examples[]='• "Registre entrada recebida do cliente X por Pix" (pede dados e confirmação)';
  if(self::supports($catalog,'cross_module_report'))$examples[]='• "Resumo das tarefas e da agenda desta semana"';
  if(self::supports($catalog,'create_task'))$examples[]='• "Crie uma tarefa para o cliente..."';
  if(!$examples)return 'Não encontrei ferramentas de consulta disponíveis para seu acesso atual. Consulte suas permissões no TaskEsc.';
  return "Posso ajudar com o que está autorizado para você no TaskEsc.\n".
   implode("\n",array_slice($examples,0,6)).
   "\nVocê pode fazer perguntas de acompanhamento no privado, como “e as do Saulo?” após uma consulta de tarefas.";
 }
 public static function kind(string $message): string {
  $t=self::normalize($message);
  return preg_match('/\A(?:ajuda|menu|o que voce faz|o que voce pode fazer|como voce pode me ajudar|quais sao suas funcoes)\z/u',$t)
   ?'guided_help':'local_clarification';
 }
 /** Read-only, exact-match clarification; all other messages keep normal routing. */
 public static function plan(string $message,array $catalog,bool $private): ?array {
  if(mb_strlen($message,'UTF-8')>140)return null;
  $t=self::normalize($message);
  if(preg_match('/\A(?:ajuda|menu|o que voce faz|o que voce pode fazer|como voce pode me ajudar|quais sao suas funcoes)\z/u',$t))
   return ['text'=>self::help($catalog)];
  $task=self::supports($catalog,'list_tasks');
  $finance=self::supports($catalog,'finance_expenses');
  $receivables=self::supports($catalog,'finance_receivables');
  $agenda=self::supports($catalog,'agenda_list');
  if(preg_match('/\A(?:(?:me\s+)?(?:mande|manda|mostre|mostra|liste|lista|quero\s+ver)\s+)?(?:so\s+)?(?:os|as)\s+pendentes\z/u',$t)){
   $choices=[];
   if($task)$choices[]='tarefas em aberto';
   if($finance)$choices[]='despesas pendentes';
   if($receivables)$choices[]='cobranças a receber';
   // No financial details or names are disclosed in a group.
   if(count($choices)>1)return ['text'=>'Você quer consultar '.implode(', ',array_slice($choices,0,-1))
    .' ou '.end($choices).'?'];
   if($task)return ['text'=>'Você quer ver as tarefas que ainda estão em aberto?'];
   return null;
  }
  if($private&&preg_match('/\A(?:e\s+)?(?:amanha|para\s+amanha)\z/u',$t) && $agenda && $task)
   return ['text'=>'Você quer ver os compromissos de amanhã ou as tarefas com prazo amanhã?'];
  if($private&&preg_match('/\A(?:esse|este|neste|deste)\s+mes\z/u',$t) && $finance && $task)
   return ['text'=>'Qual informação você quer consultar neste mês: tarefas, despesas ou cobranças a receber?'];
  return null;
 }
 /** Present the requested data first and explicitly mark truncated task lists. */
 public static function formatTasks(array $result): string {
  $tasks=$result['tasks']??[];
  if(!is_array($tasks))throw new RuntimeException('ai_invalid_response');
  $count=(int)($result['total']??count($tasks));
  if($tasks===[]){
   if(isset($result['searched_stage'])){
    $stage=mb_substr((string)$result['searched_stage'],0,60);
    if(!empty($result['alternatives'])){
     $lines=['Não encontrei tarefas nessa etapa: '.$stage.'.',
      'Mas há '.(int)($result['alternative_total']??0).' correspondência(s) em outras etapas:'];
     foreach($result['alternatives'] as $v)
      $lines[]='• #'.(int)$v['id'].' '.mb_substr((string)$v['title'],0,70)
       .' — '.mb_substr((string)$v['stage'],0,45);
     return mb_substr(implode("\n",$lines)."\nConsulta somente leitura.",0,3000);
    }
    return 'Não encontrei tarefas com esses filtros na etapa '.$stage.' nem nas outras etapas visíveis para você.';
   }
   return 'Não encontrei tarefas para essa consulta. Tente informar outro nome, cliente ou etapa.';
  }
  $lines=['Encontrei '.$count.' tarefa(s) para sua consulta:'];
  foreach($tasks as $v){
   $line='• #'.(int)$v['id'].' '.mb_substr((string)$v['title'],0,70).
    ' — '.mb_substr((string)$v['stage'],0,40);
   if(!empty($v['due_at'])){
    try{
     $due=new DateTimeImmutable((string)$v['due_at'],new DateTimeZone('UTC'));
     $local=$due->setTimezone(new DateTimeZone((string)config('timezone','America/Fortaleza')));
     $line.=' | prazo: '.$local->format('d/m/Y');
    }catch(Throwable){}
   }
   $lines[]=$line;
  }
  if($count>count($tasks))$lines[]='Mostrando '.count($tasks).' de '.$count.'. Você pode refinar por responsável ou etapa.';
  $lines[]='Consulta somente leitura.';
  return mb_substr(implode("\n",$lines),0,3200);
 }
}
