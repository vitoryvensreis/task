<?php
declare(strict_types=1);

/**
 * Short private dialogue for marking a registered expense as paid.
 * Context comes ONLY from the immediately previous authorized, delivered
 * WhatsApp message, never from another chat, user, or an arbitrary LLM guess.
 * Produces text clarification or a proposed action; actual mutation ALWAYS
 * goes through AiWhatsAppApproval (separate one-time CONFIRMAR token).
 */
final class AiWhatsAppExpensePaidDialogue
{
 private const TTL_MINUTES=10;

 private static function allowed(array $catalog,array $row,int $actor): bool {
  if($actor<1||($row['chat_type']??'')!=='private')return false;
  return in_array('mark_expense_paid',array_column(array_column($catalog,'function'),'name'),true);
 }

 private static function previous(array $row,int $actor): ?array {
  $id=(int)($row['id']??0);
  if($id<1)return null;
  $last=DB::one("SELECT id,body,ai_reply,ai_state,auth_status,auth_user_id,received_at
    FROM ai_whatsapp_inbox
    WHERE id<? AND session_name=? AND chat_id=? AND sender_id=? AND chat_type='private'
    ORDER BY id DESC LIMIT 1",[
     $id,(string)($row['session_name']??''),(string)($row['chat_id']??''),
     (string)($row['sender_id']??'')
    ]);
  if(!$last||($last['ai_state']??'')!=='sent'
   ||($last['auth_status']??'')!=='authorized'||(int)$last['auth_user_id']!==$actor)return null;
  try{
   $time=new DateTimeImmutable((string)$last['received_at'],new DateTimeZone('UTC'));
   $now=new DateTimeImmutable('now',new DateTimeZone('UTC'));
   if($time<$now->modify('-'.self::TTL_MINUTES.' minutes')||$time>$now->modify('+1 minute'))return null;
  }catch(Throwable){return null;}
  return $last;
 }

 private static function previousState(?array $prev): array {
  if(!$prev)return ['kind'=>'none','id'=>null];
  $reply=trim((string)($prev['ai_reply']??''));
  // A successful CONFIRMAR for a new expense is authoritative. Never use
  // merely a user's "despesa 48" or an LLM assertion as a created record.
  if(preg_match('/\ACONFIRMAR\s+[A-F0-9]{12}\z/i',trim((string)$prev['body']))
   &&preg_match('/\ADespesa criada \(pendente\) com sucesso \(#([1-9]\d*)\)\.\z/u',$reply,$m))
   return ['kind'=>'created','id'=>(int)$m[1]];
  if(preg_match('/\APara marcar a despesa #([1-9]\d*) como paga, qual foi a data do pagamento\?/u',$reply,$m))
   return ['kind'=>'await_date','id'=>(int)$m[1]];
  if($reply==='Qual é o ID da despesa que deseja marcar como paga? Ainda não alterei nenhuma despesa.')
   return ['kind'=>'await_id','id'=>null];
  return ['kind'=>'none','id'=>null];
 }

 private static function localDate(string $input): ?string {
  $zone=new DateTimeZone((string)config('timezone','America/Fortaleza'));
  $today=new DateTimeImmutable('today',$zone);
  if($input==='hoje')return $today->format('Y-m-d');
  if($input==='ontem')return $today->modify('-1 day')->format('Y-m-d');
  $date=null;
  if(preg_match('/\A\d{4}-\d{2}-\d{2}\z/D',$input))$date=DateTimeImmutable::createFromFormat('!Y-m-d',$input,$zone);
  elseif(preg_match('/\A\d{2}\/\d{2}\/\d{4}\z/D',$input))$date=DateTimeImmutable::createFromFormat('!d/m/Y',$input,$zone);
  if(!$date)return null;
  $valid=$date->format(preg_match('/\A\d{4}/',$input)?'Y-m-d':'d/m/Y')===$input;
  return $valid?$date->format('Y-m-d'):null;
 }

 private static function askDate(int $id): array {
  return ['text'=>'Para marcar a despesa #'.$id." como paga, qual foi a data do pagamento? Responda 'hoje' ou DD/MM/AAAA. Ainda não alterei a despesa."];
 }

 private static function action(int $id,string $date): array {
  return ['tool'=>'mark_expense_paid','arguments'=>['expense_id'=>$id,'paid_on'=>$date]];
 }

 /**
  * Return null for unrelated or unsafe text. Purely local grammar avoids
  * unnecessary model calls and prevents accidental financial changes.
  */
 public static function plan(string $message,array $row,int $actor,array $catalog): ?array {
  if(!self::allowed($catalog,$row,$actor)||mb_strlen($message,'UTF-8')>180)return null;
  $text=AiWhatsAppFinancialConversation::normalize($message);
  $prior=self::previousState(self::previous($row,$actor));

  // Follow up on our own explicit prompt for an ID. ID-only messages must
  // never cause a mark-paid proposal without a separate payment date.
  if($prior['kind']==='await_id'&&preg_match('/\A#?([1-9]\d{0,9})\z/D',$text,$m))
   return self::askDate((int)$m[1]);
  // A requested payment date is a missing slot; the expense ID is preserved.
  if($prior['kind']==='await_date'){
   $date=self::localDate($text);
   if($date!==null)return self::action((int)$prior['id'],$date);
   if(preg_match('/\A#?([1-9]\d{0,9})\z/D',$text,$m)){
    if((int)$m[1]===(int)$prior['id'])return self::askDate((int)$prior['id']);
    return ['text'=>'A despesa em andamento é #'.$prior['id'].'. Para mudar para #'.$m[1].', faça um pedido completo. Nenhum pagamento foi registrado.'];
   }
  }

  // Explicit request: "pode colocar ela/a despesa 48 como pago [hoje]".
  // Pronoun resolves only to the immediately previous successful creation.
  if(!preg_match('/\A(?:(?:pode\s+)?(?:colocar|coloca|marcar|marca|marque|deixar|deixa|registrar))\s+(?:(?:a|essa|esta)\s+)?(?:despesa\s*#?\s*([1-9]\d{0,9})|(?:a\s+)?(?:ela|essa|esta|despesa))\s+como\s+pag[ao](?:\s+(?:(?:em|no\s+dia)\s+)?(hoje|ontem|\d{4}-\d{2}-\d{2}|\d{2}\/\d{2}\/\d{4}))?\z/u',$text,$m))
   return null;
  $id=isset($m[1])&&$m[1]!==''?(int)$m[1]:($prior['kind']==='created'?(int)$prior['id']:0);
  if($id===0)return ['text'=>'Qual é o ID da despesa que deseja marcar como paga? Ainda não alterei nenhuma despesa.'];
  $when=(string)($m[2]??'');
  if($when==='')return self::askDate($id);
  $date=self::localDate($when);
  if($date===null)return null;
  return self::action($id,$date);
 }
}
