<?php
declare(strict_types=1);
/**
 * CLI-only live diagnosis (NO repair/no queue replays).
 * No message, sender, phone, chat id, token, API response, or secret is printed.
 */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/app/bootstrap.php';
try{
 $now=gmdate('Y-m-d\TH:i:s\Z');
 $health=AiWhatsAppWorker::health();
 $stats=DB::all(
  "SELECT chat_type, ai_state, auth_status, COUNT(*) quantity
     FROM ai_whatsapp_inbox
    WHERE received_at>=DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 24 HOUR)
    GROUP BY chat_type, ai_state, auth_status ORDER BY chat_type, ai_state, auth_status");
 $last=DB::one(
  "SELECT MAX(received_at) received, MAX(CASE WHEN ai_state='sent' THEN received_at END) sent,
    MAX(CASE WHEN ai_state IN ('queued','retry','processing','sending','reply_pending') THEN received_at END) unresolved
   FROM ai_whatsapp_inbox");
 $pending=DB::one(
  "SELECT COUNT(*) qty,MIN(received_at) oldest,
    SUM(CASE WHEN ai_state='processing' THEN 1 ELSE 0 END) processing,
    SUM(CASE WHEN ai_state='sending' THEN 1 ELSE 0 END) sending,
    SUM(CASE WHEN ai_state='reply_pending' THEN 1 ELSE 0 END) reply_pending
   FROM ai_whatsapp_inbox WHERE ai_state IN ('queued','retry','processing','sending','reply_pending')");
 $wpp=['configured'=>null,'provider_up'=>null,'connected'=>null,'check'=>'not_checked'];
 try{
  $admin=DB::one("SELECT * FROM users WHERE role='admin' AND active=1 ORDER BY id LIMIT 1");
  if($admin){
   Auth::$user=$admin;Auth::$actor=$admin;Auth::$previewClientId=null;Auth::$origin='web';
   $s=WppConnect::status();
   $wpp=['configured'=>(bool)($s['configured']??false),
    'provider_up'=>(bool)($s['provider_up']??false),
    'connected'=>(bool)($s['connected']??false),'check'=>'ok'];
  }
 }catch(Throwable $e){$wpp['check']='unavailable';}
 echo json([
  'utc'=>$now,
  'worker_health'=>['schema'=>$health['schema']??null,'enabled'=>$health['enabled']??null,'queue'=>$health['queue']??null],
  'wppconnect'=>$wpp,
  'messages_24h'=>array_map(static fn(array $s)=>[
   'chat'=>(string)$s['chat_type'],'state'=>(string)$s['ai_state'],
   'authorization'=>(string)$s['auth_status'],'count'=>(int)$s['quantity']],$stats),
  'latest_utc'=>['received'=>$last['received']??null,'sent'=>$last['sent']??null,
   'unresolved'=>$last['unresolved']??null],
  'pending'=>['count'=>(int)($pending['qty']??0),'oldest_utc'=>$pending['oldest']??null,
   'processing'=>(int)($pending['processing']??0),
   'sending'=>(int)($pending['sending']??0),
   'reply_pending'=>(int)($pending['reply_pending']??0)]
 ]).PHP_EOL;
}catch(Throwable $e){
 error_log('TaskEsc AI incident probe failed ['.get_class($e).']');
 fwrite(STDERR,json(['status'=>'error','code'=>'ai_incident_diagnostic_failed']).PHP_EOL);
 exit(1);
}
