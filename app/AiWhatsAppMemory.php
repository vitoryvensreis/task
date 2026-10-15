<?php
declare(strict_types=1);

/**
 * Persistent per-user/per-chat intent memory. Every inbound question is already
 * saved in ai_whatsapp_inbox; idempotency stores only reusable, read-only plans.
 *
 * Important: never cache business results, free-form model text, write actions
 * or confirmation codes. Business authorization and live DB reads still happen
 * for every incoming WhatsApp message.
 */
final class AiWhatsAppMemory
{
 private const PREFIX='ai-read-plan-v1:';
 private const TTL_DAYS=7;
 private const SAFE_TOOLS=[
  'list_tasks','attention_report','agenda_calendars','agenda_list','agenda_detail',
  'list_clients','list_projects','list_team','list_events',
  'finance_summary','finance_receivables','finance_expenses'
 ];
 private static function allowedMessage(string $message): bool {
  $msg=trim($message);
  if($msg===''||mb_strlen($msg,'UTF-8')>400)return false;
  // Never retain action approvals, PINs, credentials, secret-looking requests,
  // or date-relative interpretation that could be different tomorrow.
  if(preg_match('/\b(?:confirmar|cancelar)\s+[a-f0-9]{8,}\b/i',$msg))return false;
  if(preg_match('/\b(?:token|senha|api[\s_-]*key|secret|credencial|chave\s+de\s+api)\b/iu',$msg))return false;
  if(preg_match('/\b(?:hoje|amanh[ãa]|ontem|anteontem|agora|semana|m[eê]s|segunda|ter[cç]a|quarta|quinta|sexta|s[aá]bado|domingo)\b/iu',$msg))return false;
  return true;
 }
 private static function key(string $message,array $row,int $userId,array $catalog): ?array {
  if($userId<1||!self::allowedMessage($message))return null;
  if(!in_array($row['chat_type']??null,['group','private'],true))return null;
  $names=array_values(array_filter(array_column(array_column($catalog,'function'),'name'),'is_string'));
  if(!$names)return null;
  $normalized=mb_strtolower(trim(preg_replace('/\s+/u',' ',$message)??$message),'UTF-8');
  $scope=(string)$row['session_name'].'|'.(string)$row['chat_id'].'|'.(string)$row['chat_type'];
  $secret=(string)config('app_key','');
  if(strlen($secret)<32)return null;
  $fingerprint=hash('sha256',json($catalog)); // Permission/tool/schema changes invalidate old plans.
  $key=self::PREFIX.hash_hmac('sha256',$normalized.'|'.$scope.'|'.$fingerprint,$secret);
  return [$key,$fingerprint,$names];
 }
 private static function planAllowed(array $plan,array $names): bool {
  if(array_keys($plan)!==['tool','arguments'])return false;
  $name=$plan['tool']??null;$args=$plan['arguments']??null;
  if(!is_string($name)||!in_array($name,self::SAFE_TOOLS,true)||!in_array($name,$names,true))return false;
  if(!is_array($args)||count($args)>10||($args!==[]&&array_is_list($args)))return false;
  if(strlen(json($args))>2500)return false;
  return true;
 }
 public static function recall(string $message,array $row,int $userId,array $catalog): ?array {
  $parts=self::key($message,$row,$userId,$catalog);
  if($parts===null)return null;
  [$key,$fingerprint,$names]=$parts;
  $record=DB::one("SELECT result_json FROM idempotency WHERE user_id=? AND operation_key=? AND request_hash=?
    AND created_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 7 DAY)",[$userId,$key,$fingerprint]);
  if(!$record)return null;
  try{$plan=decode((string)$record['result_json'],null);}catch(Throwable){return null;}
  return is_array($plan)&&self::planAllowed($plan,$names)?$plan:null;
 }
 public static function remember(string $message,array $row,int $userId,array $catalog,array $plan): void {
  $parts=self::key($message,$row,$userId,$catalog);
  if($parts===null)return;
  [$key,$fingerprint,$names]=$parts;
  if(!self::planAllowed($plan,$names))return;
  DB::run("INSERT INTO idempotency(user_id,operation_key,request_hash,result_json,created_at)
    VALUES (?,?,?,?,UTC_TIMESTAMP())
    ON DUPLICATE KEY UPDATE request_hash=VALUES(request_hash),result_json=VALUES(result_json),created_at=VALUES(created_at)",
    [$userId,$key,$fingerprint,json($plan)]);
 }
}
