<?php
declare(strict_types=1);
/** Read-only server diagnostic. No chat body, sender, tokens or secret output. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/app/bootstrap.php';
try{
 $health=AiWhatsAppWorker::health();
 $counts=DB::all("SELECT ai_state,COUNT(*) AS total FROM ai_whatsapp_inbox WHERE received_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 24 HOUR) GROUP BY ai_state ORDER BY ai_state");
 $recent=DB::all("SELECT id,received_at,chat_type,auth_status,auth_reason,ai_state,ai_attempts,ai_error FROM ai_whatsapp_inbox WHERE received_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 24 HOUR) ORDER BY id DESC LIMIT 15");
 echo json(['health'=>$health,'last_24h_states'=>array_map(static fn($r)=>['state'=>(string)$r['ai_state'],'count'=>(int)$r['total']],$counts),
  'recent'=>array_map(static fn($r)=>['id'=>(int)$r['id'],'utc'=>(string)$r['received_at'],'chat'=>(string)$r['chat_type'],
    'authorization'=>(string)$r['auth_status'],'auth_reason'=>(string)($r['auth_reason']??''),'state'=>(string)$r['ai_state'],
    'attempts'=>(int)$r['ai_attempts'],'error_code'=>(string)($r['ai_error']??'')],$recent)]).PHP_EOL;
}catch(Throwable $e){
 fwrite(STDERR,json(['status'=>'error','code'=>'diagnostic_unavailable']).PHP_EOL);exit(1);
}
