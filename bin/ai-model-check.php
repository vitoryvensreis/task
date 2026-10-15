<?php
declare(strict_types=1);
/**
 * Run on VPS: php bin/ai-model-check.php
 * Read-only metadata lookups. Does NOT call text generation or reveal API keys,
 * request bodies, WhatsApp messages or provider error response bodies.
 */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/app/bootstrap.php';
if(!extension_loaded('curl')){fwrite(STDERR,"PHP cURL indisponível.\n");exit(1);}
$cfg=config('ai_whatsapp',[]);
if(!is_array($cfg))$cfg=[];
$check=static function(string $url,array $headers): array {
 $c=curl_init($url);
 if($c===false)return ['http'=>0,'status'=>'connection_failed'];
 curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>12,
  CURLOPT_FOLLOWLOCATION=>false,CURLOPT_HTTPHEADER=>$headers,CURLOPT_USERAGENT=>'TaskEsc-ModelCheck/1.0']);
 $raw=curl_exec($c);$http=(int)curl_getinfo($c,CURLINFO_HTTP_CODE);curl_close($c);
 if(!is_string($raw))return ['http'=>0,'status'=>'connection_failed'];
 $status=match(true){
  $http===200=>'available',$http===404=>'not_available_to_key',
  $http===429=>'quota_limit',in_array($http,[401,403],true)=>'auth_or_access_rejected',
  $http>=500=>'provider_unavailable',default=>'provider_error'
 };
 return ['http'=>$http,'status'=>$status];
};
$result=['enabled'=>(bool)($cfg['enabled']??false),'primary'=>(string)($cfg['provider']??'gemini'),
 'fallback'=>(string)($cfg['fallback_provider']??'groq'),'models'=>[]];
$gm=(string)($cfg['gemini_model']??'gemini-3.8-flash');
$gk=trim((string)($cfg['gemini_api_key']??''));
if(!preg_match('/\A[a-z0-9][a-z0-9._-]{2,90}\z/D',$gm)){
 $result['models']['gemini']=['status'=>'invalid_model_identifier'];
}elseif(strlen($gk)<=20){
 $result['models']['gemini']=['model'=>$gm,'status'=>'not_configured'];
}else{
 $result['models']['gemini']=['model'=>$gm]+$check('https://generativelanguage.googleapis.com/v1beta/models/'.$gm,['x-goog-api-key: '.$gk,'Accept: application/json']);
}
$groq=(string)($cfg['groq_model']??'openai/gpt-oss-120b');
$gq=trim((string)($cfg['groq_api_key']??''));
$probe=in_array('--probe-groq',$argv??[],true);
foreach(array_slice($argv??[],1) as $argument){
 if($argument!=='--probe-groq'){fwrite(STDERR,"Opção desconhecida. Use --probe-groq somente quando quiser testar uma geração real.\n");exit(2);}
}
if(!preg_match('/\A[a-z0-9][a-z0-9._\/-]{2,90}\z/D',$groq)){
 $result['models']['groq']=['status'=>'invalid_model_identifier'];
}elseif(strlen($gq)<=20){
 $result['models']['groq']=['model'=>$groq,'status'=>'not_configured'];
}else{
 // Groq IDs may contain slashes: a model-detail URL is not a reliable
 // availability check in all gateways. Prefer the authenticated inventory.
 $c=curl_init('https://api.groq.com/openai/v1/models');
 $raw=null;$http=0;
 if($c!==false){
  curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>12,
   CURLOPT_FOLLOWLOCATION=>false,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$gq,'Accept: application/json'],
   CURLOPT_USERAGENT=>'TaskEsc-ModelCheck/1.1']);
  $response=curl_exec($c);$http=(int)curl_getinfo($c,CURLINFO_HTTP_CODE);curl_close($c);
  if(is_string($response))$raw=$response;
 }
 $result['models']['groq']=AiWhatsAppModelDiagnostics::fromGroqList($groq,$http,$raw);
 if($probe){
  // Opt-in single low-token generation to verify actual function calling.
  // This does not invoke any real TaskEsc tool, write any business data,
  // or echo request/response payloads. It MAY consume provider credits.
  if($result['models']['groq']['status']!=='listed'){
   $result['models']['groq']['probe']=['status'=>'skipped_model_not_listed','generation_tested'=>false];
  }else{
   $call=curl_init('https://api.groq.com/openai/v1/chat/completions');
   $answer=null;$status=0;
   if($call!==false){
    curl_setopt_array($call,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,
     CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>30,CURLOPT_FOLLOWLOCATION=>false,
     CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$gq,'Content-Type: application/json','Accept: application/json'],
     CURLOPT_POSTFIELDS=>json(AiWhatsAppModelDiagnostics::groqProbeBody($groq)),
     CURLOPT_USERAGENT=>'TaskEsc-ModelCheck/1.1']);
    $output=curl_exec($call);$status=(int)curl_getinfo($call,CURLINFO_HTTP_CODE);curl_close($call);
    if(is_string($output))$answer=$output;
   }
   $result['models']['groq']['probe']=AiWhatsAppModelDiagnostics::fromGroqProbe($status,$answer);
  }
 }
}
echo json($result).PHP_EOL;
