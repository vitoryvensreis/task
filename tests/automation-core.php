<?php
declare(strict_types=1);

define('ROOT',dirname(__DIR__));
$port=getenv('OUTBOX_TEST_PORT');
if(!$port)throw new RuntimeException('Run only against the disposable CI database.');

$configPath=ROOT.'/config/config.php';
if(is_file($configPath))@unlink($configPath);
$config=[
 'base_url'=>'https://task.escotilhaprodutora.com',
 'timezone'=>'America/Fortaleza',
 'db'=>['host'=>'127.0.0.1','port'=>(int)$port,'name'=>'taskesc_test','user'=>'root','password'=>'taskesc-ci-only'],
 'app_key'=>bin2hex(random_bytes(32)),
 'smtp'=>['host'=>''],
 'wppconnect'=>['internal_url'=>'http://127.0.0.1:21465','public_url'=>'https://wppconnect.escotilhaprodutora.com','session'=>'taskesc','secret'=>str_repeat('a',64),'webhook_url'=>''],
];
file_put_contents($configPath,"<?php\nreturn ".var_export($config,true).";\n");
@mkdir(ROOT.'/storage/sessions',0700,true);
touch(ROOT.'/storage/installed.lock');

require ROOT.'/app/bootstrap.php';

function autoCheck(bool $condition,string $message): void {if(!$condition)throw new RuntimeException($message);}
function autoUser(int $id): array {
 $u=DB::one('SELECT id,name,email,whatsapp_phone,role,active,email_notifications,weekly_report,twofa_enabled,last_login_at FROM users WHERE id=?',[$id]);
 if(!$u)throw new RuntimeException('Missing automation test user.');
 $u['is_preview']=0;return $u;
}
function autoAs(int $id): void {
 Auth::$user=autoUser($id);Auth::$actor=Auth::$user;Auth::$previewClientId=null;Auth::$origin='web';Auth::$scopes='tools.read tools.write';
}

