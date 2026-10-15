<?php
declare(strict_types=1);

/**
 * Step 1/3: privacy-preserving TaskEsc AI usage events, written outside public/.
 * Never stores messages, WhatsApp JIDs, names, task IDs, API keys or response bodies.
 * Telemetry is best-effort and MUST NOT block business processing.
 */
final class AiWhatsAppUsageMetrics
{
 private const ROUTES=['fast_path','cache_hit','model_route'];
 private const CONVERSATION=['guided_help','local_clarification','context_followup','answer_cache_hit'];
 private const PROVIDERS=['gemini','groq'];

 public static function fileForDay(string $date): ?string {
  if(!preg_match('/\A\d{4}-\d{2}-\d{2}\z/D',$date))return null;
  return ROOT.'/storage/logs/ai-usage-'.$date.'.jsonl';
 }

 private static function intMetric(mixed $value): ?int {
  if(!is_int($value)&&!(is_string($value)&&ctype_digit($value)))return null;
  $number=(int)$value;
  return $number>=0&&$number<=1000000000?$number:null;
 }

 public static function providerRow(
  string $provider,string $model,int $http,int $curlErr,float $startedAt,?string $body,
  ?float $endedAt=null
 ): array {
  $provider=in_array($provider,self::PROVIDERS,true)?$provider:'unknown';
  $model=preg_match('/\A[a-zA-Z0-9._\/-]{1,100}\z/D',$model)?$model:'unknown';
  $endedAt??=microtime(true);
  $latency=max(0,min(600000,(int)round(($endedAt-$startedAt)*1000)));
  $http=($http>=100&&$http<=599)?$http:0;
  $curlErr=max(0,min(9999,$curlErr));
  $usage=[];
  if(is_string($body)&&$body!==''){
   $data=json_decode($body,true,32);
   if(is_array($data)){
    $usage=$provider==='gemini'?($data['usageMetadata']??[]):($data['usage']??[]);
    if(!is_array($usage))$usage=[];
   }
  }
  if($provider==='gemini'){
   $input=self::intMetric($usage['promptTokenCount']??null);
   $output=self::intMetric($usage['candidatesTokenCount']??null);
   $total=self::intMetric($usage['totalTokenCount']??null);
  }else{
   $input=self::intMetric($usage['prompt_tokens']??null);
   $output=self::intMetric($usage['completion_tokens']??null);
   $total=self::intMetric($usage['total_tokens']??null);
  }
  return [
   'type'=>'provider','provider'=>$provider,'model'=>$model,
   'status'=>$curlErr!==0||$http===0?'transport_error':($http>=200&&$http<300?'http_ok':'http_error'),
   'http'=>$http,'curl_errno'=>$curlErr,'duration_ms'=>$latency,
   'input_tokens'=>$input,'output_tokens'=>$output,'total_tokens'=>$total
  ];
 }

 public static function provider(
  string $provider,string $model,int $http,int $curlErr,float $startedAt,?string $body
 ): void {
  self::write(self::providerRow($provider,$model,$http,$curlErr,$startedAt,$body));
 }

 public static function routing(string $route): void {
  if(!in_array($route,self::ROUTES,true))return;
  self::write(['type'=>'routing','route'=>$route]);
 }
 /** Counter-only events. Never store message content or a conversation ID. */
 public static function conversation(string $kind): void {
  if(!in_array($kind,self::CONVERSATION,true))return;
  self::write(['type'=>'conversation','kind'=>$kind]);
 }

