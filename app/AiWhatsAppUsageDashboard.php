<?php
declare(strict_types=1);

/**
 * Aggregated ADMIN-ONLY AI monitoring dashboard.
 * Reads private JSONL files; never returns raw records, messages or credentials.
 */
final class AiWhatsAppUsageDashboard
{
 public static function read(array $params=[]): array {
  Auth::admin();
  if(Auth::isPreview())fail('Saia da visualização de cliente para acessar o monitoramento.',403);
  if(Auth::$origin==='api')Auth::scope('api.read');
  $days=filter_var($params['days']??7,FILTER_VALIDATE_INT);
  if(!in_array($days,[1,7,14,30],true))fail('Período inválido. Use 1, 7, 14 ou 30 dias.',422);
  $filter=(string)($params['provider']??'all');
  if(!in_array($filter,['all','gemini','groq'],true))fail('Provedor inválido.',422);
  return self::aggregate($days,$filter);
 }

 public static function aggregate(int $days,string $provider='all',?DateTimeImmutable $now=null): array {
  if(!in_array($days,[1,7,14,30],true)||!in_array($provider,['all','gemini','groq'],true))
   throw new InvalidArgumentException('Invalid monitoring filter');
  $utc=new DateTimeZone('UTC');
  $today=($now??new DateTimeImmutable('now',$utc))->setTimezone($utc);
  $sum=[
   'days'=>$days,'provider_filter'=>$provider,'timezone'=>'UTC',
   'requests'=>0,'http_ok'=>0,'http_errors'=>0,'transport_errors'=>0,
   'input_tokens'=>0,'output_tokens'=>0,'total_tokens'=>0,'token_samples'=>0,
   'latency_samples'=>0,'avg_latency_ms'=>null,
   'routes'=>['fast_path'=>0,'cache_hit'=>0,'model_route'=>0],
   'conversation'=>['guided_help'=>0,'local_clarification'=>0,'context_followup'=>0],
   'providers'=>[],'models'=>[],'daily'=>[],
   'files_read'=>0,'truncated_files'=>0,
   'coverage'=>'Dados coletados desde a instalação do monitoramento. HTTP 2xx não comprova sucesso da operação no TaskEsc.'
  ];
  $durationSum=0;
  for($offset=$days-1;$offset>=0;$offset--){
   $date=$today->modify('-'.$offset.' days')->format('Y-m-d');
   $row=['date'=>$date,'requests'=>0,'http_ok'=>0,'errors'=>0,'tokens'=>0,
    'fast_path'=>0,'cache_hit'=>0,'model_route'=>0];
   $path=AiWhatsAppUsageMetrics::fileForDay($date);
   if(!$path||!is_file($path)||!is_readable($path)){$sum['daily'][]=$row;continue;}
   $handle=@fopen($path,'rb');
   if($handle===false){$sum['daily'][]=$row;continue;}
   $sum['files_read']++;
   $read=0;
   try{
    while(($line=fgets($handle,2048))!==false){
     if(++$read>100000){$sum['truncated_files']++;break;}
     if(strlen($line)>1200)continue;
     $v=json_decode($line,true,16);
     if(!is_array($v))continue;
     if(($v['type']??'')==='routing'){
      // Routing events are not linked to a provider, so filter-specific
      // requests never falsely attribute route savings to Gemini or Groq.
      if($provider!=='all')continue;
      $route=$v['route']??null;
      if(!is_string($route)||!array_key_exists($route,$sum['routes']))continue;
      $sum['routes'][$route]++;$row[$route]++;
      continue;
     }
     if(($v['type']??'')==='conversation'){
      // Do not assign local conversations to a paid provider.
      if($provider!=='all')continue;
      $kind=$v['kind']??null;
      if(is_string($kind)&&array_key_exists($kind,$sum['conversation']))$sum['conversation'][$kind]++;
      continue;
     }
     if(($v['type']??'')!=='provider')continue;
     $name=$v['provider']??'';
     if(!in_array($name,['gemini','groq'],true)||($provider!=='all'&&$name!==$provider))continue;
     $status=$v['status']??'';
     if(!in_array($status,['http_ok','http_error','transport_error'],true))continue;
     $model=$v['model']??'';
     if(!is_string($model)||!preg_match('/\A[a-zA-Z0-9._\/-]{1,100}\z/D',$model))$model='unknown';
     $sum['requests']++;$row['requests']++;
     $ok=$status==='http_ok';
     if($ok){$sum['http_ok']++;$row['http_ok']++;}
     else{$row['errors']++;$sum[$status==='transport_error'?'transport_errors':'http_errors']++;}
     $sum['providers'][$name]??=['requests'=>0,'http_ok'=>0,'errors'=>0,'tokens'=>0,'avg_latency_ms'=>null];
     $sum['providers'][$name]['requests']++;
     $sum['providers'][$name][$ok?'http_ok':'errors']++;
     $sum['models'][$model]??=['provider'=>$name,'requests'=>0,'tokens'=>0,'http_ok'=>0,'errors'=>0];
     $sum['models'][$model]['requests']++;
     $sum['models'][$model][$ok?'http_ok':'errors']++;
     foreach(['input_tokens','output_tokens','total_tokens'] as $field){
      $number=self::safeMetric($v[$field]??null);
      if($number===null)continue;
      $sum[$field]+=$number;
      if($field==='total_tokens'){
       $sum['token_samples']++;
       $row['tokens']+=$number;
       $sum['providers'][$name]['tokens']+=$number;
       $sum['models'][$model]['tokens']+=$number;
      }
     }
     $ms=self::safeMetric($v['duration_ms']??null);
     if($ms!==null){$durationSum+=$ms;$sum['latency_samples']++;}
    }
   }finally{fclose($handle);}
   $sum['daily'][]=$row;
  }
  $sum['avg_latency_ms']=$sum['latency_samples']?(int)round($durationSum/$sum['latency_samples']):null;
  ksort($sum['providers']);ksort($sum['models']);
  // Not a cost estimate: no prices or bills are inferred from route events.
  $sum['avoided_planning_calls']=$provider==='all'?$sum['routes']['fast_path']+$sum['routes']['cache_hit']:null;
  return $sum;
 }

 private static function safeMetric(mixed $v): ?int {
  if(!is_int($v)&&!(is_string($v)&&ctype_digit($v)))return null;
  $n=filter_var($v,FILTER_VALIDATE_INT);
  return $n!==false&&$n>=0&&$n<=1000000000?$n:null;
 }
}
