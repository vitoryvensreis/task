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

 autoCheck((int)(DB::one("SELECT value FROM settings WHERE `key`='schema_version'")['value']??0)===27,'Fresh schema must be 27.');
 // Verify 24 -> 26 schema upgrade and preservation of WhatsApp data.
 $db->exec('ALTER TABLE demands DROP COLUMN whatsapp_custom_phone');
 $db->exec('ALTER TABLE whatsapp_messages MODIFY recipient_phone VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL');
 DB::run("UPDATE settings SET value='24' WHERE `key`='schema_version'");
 $upgrade=Migrations::automation();
 autoCheck($upgrade['previous']===24&&$upgrade['current']===26&&$upgrade['changed'],'Schema 24 must upgrade to 26.');
 autoCheck(!Migrations::automationIssues(),'Schema 26 must retain a valid automation structure.');
 autoCheck(Migrations::automation()['changed']===false,'Schema 26 migration must be idempotent.');
 $db->exec('ALTER TABLE demands DROP COLUMN whatsapp_custom_phone');
 DB::run("UPDATE settings SET value='25' WHERE `key`='schema_version'");
 $upgrade25=Migrations::automation();
 autoCheck($upgrade25['previous']===25&&$upgrade25['current']===26&&$upgrade25['changed'],'Schema 25 must upgrade to 26.');
 autoCheck(Migrations::automationIssues()===[],'Schema 26 must validate after upgrade.');

 $groupRows=WppConnect::normalizeGroupsResponse(['status'=>'success','response'=>[
  ['id'=>['_serialized'=>'120363012345678901@g.us'],'name'=>'Produção Geral','participants'=>[['id'=>'private']]],
  ['id'=>'123456789-123456789@g.us','subject'=>'Cliente A'],
  ['id'=>'5585999990001@c.us','name'=>'Conversa privada']
 ]]);
 autoCheck(count($groupRows)===2,'Only valid WhatsApp groups may appear in the selection list.');
 autoCheck(!array_key_exists('participants',$groupRows[0]),'Group endpoint must not expose participants.');
 $badGroup=false;
 try{WppConnect::normalizeGroupId('5585999990001@c.us');}catch(AppError $e){$badGroup=$e->status===422;}
 autoCheck($badGroup,'Individual WhatsApp IDs must be rejected as groups.');

 autoCheck(Migrations::automationIssues()===[],'Fresh schema 26 automation structure must validate.');

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

 $groupDefinition=[
  'version'=>1,
  'nodes'=>[
   ['id'=>'trigger','type'=>'trigger','config'=>['event'=>'demand.created']],
   ['id'=>'send','type'=>'action.whatsapp','config'=>['recipient'=>'group','group_id'=>'120363012345678901@g.us','message'=>'Nova task {{task.title}}']],
   ['id'=>'end','type'=>'end','config'=>[]],
  ],
  'edges'=>[['from'=>'trigger','to'=>'send','branch'=>'next'],['from'=>'send','to'=>'end','branch'=>'next']],
 ];
 $groupSaved=Automation::save(['name'=>'Grupo WhatsApp','trigger_event'=>'demand.created','definition'=>$groupDefinition,'active'=>0]);
 $groupRead=Automation::get(['id'=>$groupSaved['id']]);
 $groupNode=array_values(array_filter($groupRead['definition']['nodes'],static fn($n)=>$n['type']==='action.whatsapp'))[0]??[];
 autoCheck(($groupNode['config']['recipient']??'')==='group'&&($groupNode['config']['group_id']??'')==='120363012345678901@g.us','Group recipient and ID must be saved without truncation.');
 $invalidGroup=$groupDefinition;
 $invalidGroup['nodes'][1]['config']['group_id']='5585999990001@c.us';
 $rejected=false;
 try{Automation::save(['name'=>'Grupo inválido','trigger_event'=>'demand.created','definition'=>$invalidGroup,'active'=>0]);}catch(AppError $e){$rejected=$e->status===422;}
 autoCheck($rejected,'Saving a non-group destination must fail.');

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
  'due_at'=>$due,'priority'=>'normal','billing_mode'=>'one_off','amount_cents'=>0,'draft'=>0
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

 // Teste ponta-a-ponta: um upload final aciona mudança de coluna, e-mail, push e criação.
 $finalNodes=[
  ['id'=>'trigger','type'=>'trigger','config'=>['event'=>'demand.final_delivered']],
  ['id'=>'move','type'=>'action.task','config'=>['operation'=>'stage','value'=>'5']],
  ['id'=>'priority','type'=>'action.task','config'=>['operation'=>'priority','value'=>'high']],
  ['id'=>'email','type'=>'action.email','config'=>['recipient'=>'custom','custom_email'=>'delivery@ci.invalid','subject'=>'Entrega final: {{task.title}}','message'=>'Link da task: {{task.url}}']],
  ['id'=>'push','type'=>'action.push','config'=>['recipient'=>'creator','title'=>'Entrega concluída','message'=>'Final: {{task.title}}']],
  ['id'=>'create','type'=>'action.create_task','config'=>['client_mode'=>'related','category_id'=>1,'title'=>'Revisar {{task.title}}','brief'=>'Verificar os arquivos da entrega final.','hours_due'=>72,'priority'=>'normal']],
  ['id'=>'end','type'=>'end','config'=>[]]
 ];
 $finalEdges=[];
 for($i=0;$i<count($finalNodes)-1;$i++)$finalEdges[]=['from'=>$finalNodes[$i]['id'],'to'=>$finalNodes[$i+1]['id'],'branch'=>'next'];
 $finalAutomation=Automation::save(['name'=>'Teste entrega final','trigger_event'=>'demand.final_delivered','definition'=>['version'=>1,'nodes'=>$finalNodes,'edges'=>$finalEdges],'active'=>1]);
 $initialCopies=(int)DB::one("SELECT COUNT(*) n FROM demands WHERE title='Revisar Task automática'")['n'];
 Files::add(['demand_id'=>$demandId,'kind'=>'final','label'=>'Link final de teste','url'=>'https://example.com/entrega-final']);
 AutomationWorker::tick(200,80);
 $finalRun=DB::one('SELECT id,status FROM automation_runs WHERE automation_id=? ORDER BY id DESC LIMIT 1',[$finalAutomation['id']]);
 autoCheck($finalRun&&$finalRun['status']==='success','Final delivery must execute all actions.');
 $moved=DB::one('SELECT stage_id,priority FROM demands WHERE id=?',[$demandId]);
 autoCheck((int)$moved['stage_id']===5&&$moved['priority']==='high','Task actions must persist stage and priority.');
 autoCheck((int)DB::one("SELECT COUNT(*) n FROM transitions WHERE demand_id=? AND to_stage=5",[$demandId])['n']>=1,'Automation stage must write transition.');
 autoCheck((int)DB::one("SELECT COUNT(*) n FROM mail_queue WHERE recipient='delivery@ci.invalid' AND subject LIKE 'Entrega final:%'")['n']===1,'Email action must queue exactly one message.');
 autoCheck((int)DB::one('SELECT COUNT(*) n FROM notifications WHERE dedupe_key=?',['auto-push:'.$finalRun['id'].':push:'.$admin])['n']===1,'Push action must persist notification once.');
 autoCheck((int)DB::one("SELECT COUNT(*) n FROM demands WHERE title='Revisar Task automática'")['n']===$initialCopies+1,'Create task action must create one task.');
 AutomationWorker::tick(200,80);
 autoCheck((int)DB::one("SELECT COUNT(*) n FROM demands WHERE title='Revisar Task automática'")['n']===$initialCopies+1,'Auto-created tasks cannot produce recursive task creation.');
 AutomationWorker::executeRun((int)$finalRun['id']);
 autoCheck((int)DB::one("SELECT COUNT(*) n FROM mail_queue WHERE recipient='delivery@ci.invalid' AND subject LIKE 'Entrega final:%'")['n']===1,'Completed automation step must never duplicate email.');
 autoCheck((int)DB::one('SELECT COUNT(*) n FROM automation_runs WHERE automation_id=?',[$finalAutomation['id']])['n']===1,'One final upload must yield one automation run.');

 // O destinatário manual é propriedade da task e o worker precisa respeitá-lo.
 autoAs($admin);
 $phoneTask=Demands::save([
  'client_id'=>$clientId,'category_id'=>1,'assigned_to'=>$staffId,
  'title'=>'Destino WhatsApp manual','brief'=>'Teste de encaminhamento',
  'due_at'=>$due,'priority'=>'normal','whatsapp_recipient_mode'=>'custom',
  'whatsapp_custom_phone'=>'+55 (85) 99999-3444','billing_mode'=>'one_off','amount_cents'=>0
 ]);
 $phoneDemand=DB::one('SELECT * FROM demands WHERE id=?',[$phoneTask['id']]);
 autoCheck($phoneDemand['whatsapp_custom_phone']==='5585999993444','Task must store normalized custom WhatsApp destination.');
 $recipientReflection=new ReflectionMethod(AutomationWorker::class,'recipient');
 $recipientResult=$recipientReflection->invoke(null,['config'=>['recipient'=>'task']],$phoneDemand+['entity_type'=>'demand']);
 autoCheck($recipientResult['type']==='custom'&&$recipientResult['phone']==='5585999993444','Worker must use the task-selected custom WhatsApp number.');
 $invalidPhone=false;
 try{Demands::save([
   'client_id'=>$clientId,'category_id'=>1,'assigned_to'=>$staffId,
   'title'=>'Destino inválido','brief'=>'Teste de validação','due_at'=>$due,
   'whatsapp_recipient_mode'=>'custom','whatsapp_custom_phone'=>'123'
 ]);}catch(AppError $e){$invalidPhone=$e->status===422;}
 autoCheck($invalidPhone,'Invalid task-specific WhatsApp number must be rejected.');
 Demands::save([
   'id'=>$phoneDemand['id'],'version'=>$phoneDemand['version'],'title'=>$phoneDemand['title'],
   'brief'=>$phoneDemand['brief'],'due_at'=>$due,'whatsapp_recipient_mode'=>'client'
 ]);
 $changedPhone=DB::one('SELECT whatsapp_recipient_mode,whatsapp_custom_phone FROM demands WHERE id=?',[$phoneDemand['id']]);
 autoCheck($changedPhone['whatsapp_recipient_mode']==='client'&&$changedPhone['whatsapp_custom_phone']===null,'Changing recipient must clear the task custom number.');

 // Somente administradores podem configurar destinatários pelo formulário da task.
 autoAs($staffId);
 $noOverride=false;
 try{Demands::save([
  'id'=>$phoneDemand['id'],'version'=>DB::one('SELECT version FROM demands WHERE id=?',[$phoneDemand['id']])['version'],
  'whatsapp_recipient_mode'=>'none'
 ]);}catch(AppError $e){$noOverride=$e->status===403;}
 autoCheck($noOverride,'Staff must not change WhatsApp recipients through the API.');
 $original=DB::one('SELECT * FROM demands WHERE id=?',[$phoneDemand['id']]);
 Demands::save([
  'id'=>$original['id'],'version'=>$original['version'],
  'title'=>$original['title'],'brief'=>$original['brief'],
  'due_at'=>$due,'priority'=>'normal'
 ]);
 $preserved=DB::one('SELECT whatsapp_recipient_mode,whatsapp_custom_phone FROM demands WHERE id=?',[$phoneDemand['id']]);
 autoCheck($preserved['whatsapp_recipient_mode']==='client'&&$preserved['whatsapp_custom_phone']===null,
  'Staff editing without WhatsApp fields must preserve existing recipient.');
 $publicTask=Demands::visible(DB::one('SELECT * FROM demands WHERE id=?',[$phoneDemand['id']]));
 autoCheck(!array_key_exists('whatsapp_recipient_mode',$publicTask)&&!array_key_exists('whatsapp_custom_phone',$publicTask),
  'Staff API data must not expose admin-only WhatsApp settings.');
 autoAs($admin);


 // Editor livre: um gatilho pode executar dois ramos independentes.
 $parallel=[
  'version'=>1,
  'nodes'=>[
   ['id'=>'trigger','type'=>'trigger','config'=>['event'=>'demand.created'],'position'=>['x'=>90,'y'=>230]],
   ['id'=>'push_a','type'=>'action.push','config'=>['recipient'=>'creator','title'=>'Ramo A','message'=>'Ação A'],'position'=>['x'=>490,'y'=>100]],
   ['id'=>'push_b','type'=>'action.push','config'=>['recipient'=>'creator','title'=>'Ramo B','message'=>'Ação B'],'position'=>['x'=>490,'y'=>400]],
   ['id'=>'end_a','type'=>'end','config'=>[],'position'=>['x'=>890,'y'=>100]],
   ['id'=>'end_b','type'=>'end','config'=>[],'position'=>['x'=>890,'y'=>400]],
  ],
  'edges'=>[
   ['from'=>'trigger','to'=>'push_a','branch'=>'next'],
   ['from'=>'trigger','to'=>'push_b','branch'=>'next'],
   ['from'=>'push_a','to'=>'end_a','branch'=>'next'],
   ['from'=>'push_b','to'=>'end_b','branch'=>'next'],
  ]
 ];
 $savedParallel=Automation::save(['name'=>'Ramos paralelos','trigger_event'=>'demand.created','definition'=>$parallel,'active'=>1]);
 $parallelRead=Automation::get(['id'=>$savedParallel['id']]);
 $savedPosition=array_values(array_filter($parallelRead['definition']['nodes'],fn($n)=>$n['id']==='push_b'))[0]['position']??[];
 autoCheck(($savedPosition['x']??null)===490&&($savedPosition['y']??null)===400,'Free canvas card position must survive save/reload.');
 autoCheck(count($parallelRead['definition']['edges'])===4,'Parallel outgoing edges must survive serialization.');
 $parallelTask=Demands::save([
  'client_id'=>$clientId,'category_id'=>1,'assigned_to'=>$staffId,
  'title'=>'Evento com dois caminhos','brief'=>'Teste de paralelismo',
  'due_at'=>$due,'priority'=>'normal'
 ]);
 AutomationWorker::tick(250,120);
 $runParallel=DB::one('SELECT id,status FROM automation_runs WHERE automation_id=? AND entity_id=? ORDER BY id DESC LIMIT 1',[$savedParallel['id'],$parallelTask['id']]);
 autoCheck($runParallel&&$runParallel['status']==='success','Parallel automation must execute successfully.');
 foreach(['push_a','push_b'] as $nodeId){
  $step=DB::one('SELECT status FROM automation_run_steps WHERE run_id=? AND node_id=?',[$runParallel['id'],$nodeId]);
  autoCheck($step&&$step['status']==='success','Each outgoing branch must execute once: '.$nodeId);
  autoCheck((int)(DB::one('SELECT COUNT(*) n FROM notifications WHERE dedupe_key=?',['auto-push:'.$runParallel['id'].':'.$nodeId.':'.$admin])['n']??0)===1,
   'Each parallel branch must send exactly one notification.');
 }
 AutomationWorker::executeRun((int)$runParallel['id']);
 autoCheck((int)(DB::one('SELECT COUNT(*) n FROM notifications WHERE dedupe_key LIKE ?',['auto-push:'.$runParallel['id'].':%'])['n']??0)===2,'Parallel branches must not duplicate after rerun.');

 $cyclic=$parallel;
 $cyclic['edges'][]=['from'=>'push_a','to'=>'trigger','branch'=>'next'];
 $rejectedCycle=false;
 try{Automation::validateDefinition($cyclic,'demand.created');}catch(AppError $e){$rejectedCycle=$e->status===422;}
 autoCheck($rejectedCycle,'Cycles must remain invalid in free canvas.');
 $duplicateEdge=$parallel;
 $duplicateEdge['edges'][]=$duplicateEdge['edges'][0];
 $rejectedDuplicate=false;
 try{Automation::validateDefinition($duplicateEdge,'demand.created');}catch(AppError $e){$rejectedDuplicate=$e->status===422;}
 autoCheck($rejectedDuplicate,'Duplicate edges must be rejected.');

 $health=AutomationWorker::health(90);
 autoCheck($health['status']==='ok','Automation worker heartbeat/schema health must be OK after tick.');

 echo "Automation core: client events, deadlines, billing triggers, flexible WhatsApp recipients and worker OK\n";
}finally{
 Auth::$user=null;Auth::$actor=null;Auth::$previewClientId=null;
 @unlink($configPath);@unlink(ROOT.'/storage/installed.lock');
}
