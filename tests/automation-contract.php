<?php
declare(strict_types=1);

define('ROOT',dirname(__DIR__));
function contractCheck(bool $condition,string $message): void {if(!$condition)throw new RuntimeException($message);}

$schema=file_get_contents(ROOT.'/database/schema.sql');
$migration=file_get_contents(ROOT.'/database/migrations/024_automation_core.sql');
$migrations=file_get_contents(ROOT.'/app/Migrations.php');
$automation=file_get_contents(ROOT.'/app/Automation.php');
$worker=file_get_contents(ROOT.'/app/AutomationWorker.php');
$cli=file_get_contents(ROOT.'/bin/automation-worker.php');
$unit=file_get_contents(ROOT.'/ops/taskesc-automation-worker.service');
$installer=file_get_contents(ROOT.'/ops/automation-worker.py');
$wpp=file_get_contents(ROOT.'/app/WppConnect.php');
$app=file_get_contents(ROOT.'/public/assets/app.js');

foreach([$schema,$migration,$migrations,$automation,$worker,$cli,$unit,$installer,$wpp,$app] as $source)contractCheck(is_string($source)&&$source!=='','Automation contract file missing.');

contractCheck(str_contains($schema,"('schema_version','24')"),'Fresh schema must be marked 24.');
foreach(['automations','automation_runs','automation_run_steps','automation_worker_state','whatsapp_messages'] as $table)contractCheck(str_contains($schema,'CREATE TABLE '.$table),'Fresh schema missing '.$table);
contractCheck(str_contains($schema,'whatsapp_phone VARCHAR(20)')&&str_contains($schema,"whatsapp_recipient_mode VARCHAR(20) NOT NULL DEFAULT 'none'"),'Fresh schema missing WhatsApp contact/task fields.');
contractCheck(str_contains($migrations,'AUTOMATION_VERSION=24')&&str_contains($migrations,'public static function automation()'),'Scoped schema 24 migration missing.');
contractCheck(str_contains($migration,'uq_automation_run_operation')&&str_contains($migration,'uq_whatsapp_run_node'),'Automation idempotency indexes missing.');
contractCheck(str_contains($automation,"'demand.created'=>'Task criada'")&&str_contains($automation,"'action.whatsapp'"),'Automation catalog must expose demand triggers and WhatsApp action.');
contractCheck(str_contains($automation,'O fluxo não pode conter ciclos'),'Automation graph must reject cycles.');
contractCheck(str_contains($worker,'operation_id')&&str_contains($worker,'INSERT IGNORE INTO automation_runs'),'Worker must dedupe Outbox fanout by operation.');
contractCheck(str_contains($worker,'whatsapp_delivery_ambiguous'),'Worker must avoid blind resend after an uncertain process interruption.');
contractCheck(str_contains($worker,"status='retry'")&&str_contains($worker,'MAX_ATTEMPTS=4'),'Worker must use bounded retry.');
contractCheck(str_contains($wpp,'sendAutomationMessage'),'WPPConnect must expose the internal automation send method.');
contractCheck(str_contains($worker,"if($mode==='assigned_user')$mode='creator'")&&str_contains($worker,"$demand['created_by']")&&str_contains($worker,"'type'=>'creator'"),'Legacy assigned-user recipient must resolve to the task creator, never the responsible user.');
contractCheck(str_contains($worker,"'{{creator.name}}'"),'Automation templates must expose the task creator name.');

contractCheck(str_contains($unit,'TaskEsc Automation Worker')&&str_contains($unit,'NoNewPrivileges=true'),'Automation worker service definition missing.');
contractCheck(str_contains($installer,"SERVICE='taskesc-automation-worker.service'")&&str_contains($installer,'--health'),'Automation worker installer/health missing.');
contractCheck(str_contains($app,'name="whatsapp_recipient_mode"')&&substr_count($app,'name="whatsapp_phone"')>=2,'Automation WhatsApp UI fields missing.');

echo "Automation contract: schema 24, graph engine, idempotent worker, WPP action and UI fields OK\n";
