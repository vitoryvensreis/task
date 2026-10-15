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
// Zero-argument functions must never put PHP [] where JSON Schema expects an object.
$zero=['type'=>'function','function'=>['name'=>'agenda_calendars','description'=>'List available calendars','parameters'=>[
 'type'=>'object','properties'=>[],'additionalProperties'=>false]]];
$geminiZero=AiWhatsAppGemini::requestBody('Listar agendas',[] ,[$zero]);
$declaration=$geminiZero['tools'][0]['functionDeclarations'][0];
t($declaration['name']==='agenda_calendars'&&!array_key_exists('parameters',$declaration),
 'Gemini no-arg function must OMIT optional parameters schema');
$groqZero=AiWhatsAppToolSchemas::forGroq([$zero]);
$groqJson=json_encode($groqZero,JSON_THROW_ON_ERROR);
t(str_contains($groqJson,'"properties":{}')&&!str_contains($groqJson,'"properties":[]'),
 'Groq no-arg function requires empty JSON object, not PHP empty list');
t($zero['function']['parameters']['properties']===[],
 'Normalization must not mutate the permission catalog');
$nested=['type'=>'function','function'=>['name'=>'nested','description'=>'nested','parameters'=>[
 'type'=>'object','properties'=>['root'=>['type'=>'object','properties'=>[]]],'additionalProperties'=>false]]];
$nestedJson=json_encode(AiWhatsAppToolSchemas::forGroq([$nested]),JSON_THROW_ON_ERROR);
t(str_contains($nestedJson,'"root":{"type":"object","properties":{}}'),
 'Nested no-argument object schemas must remain JSON objects');
t(AiWhatsAppErrors::code(new RuntimeException('ai_provider_bad_request'))==='ai_provider_bad_request',
 'Bad request distinguished from transport failures');
t(!AiWhatsAppErrors::retryable(new RuntimeException('ai_provider_bad_request')),
 'Repeated malformed HTTP 400 must not burn three worker retries');
t(AiWhatsAppProvider::fallbackEligible('ai_provider_bad_request'),
 'One alternative provider may still handle a supported request');

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
// Deterministic project triage: only current, non-completed visible tasks count.
$at=new DateTimeImmutable('2026-10-15 12:00:00',new DateTimeZone('America/Fortaleza'));
$sample=[
 ['id'=>101,'title'=>'Revisar anúncio vencido','project_id'=>7,'client_id'=>2,'client_name'=>'Cliente A','stage_kind'=>'in_progress','stage_name'=>'Em produção','priority'=>'urgent','due_at'=>'2026-10-14 10:00:00'],
 ['id'=>102,'title'=>'Aula em andamento','project_id'=>8,'client_id'=>3,'client_name'=>'Cliente B','stage_kind'=>'todo','stage_name'=>'A fazer','priority'=>'high','due_at'=>'2026-10-18 15:00:00'],
 ['id'=>103,'title'=>'Aula já finalizada','project_id'=>7,'client_id'=>2,'client_name'=>'Cliente A','stage_kind'=>'completed','stage_name'=>'Concluídas','priority'=>'urgent','due_at'=>'2026-10-02 10:00:00'],
 ['id'=>104,'title'=>'Tarefa normal sem projeto','project_id'=>null,'client_id'=>4,'client_name'=>'Cliente C','stage_kind'=>'in_progress','stage_name'=>'Em produção','priority'=>'normal','due_at'=>'2026-11-10 09:00:00']
];
$report=AiWhatsAppAttentionReport::summarize($sample,[7=>'Projeto Vídeos',8=>'Projeto Aulas'],$at,4);
t($report['kind']==='attention_report'&&$report['active']===3&&$report['completed']===1,
 'Report excludes completed tasks from active/urgency totals');
t($report['overdue']===1&&$report['upcoming']===1&&$report['high']===2,
 'Deadline and priority classification respects local time');
t($report['groups'][0]['label']==='Projeto: Projeto Vídeos',
 'Risk prioritization puts overdue project first');
t(str_contains($report['groups'][2]['label'],'sem projeto vinculado'),
 'Tasks without projects must be labelled by client, not invented project');
$written=AiWhatsAppAttentionReport::format($report);
t(str_contains($written,'#101')&&str_contains($written,'vencido')&&!str_contains($written,'#103'),
 'Report includes actual overdue IDs, not completed tasks');
t(str_contains($written,'Nenhuma tarefa foi alterada.')&&mb_strlen($written)<3201,
 'WhatsApp report must be bounded and explicitly read-only');

t(!AiWhatsAppProvider::enabled(),'No private key must mean disabled');
t(AiWhatsAppProvider::status()['gemini_configured']===false,'No configured key');
echo "TaskEsc AI providers: Gemini schema, planning, allowlist and disabled-by-default OK\n";
