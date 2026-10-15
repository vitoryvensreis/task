<?php
declare(strict_types=1);

/** Pure, read-only interpretation of Groq's authenticated model list. */
final class AiWhatsAppModelDiagnostics
{
 private const CANDIDATES=['openai/gpt-oss-120b','openai/gpt-oss-20b','qwen/qwen3.8-27b'];

 public static function fromGroqList(string $model,int $http,?string $body): array
 {
  $out=['model'=>$model,'http'=>$http,'source'=>'models_list','generation_tested'=>false];
  if($http!==200||$body===null){
   $out['status']=match(true){
    $http===0=>'connection_failed',
    $http===401||$http===403=>'auth_or_access_rejected',
    $http===429=>'quota_limit',
    $http>=500=>'provider_unavailable',
    default=>'inventory_unavailable'
   };
   return $out;
  }
  $payload=json_decode($body,true);
  if(!is_array($payload)||!is_array($payload['data']??null)){
   $out['status']='invalid_inventory_response';
   return $out;
  }
  $ids=[];
  foreach($payload['data'] as $item){
   if(is_array($item)&&is_string($item['id']??null)&&($item['active']??true)!==false){
    $ids[$item['id']]=true;
   }
  }
  $out['status']=isset($ids[$model])?'listed':'not_listed';
  if(!isset($ids[$model])){
   $out['available_replacements']=array_values(array_filter(self::CANDIDATES,static fn($name)=>isset($ids[$name])));
  }
  return $out;
 }

 /** A single explicit POST /chat/completions can confirm model + tool-call behavior. */
 public static function groqProbeBody(string $model): array
 {
  return [
   'model'=>$model,
   'messages'=>[['role'=>'user','content'=>'Call taskesc_diagnostic with ok=true. This is a harmless API connectivity test.']],
   'tools'=>[['type'=>'function','function'=>[
    'name'=>'taskesc_diagnostic',
    'description'=>'Harmless tool-call probe; no data access or mutations.',
    'parameters'=>['type'=>'object','properties'=>['ok'=>['type'=>'boolean']],'required'=>['ok'],'additionalProperties'=>false]
   ]]],
   'tool_choice'=>'required','parallel_tool_calls'=>false,'temperature'=>0,
   'reasoning_effort'=>'low','max_completion_tokens'=>256
  ];
 }

 public static function fromGroqProbe(int $http,?string $body): array
 {
  $out=['http'=>$http,'generation_tested'=>true,'status'=>'probe_failed'];
  if($body===null||$http===0){$out['status']='connection_failed';return $out;}
  if($http!==200){
   $out['status']=match(true){
    $http===401||$http===403=>'auth_or_access_rejected',
    $http===404=>'model_unavailable_for_generation',
    $http===429=>'quota_limit',
    $http>=500=>'provider_unavailable',
    default=>'request_rejected'
   };
   return $out;
  }
  $payload=json_decode($body,true);
  $calls=$payload['choices'][0]['message']['tool_calls']??null;
  if(!is_array($calls)||count($calls)!==1||($calls[0]['function']['name']??null)!=='taskesc_diagnostic'){
   $out['status']='response_without_expected_tool_call';return $out;
  }
  $args=json_decode((string)($calls[0]['function']['arguments']??''),true);
  $out['status']=is_array($args)&&($args['ok']??null)===true?'operational':'unexpected_tool_arguments';
  return $out;
 }
}