try{
 $db=DB::conn();
 $db->exec('SET FOREIGN_KEY_CHECKS=0');
 foreach(DB::all('SHOW TABLES') as $row){$table=(string)array_values($row)[0];if(!preg_match('/\A[A-Za-z0-9_]+\z/D',$table))throw new RuntimeException('Unexpected table.');$db->exec('DROP TABLE `'.$table.'`');}
 $db->exec('SET FOREIGN_KEY_CHECKS=1');
 $db->exec(file_get_contents(ROOT.'/database/schema.sql'));

 autoCheck((int)(DB::one("SELECT value FROM settings WHERE `key`='schema_version'")['value']??0)===24,'Fresh schema must be 24.');
 autoCheck(Migrations::automationIssues()===[],'Fresh schema 24 automation structure must validate.');

 $password=password_hash('Taskesc-automation-ci!',PASSWORD_DEFAULT);
 $admin=DB::insert('users',['name'=>'Admin Automation','email'=>'admin-auto@ci.invalid','whatsapp_phone'=>'5585999990001','password_hash'=>$password,'role'=>'admin','active'=>1,'email_notifications'=>0,'created_at'=>now()]);
 autoAs($admin);

 $clientResult=Catalog::clientSave([
  'name'=>'Cliente Automação','brand'=>'Teste','commercial_type'=>'one_off','monthly_amount_cents'=>0,'monthly_payment_day'=>10,
  'drive_account_id'=>0,'email'=>'cliente-auto@ci.invalid','phone'=>'(85) 99999-1000','whatsapp_phone'=>'+55 (85) 99999-1000',
  'document'=>'','notes'=>'','brand_url'=>'','active'=>1
 ]);
 $clientId=(int)$clientResult['id'];
 $client=DB::one('SELECT whatsapp_phone FROM clients WHERE id=?',[$clientId]);
 autoCheck(($client['whatsapp_phone']??'')==='5585999991000','Client WhatsApp must be normalized.');

 $staffResult=Catalog::userSave([
  'name'=>'Editor Automation','email'=>'editor-auto@ci.invalid','whatsapp_phone'=>'55 85 99999-2000','role'=>'staff','active'=>1,
  'email_notifications'=>0,'password'=>'Taskesc-automation-ci!','clients'=>[['client_id'=>$clientId]]
 ]);
 $staffId=(int)$staffResult['id'];
 autoCheck((DB::one('SELECT whatsapp_phone FROM users WHERE id=?',[$staffId])['whatsapp_phone']??'')==='5585999992000','User WhatsApp must be normalized.');

 $invalid=false;
 try{Catalog::userSave(['name'=>'Bad Phone','email'=>'bad-phone@ci.invalid','whatsapp_phone'=>'123','role'=>'staff','active'=>1,'password'=>'Taskesc-automation-ci!','clients'=>[]]);}
 catch(AppError $e){$invalid=$e->status===422;}
 autoCheck($invalid,'Invalid WhatsApp must be rejected.');

 $customDefinition=[
  'version'=>1,
  'nodes'=>[
   ['id'=>'trigger','type'=>'trigger','config'=>['event'=>'demand.created']],
   ['id'=>'send','type'=>'action.whatsapp','config'=>['recipient'=>'custom','custom_phone'=>'+55 (85) 99999-3000','message'=>'Task {{task.title}}']],
   ['id'=>'end','type'=>'end','config'=>[]],
  ],
  'edges'=>[
   ['from'=>'trigger','to'=>'send','branch'=>'next'],
   ['from'=>'send','to'=>'end','branch'=>'next'],
  ],
 ];
 $customAutomation=Automation::save(['name'=>'Número específico','trigger_event'=>'demand.created','definition'=>$customDefinition,'active'=>0]);
 $customSaved=Automation::get(['id'=>$customAutomation['id']]);
 $customNode=array_values(array_filter($customSaved['definition']['nodes'],fn($n)=>$n['type']==='action.whatsapp'))[0]??[];
 autoCheck(($customNode['config']['recipient']??'')==='custom'&&($customNode['config']['custom_phone']??'')==='5585999993000','Custom WhatsApp recipient must be normalized and persisted.');

 $definition=[
  'version'=>1,
  'nodes'=>[
   ['id'=>'trigger','type'=>'trigger','config'=>['event'=>'demand.created']],
   ['id'=>'condition','type'=>'condition','config'=>['field'=>'priority','operator'=>'eq','value'=>'normal']],
   ['id'=>'end_yes','type'=>'end','config'=>[]],
   ['id'=>'end_no','type'=>'end','config'=>[]],
  ],
  'edges'=>[
   ['from'=>'trigger','to'=>'condition','branch'=>'next'],
   ['from'=>'condition','to'=>'end_yes','branch'=>'yes'],
   ['from'=>'condition','to'=>'end_no','branch'=>'no'],
  ],
 ];
 $automation=Automation::save(['name'=>'Nova task normal','trigger_event'=>'demand.created','definition'=>$definition,'active'=>1]);
 autoCheck((int)$automation['id']>0&&$automation['active']===1,'Automation must be saved active.');

 $due=gmdate('Y-m-d\TH:i',time()+7*86400);
 $saved=Demands::save([
  'client_id'=>$clientId,'category_id'=>1,'assigned_to'=>$staffId,'title'=>'Task automática','brief'=>'Briefing da task',
  'due_at'=>$due,'priority'=>'normal','whatsapp_recipient_mode'=>'creator','billing_mode'=>'one_off','amount_cents'=>0,'draft'=>0
 ]);
 $demandId=(int)$saved['id'];
 $demand=DB::one('SELECT * FROM demands WHERE id=?',[$demandId]);
 autoCheck(($demand['whatsapp_recipient_mode']??'')==='creator','Task WhatsApp recipient mode must persist as creator.');
 autoCheck((int)$demand['created_by']===$admin&&((int)$demand['assigned_to'])===$staffId,'Task creator and responsible must remain distinct users.');

 $tick=AutomationWorker::tick(200,20);
 autoCheck((int)$tick['ingested']===1,'Fanout Outbox rows must create only one automation run per operation.');
 $runs=DB::all('SELECT * FROM automation_runs WHERE automation_id=?',[$automation['id']]);
 autoCheck(count($runs)===1&&$runs[0]['status']==='success','Created-task automation must execute successfully.');
 $conditionStep=DB::one("SELECT * FROM automation_run_steps WHERE run_id=? AND node_id='condition'",[$runs[0]['id']]);
 $conditionOutput=json_decode((string)$conditionStep['output_json'],true,16,JSON_THROW_ON_ERROR);
 autoCheck(($conditionOutput['result']??false)===true,'Condition node must evaluate demand data.');

 $skipDefinition=[
  'version'=>1,
  'nodes'=>[
   ['id'=>'trigger','type'=>'trigger','config'=>['event'=>'demand.updated']],
   ['id'=>'send','type'=>'action.whatsapp','config'=>['recipient'=>'task','message'=>'Task {{task.title}} atualizada para {{client.name}}']],
   ['id'=>'end','type'=>'end','config'=>[]],
  ],
  'edges'=>[
   ['from'=>'trigger','to'=>'send','branch'=>'next'],
   ['from'=>'send','to'=>'end','branch'=>'next'],
  ],
 ];
 $skipAutomation=Automation::save(['name'=>'WhatsApp conforme task','trigger_event'=>'demand.updated','definition'=>$skipDefinition,'active'=>1]);
 $fresh=DB::one('SELECT * FROM demands WHERE id=?',[$demandId]);
 Demands::save([
  'id'=>$demandId,'version'=>(int)$fresh['version'],'client_id'=>$clientId,'category_id'=>1,'assigned_to'=>$staffId,
  'title'=>'Task automática','brief'=>'Briefing atualizado','due_at'=>date('Y-m-d\TH:i',strtotime((string)$fresh['due_at'].' UTC')),
  'priority'=>'normal','whatsapp_recipient_mode'=>'none','billing_mode'=>'one_off','amount_cents'=>0,'draft'=>0
 ]);
 AutomationWorker::tick(200,20);
 $skipRun=DB::one('SELECT * FROM automation_runs WHERE automation_id=? ORDER BY id DESC LIMIT 1',[$skipAutomation['id']]);
 autoCheck($skipRun&&$skipRun['status']==='success','Recipient none must skip WhatsApp without failing the run.');
 $sendStep=DB::one("SELECT * FROM automation_run_steps WHERE run_id=? AND node_id='send'",[$skipRun['id']]);
 autoCheck(($sendStep['status']??'')==='skipped','WhatsApp action must be marked skipped when task says none.');
 autoCheck((int)(DB::one('SELECT COUNT(*) n FROM whatsapp_messages WHERE run_id=?',[$skipRun['id']])['n']??0)===0,'Skipped WhatsApp must not call or log provider delivery.');

 $rendered=AutomationWorker::renderTemplate('Olá {{client.name}} · {{task.title}} · criado por {{creator.name}}',DB::one("SELECT d.*,c.name client_name,u.name assigned_name,creator.name creator_name,s.name stage_name FROM demands d JOIN clients c ON c.id=d.client_id JOIN stages s ON s.id=d.stage_id LEFT JOIN users u ON u.id=d.assigned_to LEFT JOIN users creator ON creator.id=d.created_by WHERE d.id=?",[$demandId]));
 autoCheck(str_contains($rendered,'Cliente Automação')&&str_contains($rendered,'Task automática')&&str_contains($rendered,'Admin Automation'),'Template variables must render client, task and creator context.');

 $driveTest=AutomationWorker::renderTemplate('Acesse {{link do drive}}',[
  'entity_type'=>'demand','id'=>$demandId,'final_delivery_link'=>'https://drive.google.com/drive/folders/ExampleDelivery123'
 ]);
 autoCheck($driveTest==='Acesse https://drive.google.com/drive/folders/ExampleDelivery123','Drive link must resolve into automation WhatsApp message.');

 $clientTriggerDefinition=[
  'version'=>1,
  'nodes'=>[
   ['id'=>'trigger','type'=>'trigger','config'=>['event'=>'client.created']],
   ['id'=>'end','type'=>'end','config'=>[]],
  ],
  'edges'=>[['from'=>'trigger','to'=>'end','branch'=>'next']],
 ];
 $clientAutomation=Automation::save(['name'=>'Cliente criado','trigger_event'=>'client.created','definition'=>$clientTriggerDefinition,'active'=>1]);
 $client2=Catalog::clientSave([
  'name'=>'Cliente Gatilho','brand'=>'','commercial_type'=>'one_off','monthly_amount_cents'=>0,'monthly_payment_day'=>10,
  'drive_account_id'=>0,'email'=>'gatilho@ci.invalid','phone'=>'85999994000','whatsapp_phone'=>'5585999994000',
  'document'=>'','notes'=>'','brand_url'=>'','active'=>1
 ]);
 AutomationWorker::tick(200,50);
 $clientRun=DB::one('SELECT * FROM automation_runs WHERE automation_id=? ORDER BY id DESC LIMIT 1',[$clientAutomation['id']]);
 autoCheck($clientRun&&$clientRun['entity_type']==='client'&&(int)$clientRun['entity_id']===(int)$client2['id']&&$clientRun['status']==='success','Client-created Outbox trigger must execute.');

 $teamTriggerDefinition=[
  'version'=>1,
  'nodes'=>[
   ['id'=>'trigger','type'=>'trigger','config'=>['event'=>'client.team_member_added']],
   ['id'=>'end','type'=>'end','config'=>[]],
  ],
  'edges'=>[['from'=>'trigger','to'=>'end','branch'=>'next']],
 ];
 $teamAutomation=Automation::save(['name'=>'Conta equipe criada','trigger_event'=>'client.team_member_added','definition'=>$teamTriggerDefinition,'active'=>1]);
 $teamUser=Catalog::userSave([
  'name'=>'Equipe Gatilho','email'=>'equipe-gatilho@ci.invalid','whatsapp_phone'=>'5585999995000','role'=>'staff','active'=>1,
  'email_notifications'=>0,'password'=>'Taskesc-automation-ci!','clients'=>[['client_id'=>(int)$client2['id']]]
 ]);
 AutomationWorker::tick(200,50);
 $teamRun=DB::one('SELECT * FROM automation_runs WHERE automation_id=? ORDER BY id DESC LIMIT 1',[$teamAutomation['id']]);
 autoCheck($teamRun&&$teamRun['entity_type']==='client'&&(int)$teamRun['entity_id']===(int)$client2['id']&&$teamRun['status']==='success','Team-account-created trigger must execute for its related client.');

 $dueSoonDefinition=[
  'version'=>1,
  'nodes'=>[
   ['id'=>'trigger','type'=>'trigger','config'=>['event'=>'demand.due_soon','hours_before'=>24]],
   ['id'=>'end','type'=>'end','config'=>[]],
  ],
  'edges'=>[['from'=>'trigger','to'=>'end','branch'=>'next']],
 ];
 $dueSoonAutomation=Automation::save(['name'=>'Task próxima','trigger_event'=>'demand.due_soon','definition'=>$dueSoonDefinition,'active'=>1]);
 $futureDue=gmdate('Y-m-d H:i:s',time()+3600);DB::update('demands',$demandId,['due_at'=>$futureDue,'draft'=>0,'updated_at'=>now()]);
 $scheduled=AutomationWorker::scheduleDueTriggers(true);
 autoCheck($scheduled>=1,'Due-soon scheduler must create a run for a task inside the configured window.');
 AutomationWorker::executeReady(100);
 $dueSoonRun=DB::one('SELECT * FROM automation_runs WHERE automation_id=? ORDER BY id DESC LIMIT 1',[$dueSoonAutomation['id']]);
 autoCheck($dueSoonRun&&$dueSoonRun['status']==='success'&&$dueSoonRun['entity_type']==='demand','Due-soon task run must execute.');
 autoCheck(AutomationWorker::scheduleDueTriggers(true)===0,'Due-soon scheduler must be idempotent for the same task/deadline.');

 $overdueDefinition=[
  'version'=>1,
  'nodes'=>[
   ['id'=>'trigger','type'=>'trigger','config'=>['event'=>'demand.overdue']],
   ['id'=>'end','type'=>'end','config'=>[]],
  ],
  'edges'=>[['from'=>'trigger','to'=>'end','branch'=>'next']],
 ];
 $overdueAutomation=Automation::save(['name'=>'Task atrasada','trigger_event'=>'demand.overdue','definition'=>$overdueDefinition,'active'=>1]);
 DB::update('demands',$demandId,['due_at'=>gmdate('Y-m-d H:i:s',time()-3600),'updated_at'=>now()]);
 autoCheck(AutomationWorker::scheduleDueTriggers(true)>=1,'Overdue scheduler must create a run for an open late task.');
 AutomationWorker::executeReady(100);
 $overdueRun=DB::one('SELECT * FROM automation_runs WHERE automation_id=? ORDER BY id DESC LIMIT 1',[$overdueAutomation['id']]);
 autoCheck($overdueRun&&$overdueRun['status']==='success','Overdue task run must execute.');

 $invoiceDueDefinition=[
  'version'=>1,
  'nodes'=>[
   ['id'=>'trigger','type'=>'trigger','config'=>['event'=>'invoice.due_soon','days_before'=>3]],
   ['id'=>'end','type'=>'end','config'=>[]],
  ],
  'edges'=>[['from'=>'trigger','to'=>'end','branch'=>'next']],
 ];
 $invoiceDueAutomation=Automation::save(['name'=>'Cobrança próxima','trigger_event'=>'invoice.due_soon','definition'=>$invoiceDueDefinition,'active'=>1]);
 $localTz=new DateTimeZone(config('timezone'));$invoiceDue=(new DateTimeImmutable('now',$localTz))->modify('+2 days')->format('Y-m-d');
 $invoiceId=DB::insert('invoices',['client_id'=>(int)$client2['id'],'description'=>'Cobrança Automação','amount_cents'=>12345,'due_on'=>$invoiceDue,'status'=>'open','created_at'=>now()]);
 autoCheck(AutomationWorker::scheduleDueTriggers(true)>=1,'Invoice due-soon scheduler must create a run.');
 AutomationWorker::executeReady(100);
 $invoiceDueRun=DB::one('SELECT * FROM automation_runs WHERE automation_id=? ORDER BY id DESC LIMIT 1',[$invoiceDueAutomation['id']]);
 autoCheck($invoiceDueRun&&$invoiceDueRun['status']==='success'&&$invoiceDueRun['entity_type']==='invoice','Invoice due-soon run must execute.');

 $invoiceOverdueDefinition=[
  'version'=>1,
  'nodes'=>[
   ['id'=>'trigger','type'=>'trigger','config'=>['event'=>'invoice.overdue']],
   ['id'=>'end','type'=>'end','config'=>[]],
  ],
  'edges'=>[['from'=>'trigger','to'=>'end','branch'=>'next']],
 ];
 $invoiceOverdueAutomation=Automation::save(['name'=>'Cobrança atrasada','trigger_event'=>'invoice.overdue','definition'=>$invoiceOverdueDefinition,'active'=>1]);
 DB::update('invoices',$invoiceId,['due_on'=>(new DateTimeImmutable('now',$localTz))->modify('-1 day')->format('Y-m-d')]);
 autoCheck(AutomationWorker::scheduleDueTriggers(true)>=1,'Invoice overdue scheduler must create a run for open balance.');
 AutomationWorker::executeReady(100);
 $invoiceOverdueRun=DB::one('SELECT * FROM automation_runs WHERE automation_id=? ORDER BY id DESC LIMIT 1',[$invoiceOverdueAutomation['id']]);
 autoCheck($invoiceOverdueRun&&$invoiceOverdueRun['status']==='success','Invoice overdue run must execute.');

 $invoiceSubject=DB::one("SELECT i.id invoice_id,i.description invoice_description,i.due_on invoice_due_on,i.amount_cents invoice_amount_cents,i.status invoice_status,c.id client_id,c.name client_name,c.email client_email,c.whatsapp_phone client_whatsapp FROM invoices i JOIN clients c ON c.id=i.client_id WHERE i.id=?",[$invoiceId]);
 $invoiceSubject['entity_type']='invoice';
 $invoiceText=AutomationWorker::renderTemplate('{{client.name}} · {{invoice.amount}} · {{invoice.due_on}}',$invoiceSubject);
 autoCheck(str_contains($invoiceText,'Cliente Gatilho')&&str_contains($invoiceText,'R$ 123,45'),'Invoice/client template variables must render.');

 $health=AutomationWorker::health(90);
 autoCheck($health['status']==='ok','Automation worker heartbeat/schema health must be OK after tick.');

 echo "Automation core: client events, deadlines, billing triggers, flexible WhatsApp recipients and worker OK\n";
}finally{
 Auth::$user=null;Auth::$actor=null;Auth::$previewClientId=null;
 @unlink($configPath);@unlink(ROOT.'/storage/installed.lock');
}
