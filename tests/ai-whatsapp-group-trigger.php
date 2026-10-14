<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
function gr(bool $ok,string $why): void {if(!$ok)throw new RuntimeException($why);}
foreach(['Task, liste minhas tarefas','Ei Task, me ajuda','@Task mostre agenda hoje','TaskEsc, quais são os compromissos?','TASK:'] as $body){
 gr(AiWhatsAppGroupTrigger::named($body),'Must detect name '.$body);
 gr(AiWhatsAppGroupTrigger::addressed($body,[]),'Name text works without WhatsApp metadata');
}
foreach(['tasks de hoje','TaskEscapou','task_123','contatos/tasks aqui','outask'] as $body){
 gr(!AiWhatsAppGroupTrigger::named($body),'Must ignore unrelated word '.$body);
 gr(!AiWhatsAppGroupTrigger::addressed($body,[]),'Group must remain silent');
}
gr(AiWhatsAppGroupTrigger::addressed('pedido',['123@c.us'],fn()=>true),'True WhatsApp mention works');
gr(!AiWhatsAppGroupTrigger::addressed('pedido',['123@c.us'],fn()=>false),'Foreign mention ignored');
gr(AiWhatsAppGroupTrigger::command('Task, liste minhas tarefas')==='liste minhas tarefas','Prefix must be stripped');
gr(AiWhatsAppGroupTrigger::command('TaskEsc! Mostre agenda')==='Mostre agenda','TaskEsc stripped');
$today=(new DateTimeImmutable('today',new DateTimeZone((string)config('timezone','America/Fortaleza'))))->format('Y-m-d');
gr(AiWhatsAppFastPath::plan('Mostre os compromissos de hoje',false,true)===['tool'=>'agenda_list','arguments'=>['from'=>$today,'to'=>$today]],'Authorized ADM gets model-free calendar read');
gr(AiWhatsAppFastPath::plan('Mostre os compromissos de hoje',false,false)===null,'Others cannot use agenda fast path');
echo "AI group Task trigger and calendar fast path OK\n";
