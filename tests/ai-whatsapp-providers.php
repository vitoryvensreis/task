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

// Step 1 metrics: provider counters without persisting prompt, response or IDs.
$start=1000.0;
$geminiUsage='{"usageMetadata":{"promptTokenCount":110,"candidatesTokenCount":17,"totalTokenCount":127},"candidates":[{"content":{"parts":[{"text":"PRIVATE DATA NEVER RECORDED"}]}}]}';
$metric=AiWhatsAppUsageMetrics::providerRow('gemini','gemini-3.8-flash',200,0,$start,$geminiUsage,1000.245);
t($metric['duration_ms']===245&&$metric['input_tokens']===110&&$metric['output_tokens']===17&&$metric['total_tokens']===127,
 'Gemini reported token counts and latency captured');
t($metric['status']==='http_ok'&&$metric['http']===200&&$metric['curl_errno']===0,
 'Gemini status is numeric');
t(!str_contains(json_encode($metric),'PRIVATE DATA'),'Metrics must never contain model response');
$groqUsage='{"usage":{"prompt_tokens":88,"completion_tokens":12,"total_tokens":100},"choices":[{"message":{"content":"secret response"}}]}';
$groqMetric=AiWhatsAppUsageMetrics::providerRow('groq','openai/gpt-oss-120b',200,0,10.0,$groqUsage,10.95);
t($groqMetric['input_tokens']===88&&$groqMetric['output_tokens']===12&&$groqMetric['total_tokens']===100,
 'Groq token counts from API usage');
t(!str_contains(json_encode($groqMetric),'secret response'),'No model text stored in metrics');
$rejectMetric=AiWhatsAppUsageMetrics::providerRow('gemini','gemini-3.8-flash',429,0,20.0,'{"error":{"message":"PRIVATE API CREDENTIAL"}}',20.1);
t($rejectMetric['status']==='http_error'&&$rejectMetric['total_tokens']===null,
 '429 has no invented token counts');
t(!str_contains(json_encode($rejectMetric),'PRIVATE'),'Never store error bodies');
$timeoutMetric=AiWhatsAppUsageMetrics::providerRow('groq','openai/gpt-oss-120b',0,28,25.0,null,50.0);
t($timeoutMetric['status']==='transport_error'&&$timeoutMetric['curl_errno']===28&&$timeoutMetric['duration_ms']===25000,
 'Timeout recorded without PII');
$invalidMetric=AiWhatsAppUsageMetrics::providerRow('unexpected','key value',500,0,10.0,null,10.1);
t($invalidMetric['provider']==='unknown'&&$invalidMetric['model']==='unknown','Provider labels are sanitized');

