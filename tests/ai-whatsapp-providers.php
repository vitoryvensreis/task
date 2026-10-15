<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
// Local CLI environments without mbstring may still run the pure parsing tests.
if(!function_exists('mb_substr')){function mb_substr(string $s,int $start,?int $length=null): string{return substr($s,$start,$length);}}
function t(bool $yes,string $message): void {if(!$yes)throw new RuntimeException($message);}
function answer(array $parts): array {return ['candidates'=>[['content'=>['parts'=>$parts]]]];}
$tools=[
 ['type'=>'function','function'=>['name'=>'list_tasks','description'=>'Listar tarefas','parameters'=>['type'=>'object','properties'=>['query'=>['type'=>'string']],'additionalProperties'=>false]]],
 ['type'=>'function','function'=>['name'=>'edit_task','description'=>'Editar tarefa','parameters'=>['type'=>'object','properties'=>['task_id'=>['type'=>'integer']],'required'=>['task_id'],'additionalProperties'=>false]]]
];
$req=AiWhatsAppGemini::requestBody('Liste tasks',['clients'=>[]],$tools);
t($req['tools'][0]['functionDeclarations'][0]['parameters']['type']==='OBJECT','Gemini schema object type');
t($req['tools'][0]['functionDeclarations'][0]['parameters']['properties']['query']['type']==='STRING','Gemini schema nested string');
t(!isset($req['tools'][0]['functionDeclarations'][0]['parameters']['additionalProperties']),'Gemini schema portability');
t($req['contents'][0]['parts'][0]['text']==='Liste tasks','Gemini prompt');
$reply=AiWhatsAppGemini::parseResponse(answer([['functionCall'=>['name'=>'list_tasks','args'=>[]]]]),$tools);
t($reply===['tool'=>'list_tasks','arguments'=>[]],'Empty arguments allowed');
$edit=AiWhatsAppGemini::parseResponse(answer([['functionCall'=>['name'=>'edit_task','args'=>['task_id'=>12]]]]),$tools);
t($edit['arguments']['task_id']===12,'Arguments preserved');
$text=AiWhatsAppGemini::parseResponse(answer([['text'=>'Qual o ID da task?']]),$tools);
t($text['text']==='Qual o ID da task?','Text returned');
foreach([
 answer([['functionCall'=>['name'=>'delete_task','args'=>[]]]]),
 answer([['functionCall'=>['name'=>'edit_task','args'=>[]]],['functionCall'=>['name'=>'edit_task','args'=>[]]]]),
 answer([['functionCall'=>['name'=>'edit_task','args'=>[1,2]]]]),
 answer([['text'=>'']]),
] as $bad){
 try{AiWhatsAppGemini::parseResponse($bad,$tools);throw new RuntimeException('Unsafe Gemini response accepted');}
 catch(RuntimeException $e){t($e->getMessage()!=='Unsafe Gemini response accepted','Unsafe response rejected');}
}
// Model HTTP 404 must allow configured Groq as a separate provider.
foreach(['ai_provider_unavailable','ai_connection_failed','ai_provider_rate_limit','ai_provider_model_unavailable'] as $reason){
 t(AiWhatsAppProvider::fallbackEligible($reason),'Fallback for recoverable unavailability: '.$reason);
}
foreach(['ai_provider_bad_credentials','ai_unauthorized_tool','ai_invalid_response','ai_multiple_tools','ai_invalid_args','ai_tool_not_allowed'] as $reason){
 t(!AiWhatsAppProvider::fallbackEligible($reason),'Never retry unsafe or unauthorized responses: '.$reason);
}
// Diagnostic should use authenticated /models inventory, NOT a failing item URL
// containing the slash-delimited identifier openai/gpt-oss-120b.
$catalog=json_encode(['object'=>'list','data'=>[
 ['id'=>'openai/gpt-oss-120b','active'=>true],
 ['id'=>'openai/gpt-oss-20b','active'=>true],
 ['id'=>'removed/model','active'=>false]
]],JSON_THROW_ON_ERROR);
$listed=AiWhatsAppModelDiagnostics::fromGroqList('openai/gpt-oss-120b',200,$catalog);
t($listed['status']==='listed'&&$listed['http']===200&&$listed['generation_tested']===false,
 'Model listed by API is not falsely declared unavailable');
$missing=AiWhatsAppModelDiagnostics::fromGroqList('llama-3.3-70b-versatile',200,$catalog);
t($missing['status']==='not_listed'&&$missing['available_replacements']===['openai/gpt-oss-120b','openai/gpt-oss-20b'],
 'Deprecation suggestions must come from returned catalog');
t(AiWhatsAppModelDiagnostics::fromGroqList('removed/model',200,$catalog)['status']==='not_listed','Inactive models unavailable');
t(AiWhatsAppModelDiagnostics::fromGroqList('openai/gpt-oss-120b',200,'not json')['status']==='invalid_inventory_response',
 'Malformed model list is not successful');
t(AiWhatsAppModelDiagnostics::fromGroqList('openai/gpt-oss-120b',401,null)['status']==='auth_or_access_rejected',
 'Bad provider authentication is reported');
$probe=AiWhatsAppModelDiagnostics::groqProbeBody('openai/gpt-oss-120b');
t($probe['tool_choice']==='required'&&$probe['tools'][0]['function']['name']==='taskesc_diagnostic',
 'Probe only calls harmless synthetic tool');
$validProbe=json_encode(['choices'=>[['message'=>['tool_calls'=>[
 ['function'=>['name'=>'taskesc_diagnostic','arguments'=>'{"ok":true}']]
]]]]],JSON_THROW_ON_ERROR);
t(AiWhatsAppModelDiagnostics::fromGroqProbe(200,$validProbe)['status']==='operational',
 'Actual function-call success classified');
t(AiWhatsAppModelDiagnostics::fromGroqProbe(200,'{}')['status']==='response_without_expected_tool_call',
 'HTTP 200 without tool call must not be declared operational');
t(AiWhatsAppModelDiagnostics::fromGroqProbe(404,'{}')['status']==='model_unavailable_for_generation',
 'Only actual generation endpoint 404 marks model unavailable for generation');
t(AiWhatsAppModelDiagnostics::fromGroqProbe(429,'{}')['status']==='quota_limit',
 'Probe reports quota limits');
t(!AiWhatsAppProvider::enabled(),'No private key must mean disabled');
t(AiWhatsAppProvider::status()['gemini_configured']===false,'No configured key');
echo "TaskEsc AI providers: Gemini schema, planning, allowlist and disabled-by-default OK\n";
