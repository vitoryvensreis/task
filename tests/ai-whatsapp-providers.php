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
t(!AiWhatsAppProvider::enabled(),'No private key must mean disabled');
t(AiWhatsAppProvider::status()['gemini_configured']===false,'No configured key');
echo "TaskEsc AI providers: Gemini schema, planning, allowlist and disabled-by-default OK\n";
