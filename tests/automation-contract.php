<?php
declare(strict_types=1);

define('ROOT',dirname(__DIR__));
function contractCheck(bool $condition,string $message): void {if(!$condition)throw new RuntimeException($message);}

$schema=file_get_contents(ROOT.'/database/schema.sql');
$migration=file_get_contents(ROOT.'/database/migrations/024_automation_core.sql');
$migrations=file_get_contents(ROOT.'/app/Migrations.php');
$automation=file_get_contents(ROOT.'/app/Automation.php');
$worker=file_get_contents(ROOT.'/app/AutomationWorker.php');
$businessEvents=file_get_contents(ROOT.'/app/AutomationEvent.php');
$actions=file_get_contents(ROOT.'/app/AutomationActions.php');
$catalog=file_get_contents(ROOT.'/app/Catalog.php');
$cli=file_get_contents(ROOT.'/bin/automation-worker.php');
$unit=file_get_contents(ROOT.'/ops/taskesc-automation-worker.service');
$installer=file_get_contents(ROOT.'/ops/automation-worker.py');
$wpp=file_get_contents(ROOT.'/app/WppConnect.php');
$app=file_get_contents(ROOT.'/public/assets/app.js');
$demand=file_get_contents(ROOT.'/app/Demands.php');

foreach([$schema,$migration,$migrations,$automation,$worker,$businessEvents,$actions,$catalog,$cli,$unit,$installer,$wpp,$app,$demand] as $source)contractCheck(is_string($source)&&$source!=='','Automation contract file missing.');

contractCheck(str_contains($schema,"('schema_version','29')"),'Fresh schema must be marked 29.');
foreach(['automations','automation_runs','automation_run_steps','automation_worker_state','whatsapp_messages'] as $table)contractCheck(str_contains($schema,'CREATE TABLE '.$table),'Fresh schema missing '.$table);
contractCheck(str_contains($schema,'whatsapp_phone VARCHAR(20)')&&str_contains($schema,"whatsapp_recipient_mode VARCHAR(20) NOT NULL DEFAULT 'none'"),'Fresh schema missing WhatsApp contact/task fields.');
contractCheck(str_contains($migrations,'AUTOMATION_VERSION=24')&&str_contains($migrations,'public static function automation()'),'Scoped schema 24 migration missing.');
contractCheck(str_contains($migration,'uq_automation_run_operation')&&str_contains($migration,'uq_whatsapp_run_node'),'Automation idempotency indexes missing.');
contractCheck(str_contains($automation,"'demand.created'=>'Task criada'")&&str_contains($automation,"'action.whatsapp'"),'Automation catalog must expose demand triggers and WhatsApp action.');
contractCheck(str_contains($automation,"'client.created'=>'Cliente criado'")&&str_contains($automation,"'client.team_member_added'=>'Conta da equipe criada para cliente'"),'Automation catalog must expose client lifecycle triggers.');
contractCheck(str_contains($automation,"'demand.due_soon'=>'Task próxima da data de entrega'")&&str_contains($automation,"'demand.overdue'=>'Task atrasada'"),'Automation catalog must expose task due/overdue triggers.');
contractCheck(str_contains($automation,"'invoice.due_soon'=>'Cobrança próxima do vencimento'")&&str_contains($automation,"'invoice.overdue'=>'Cobrança atrasada'"),'Automation catalog must expose billing due/overdue triggers.');
contractCheck(str_contains($worker,'scheduleDueTriggers')&&str_contains($worker,'createScheduledRun'),'Worker must schedule time-based automations idempotently.');
contractCheck(str_contains($businessEvents,"'client.created'")&&str_contains($businessEvents,"'client.team_member_added'")&&str_contains($businessEvents,'EventOutbox::enqueue'),'Client lifecycle triggers must use the transactional Outbox.');
contractCheck(str_contains($catalog,"AutomationEvent::emit('client.created'")&&str_contains($catalog,"AutomationEvent::emit('client.team_member_added'"),'Client/user mutations must emit automation events inside their transactions.');

