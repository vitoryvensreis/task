<?php
declare(strict_types=1);

/**
 * Separate, durable WhatsApp AI processor. Business action and outcome are
 * committed in the same transaction, so inbox retries cannot repeat writes.
 * Outbound is intentionally at-most-once (unknown sends require manual review).
 */
final class AiWhatsAppWorker
{
 private static function now(): string {return (new DateTimeImmutable('now',new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');}
 public static function health(): array {
  $schema=(int)(DB::one("SELECT value FROM settings WHERE `key`='schema_version'")['value']??0);
  return ['status'=>$schema===29?'ok':'error','schema'=>$schema,'enabled'=>AiWhatsAppProvider::enabled(),
   'admin_control_enabled'=>AiWhatsAppAdminActions::enabled(),
   'agenda_control_enabled'=>AiWhatsAppAgendaTools::writeEnabled(),
   'queue'=>(int)(DB::one("SELECT COUNT(*) n FROM ai_whatsapp_inbox WHERE ai_state IN ('queued','retry')")['n']??0)];
 }
 public static function tick(int $limit=5,?callable $planner=null,?callable $verify=null,?callable $mentioned=null,?callable $send=null,bool $test=false): array {
  if(!$test&&!AiWhatsAppProvider::enabled())return ['enabled'=>false,'processed'=>0];
  $done=0;
  for($i=0;$i<max(1,min($limit,20));$i++){
   $item=self::claim();
   if(!$item)break;
   try{self::process($item,$planner,$verify,$mentioned,$send);}
   catch(Throwable $e){self::retryOrExplain($item,$e,$verify,$mentioned,$send);error_log('TaskEsc AI worker #'.$item['id'].' processing error ['.AiWhatsAppErrors::code($e).']');}
   $done++;
  }
  return ['enabled'=>true,'processed'=>$done];
 }
 private static function claim(): ?array {
  return DB::tx(static function(): ?array {
   // Reclaim abandoned inference, but not actions already committed or uncertain sends.
   DB::run("UPDATE ai_whatsapp_inbox SET ai_state='retry' WHERE ai_state='processing' AND ai_updated_at<DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 3 MINUTE) AND ai_attempts<3");
   $row=DB::one("SELECT * FROM ai_whatsapp_inbox WHERE ai_state IN ('queued','retry') AND (ai_available_at IS NULL OR ai_available_at<=UTC_TIMESTAMP(6)) AND ai_attempts<3 ORDER BY id LIMIT 1 FOR UPDATE");
   if(!$row)return null;
   DB::run("UPDATE ai_whatsapp_inbox SET ai_state='processing',ai_updated_at=?,ai_attempts=ai_attempts+1 WHERE id=?",[self::now(),$row['id']]);
   return $row;
  });
 }
 private static function decision(array $row,?callable $verify): array {
  return $verify? $verify($row):AiWhatsAppAccess::decide((string)$row['sender_id'],(string)$row['chat_type'],(string)$row['chat_id']);
 }
 private static function sameUser(array $first,array $current): bool {
  return ($current['status']??'')==='authorized' && (int)($first['user_id']??0)>0
   && (int)$current['user_id']===(int)$first['user_id'];
 }
 private static function actor(int $id): void {
  $user=DB::one("SELECT * FROM users WHERE id=? AND active=1",[$id]);
  if(!$user)throw new RuntimeException('ai_actor_unavailable');
  Auth::$user=$user;Auth::$actor=$user;Auth::$previewClientId=null;Auth::$origin='whatsapp';
 }
 private static function process(array $row,?callable $planner,?callable $verify,?callable $mentioned,?callable $send): void {
  $id=(int)$row['id'];
  if($row['chat_type']==='group'){
   $jids=decode($row['ai_mentions_json']??null,[]);
   if(!is_array($jids))$jids=[];
   if(!AiWhatsAppGroupTrigger::addressed((string)($row['body']??''),$jids,$mentioned)){self::ignore($id,'group_not_addressed');return;}
  }
  $first=self::decision($row,$verify);
  if(($first['status']??'')!=='authorized'){self::ignore($id,'access_denied');return;}
  $body=trim((string)($row['body']??''));
  if($body===''||!in_array((string)$row['message_type'],['chat','text'],true)){self::ignore($id,'unsupported_message');return;}
  self::actor((int)$first['user_id']);
  if($row['chat_type']==='group'&&(Auth::$user['role']??'')!=='admin'){self::ignore($id,'group_admin_only');return;}
  if($row['chat_type']==='group')$body=AiWhatsAppGroupTrigger::command($body);
  if($body==='')$body='ajuda';
  $financialPrivate=$row['chat_type']==='private' && Auth::can('finance.read') && (Auth::$user['role']??'')==='admin';
  $tools=array_merge(AiWhatsAppTools::catalog($financialPrivate),AiWhatsAppAdminActions::catalog($financialPrivate),AiWhatsAppAgendaTools::catalog($row['chat_type']==='private'));
  // Confirmation codes are parsed locally; never expose approval operation to a model.
  // Read-only commands and exact greetings never contact a paid/quota-limited model.
  // For everything else, preserve the original Gemini -> Groq route.
  $localConfirmation=$financialPrivate?AiWhatsAppApproval::command($body):null;
  $conversationPlan=$localConfirmation===null
    ?AiWhatsAppConversationContext::plan($body,$row,(int)$first['user_id'],$tools):null;
  $uxPlan=($localConfirmation===null&&$conversationPlan===null)
    ?AiWhatsAppConversationUX::plan($body,$tools,$row['chat_type']==='private'):null;
  $planned=$localConfirmation
    ??AiWhatsAppFinancialConversation::plan($body,$row,(int)$first['user_id'],$financialPrivate)
    ??$conversationPlan
    ??AiWhatsAppCrossModuleReport::fastPlan($body,$financialPrivate)
    ??$uxPlan
    ??AiWhatsAppFastPath::plan($body,$financialPrivate,(Auth::$user['role']??'')==='admin',
    $row['chat_type']==='private' && (Auth::$user['role']??'')==='admin');
  $trustedFollowup=$conversationPlan!==null && $planned===$conversationPlan;
  // A fast route or cached interpretation avoids planning requests, NOT live DB reads.
  $route=$planned!==null?'fast_path':null;
  $fromModel=false;
  if($planned===null){
   // Reuse only a prior authorized READ-ONLY tool interpretation, never the old answer.
   $planned=AiWhatsAppMemory::recall($body,$row,(int)$first['user_id'],$tools);
   if($planned===null){
    $route='model_route';
    $context=AiWhatsAppTools::context()+AiWhatsAppAgendaTools::context($row['chat_type']==='private');
    $planned=AiWhatsAppSmartRouter::plan($body,$context,$tools,$planner);
    $fromModel=true;
   }else{
    $route='cache_hit';
   }
  }
  // Capture the route decision only (no input text/user/chat identifiers).
  if($planner===null&&$route!==null)AiWhatsAppUsageMetrics::routing($route);
  if(!is_array($planned))throw new RuntimeException('ai_plan_invalid');
  $message='';
  if(isset($planned['tool'])){
   $name=(string)$planned['tool'];$args=$planned['arguments']??null;
   if(!is_array($args))throw new RuntimeException('ai_arguments_invalid');
   // Lock inbox row and revalidate identity+group membership before EACH write/read tool.
   try{$message=DB::tx(function() use($id,$row,$first,$name,$args,$tools,$verify,$localConfirmation,$fromModel,$body,$planned,$trustedFollowup): string {
    $locked=DB::one('SELECT ai_state FROM ai_whatsapp_inbox WHERE id=? FOR UPDATE',[$id]);
    if(($locked['ai_state']??'')!=='processing')throw new RuntimeException('ai_operation_already_handled');
    $again=self::decision($row,$verify);
    if(!self::sameUser($first,$again))throw new RuntimeException('ai_access_revoked');
    self::actor((int)$again['user_id']);
    if($row['chat_type']==='group'&&(Auth::$user['role']??'')!=='admin')throw new RuntimeException('ai_access_revoked');
    $allowed=array_column(array_column($tools,'function'),'name');
    $isLocalApproval=$localConfirmation!==null && AiWhatsAppApproval::internal($name);
    if(!in_array($name,$allowed,true)&&!$isLocalApproval)throw new RuntimeException('ai_tool_not_allowed');
    // Do not swallow business exceptions inside this transaction: they must roll back.
    if($isLocalApproval)$result=AiWhatsAppApproval::decide($name,$args,$row);
    elseif(AiWhatsAppAdminActions::sensitive($name))$result=AiWhatsAppApproval::propose($name,$args,$row);
    elseif(in_array($name,['list_clients','list_projects','list_team','list_events'],true))$result=AiWhatsAppAdminActions::run($name,$args);
    elseif($name==='cross_module_report')$result=AiWhatsAppCrossModuleReport::run($args,$row['chat_type']==='private',$body,$trustedFollowup);
    elseif(AiWhatsAppAgendaTools::supported($name))$result=AiWhatsAppAgendaTools::run($name,$args,$row['chat_type']==='private');
    else $result=AiWhatsAppTools::run($name,$args);
    // Always execute the tool and verify current ACL BEFORE consulting response cache.
    // Therefore no live task, financial balance, or calendar event can become stale.
    $reply=null;
    try{$reply=AiWhatsAppAnswerCache::recall($body,$row,(int)$first['user_id'],$tools,$planned,$result);}
    catch(Throwable){error_log('TaskEsc AI answer cache read unavailable');}
    if($reply===null){
     $reply=self::formatResult($result);
     try{AiWhatsAppAnswerCache::remember($body,$row,(int)$first['user_id'],$tools,$planned,$result,$reply);}
     catch(Throwable){error_log('TaskEsc AI answer cache write unavailable');}
    }elseif($planner===null){
     AiWhatsAppUsageMetrics::conversation('answer_cache_hit');
    }
    // Keep only the preceding authorized READ intent, not data or chat text.
    // This is a private and short-lived reference; live tool ACL runs again on follow-up.
    try{AiWhatsAppConversationContext::remember($row,(int)$first['user_id'],$tools,$planned);}
    catch(Throwable){error_log('TaskEsc AI conversation context unavailable');}
    // Cache an interpretation only AFTER an authorized successful read. Never cache writes.
    if($fromModel){
     try{AiWhatsAppMemory::remember($body,$row,(int)$first['user_id'],$tools,$planned);}
     catch(Throwable){error_log('TaskEsc AI intent memory write unavailable');}
    }
    DB::run("UPDATE ai_whatsapp_inbox SET ai_state='reply_pending',ai_reply=?,ai_processed_at=?,ai_updated_at=? WHERE id=?",[$reply,self::now(),self::now(),$id]);
    return $reply;
   });}catch(AppError $e){
    // The business transaction has rolled back; only an error reply is queued.
    $message=mb_substr($e->getMessage(),0,280);
    DB::run("UPDATE ai_whatsapp_inbox SET ai_state='reply_pending',ai_reply=?,ai_processed_at=?,ai_updated_at=? WHERE id=? AND ai_state='processing'",[$message,self::now(),self::now(),$id]);
   }catch(RuntimeException $e){
    if($e->getMessage()==='ai_access_revoked'){self::ignore($id,'access_revoked');return;}
    throw $e;
   }
  }else{
   $message=trim((string)($planned['text']??''));
   if($message==='')throw new RuntimeException('ai_no_reply');
   $again=self::decision($row,$verify);
   if(!self::sameUser($first,$again)){self::ignore($id,'access_revoked');return;}
   $message=mb_substr($message,0,550);
   DB::run("UPDATE ai_whatsapp_inbox SET ai_state='reply_pending',ai_reply=?,ai_processed_at=?,ai_updated_at=? WHERE id=? AND ai_state='processing'",[$message,self::now(),self::now(),$id]);
   if($planner===null&&$uxPlan!==null&&$planned===$uxPlan)
    AiWhatsAppUsageMetrics::conversation(AiWhatsAppConversationUX::kind($body));
  }
  if($planner===null&&$trustedFollowup&&isset($planned['tool']))
   AiWhatsAppUsageMetrics::conversation('context_followup');
  // No additional business operation after this point, only outbound delivery.
  self::deliver($row,$first,$verify,$mentioned,$send,$message);
 }
 private static function deliver(array $row,array $first,?callable $verify,?callable $mentioned,?callable $send,string $message): void {
  $id=(int)$row['id'];
  $final=self::decision($row,$verify);
  if(!self::sameUser($first,$final)){self::ignore($id,'access_revoked_before_reply');return;}
  if($row['chat_type']==='group'){
   $jids=decode($row['ai_mentions_json']??null,[]);
   if(!is_array($jids))$jids=[];
   if(!AiWhatsAppGroupTrigger::addressed((string)($row['body']??''),$jids,$mentioned)){self::ignore($id,'mention_unverified');return;}
   self::actor((int)$final['user_id']);
   if((Auth::$user['role']??'')!=='admin'){self::ignore($id,'group_admin_only');return;}
  }
  $recipient=$row['chat_id'];
  if($row['chat_type']==='private'){
   $phone=(string)(DB::one('SELECT whatsapp_phone FROM users WHERE id=? AND active=1',[(int)$final['user_id']])['whatsapp_phone']??'');
   if(!preg_match('/\\A[0-9]{10,15}\\z/D',$phone)){self::ignore($id,'recipient_unavailable');return;}
   $recipient=$phone;
  }
  // Reserve the one outbound attempt before calling WPPConnect. If PHP dies
  // after remote send, the record remains 'sending', never automatically replayed.
  $claimed=DB::run("UPDATE ai_whatsapp_inbox SET ai_state='sending',ai_updated_at=? WHERE id=? AND ai_state='reply_pending'",[self::now(),$id]);
  if($claimed->rowCount()!==1)return;
  try{
   if($send)$send($recipient,$message,$row['chat_type']==='group');
   else WppConnect::sendAutomationMessage($recipient,$message,$row['chat_type']==='group');
   DB::run("UPDATE ai_whatsapp_inbox SET ai_state='sent',ai_sent_at=?,ai_updated_at=? WHERE id=? AND ai_state='sending'",[self::now(),self::now(),$id]);
  }catch(Throwable){
   DB::run("UPDATE ai_whatsapp_inbox SET ai_state='delivery_uncertain',ai_error='provider_delivery_uncertain',ai_updated_at=? WHERE id=? AND ai_state='sending'",[self::now(),$id]);
  }
 }
 private static function brl(int $cents): string {
  return 'R$ '.number_format($cents/100,2,',','.');
 }
 private static function formatResult(array $r): string {
  if(($r['kind']??'')==='approval_pending')return "Operação preparada (AINDA NÃO EXECUTADA):\n".$r['summary']."\nPara autorizar, responda exatamente: CONFIRMAR ".$r['code']."\nPara desistir: CANCELAR ".$r['code']."\nCódigo válido por 10 minutos. Apenas no privado.";
  if(($r['kind']??'')==='approval_status')return (string)$r['message'];
  if(($r['kind']??'')==='admin_done')return $r['action'].' com sucesso (#'.$r['id'].').';
  if(str_starts_with((string)($r['kind']??''),'agenda_'))return AiWhatsAppAgendaTools::format($r);
  if(($r['kind']??'')==='admin_list'){
   $lines=[(string)$r['heading'].':'];foreach($r['items'] as $item)$lines[]='#'.$item['id'].' '.mb_substr((string)$item['name'],0,85);
   return count($lines)>1?implode("\n",$lines):'Nenhum registro encontrado.';
  }
  if(($r['kind']??'')==='finance_summary'){
   return 'Resumo financeiro ('.date('d/m/Y',strtotime($r['from'])).' a '.date('d/m/Y',strtotime($r['to'])).'):'."\n"
    .'Faturado: '.self::brl((int)$r['billed_cents'])."\n"
    .'Recebido: '.self::brl((int)$r['received_cents'])."\n"
    .'Custos: '.self::brl((int)$r['expense_cents'])."\n"
    .'Custos pagos: '.self::brl((int)$r['paid_expense_cents'])."\n"
    .'Resultado de caixa: '.self::brl((int)$r['profit_cents'])."\n"
    .'A receber (total registrado): '.self::brl((int)$r['open_all_cents'])."\n"
    .'Vencido (total registrado): '.self::brl((int)$r['overdue_all_cents'])."\n"
    .'Somente lançamentos já registrados; sem sincronizar novas cobranças.';
  }
  if(($r['kind']??'')==='finance_receivables'){
   $period=$r['scope']==='all'?'Todas as datas':date('m/Y',strtotime($r['from']));
   $lines=['Contas a receber ('.$period.'): '.self::brl((int)$r['total_cents']),
    'Vencidas: '.self::brl((int)$r['overdue_cents']),'Cobranças pendentes: '.(int)$r['count']];
   foreach($r['rows'] as $v){
    $lines[]='#'.$v['id'].' '.$v['client'].' — '.self::brl((int)$v['balance_cents'])
     .' ('.date('d/m/Y',strtotime($v['due_on'])).($v['overdue']?' vencida':'').')';
   }
   if((int)$r['count']>count($r['rows']))$lines[]='Exibindo '.count($r['rows']).' de '.$r['count'].' cobranças.';
   $lines[]='Somente cobranças já registradas.';
   return implode("\n",$lines);
  }
  if(($r['kind']??'')==='finance_expenses'){
   $status=(string)($r['payment_status']??'all');
   $heading=match($status){
    'pending'=>'Despesas/custos pendentes',
    'paid'=>'Despesas/custos pagos',
    default=>'Despesas/custos registrados'
   };
   $lines=[$heading.' ('.date('d/m/Y',strtotime($r['from'])).' a '.date('d/m/Y',strtotime($r['to'])).'):',
    'Total: '.self::brl((int)$r['total_cents']).' | '.(int)$r['count'].' lançamento(s)'];
   if($status==='all'){
    $lines[]='Pendentes: '.self::brl((int)$r['pending_total_cents']).' ('.(int)$r['pending_count'].')';
    $lines[]='Pagos: '.self::brl((int)$r['paid_total_cents']).' ('.(int)$r['paid_count'].')';
   }
   if(!$r['rows'])$lines[]='Nenhuma despesa correspondente encontrada.';
   foreach($r['rows'] as $v)
    $lines[]=date('d/m/Y',strtotime($v['date'])).' '.$v['description'].' — '.self::brl((int)$v['amount_cents']).($v['paid']?' (pago)':' (pendente)');
   if((int)$r['count']>count($r['rows']))$lines[]='Exibindo '.count($r['rows']).' de '.$r['count'].' registros.';
   $lines[]='Valores dos lançamentos já registrados. Data do registro não significa vencimento.';
   $lines[]='Consulta somente leitura.';
   return implode("\n",$lines);
  }
  if(($r['kind']??'')==='created')return 'Task #'.$r['id'].' criada com sucesso.';
  if(($r['kind']??'')==='edited')return 'Task #'.$r['id'].' atualizada com sucesso.';
  if(($r['kind']??'')==='moved')return 'Task #'.$r['id'].' movida para '.mb_substr((string)$r['stage'],0,60).'.';
  if(($r['kind']??'')==='cross_module_report')return AiWhatsAppCrossModuleReport::format($r);
  if(($r['kind']??'')==='attention_report')return AiWhatsAppAttentionReport::format($r);
  if(($r['kind']??'')==='list')return AiWhatsAppConversationUX::formatTasks($r);
  throw new RuntimeException('ai_unknown_result');
 }
 private static function ignore(int $id,string $reason): void {
  DB::run("UPDATE ai_whatsapp_inbox SET ai_state='ignored',ai_error=?,ai_updated_at=? WHERE id=? AND ai_state IN ('processing','reply_pending')",[$reason,self::now(),$id]);
 }
 /**
  * Explain failures on the last attempt (or immediately for invalid requests).
  * At most one outbound delivery; the message state is reserved before WPPConnect.
  */
 private static function retryOrExplain(array $row,Throwable $error,?callable $verify,?callable $mentioned,?callable $send): void {
  $id=(int)$row['id'];$code=AiWhatsAppErrors::code($error);
  $attempt=(int)(DB::one('SELECT ai_attempts FROM ai_whatsapp_inbox WHERE id=?',[$id])['ai_attempts']??3);
  if(AiWhatsAppErrors::retryable($error)&&$attempt<3){
   DB::run("UPDATE ai_whatsapp_inbox SET ai_state='retry',ai_error=?,ai_available_at=?,ai_updated_at=? WHERE id=? AND ai_state='processing'",
    [$code,gmdate('Y-m-d H:i:s',time()+120),self::now(),$id]);
   return;
  }
  $reply=AiWhatsAppErrors::message($error);
  $written=DB::run("UPDATE ai_whatsapp_inbox SET ai_state='reply_pending',ai_error=?,ai_reply=?,ai_processed_at=?,ai_updated_at=? WHERE id=? AND ai_state='processing'",
   [$code,$reply,self::now(),self::now(),$id]);
  if($written->rowCount()!==1)return;
  try{
   $first=self::decision($row,$verify);
   if(($first['status']??'')!=='authorized'||($row['auth_user_id']!==null && (int)$row['auth_user_id']!==(int)($first['user_id']??0))){
    self::ignore($id,'access_revoked_before_error');return;
   }
   self::actor((int)$first['user_id']);
   if($row['chat_type']==='group'&&(Auth::$user['role']??'')!=='admin'){
    self::ignore($id,'group_admin_only');return;
   }
   self::deliver($row,$first,$verify,$mentioned,$send,$reply);
  }catch(Throwable){
   // No unsafe automatic resend: if WPPConnect accepted, result may be uncertain.
   DB::run("UPDATE ai_whatsapp_inbox SET ai_state='failed',ai_error='error_reply_unavailable',ai_updated_at=? WHERE id=? AND ai_state='reply_pending'",[self::now(),$id]);
  }
 }
}