 private static function write(array $event): void {
  try{
   $filename=self::fileForDay(gmdate('Y-m-d'));
   if($filename===null||!is_dir(dirname($filename)))return;
   $event=['at'=>gmdate('Y-m-d\TH:i:s\Z')]+$event;
   $line=json_encode($event,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
   if(strlen($line)>1200)return;
   $isNew=!is_file($filename);
   $fp=@fopen($filename,'ab');
   if($fp===false)return;
   try{
    if($isNew)@chmod($filename,0600);
    if(!flock($fp,LOCK_EX))return;
    try{
     $remaining=$line;
     while($remaining!==''){
      $written=fwrite($fp,$remaining);
      if($written===false||$written===0)break;
      $remaining=substr($remaining,$written);
     }
    }finally{flock($fp,LOCK_UN);}
   }finally{fclose($fp);}
  }catch(Throwable){ /* Observability must never block tasks, approvals or WhatsApp replies. */ }
 }

 /** Aggregate-only report for CLI now, ready to feed the ADM dashboard in Step 2. */
 public static function summarizeFiles(int $days=7,?DateTimeImmutable $now=null): array {
  $days=max(1,min(30,$days));
  $now=($now??new DateTimeImmutable('now',new DateTimeZone('UTC')))
   ->setTimezone(new DateTimeZone('UTC'));
  $summary=['period_days'=>$days,'provider_attempts'=>0,'provider_success_http'=>0,
   'provider_errors'=>0,'duration_ms_sum'=>0,'duration_samples'=>0,
   'input_tokens'=>0,'output_tokens'=>0,'total_tokens'=>0,'token_known_attempts'=>0,
   'routes'=>['fast_path'=>0,'cache_hit'=>0,'model_route'=>0],
   'conversation'=>['guided_help'=>0,'local_clarification'=>0,'context_followup'=>0,'answer_cache_hit'=>0],'providers'=>[],
   'coverage'=>'Only events collected since metrics deployment. HTTP 200 does not guarantee TaskEsc tool execution.'];
  for($day=0;$day<$days;$day++){
   $date=$now->modify('-'.$day.' days')->format('Y-m-d');
   $file=self::fileForDay($date);
   if($file===null||!is_file($file)||!is_readable($file))continue;
   $handle=@fopen($file,'rb');
   if($handle===false)continue;
   try{
    while(($line=fgets($handle,2048))!==false){
     if(strlen($line)>1200)continue;
     $v=json_decode($line,true,16);
     if(!is_array($v))continue;
     if(($v['type']??null)==='routing'){
      $route=$v['route']??'';
      if(isset($summary['routes'][$route]))$summary['routes'][$route]++;
      continue;
     }
     if(($v['type']??null)==='conversation'){
      $kind=$v['kind']??'';
      if(is_string($kind)&&isset($summary['conversation'][$kind]))$summary['conversation'][$kind]++;
      continue;
     }
     if(($v['type']??null)!=='provider'||!in_array($v['provider']??'',self::PROVIDERS,true))continue;
     $provider=$v['provider'];
     $summary['provider_attempts']++;
     $ok=($v['status']??null)==='http_ok';
     if($ok)$summary['provider_success_http']++;else $summary['provider_errors']++;
     $summary['providers'][$provider]??=['attempts'=>0,'http_ok'=>0,'errors'=>0,'total_tokens'=>0];
     $summary['providers'][$provider]['attempts']++;
     $summary['providers'][$provider][$ok?'http_ok':'errors']++;
     foreach(['input_tokens','output_tokens','total_tokens'] as $k){
      $amount=self::intMetric($v[$k]??null);
      if($amount!==null){
       $summary[$k]+=$amount;
       if($k==='total_tokens'){
        $summary['token_known_attempts']++;
        $summary['providers'][$provider]['total_tokens']+=$amount;
       }
      }
     }
     $duration=self::intMetric($v['duration_ms']??null);
     if($duration!==null){$summary['duration_samples']++;$summary['duration_ms_sum']+=$duration;}
    }
   }finally{fclose($handle);}
  }
  $summary['average_provider_ms']=$summary['duration_samples']>0
   ?(int)round($summary['duration_ms_sum']/$summary['duration_samples']):null;
  unset($summary['duration_ms_sum']);
  // Route events represent local dispatch decisions, not monetary savings.
  return $summary;
 }
}
