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
$groq=(string)($cfg['groq_model']??'llama-3.3-70b-versatile');
$gq=trim((string)($cfg['groq_api_key']??''));
if(!preg_match('/\A[a-z0-9][a-z0-9._\/-]{2,90}\z/D',$groq)){
 $result['models']['groq']=['status'=>'invalid_model_identifier'];
}elseif(strlen($gq)<=20){
 $result['models']['groq']=['model'=>$groq,'status'=>'not_configured'];
}else{
 $result['models']['groq']=['model'=>$groq]+$check('https://api.groq.com/openai/v1/models/'.rawurlencode($groq),['Authorization: Bearer '.$gq,'Accept: application/json']);
}
echo json($result).PHP_EOL;