contractCheck(str_contains($automation,'O fluxo não pode conter ciclos'),'Automation graph must reject cycles.');
contractCheck(str_contains($automation,'$cleanNode')&&str_contains($automation,'position')&&str_contains($automation,'$branchCount'),'Graph validator must persist positions and allow bounded multiple outgoing edges.');
contractCheck(str_contains($app,'automationFlowMarkup(')&&str_contains($app,'automationFlowWire('),'Free canvas editor must render positionable graph nodes.');
contractCheck(str_contains($worker,'operation_id')&&str_contains($worker,'INSERT IGNORE INTO automation_runs'),'Worker must dedupe Outbox fanout by operation.');
contractCheck(str_contains($worker,'whatsapp_delivery_ambiguous'),'Worker must avoid blind resend after an uncertain process interruption.');
contractCheck(str_contains($worker,"status='retry'")&&str_contains($worker,'MAX_ATTEMPTS=4'),'Worker must use bounded retry.');
contractCheck(str_contains($wpp,'sendAutomationMessage'),'WPPConnect must expose the internal automation send method.');
contractCheck(str_contains($worker,"if(\$mode==='assigned_user')\$mode='creator'")&&str_contains($worker,"\$subject['created_by']")&&str_contains($worker,"'type'=>'creator'"),'Legacy task recipient assigned_user must resolve to the task creator.');
contractCheck(str_contains($worker,"if(\$mode==='assigned_user'){")&&str_contains($worker,"\$subject['assigned_to']"),'Explicit WhatsApp action must still support the responsible user.');
contractCheck(str_contains($worker,"\$mode==='custom'")&&str_contains($worker,"custom_phone"),'Explicit WhatsApp action must support a custom phone number.');
contractCheck(str_contains($worker,"'{{creator.name}}'")&&str_contains($worker,"'{{invoice.due_on}}'")&&str_contains($worker,"'{{team.name}}'"),'Automation templates must expose task, invoice and team variables.');

contractCheck(str_contains($unit,'TaskEsc Automation Worker')&&str_contains($unit,'NoNewPrivileges=true'),'Automation worker service definition missing.');
contractCheck(str_contains($installer,"SERVICE='taskesc-automation-worker.service'")&&str_contains($installer,'--health'),'Automation worker installer/health missing.');
contractCheck(str_contains($app,'name="whatsapp_recipient_mode"')&&substr_count($app,'name="whatsapp_phone"')>=2,'Automation WhatsApp UI fields missing.');
contractCheck(str_contains($app,'name="whatsapp_custom_phone"')&&str_contains($app,'Número específico'),'Task form must expose custom phone destination.');
contractCheck(str_contains($app,'${admin?`<div class="field"><label>Disparos de WhatsApp</label>'),'Task WhatsApp fields must be admin-only.');
contractCheck(str_contains($demand,"if(\$role==='admin')")&&str_contains($demand,'Somente administradores podem configurar disparos de WhatsApp.'),'Server must protect WhatsApp options from other roles.');
contractCheck(str_contains($schema,'whatsapp_custom_phone VARCHAR(20) NULL'),'Schema must store task-specific WhatsApp numbers.');
contractCheck(str_contains($worker,'task_whatsapp_number_invalid'),'Worker must resolve task-specific numbers.');

contractCheck(str_contains($automation,"'demand.final_delivered'=>'Envio de entrega final'"),'Final delivery trigger missing.');
foreach(['action.task','action.email','action.push','action.create_task'] as $type)contractCheck(str_contains($automation,"'".$type."'")&&str_contains($worker,"'".$type."'"),'Action missing: '.$type);
contractCheck(str_contains($actions,'self::record($stepId')&&str_contains($actions,'automation_generated'),'Atomic/idempotent automation actions missing.');
contractCheck(str_contains(file_get_contents(ROOT.'/app/Files.php'),'demand.final_delivered'),'Final uploads must emit an outbox event.');

echo "Automation contract: client/billing triggers, scheduled deadlines, flexible WhatsApp recipients and graph engine OK\n";
