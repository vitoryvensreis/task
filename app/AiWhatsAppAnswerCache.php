<?php
declare(strict_types=1);

/**
 * Read-only answer cache, strictly scoped to the authorized private conversation.
 * Every lookup runs the CURRENT business tool first; a cached reply is used only
 * when the live result digest still matches. No stale financial/agenda/task results.
 * Inbound questions already persist in ai_whatsapp_inbox; do not duplicate bodies.
 */
final class AiWhatsAppAnswerCache
{
 private const PREFIX='ai-answer-v1:';
 private const DAYS=7;
 private const SAFE=[
  'list_tasks','attention_report','agenda_calendars','agenda_list','agenda_detail',
  'agenda_conflicts','list_clients','list_projects','list_team','list_events',
  'finance_summary','finance_receivables','finance_expenses','cross_module_report'
 ];
 private static function parts(string $question,array $row,int $userId,array $catalog,array $plan): ?array {
  if($userId<1||($row['chat_type']??'')!=='private'||mb_strlen($question,'UTF-8')>1200)return null;
  if(array_keys($plan)!==['tool','arguments']||!in_array($plan['tool']??null,self::SAFE,true))return null;
  $tool=$plan['tool'];$args=$plan['arguments'];
  if(!is_array($args)||count($args)>16||($args!==[]&&array_is_list($args)))return null;
  if(!in_array($tool,array_column(array_column($catalog,'function'),'name'),true))return null;
  $secret=(string)config('app_key','');
  if(strlen($secret)<32)return null;
  $norm=mb_strtolower(trim(preg_replace('/\s+/u',' ',$question)??$question),'UTF-8');
  if($norm==='')return null;
  $scope=implode('|',[
   (string)($row['session_name']??''),(string)($row['chat_id']??''),
   (string)($row['sender_id']??''),'private'
  ]);
  $fingerprint=hash('sha256',json($catalog));
  $hash=hash_hmac('sha256',json([$norm,$scope,$fingerprint,$tool,$args]),$secret);
  return [self::PREFIX.$hash,$fingerprint,$secret];
 }
 private static function digest(array $result,string $secret): string {
  // Includes every returned field, including totals/versions returned by gateways.
  return hash_hmac('sha256',json($result),$secret);
 }
 public static function recall(string $question,array $row,int $userId,array $catalog,array $plan,array $liveResult): ?string {
  $parts=self::parts($question,$row,$userId,$catalog,$plan);
  if($parts===null)return null;
  [$key,$fingerprint,$secret]=$parts;
  $rec=DB::one("SELECT result_json FROM idempotency WHERE user_id=? AND operation_key=? AND request_hash=?
   AND created_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 7 DAY)",[$userId,$key,$fingerprint]);
  if(!$rec)return null;
  try{$saved=decode((string)$rec['result_json'],null);}catch(Throwable){return null;}
  if(!is_array($saved)||($saved['digest']??null)!==self::digest($liveResult,$secret))return null;
  $reply=$saved['reply']??null;
  return is_string($reply)&&$reply!==''&&mb_strlen($reply,'UTF-8')<=4000?$reply:null;
 }
 public static function remember(string $question,array $row,int $userId,array $catalog,array $plan,array $liveResult,string $reply): void {
  if($reply===''||mb_strlen($reply,'UTF-8')>4000)return;
  $parts=self::parts($question,$row,$userId,$catalog,$plan);
  if($parts===null)return;
  [$key,$fingerprint,$secret]=$parts;
  // Only the rendered answer is cached; no raw tool result or API credentials.
  $saved=['digest'=>self::digest($liveResult,$secret),'reply'=>$reply];
  DB::run("INSERT INTO idempotency(user_id,operation_key,request_hash,result_json,created_at)
    VALUES (?,?,?,?,UTC_TIMESTAMP())
    ON DUPLICATE KEY UPDATE request_hash=VALUES(request_hash),result_json=VALUES(result_json),created_at=VALUES(created_at)",
    [$userId,$key,$fingerprint,json($saved)]);
 }
}
