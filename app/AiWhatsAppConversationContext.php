<?php
declare(strict_types=1);

/**
 * Short-lived private conversational context for read-only tools.
 * We store ONLY the prior authorized tool name and validated arguments.
 * We never retain business results, WhatsApp message content, free text from an LLM,
 * approval codes or tool writes. Every follow-up re-runs the current authorized API.
 */
final class AiWhatsAppConversationContext
{
 private const PREFIX='ai-conversation-v1:';
 private const TTL_MINUTES=10;
 private const READ_TOOLS=['list_tasks','agenda_list','finance_expenses'];
 private static function currentNames(array $catalog): array {
  return array_values(array_filter(array_column(array_column($catalog,'function'),'name'),'is_string'));
 }
 private static function key(array $row,int $userId,array $catalog): ?array {
  if($userId<=0||($row['chat_type']??null)!=='private')return null;
  $secret=(string)config('app_key','');
  if(strlen($secret)<32)return null;
  $nameHash=hash('sha256',json($catalog));
  $scope=(string)($row['session_name']??'').'|'.(string)($row['chat_id']??'')
   .'|'.(string)($row['sender_id']??'').'|private|'.$nameHash;
  return [self::PREFIX.hash_hmac('sha256',$scope,$secret),$nameHash];
 }
 private static function safePlan(array $plan,array $catalog): bool {
  if(array_keys($plan)!==['tool','arguments'])return false;
  $tool=$plan['tool']??null;$args=$plan['arguments']??null;
  if(!is_string($tool)||!in_array($tool,self::READ_TOOLS,true)
   ||!in_array($tool,self::currentNames($catalog),true))return false;
  if(!is_array($args)||count($args)>8||($args!==[]&&array_is_list($args)))return false;
  if(strlen(json($args))>1100)return false;
  $permitted=match($tool){
   'list_tasks'=>['query','limit','stage_id','stage_name','show_other_stages','client_id','overdue'],
   'agenda_list'=>['from','to','limit'],
   'finance_expenses'=>['from','to','limit','payment_status'],
   default=>[]
  };
  foreach($args as $name=>$value){
   if(!is_string($name)||!in_array($name,$permitted,true)
    ||is_array($value)||is_object($value)||is_null($value))return false;
  }
  return true;
 }
 public static function remember(array $row,int $userId,array $catalog,array $plan): void {
  if(!self::safePlan($plan,$catalog))return;
  $parts=self::key($row,$userId,$catalog);
  if(!$parts)return;
  [$key,$fingerprint]=$parts;
  $id=(int)($row['id']??0);if($id<=0)return;
  $value=['source_id'=>$id,'plan'=>$plan];
  DB::run("INSERT INTO idempotency(user_id,operation_key,request_hash,result_json,created_at)
    VALUES (?,?,?,?,UTC_TIMESTAMP())
    ON DUPLICATE KEY UPDATE request_hash=VALUES(request_hash),result_json=VALUES(result_json),created_at=VALUES(created_at)",
    [$userId,$key,$fingerprint,json($value)]);
 }
 private static function previous(array $row,int $userId,array $catalog): ?array {
  $parts=self::key($row,$userId,$catalog);if(!$parts)return null;
  [$key,$fingerprint]=$parts;
  $currentId=(int)($row['id']??0);if($currentId<=0)return null;
  // Select the MOST RECENT inbound from this sender and conversation,
  // even if ignored, denied or unanswered. No skipping intervening turns.
  $last=DB::one("SELECT id,auth_user_id,auth_status,ai_state,received_at
    FROM ai_whatsapp_inbox
    WHERE session_name=? AND chat_id=? AND sender_id=? AND chat_type='private' AND id<?
    ORDER BY id DESC LIMIT 1",[
     (string)($row['session_name']??''),(string)($row['chat_id']??''),
     (string)($row['sender_id']??''),$currentId
    ]);
  if(!$last||$last['ai_state']!=='sent'||$last['auth_status']!=='authorized'
   ||(int)$last['auth_user_id']!==$userId)return null;
  $record=DB::one("SELECT result_json FROM idempotency
    WHERE user_id=? AND operation_key=? AND request_hash=?
      AND created_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 10 MINUTE)",[$userId,$key,$fingerprint]);
  if(!$record)return null;
  try{$value=decode((string)$record['result_json'],null);}catch(Throwable){return null;}
  if(!is_array($value)||($value['source_id']??null)!==(int)$last['id']
   ||!is_array($value['plan']??null)||!self::safePlan($value['plan'],$catalog))return null;
  // Inbox may have an older receive timestamp if the sender replays a queued message.
  try{
   $when=new DateTimeImmutable((string)$last['received_at'],new DateTimeZone('UTC'));
   if($when < new DateTimeImmutable('-10 minutes',new DateTimeZone('UTC')))return null;
  }catch(Throwable){return null;}
  return $value['plan'];
 }
 private static function norm(string $message): string {
  return AiWhatsAppFinancialConversation::normalize($message);
 }
 private static function tomorrow(): string {
  return (new DateTimeImmutable('today',new DateTimeZone((string)config('timezone','America/Fortaleza'))))
   ->modify('+1 day')->format('Y-m-d');
 }
 private static function followup(string $message,array $previous): ?array {
  if(mb_strlen($message,'UTF-8')>130)return null;
  $text=self::norm($message);
  $tool=$previous['tool'];$args=$previous['arguments'];
  // Exact, harmless read-only follow-up grammar. Never execute writes from context.
  if($tool==='list_tasks'){
   if(preg_match('/\A(?:e\s+)?(?:as\s+)?(?:do|da|de)\s+([a-z0-9][a-z0-9 -]{1,39})\z/u',$text,$m)){
    $name=trim($m[1]);
    if(preg_match('/\b(?:apague|exclua|delete|remova|edite|mova|cancele|pague)\b/u',$name))return null;
    $args['query']=$name;
    // The new term replaces the original topic; retain stage/client filters if set.
    return ['tool'=>$tool,'arguments'=>$args];
   }
   if(preg_match('/\A(?:e\s+)?(?:so|somente|apenas)\s+as\s+atrasadas\z/u',$text)){
    $args['overdue']=true;return ['tool'=>$tool,'arguments'=>$args];
   }
   if(preg_match('/\A(?:e\s+)?(?:so|somente|apenas)\s+as\s+(?:em\s+producao|concluidas)\z/u',$text)){
    $stage=str_contains($text,'producao')?'Em produção':'Concluídas';
    unset($args['stage_id'],$args['show_other_stages']);
    $args['stage_name']=$stage;
    return ['tool'=>$tool,'arguments'=>$args];
   }
  }
  if($tool==='agenda_list'&&preg_match('/\A(?:e\s+)?(?:amanha|para\s+amanha|os\s+de\s+amanha)\z/u',$text)){
   $tomorrow=self::tomorrow();
   $args['from']=$tomorrow;$args['to']=$tomorrow;
   return ['tool'=>$tool,'arguments'=>$args];
  }
  if($tool==='finance_expenses'&&preg_match('/\A(?:e\s+)?(?:so|somente|apenas)\s+as\s+(pendentes|pagas)\z/u',$text,$m)){
   $args['payment_status']=$m[1]==='pendentes'?'pending':'paid';
   return ['tool'=>$tool,'arguments'=>$args];
  }
  return null;
 }
 /**
  * Returns a plan only for the just-previous authorized PRIVATE read.
  * The caller still enforces current ACL, verifies sender again and reads live data.
  */
 public static function plan(string $message,array $row,int $userId,array $catalog): ?array {
  if(($row['chat_type']??null)!=='private')return null;
  $prior=self::previous($row,$userId,$catalog);
  if($prior===null)return null;
  $next=self::followup($message,$prior);
  return $next!==null&&self::safePlan($next,$catalog)?$next:null;
 }
}