// Dashboard aggregate: historical fixture dates protect current production metrics.
$fixtureDate='2001-01-19';
$fixturePath=AiWhatsAppUsageMetrics::fileForDay($fixtureDate);
t(is_string($fixturePath),'Metrics day filename is valid');
@mkdir(dirname($fixturePath),0700,true);
t(!is_file($fixturePath),'Historical fixture does not overwrite existing telemetry');
$eventRows=[
 ['at'=>'2001-01-19T00:01:00Z','type'=>'routing','route'=>'fast_path'],
 ['at'=>'2001-01-19T00:02:00Z','type'=>'routing','route'=>'cache_hit'],
 ['at'=>'2001-01-19T00:03:00Z','type'=>'routing','route'=>'model_route'],
 ['at'=>'2001-01-19T00:04:00Z','type'=>'provider','provider'=>'gemini','model'=>'gemini-3.8-flash',
  'status'=>'http_ok','http'=>200,'curl_errno'=>0,'duration_ms'=>450,
  'input_tokens'=>100,'output_tokens'=>20,'total_tokens'=>120],
 ['at'=>'2001-01-19T00:05:00Z','type'=>'provider','provider'=>'groq','model'=>'openai/gpt-oss-120b',
  'status'=>'http_error','http'=>429,'curl_errno'=>0,'duration_ms'=>200,
  'input_tokens'=>null,'output_tokens'=>null,'total_tokens'=>null],
 ['at'=>'2001-01-19T00:06:00Z','type'=>'provider','provider'=>'groq','model'=>'openai/gpt-oss-120b',
  'status'=>'transport_error','http'=>0,'curl_errno'=>28,'duration_ms'=>25000,
  'input_tokens'=>null,'output_tokens'=>null,'total_tokens'=>null],
 ['at'=>'2001-01-19T00:07:00Z','type'=>'routing','route'=>'wrong_route'],
 ['at'=>'2001-01-19T00:07:10Z','type'=>'conversation','kind'=>'guided_help'],
 ['at'=>'2001-01-19T00:07:20Z','type'=>'conversation','kind'=>'local_clarification'],
 ['at'=>'2001-01-19T00:07:30Z','type'=>'conversation','kind'=>'context_followup'],
 ['at'=>'2001-01-19T00:07:40Z','type'=>'conversation','kind'=>'wrong_or_sensitive'],
 ['at'=>'2001-01-19T00:08:00Z','type'=>'provider','provider'=>'not-authorized','model'=>'inject',
  'status'=>'http_ok','total_tokens'=>999999],
];
try{
 $lines=array_map(static fn($v)=>json_encode($v,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),$eventRows);
 file_put_contents($fixturePath,implode("\n",$lines)."\n",LOCK_EX);
 $now=new DateTimeImmutable('2001-01-19 12:00:00',new DateTimeZone('UTC'));
 $aggregate=AiWhatsAppUsageDashboard::aggregate(1,'all',$now);
 t($aggregate['requests']===3&&$aggregate['http_ok']===1&&$aggregate['http_errors']===1&&$aggregate['transport_errors']===1,
  'Dashboard aggregates Gemini and Groq errors correctly');
 t($aggregate['input_tokens']===100&&$aggregate['output_tokens']===20&&$aggregate['total_tokens']===120&&$aggregate['token_samples']===1,
  'Dashboard never invents missing tokens');
 t($aggregate['avg_latency_ms']===8550&&$aggregate['latency_samples']===3,
  'Dashboard calculates average latency from real samples');
 t($aggregate['avoided_planning_calls']===2&&$aggregate['routes']['model_route']===1,
  'PHP+cache route count avoids false currency savings');
 t($aggregate['conversation']['guided_help']===1
   &&$aggregate['conversation']['local_clarification']===1
   &&$aggregate['conversation']['context_followup']===1,
  'Conversation counters are aggregate-only and ignore unexpected event names');
 t(count($aggregate['daily'])===1&&$aggregate['daily'][0]['date']===$fixtureDate,
  'UTC daily graph uses one date');
 $groqOnly=AiWhatsAppUsageDashboard::aggregate(1,'groq',$now);
 t($groqOnly['requests']===2&&$groqOnly['total_tokens']===0&&$groqOnly['avoided_planning_calls']===null,
  'Provider filter excludes unrelated metrics and does not attribute cache to Groq');
 t($groqOnly['routes']['fast_path']===0,'Provider filter hides unattributable route events');
 t($groqOnly['conversation']['guided_help']===0&&$groqOnly['conversation']['context_followup']===0,
  'Provider filter must not attribute local dialogue to Groq');
 $noMetrics=AiWhatsAppUsageDashboard::aggregate(7,'all',new DateTimeImmutable('2001-02-20',new DateTimeZone('UTC')));
 t($noMetrics['requests']===0&&count($noMetrics['daily'])===7,
  'Empty monitoring period renders zero-safe charts');
 try{AiWhatsAppUsageDashboard::aggregate(90,'all',$now);throw new RuntimeException('Invalid days accepted');}
 catch(InvalidArgumentException){}
 // Unit auth tests: API endpoint is exclusively for ADM, not regular users or client preview.
 $priorUser=Auth::$user;$priorActor=Auth::$actor;$priorPreview=Auth::$previewClientId;
 try{
  Auth::$user=['id'=>1,'role'=>'staff','active'=>1];Auth::$actor=Auth::$user;Auth::$previewClientId=null;
  try{AiWhatsAppUsageDashboard::read(['days'=>1]);throw new RuntimeException('Staff access accepted');}
  catch(AppError $e){t($e->status===403,'Staff must receive 403');}
  Auth::$user=['id'=>2,'role'=>'admin','active'=>1];Auth::$actor=Auth::$user;
  t(AiWhatsAppUsageDashboard::read(['days'=>1,'provider'=>'all'])['days']===1,
   'Verified ADM can retrieve aggregate-only monitoring data');
  try{AiWhatsAppUsageDashboard::read(['days'=>40]);throw new RuntimeException('Invalid days accepted');}
  catch(AppError $e){t($e->status===422,'Days strictly validated');}
  try{AiWhatsAppUsageDashboard::read(['provider'=>'<script>']);throw new RuntimeException('Invalid provider accepted');}
  catch(AppError $e){t($e->status===422,'Provider parameter strictly validated');}
  Auth::$previewClientId=3;
  try{AiWhatsAppUsageDashboard::read([]);throw new RuntimeException('Preview accepted');}
  catch(AppError $e){t($e->status===403,'Client preview must not access AI monitoring');}
 }finally{Auth::$user=$priorUser;Auth::$actor=$priorActor;Auth::$previewClientId=$priorPreview;}
}finally{@unlink($fixturePath);}

t(!AiWhatsAppProvider::enabled(),'No private key must mean disabled');
t(AiWhatsAppProvider::status()['gemini_configured']===false,'No configured key');
echo "TaskEsc AI providers: Gemini schema, planning, allowlist and disabled-by-default OK\n";
