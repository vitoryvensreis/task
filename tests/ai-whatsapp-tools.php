<?php
declare(strict_types=1);
define('ROOT',dirname(__DIR__));
$port=getenv('OUTBOX_TEST_PORT');if(!$port)throw new RuntimeException('CI database required');
@unlink(ROOT.'/config/config.php');
$c=['base_url'=>'https://task.escotilhaprodutora.com','timezone'=>'America/Fortaleza','db'=>['host'=>'127.0.0.1','port'=>(int)$port,'name'=>'taskesc_test','user'=>'root','password'=>'taskesc-ci-only'],'app_key'=>bin2hex(random_bytes(32)),'ai_whatsapp'=>['enabled'=>false,'groq_api_key'=>'','admin_control_enabled'=>true]];
file_put_contents(ROOT.'/config/config.php',"<?php\nreturn ".var_export($c,true).";\n");
@mkdir(ROOT.'/storage/sessions',0700,true);touch(ROOT.'/storage/installed.lock');
require ROOT.'/app/bootstrap.php';
function at(bool $ok,string $why): void {if(!$ok)throw new RuntimeException($why);}
try{
 $db=DB::conn();$db->exec('SET FOREIGN_KEY_CHECKS=0');
 foreach(DB::all('SHOW TABLES') as $r){$name=(string)array_values($r)[0];if(!preg_match('/\A[A-Za-z0-9_]+\z/D',$name))throw new RuntimeException('unexpected table');$db->exec('DROP TABLE `'.$name.'`');}
 $db->exec('SET FOREIGN_KEY_CHECKS=1');$db->exec(file_get_contents(ROOT.'/database/schema.sql'));
 at(setting('schema_version')==='29','Schema 29 required');
 at(Migrations::assistantToolsIssues()===[],'Schema 29 must validate');
 at(Migrations::assistantTools()['changed']===false,'Schema 29 must be idempotent');
 at(AiWhatsAppGroq::enabled()===false,'Groq must be disabled until configured');
 at(AiWhatsAppWorker::health()['status']==='ok','Worker must be healthy when disabled');

 $admin=DB::insert('users',['name'=>'Admin AI','email'=>'admin-ai@ci.invalid','whatsapp_phone'=>'5585999990001','password_hash'=>password_hash('ci-test-password',PASSWORD_DEFAULT),'role'=>'admin','active'=>1,'created_at'=>now()]);
 $ignoredWhileDisabled=WppWebhook::handle(['event'=>'onmessage','session'=>'taskesc','body'=>[
  'id'=>'disabled-key-1','from'=>'5585999990001@c.us','body'=>'Criar task antes de habilitar Groq','type'=>'chat','fromMe'=>false
 ]]);
 at(($ignoredWhileDisabled['stored']??false)===true,'Authenticated test message stored');
 $disabledRow=DB::one("SELECT auth_status,ai_state FROM ai_whatsapp_inbox WHERE provider_message_id='disabled-key-1'");
 at(($disabledRow['auth_status']??'')==='authorized'&&($disabledRow['ai_state']??'')==='ignored','Disabled AI must not enqueue authorized messages for replay after activation');

 $clientId=DB::insert('clients',['name'=>'Cliente AI','created_at'=>now()]);
 $categoryId=DB::insert('categories',['name'=>'Vídeo AI','fields_json'=>'[]','default_assigned_to'=>$admin,'default_amount_cents'=>29900]);
 $chat='5585999990001@c.us';
 $msg=DB::insert('ai_whatsapp_inbox',['session_name'=>'taskesc','provider_message_id'=>'ai-test-1','chat_id'=>$chat,'sender_id'=>$chat,'chat_type'=>'private','message_type'=>'chat','body'=>'Criar uma task','status'=>'received','auth_status'=>'authorized','auth_reason'=>'user_authorized','received_at'=>now(),'ai_state'=>'queued']);
 $sent=[];$sender=function($phone,$text,$group)use(&$sent){$sent[]=[$phone,$text,$group];};
 $verifier=fn($row)=>['status'=>'authorized','user_id'=>$admin];
 $planner=fn($text,$context,$tools)=>['tool'=>'create_task','arguments'=>['client_id'=>$clientId,'category_id'=>$categoryId,'assigned_to'=>$admin,'title'=>'Aula Augusto','brief'=>'Editar aula']];
 at(AiWhatsAppWorker::tick(1,$planner,$verifier,null,$sender,true)['processed']===1,'Create command processed');
 at(DB::one('SELECT ai_state FROM ai_whatsapp_inbox WHERE id=?',[$msg])['ai_state']==='sent','Reply sent once');
 at((int)(DB::one('SELECT COUNT(*) n FROM demands')['n']??0)===1,'Exactly one task created');
 at((int)DB::one('SELECT amount_cents FROM demands WHERE id=1')['amount_cents']===0,'WhatsApp-created task must never create charges');
 at(count($sent)===1&&$sent[0][0]==='5585999990001','Private reply target correct');
 at(AiWhatsAppWorker::tick(1,$planner,$verifier,null,$sender,true)['processed']===0,'Cannot replay processed task');
 at((int)(DB::one('SELECT COUNT(*) n FROM demands')['n']??0)===1,'Duplicate not created');

 $groupId='120363012345678901@g.us';
 $ignored=DB::insert('ai_whatsapp_inbox',['session_name'=>'taskesc','provider_message_id'=>'ai-test-2','chat_id'=>$groupId,'sender_id'=>$chat,'chat_type'=>'group','message_type'=>'chat','body'=>'Criar uma task','status'=>'received','auth_status'=>'authorized','auth_reason'=>'group_admin_present','received_at'=>now(),'ai_state'=>'queued','ai_mentions_json'=>'[]']);
 AiWhatsAppWorker::tick(1,$planner,$verifier,fn()=>false,$sender,true);
 at(DB::one('SELECT ai_state FROM ai_whatsapp_inbox WHERE id=?',[$ignored])['ai_state']==='ignored','Unmentioned group ignored');
 at((int)(DB::one('SELECT COUNT(*) n FROM demands')['n']??0)===1,'Unmentioned group cannot write');
 at(count($sent)===1,'Unmentioned group cannot receive response');

 $group=DB::insert('ai_whatsapp_inbox',['session_name'=>'taskesc','provider_message_id'=>'ai-test-3','chat_id'=>$groupId,'sender_id'=>$chat,'chat_type'=>'group','message_type'=>'chat','body'=>'Mover uma task','status'=>'received','auth_status'=>'authorized','auth_reason'=>'group_admin_present','received_at'=>now(),'ai_state'=>'queued','ai_mentions_json'=>json(['5585999999999@lid'])]);
 $numberOfChecks=0;
 $revoke=function($row)use($admin,&$numberOfChecks){
  $numberOfChecks++;
  return $numberOfChecks===1?['status'=>'authorized','user_id'=>$admin]:['status'=>'denied','user_id'=>null];
 };
 $movePlan=fn($text,$context,$tools)=>['tool'=>'move_task','arguments'=>['task_id'=>1,'stage_id'=>5]];
 AiWhatsAppWorker::tick(1,$movePlan,$revoke,fn()=>true,$sender,true);
 at($numberOfChecks>=2,'Group authorization must be checked again immediately before tool');
 at(DB::one('SELECT ai_state FROM ai_whatsapp_inbox WHERE id=?',[$group])['ai_state']==='ignored','Revoked user must not execute');
 at((int)DB::one('SELECT stage_id FROM demands WHERE id=1')['stage_id']!==5,'Revoked group cannot move task');
 at(count($sent)===1,'Revoked group may not receive a message');

 $privateMove=DB::insert('ai_whatsapp_inbox',['session_name'=>'taskesc','provider_message_id'=>'ai-test-4','chat_id'=>$chat,'sender_id'=>$chat,'chat_type'=>'private','message_type'=>'chat','body'=>'Mover','status'=>'received','auth_status'=>'authorized','auth_reason'=>'user_authorized','received_at'=>now(),'ai_state'=>'queued']);
 $checks=0;
 $allowed=function($row)use($admin,&$checks){$checks++;return ['status'=>'authorized','user_id'=>$admin];};
 AiWhatsAppWorker::tick(1,$movePlan,$allowed,null,$sender,true);
 at($checks>=3,'Revalidate before planning, tool and response');
 at((int)DB::one('SELECT stage_id FROM demands WHERE id=1')['stage_id']===5,'Authorized move uses Kanban');
 at(DB::one('SELECT ai_state FROM ai_whatsapp_inbox WHERE id=?',[$privateMove])['ai_state']==='sent','Authorized move confirms reply');
 at(count($sent)===2,'Only the two authorized commands may reply');

 // Passo 4: uma única edição por mensagem, whitelist rígida e preservação de dados protegidos.
 $editCatalog=AiWhatsAppTools::catalog();
 at(in_array('edit_task',array_column(array_column($editCatalog,'function'),'name'),true),'Edit exposed to authorized users');
 $beforeEdit=DB::one('SELECT * FROM demands WHERE id=1');
 $editMsg=DB::insert('ai_whatsapp_inbox',['session_name'=>'taskesc','provider_message_id'=>'ai-test-edit-1','chat_id'=>$chat,'sender_id'=>$chat,'chat_type'=>'private','message_type'=>'chat','body'=>'Editar o briefing e prioridade da task 1','status'=>'received','auth_status'=>'authorized','auth_reason'=>'user_authorized','received_at'=>now(),'ai_state'=>'queued']);
 $editPlan=fn($text,$context,$tools)=>['tool'=>'edit_task','arguments'=>['task_id'=>1,'brief'=>'Novo briefing pelo WhatsApp','priority'=>'high']];
 AiWhatsAppWorker::tick(1,$editPlan,$allowed,null,$sender,true);
 $afterEdit=DB::one('SELECT * FROM demands WHERE id=1');
 at($afterEdit['brief']==='Novo briefing pelo WhatsApp'&&$afterEdit['priority']==='high','Edit changes only allowed fields');
 foreach(['due_at','title','client_id','project_id','category_id','stage_id','assigned_to','draft','billing_mode','amount_cents','commercial_status'] as $key)
  at((string)($afterEdit[$key]??'')===(string)($beforeEdit[$key]??''),'Protected field changed: '.$key);
 at((int)$afterEdit['version']===(int)$beforeEdit['version']+1,'Version increased once');
 at(DB::one('SELECT ai_state FROM ai_whatsapp_inbox WHERE id=?',[$editMsg])['ai_state']==='sent','Edit reply sent');
 at(count($sent)===3&&str_contains($sent[2][1],'atualizada'),'Edit confirmation');
 AiWhatsAppWorker::tick(1,$editPlan,$allowed,null,$sender,true);
 at((int)DB::one('SELECT version FROM demands WHERE id=1')['version']===(int)$afterEdit['version'],'Edit cannot replay');

 $dueLocal=businessDeadline(72)->format('Y-m-d\TH:i');
 $editDue=AiWhatsAppTools::run('edit_task',['task_id'=>1,'due_at'=>$dueLocal]);
 at($editDue['kind']==='edited','Due date can be edited');
 $utc=(new DateTimeImmutable($dueLocal,new DateTimeZone('America/Fortaleza')))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
 at(DB::one('SELECT due_at FROM demands WHERE id=1')['due_at']===$utc,'Due date timezone correctly converted');
 $afterDue=DB::one('SELECT * FROM demands WHERE id=1');
 $invalid=[
   ['task_id'=>1], // Empty edit.
   ['task_id'=>1,'amount_cents'=>999999],
   ['task_id'=>1,'client_id'=>999,'title'=>'Malicious'],
   ['task_id'=>1,'assigned_to'=>999,'brief'=>'Malicious'],
   ['task_id'=>1,'draft'=>0,'title'=>'Publish'],
   ['task_id'=>1,'priority'=>'untrusted'],
   ['task_id'=>1,'due_at'=>'2026-02-30T09:30'],
   ['task_id'=>1,'title'=>''],
 ];
 foreach($invalid as $request){
  try{AiWhatsAppTools::run('edit_task',$request);throw new RuntimeException('Rejected edit was executed');}
  catch(AppError $e){at($e->getMessage()!=='','Unsafe edit rejected');}
 }
 at((int)DB::one('SELECT version FROM demands WHERE id=1')['version']===(int)$afterDue['version'],'Invalid edits cannot write');
 $staff=DB::insert('users',['name'=>'Staff sem acesso','email'=>'staff-ai@ci.invalid','whatsapp_phone'=>'5585999990011','password_hash'=>password_hash('staff-password',PASSWORD_DEFAULT),'role'=>'staff','active'=>1,'ai_assistant_enabled'=>1,'created_at'=>now()]);
 Auth::$user=DB::one('SELECT * FROM users WHERE id=?',[$staff]);Auth::$actor=Auth::$user;Auth::$previewClientId=null;
 at(Auth::can('tasks.edit')===true,'Staff has edit ability');
 try{AiWhatsAppTools::run('edit_task',['task_id'=>1,'title'=>'Cross client']);throw new RuntimeException('Unauthorized edit executed');}
 catch(AppError $e){at($e->getMessage()!=='','Unlinked staff denied');}
 at((int)DB::one('SELECT version FROM demands WHERE id=1')['version']===(int)$afterDue['version'],'Unauthorized edit cannot write');
 Auth::$user=DB::one('SELECT * FROM users WHERE id=?',[$admin]);Auth::$actor=Auth::$user;


 // Missing category owner: the existing business rule must use the current verified staff/admin.
 $unassignedCategory=DB::insert('categories',['name'=>'Vídeo sem padrão','fields_json'=>'[]','default_amount_cents'=>80000]);
 $body=['client_id'=>$clientId,'category_id'=>$unassignedCategory,'title'=>'TESTE IA - sem responsável padrão','brief'=>'Validar responsável automático','priority'=>'normal'];
 $actorContext=AiWhatsAppTools::context()['current_user']??null;
 at(is_array($actorContext)&&(int)$actorContext['id']===$admin&&$actorContext['name']==='Admin AI','Current user exposed to AI without credentials');
 $selfDefault=AiWhatsAppTools::run('create_task',$body);
 $createdDefault=DB::one('SELECT * FROM demands WHERE id=?',[$selfDefault['id']]);
 at((int)$createdDefault['assigned_to']===$admin,'No default means assign to verified actor');
 at((int)$createdDefault['amount_cents']===0,'Automatic assignment does not create any charges');

 // Respect a valid category default when the request did NOT explicitly choose "me".
 $secondAdmin=DB::insert('users',['name'=>'Outro responsável','email'=>'second-admin-ai@ci.invalid','whatsapp_phone'=>'5585999990022','password_hash'=>password_hash('test-password',PASSWORD_DEFAULT),'role'=>'admin','active'=>1,'created_at'=>now()]);
 $anotherCategory=DB::insert('categories',['name'=>'Vídeo com padrão diferente','fields_json'=>'[]','default_assigned_to'=>$secondAdmin]);
 $normal=AiWhatsAppTools::run('create_task',['client_id'=>$clientId,'category_id'=>$anotherCategory,'title'=>'Categoria usa padrão','brief'=>'Sem seleção']);
 at((int)DB::one('SELECT assigned_to FROM demands WHERE id=?',[$normal['id']])['assigned_to']===$secondAdmin,'Preserve valid category default');

 // Explicit self assignment must override the category default without trusting an LLM-supplied ID.
 $explicit=AiWhatsAppTools::run('create_task',['client_id'=>$clientId,'category_id'=>$anotherCategory,'title'=>'Eu mesmo responsável','brief'=>'Eu mesmo','assign_to_me'=>true]);
 at((int)DB::one('SELECT assigned_to FROM demands WHERE id=?',[$explicit['id']])['assigned_to']===$admin,'Self means authenticated actor');

 $beforeUnsafe=(int)(DB::one('SELECT COUNT(*) n FROM demands')['n']??0);
 foreach([
  ['client_id'=>$clientId,'category_id'=>$anotherCategory,'title'=>'Incompatível','brief'=>'x','assign_to_me'=>true,'assigned_to'=>$admin],
  ['client_id'=>$clientId,'category_id'=>$anotherCategory,'title'=>'Tipo inválido','brief'=>'x','assign_to_me'=>'true']
 ] as $bad){
  try{AiWhatsAppTools::run('create_task',$bad);throw new RuntimeException('Invalid assignee selection executed');}
  catch(AppError $e){at($e->getMessage()!=='','Unsafe assignee rejected');}
 }
 at((int)DB::one('SELECT COUNT(*) n FROM demands')['n']===$beforeUnsafe,'Rejected selections created no tasks');

 // A client cannot assign a task to themselves; only active staff/admin are valid assignees.
 $customer=DB::insert('users',['name'=>'Cliente AI teste','email'=>'client-self-ai@ci.invalid','password_hash'=>password_hash('client-password',PASSWORD_DEFAULT),'role'=>'client','active'=>1,'created_at'=>now()]);
 DB::insert('user_clients',['user_id'=>$customer,'client_id'=>$clientId]);
 Auth::$user=DB::one('SELECT * FROM users WHERE id=?',[$customer]);Auth::$actor=Auth::$user;
 at(AiWhatsAppTools::context()['current_user']===null,'Never expose clients as a responsible staff member');
 try{
  AiWhatsAppTools::run('create_task',['client_id'=>$clientId,'category_id'=>$unassignedCategory,'title'=>'Cliente não responsável','brief'=>'Teste','assign_to_me'=>true]);
  throw new RuntimeException('Client was assigned as staff');
 }catch(AppError $e){at($e->getMessage()!=='','Client assignment blocked');}
 Auth::$user=DB::one('SELECT * FROM users WHERE id=?',[$admin]);Auth::$actor=Auth::$user;
 at((int)DB::one('SELECT COUNT(*) n FROM demands')['n']===$beforeUnsafe,'Client self-assignment created no tasks');

 // Quota optimization: exact read-only request must run without Groq/Gemini.
 $fastMsg=DB::insert('ai_whatsapp_inbox',['session_name'=>'taskesc','provider_message_id'=>'fast-list-1','chat_id'=>$chat,'sender_id'=>$chat,'chat_type'=>'private','message_type'=>'chat','body'=>'Olá, TaskEsc! Liste minhas últimas 5 tarefas.','status'=>'received','auth_status'=>'authorized','auth_reason'=>'user_authorized','received_at'=>now(),'ai_state'=>'queued']);
 $calls=0;
 $rejectPlanner=static function() use (&$calls): array {$calls++;throw new RuntimeException('Model invoked on local request');};
 $sentBefore=count($sent);
 $tasksBefore=(int)(DB::one('SELECT COUNT(*) n FROM demands')['n']??0);
 AiWhatsAppWorker::tick(1,$rejectPlanner,$verifier,null,$sender,true);
 at($calls===0,'Read-only fast path must never invoke LLM');
 at(DB::one('SELECT ai_state FROM ai_whatsapp_inbox WHERE id=?',[$fastMsg])['ai_state']==='sent','Read-only fast path replied once');
 at(count($sent)===$sentBefore+1&&str_starts_with($sent[$sentBefore][1],'Tasks ('),'Fast path uses authorized TaskEsc task API');
 at(substr_count($sent[$sentBefore][1],"\n")<=5,'Requested result limit applies');
 at((int)(DB::one('SELECT COUNT(*) n FROM demands')['n']??0)===$tasksBefore,'Read-only fast path cannot mutate demands');

 // Private vocatives/calendar inventories are read-only and never invoke the provider.
 $prefixed=DB::insert('ai_whatsapp_inbox',['session_name'=>'taskesc','provider_message_id'=>'fast-task-prefix-1',
  'chat_id'=>$chat,'sender_id'=>$chat,'chat_type'=>'private','message_type'=>'chat','body'=>'Task, liste minhas tarefas',
  'status'=>'received','auth_status'=>'authorized','auth_reason'=>'user_authorized','received_at'=>now(),'ai_state'=>'queued']);
 $before=count($sent);
 AiWhatsAppWorker::tick(1,$rejectPlanner,$verifier,null,$sender,true);
 at($calls===0&&DB::one('SELECT ai_state FROM ai_whatsapp_inbox WHERE id=?',[$prefixed])['ai_state']==='sent',
  'Private Task prefix uses local list route');
 at(count($sent)===$before+1&&str_starts_with($sent[$before][1],'Tasks ('),'Private task reply sent');

 $calendarMsg=DB::insert('ai_whatsapp_inbox',['session_name'=>'taskesc','provider_message_id'=>'fast-calendar-1',
  'chat_id'=>$chat,'sender_id'=>$chat,'chat_type'=>'private','message_type'=>'chat','body'=>'Liste minhas agendas disponíveis',
  'status'=>'received','auth_status'=>'authorized','auth_reason'=>'user_authorized','received_at'=>now(),'ai_state'=>'queued']);
 $before=count($sent);
 AiWhatsAppWorker::tick(1,$rejectPlanner,$verifier,null,$sender,true);
 at($calls===0&&DB::one('SELECT ai_state FROM ai_whatsapp_inbox WHERE id=?',[$calendarMsg])['ai_state']==='sent',
  'Private calendars without model');
 at(count($sent)===$before+1&&preg_match('/Agendas:|Nenhuma agenda disponível/u',$sent[$before][1]),
  'Private calendar inventory delivered');

 $hello=DB::insert('ai_whatsapp_inbox',['session_name'=>'taskesc','provider_message_id'=>'fast-greeting-1','chat_id'=>$chat,'sender_id'=>$chat,'chat_type'=>'private','message_type'=>'chat','body'=>'Oi','status'=>'received','auth_status'=>'authorized','auth_reason'=>'user_authorized','received_at'=>now(),'ai_state'=>'queued']);
 AiWhatsAppWorker::tick(1,$rejectPlanner,$verifier,null,$sender,true);
 at($calls===0,'Greeting must never invoke LLM');
 at(DB::one('SELECT ai_state FROM ai_whatsapp_inbox WHERE id=?',[$hello])['ai_state']==='sent','Greeting replied once');

 $rejected=DB::insert('ai_whatsapp_inbox',['session_name'=>'taskesc','provider_message_id'=>'fast-denied-1','chat_id'=>$chat,'sender_id'=>$chat,'chat_type'=>'private','message_type'=>'chat','body'=>'Liste minhas tarefas','status'=>'received','auth_status'=>'authorized','auth_reason'=>'user_authorized','received_at'=>now(),'ai_state'=>'queued']);
 $sentBefore=count($sent);
 AiWhatsAppWorker::tick(1,$rejectPlanner,fn()=>['status'=>'denied','user_id'=>null],null,$sender,true);
 at(DB::one('SELECT ai_state FROM ai_whatsapp_inbox WHERE id=?',[$rejected])['ai_state']==='ignored','Revoked sender ignored');
 at(count($sent)===$sentBefore&&$calls===0,'No reply or model after revoked authorization');


 // Finance read-only: exact accounting, no write side-effects, ACL and private-only exposure.
 $tz=new DateTimeZone('America/Fortaleza');
 $today=(new DateTimeImmutable('now',$tz))->format('Y-m-d');
 $start=(new DateTimeImmutable('first day of this month',$tz))->format('Y-m-d');
 $last=(new DateTimeImmutable('last day of this month',$tz))->format('Y-m-d');
 $previous=(new DateTimeImmutable('first day of last month',$tz))->format('Y-m-d');
 $monthly=DB::insert('clients',['name'=>'Mensalidade não sincronizada','commercial_type'=>'monthly',
  'monthly_amount_cents'=>5000,'monthly_payment_day'=>15,'created_at'=>now()]);
 $invoice=DB::insert('invoices',['client_id'=>$clientId,'description'=>'Fatura teste financeiro','amount_cents'=>25000,'due_on'=>$today,'created_at'=>now()]);
 $older=DB::insert('invoices',['client_id'=>$clientId,'description'=>'Saldo anterior','amount_cents'=>9000,'due_on'=>$previous,'created_at'=>now()]);
 $cancelled=DB::insert('invoices',['client_id'=>$clientId,'description'=>'Cancelada','amount_cents'=>7000,'due_on'=>$today,'status'=>'cancelled','created_at'=>now()]);
 DB::insert('payments',['invoice_id'=>$invoice,'amount_cents'=>10000,'discount_cents'=>2000,'paid_on'=>$today,
  'method'=>'Pix','created_by'=>$admin,'created_at'=>now()]);
 DB::insert('expenses',['description'=>'Custo operacional teste','vendor'=>'Fornecedor teste','category'=>'Outros',
  'amount_cents'=>3000,'spent_on'=>$today,'payment_status'=>'paid','paid_on'=>$today,'created_by'=>$admin,
  'created_at'=>now(),'updated_at'=>now()]);
 DB::insert('expenses',['description'=>'Custo pendente teste','vendor'=>'Fornecedor teste','category'=>'Outros',
  'amount_cents'=>2000,'spent_on'=>$today,'payment_status'=>'pending','created_by'=>$admin,
  'created_at'=>now(),'updated_at'=>now()]);
 $invoiceCountBefore=(int)DB::one('SELECT COUNT(*) n FROM invoices')['n'];
 $expenseCountBefore=(int)DB::one('SELECT COUNT(*) n FROM expenses')['n'];
 $adminPrivateTools=array_column(array_column(AiWhatsAppTools::catalog(true),'function'),'name');
 at(in_array('finance_receivables',$adminPrivateTools,true)&&in_array('finance_summary',$adminPrivateTools,true)
  &&in_array('finance_expenses',$adminPrivateTools,true),'Admin private exposes read-only finances');
 $adminGroupTools=array_column(array_column(AiWhatsAppTools::catalog(false),'function'),'name');
 at(!in_array('finance_receivables',$adminGroupTools,true),'Financial tools never exposed in groups');
 $receivable=TaskEscFinanceReadApi::receivables(['scope'=>'month','from'=>$start,'to'=>$last]);
 at($receivable['kind']==='finance_receivables'&&$receivable['total_cents']===13000
  &&(int)$receivable['count']===1,'Monthly balance accounts for payments and discounts');
 at((int)$receivable['rows'][0]['id']===$invoice,'Receivables expose only registered open invoice');
 $allReceivables=TaskEscFinanceReadApi::receivables(['scope'=>'all']);
 at($allReceivables['total_cents']===22000&&$allReceivables['count']===2,'All open invoices across months');
 $summary=TaskEscFinanceReadApi::summary(['from'=>$start,'to'=>$last]);
 at($summary['billed_cents']===25000&&$summary['received_cents']===10000,'Faturamento versus recebimento');
 at($summary['expense_cents']===5000&&$summary['paid_expense_cents']===3000
  &&$summary['profit_cents']===7000,'Cash profit follows existing Finance::analysis definitions');
 at($summary['open_all_cents']===22000,'Global open balance includes previous month');
 $expenseDetails=TaskEscFinanceReadApi::expenses(['from'=>$start,'to'=>$last]);
 at(count($expenseDetails['rows'])===2,'Expenses read-only list');
 // Public REST v1 and WhatsApp AI share the same read-only, admin-protected gateway.
 Auth::$origin='api';Auth::$scopes='api.read api.write';
 $restReceivables=ApiV1::handle('GET','finance/receivables',['scope'=>'month','from'=>$start,'to'=>$last]);
 at($restReceivables['total_cents']===13000,'REST v1 receivables matches AI amounts');
 $restExpenses=ApiV1::handle('GET','finance/expenses',['from'=>$start,'to'=>$last]);
 at(count($restExpenses['rows'])===2,'REST v1 expense query matches AI amounts');
 try{ApiV1::handle('POST','finance/receivables',[]);throw new RuntimeException('Finance write exposed through REST');}
 catch(AppError $e){at($e->getMessage()!=='','REST v1 blocks finance write');}
 Auth::$origin='whatsapp';

 foreach([
  fn()=>TaskEscFinanceReadApi::summary(['from'=>'2026-02-30']),
  fn()=>TaskEscFinanceReadApi::receivables(['scope'=>'all','from'=>$start]),
  fn()=>TaskEscFinanceReadApi::receivables(['limit'=>11]),
  fn()=>TaskEscFinanceReadApi::summary(['delete_all'=>true])
 ] as $bad){
  try{$bad();throw new RuntimeException('Unvalidated finance query executed');}
  catch(AppError $e){at($e->getMessage()!=='','Invalid finance query rejected');}
 }
 at((int)DB::one('SELECT COUNT(*) n FROM invoices')['n']===$invoiceCountBefore
  &&(int)DB::one('SELECT COUNT(*) n FROM expenses')['n']===$expenseCountBefore,
  'Finance reads must not sync or create invoices/expenses');
 at(!DB::one('SELECT id FROM invoices WHERE client_id=?',[$monthly]),'Financial read must not sync monthly client');

 // A private authorized user can query financial balances without consuming tokens.
 $financeMsg=DB::insert('ai_whatsapp_inbox',['session_name'=>'taskesc','provider_message_id'=>'finance-fast-1',
  'chat_id'=>$chat,'sender_id'=>$chat,'chat_type'=>'private','message_type'=>'chat',
  'body'=>'me fala o que ainda tenho a receber esse mês','status'=>'received',
  'auth_status'=>'authorized','auth_reason'=>'user_authorized','received_at'=>now(),'ai_state'=>'queued']);
 $financeCalls=0;
 $noFinanceModel=static function()use(&$financeCalls):array{$financeCalls++;throw new RuntimeException('LLM invoked');};
 $beforeFinanceSent=count($sent);
 AiWhatsAppWorker::tick(1,$noFinanceModel,$verifier,null,$sender,true);
 at($financeCalls===0&&DB::one('SELECT ai_state FROM ai_whatsapp_inbox WHERE id=?',[$financeMsg])['ai_state']==='sent',
  'Authorized finance private fast-path sends once without LLM');
 at(str_contains($sent[$beforeFinanceSent][1],'R$ 130,00'),'Finance reply formats accurate cents');

 Auth::$user=DB::one('SELECT * FROM users WHERE id=?',[$staff]);Auth::$actor=Auth::$user;
 at(!in_array('finance_summary',array_column(array_column(AiWhatsAppTools::catalog(true),'function'),'name'),true),
  'Staff cannot access finance tool');
 try{TaskEscFinanceReadApi::summary();throw new RuntimeException('Staff finance bypass');}
 catch(AppError $e){at($e->getMessage()!=='','Staff denied by finance gateway');}
 Auth::$user=DB::one('SELECT * FROM users WHERE id=?',[$customer]);Auth::$actor=Auth::$user;
 try{TaskEscFinanceReadApi::receivables();throw new RuntimeException('Client finance bypass');}
 catch(AppError $e){at($e->getMessage()!=='','Client denied by finance gateway');}
 Auth::$user=DB::one('SELECT * FROM users WHERE id=?',[$admin]);Auth::$actor=Auth::$user;

 // Safe administrative controls: sensitive operations require a second exact message.
 $availableAdmin=array_column(array_column(AiWhatsAppAdminActions::catalog(true),'function'),'name');
 at((AiWhatsAppFastPath::plan('liste os clientes',true)['tool']??null)==='list_clients','Admin exact list clients avoids model');
 at(AiWhatsAppFastPath::plan('liste os clientes',false)===null,'No list clients shortcut in groups');
 at(count($availableAdmin)===10&&in_array('record_payment',$availableAdmin,true),'Admin private catalog exposes guarded tools');
 at(AiWhatsAppAdminActions::catalog(false)===[],'Admin cannot reveal admin tools in a group');
 $beforeApprovalInvoices=(int)DB::one('SELECT COUNT(*) n FROM invoices')['n'];
 $request=DB::insert('ai_whatsapp_inbox',['session_name'=>'taskesc','provider_message_id'=>'approval-propose-1',
  'chat_id'=>$chat,'sender_id'=>$chat,'chat_type'=>'private','message_type'=>'chat',
  'body'=>'Crie cobrança teste segura','status'=>'received','auth_status'=>'authorized',
  'auth_reason'=>'user_authorized','received_at'=>now(),'ai_state'=>'queued']);
 $preparePlanner=fn()=>['tool'=>'create_invoice','arguments'=>['client_id'=>$clientId,
  'description'=>'Cobrança com autorização','amount_cents'=>11500,'due_on'=>$today]];
 $prev=count($sent);
 AiWhatsAppWorker::tick(1,$preparePlanner,$verifier,null,$sender,true);
 at(DB::one('SELECT ai_state FROM ai_whatsapp_inbox WHERE id=?',[$request])['ai_state']==='sent','Proposal delivered once');
 at((int)DB::one('SELECT COUNT(*) n FROM invoices')['n']===$beforeApprovalInvoices,'Proposal alone cannot create invoice');
 preg_match('/CONFIRMAR ([A-F0-9]{12})/',$sent[$prev][1],$match);
 at(isset($match[1]),'Expected explicit, fresh confirmation token');
 $token=$match[1];
 // A different actor or group chat can never approve the admin's pending action.
 Auth::$user=DB::one('SELECT * FROM users WHERE id=?',[$staff]);Auth::$actor=Auth::$user;
 try{AiWhatsAppApproval::decide('_approve_admin',['code'=>$token],['id'=>0,'chat_type'=>'private','chat_id'=>$chat]);throw new RuntimeException('Non-admin approval allowed');}
 catch(AppError $e){at($e->getMessage()!=='','Non-admin approval denied');}
 Auth::$user=DB::one('SELECT * FROM users WHERE id=?',[$admin]);Auth::$actor=Auth::$user;
 try{AiWhatsAppApproval::decide('_approve_admin',['code'=>$token],['id'=>0,'chat_type'=>'group','chat_id'=>$groupId]);throw new RuntimeException('Group approval allowed');}
 catch(AppError $e){at($e->getMessage()!=='','Group approval denied');}
 at((int)DB::one('SELECT COUNT(*) n FROM invoices')['n']===$beforeApprovalInvoices,'No execution after denied confirmations');
 $confirm=DB::insert('ai_whatsapp_inbox',['session_name'=>'taskesc','provider_message_id'=>'approval-confirm-1',
  'chat_id'=>$chat,'sender_id'=>$chat,'chat_type'=>'private','message_type'=>'chat',
  'body'=>'CONFIRMAR '.$token,'status'=>'received','auth_status'=>'authorized',
  'auth_reason'=>'user_authorized','received_at'=>now(),'ai_state'=>'queued']);
 $llmUsed=0;$neverPlan=static function() use (&$llmUsed):array{$llmUsed++;throw new RuntimeException('Confirmation must not invoke a model');};
 AiWhatsAppWorker::tick(1,$neverPlan,$verifier,null,$sender,true);
 at($llmUsed===0,'Explicit confirmation never invokes Gemini or Groq');
 at(DB::one('SELECT ai_state FROM ai_whatsapp_inbox WHERE id=?',[$confirm])['ai_state']==='sent','Confirmation delivered once');
 at((int)DB::one('SELECT COUNT(*) n FROM invoices')['n']===$beforeApprovalInvoices+1,'Confirmed invoice created exactly once');
 $createdInvoice=DB::one("SELECT * FROM invoices WHERE description='Cobrança com autorização' ORDER BY id DESC LIMIT 1");
 at((int)$createdInvoice['amount_cents']===11500,'Confirmed monetary amount exact');
 $again=DB::insert('ai_whatsapp_inbox',['session_name'=>'taskesc','provider_message_id'=>'approval-confirm-replay',
  'chat_id'=>$chat,'sender_id'=>$chat,'chat_type'=>'private','message_type'=>'chat',
  'body'=>'CONFIRMAR '.$token,'status'=>'received','auth_status'=>'authorized',
  'auth_reason'=>'user_authorized','received_at'=>now(),'ai_state'=>'queued']);
 AiWhatsAppWorker::tick(1,$neverPlan,$verifier,null,$sender,true);
 at((int)DB::one('SELECT COUNT(*) n FROM invoices')['n']===$beforeApprovalInvoices+1,'Repeated code cannot duplicate invoice');

 // A second proposal cancelled in the chat cannot write, nor can subsequent replay.
 $cancelRequest=DB::insert('ai_whatsapp_inbox',['session_name'=>'taskesc','provider_message_id'=>'approval-propose-cancel',
  'chat_id'=>$chat,'sender_id'=>$chat,'chat_type'=>'private','message_type'=>'chat',
  'body'=>'Crie outra cobrança','status'=>'received','auth_status'=>'authorized',
  'auth_reason'=>'user_authorized','received_at'=>now(),'ai_state'=>'queued']);
 $cancelPlanner=fn()=>['tool'=>'create_invoice','arguments'=>['client_id'=>$clientId,
  'description'=>'Cobrança que não existe','amount_cents'=>11100,'due_on'=>$today]];
 $prev=count($sent);AiWhatsAppWorker::tick(1,$cancelPlanner,$verifier,null,$sender,true);
 preg_match('/CANCELAR ([A-F0-9]{12})/',$sent[$prev][1],$cancelMatch);
 at(isset($cancelMatch[1]),'Cancel code created');
 DB::insert('ai_whatsapp_inbox',['session_name'=>'taskesc','provider_message_id'=>'approval-cancelled',
  'chat_id'=>$chat,'sender_id'=>$chat,'chat_type'=>'private','message_type'=>'chat',
  'body'=>'CANCELAR '.$cancelMatch[1],'status'=>'received','auth_status'=>'authorized',
  'auth_reason'=>'user_authorized','received_at'=>now(),'ai_state'=>'queued']);
 AiWhatsAppWorker::tick(1,$neverPlan,$verifier,null,$sender,true);
 at(!DB::one("SELECT id FROM invoices WHERE description='Cobrança que não existe'"),'Cancellation must not create invoice');

 // Record a payment only after a separate confirmation, rechecking its balance.
 $paymentReq=DB::insert('ai_whatsapp_inbox',['session_name'=>'taskesc','provider_message_id'=>'approval-pay-propose',
  'chat_id'=>$chat,'sender_id'=>$chat,'chat_type'=>'private','message_type'=>'chat',
  'body'=>'Registrar recebimento Pix','status'=>'received','auth_status'=>'authorized',
  'auth_reason'=>'user_authorized','received_at'=>now(),'ai_state'=>'queued']);
 $paymentPlanner=fn()=>['tool'=>'record_payment','arguments'=>['invoice_id'=>(int)$createdInvoice['id'],
  'amount_cents'=>10000,'paid_on'=>$today,'method'=>'Pix']];
 $prev=count($sent);AiWhatsAppWorker::tick(1,$paymentPlanner,$verifier,null,$sender,true);
 $paymentBefore=(int)(DB::one('SELECT COUNT(*) n FROM payments WHERE invoice_id=?',[$createdInvoice['id']])['n']??0);
 at($paymentBefore===0,'Proposed payment never settles without approval');
 preg_match('/CONFIRMAR ([A-F0-9]{12})/',$sent[$prev][1],$paymentMatch);
 at(isset($paymentMatch[1]),'Payment approval code created');
 DB::insert('ai_whatsapp_inbox',['session_name'=>'taskesc','provider_message_id'=>'approval-pay-confirm',
  'chat_id'=>$chat,'sender_id'=>$chat,'chat_type'=>'private','message_type'=>'chat',
  'body'=>'CONFIRMAR '.$paymentMatch[1],'status'=>'received','auth_status'=>'authorized',
  'auth_reason'=>'user_authorized','received_at'=>now(),'ai_state'=>'queued']);
 AiWhatsAppWorker::tick(1,$neverPlan,$verifier,null,$sender,true);
 at((int)DB::one('SELECT COALESCE(SUM(amount_cents),0) n FROM payments WHERE invoice_id=?',[$createdInvoice['id']])['n']===10000,'Payment confirmed exactly');
 at($llmUsed===0,'All confirmation actions bypass AI model quota');
 // Expense is registered pending, then separately marked as paid.
 $expenseBefore=(int)DB::one('SELECT COUNT(*) n FROM expenses')['n'];
 DB::insert('ai_whatsapp_inbox',['session_name'=>'taskesc','provider_message_id'=>'approval-expense-prepare',
  'chat_id'=>$chat,'sender_id'=>$chat,'chat_type'=>'private','message_type'=>'chat',
  'body'=>'Nova despesa','status'=>'received','auth_status'=>'authorized','auth_reason'=>'user_authorized',
  'received_at'=>now(),'ai_state'=>'queued']);
 $expensePlanner=fn()=>['tool'=>'create_expense','arguments'=>['description'=>'Despesa do teste com IA',
  'category'=>'Outros','amount_cents'=>2300,'spent_on'=>$today]];
 $prev=count($sent);AiWhatsAppWorker::tick(1,$expensePlanner,$verifier,null,$sender,true);
 at((int)DB::one('SELECT COUNT(*) n FROM expenses')['n']===$expenseBefore,'Expense proposal read-only');
 preg_match('/CONFIRMAR ([A-F0-9]{12})/',$sent[$prev][1],$mExpense);at(isset($mExpense[1]),'Expense code created');
 DB::insert('ai_whatsapp_inbox',['session_name'=>'taskesc','provider_message_id'=>'approval-expense-confirm',
  'chat_id'=>$chat,'sender_id'=>$chat,'chat_type'=>'private','message_type'=>'chat',
  'body'=>'CONFIRMAR '.$mExpense[1],'status'=>'received','auth_status'=>'authorized','auth_reason'=>'user_authorized',
  'received_at'=>now(),'ai_state'=>'queued']);
 AiWhatsAppWorker::tick(1,$neverPlan,$verifier,null,$sender,true);
 at((int)DB::one('SELECT COUNT(*) n FROM expenses')['n']===$expenseBefore+1,'Confirmed expense created once');
 $newExpense=DB::one("SELECT * FROM expenses WHERE description='Despesa do teste com IA' ORDER BY id DESC LIMIT 1");
 at($newExpense['payment_status']==='pending'&&(int)$newExpense['amount_cents']===2300,'Expense is pending at correct cents');
 DB::insert('ai_whatsapp_inbox',['session_name'=>'taskesc','provider_message_id'=>'approval-expense-paid-propose',
  'chat_id'=>$chat,'sender_id'=>$chat,'chat_type'=>'private','message_type'=>'chat',
  'body'=>'Marcar despesa paga','status'=>'received','auth_status'=>'authorized','auth_reason'=>'user_authorized',
  'received_at'=>now(),'ai_state'=>'queued']);
 $paidPlanner=fn()=>['tool'=>'mark_expense_paid','arguments'=>['expense_id'=>(int)$newExpense['id'],'paid_on'=>$today]];
 $prev=count($sent);AiWhatsAppWorker::tick(1,$paidPlanner,$verifier,null,$sender,true);
 at(DB::one('SELECT payment_status FROM expenses WHERE id=?',[$newExpense['id']])['payment_status']==='pending','Cannot pay expense without confirmation');
 preg_match('/CONFIRMAR ([A-F0-9]{12})/',$sent[$prev][1],$mPaid);at(isset($mPaid[1]),'Paid expense code created');
 DB::insert('ai_whatsapp_inbox',['session_name'=>'taskesc','provider_message_id'=>'approval-expense-paid-confirm',
  'chat_id'=>$chat,'sender_id'=>$chat,'chat_type'=>'private','message_type'=>'chat',
  'body'=>'CONFIRMAR '.$mPaid[1],'status'=>'received','auth_status'=>'authorized','auth_reason'=>'user_authorized',
  'received_at'=>now(),'ai_state'=>'queued']);
 AiWhatsAppWorker::tick(1,$neverPlan,$verifier,null,$sender,true);
 at(DB::one('SELECT payment_status FROM expenses WHERE id=?',[$newExpense['id']])['payment_status']==='paid','Expense paid status confirmed');

 // The model cannot even offer these actions to team members or clients.
 Auth::$user=DB::one('SELECT * FROM users WHERE id=?',[$staff]);Auth::$actor=Auth::$user;
 at(AiWhatsAppAdminActions::catalog(true)===[],'Staff cannot request admin financial operations');
 Auth::$user=DB::one('SELECT * FROM users WHERE id=?',[$customer]);Auth::$actor=Auth::$user;
 at(AiWhatsAppAdminActions::catalog(true)===[],'Client cannot request admin financial operations');
 Auth::$user=DB::one('SELECT * FROM users WHERE id=?',[$admin]);Auth::$actor=Auth::$user;

 // Task named in group: exact command, no @metadata, zero LLM requests, admin-only.
 $namedGroup=DB::insert('ai_whatsapp_inbox',['session_name'=>'taskesc','provider_message_id'=>'group-task-name-1',
  'chat_id'=>$groupId,'sender_id'=>$chat,'chat_type'=>'group','message_type'=>'chat','body'=>'Task, liste minhas tarefas',
  'status'=>'received','auth_status'=>'authorized','auth_reason'=>'group_admin_present','received_at'=>now(),
  'ai_state'=>'queued','ai_mentions_json'=>'[]']);
 $called=0;$noModel=static function()use(&$called):array{$called++;throw new RuntimeException('model called for name command');};
 $sentBefore=count($sent);AiWhatsAppWorker::tick(1,$noModel,$verifier,fn()=>false,$sender,true);
 at(DB::one('SELECT ai_state FROM ai_whatsapp_inbox WHERE id=?',[$namedGroup])['ai_state']==='sent','Task group message must be answered');
 at($called===0&&count($sent)===$sentBefore+1&&$sent[$sentBefore][2]===true,'Task group reply without model');
 $staffGroup=DB::insert('ai_whatsapp_inbox',['session_name'=>'taskesc','provider_message_id'=>'group-task-name-staff-1',
  'chat_id'=>$groupId,'sender_id'=>'5585999990011@c.us','chat_type'=>'group','message_type'=>'chat','body'=>'Task, liste minhas tarefas',
  'status'=>'received','auth_status'=>'authorized','auth_reason'=>'group_admin_present','received_at'=>now(),
  'ai_state'=>'queued','ai_mentions_json'=>'[]']);
 $staffCheck=static fn()=>['status'=>'authorized','user_id'=>$staff];
 AiWhatsAppWorker::tick(1,$noModel,$staffCheck,fn()=>false,$sender,true);
 at(DB::one('SELECT ai_state FROM ai_whatsapp_inbox WHERE id=?',[$staffGroup])['ai_state']==='ignored','Non-admin group request blocked');
 at(count($sent)===$sentBefore+1,'No group response to non-admin');
 Auth::$user=DB::one('SELECT * FROM users WHERE id=?',[$admin]);Auth::$actor=Auth::$user;

 $cal=DB::insert('calendars',['name'=>'Agenda de validação AI','access_role'=>'owner','selected'=>1,'write_enabled'=>1]);
 $day=(new DateTimeImmutable('+12 days',new DateTimeZone('America/Fortaleza')))->format('Y-m-d');
 $secretEvent=DB::insert('events',['calendar_id'=>$cal,'title'=>'Reunião confidencial','starts_at'=>timestamp($day.'T08:00:00'),
  'ends_at'=>timestamp($day.'T09:00:00'),'private'=>1,'created_at'=>now(),'updated_at'=>now()]);
 $openEvent=DB::insert('events',['calendar_id'=>$cal,'title'=>'Reunião pública','starts_at'=>timestamp($day.'T10:00:00'),
  'ends_at'=>timestamp($day.'T11:00:00'),'private'=>0,'created_at'=>now(),'updated_at'=>now()]);
 $groupNames=array_column(array_column(AiWhatsAppAgendaTools::catalog(false),'function'),'name');
 at($groupNames===['agenda_list'],'Group offers read-only public agenda');
 $privateNames=array_column(array_column(AiWhatsAppAgendaTools::catalog(true),'function'),'name');
 at(in_array('agenda_create',$privateNames,true)&&in_array('agenda_cancel',$privateNames,true),'Private ADM offers confirmed writes');
 $publicAgenda=AiWhatsAppAgendaTools::run('agenda_list',['from'=>$day,'to'=>$day],false);
 at(count($publicAgenda['rows'])===1&&(int)$publicAgenda['rows'][0]['id']===$openEvent,'Private event must never leak to group');
 $privateAgenda=AiWhatsAppAgendaTools::run('agenda_list',['from'=>$day,'to'=>$day],true);
 at(count($privateAgenda['rows'])===2,'Private admin can list both appointments');
 try{AiWhatsAppAgendaTools::run('agenda_detail',['event_id'=>$secretEvent],false);throw new RuntimeException('Private leaked');}
 catch(AppError $e){at($e->status===403,'Group cannot fetch event details');}
 at(AiWhatsAppAgendaTools::run('agenda_conflicts',['starts_at'=>$day.'T10:10','ends_at'=>$day.'T10:40'],true)['kind']==='agenda_conflicts','Can check availability');

 $agendaInput=['calendar_id'=>$cal,'title'=>'Compromisso por IA','starts_at'=>$day.'T14:00','ends_at'=>$day.'T15:00','private'=>true];
 $requestAgenda=DB::insert('ai_whatsapp_inbox',['session_name'=>'taskesc','provider_message_id'=>'agenda-prepare-1','chat_id'=>$chat,'sender_id'=>$chat,
  'chat_type'=>'private','message_type'=>'chat','body'=>'Criar reunião por IA','status'=>'received','auth_status'=>'authorized',
  'auth_reason'=>'user_authorized','received_at'=>now(),'ai_state'=>'queued']);
 $proposal=static fn()=>['tool'=>'agenda_create','arguments'=>$agendaInput];
 $sentBefore=count($sent);AiWhatsAppWorker::tick(1,$proposal,$verifier,null,$sender,true);
 at(!DB::one('SELECT id FROM events WHERE title=?',['Compromisso por IA']),'Agenda proposal does not persist before confirmation');
 preg_match('/CONFIRMAR ([A-F0-9]{12})/',$sent[$sentBefore][1],$code);
 at(isset($code[1]),'Agenda approval code generated');
 DB::insert('ai_whatsapp_inbox',['session_name'=>'taskesc','provider_message_id'=>'agenda-confirm-1','chat_id'=>$chat,'sender_id'=>$chat,
  'chat_type'=>'private','message_type'=>'chat','body'=>'CONFIRMAR '.$code[1],'status'=>'received',
  'auth_status'=>'authorized','auth_reason'=>'user_authorized','received_at'=>now(),'ai_state'=>'queued']);
 $calls=0;$noConfirmModel=static function()use(&$calls):array{$calls++;throw new RuntimeException('AI on confirmation');};
 AiWhatsAppWorker::tick(1,$noConfirmModel,$verifier,null,$sender,true);
 $created=DB::one('SELECT * FROM events WHERE title=?',['Compromisso por IA']);
 at($created&&(int)$created['private']===1&&$calls===0,'Confirmed agenda event saved once without model');
 $newId=(int)$created['id'];
 [$changed,$summary]=AiWhatsAppAgendaTools::prepare('agenda_update',['event_id'=>$newId,'title'=>'Reunião ajustada pela IA']);
 at((int)$changed['version']===(int)$created['version'],'Update uses optimistic version');
 AiWhatsAppAgendaTools::runConfirmed('agenda_update',$changed);
 at(DB::one('SELECT title FROM events WHERE id=?',[$newId])['title']==='Reunião ajustada pela IA','Agenda updated');
 try{AiWhatsAppAgendaTools::runConfirmed('agenda_update',$changed);throw new RuntimeException('Stale edit accepted');}
 catch(AppError $e){at($e->status===409,'Stale agenda edit rejected');}
 [$cancel,$summary]=AiWhatsAppAgendaTools::prepare('agenda_cancel',['event_id'=>$newId]);
 AiWhatsAppAgendaTools::runConfirmed('agenda_cancel',$cancel);
 at(DB::one('SELECT status FROM events WHERE id=?',[$newId])['status']==='cancelled','Agenda cancelled via existing domain');
 Auth::$user=DB::one('SELECT * FROM users WHERE id=?',[$staff]);Auth::$actor=Auth::$user;
 at(AiWhatsAppAgendaTools::catalog(true)===[],'Non-admin has no agenda admin tools');
 try{AiWhatsAppAgendaTools::run('agenda_list',[],true);throw new RuntimeException('Staff bypassed');}
 catch(AppError $e){at($e->status===403,'Staff denied from admin calendar');}
 Auth::$user=DB::one('SELECT * FROM users WHERE id=?',[$admin]);Auth::$actor=Auth::$user;
 // Persistent per-user intent cache: store/read interpretation, never stale business results.
 $memoryQuestion='Pode encontrar a tarefa chamada Aula Augusto?';
 $memoryPlan=['tool'=>'list_tasks','arguments'=>['query'=>'Aula']];
 $memTools=array_merge(AiWhatsAppTools::catalog(true),AiWhatsAppAdminActions::catalog(true),AiWhatsAppAgendaTools::catalog(true));
 $memoryBase=['session_name'=>'taskesc','chat_id'=>$chat,'sender_id'=>$chat,'chat_type'=>'private',
  'message_type'=>'chat','body'=>$memoryQuestion,'status'=>'received','auth_status'=>'authorized',
  'auth_user_id'=>$admin,'auth_reason'=>'user_authorized','received_at'=>now(),'ai_state'=>'queued'];
 $memoryMsg=DB::insert('ai_whatsapp_inbox',array_merge($memoryBase,['provider_message_id'=>'memory-first-1']));
 $cacheCalls=0;$cachePlanner=static function() use (&$cacheCalls,$memoryPlan):array {$cacheCalls++;return $memoryPlan;};
 $memBefore=count($sent);
 AiWhatsAppWorker::tick(1,$cachePlanner,$verifier,null,$sender,true);
 at($cacheCalls===1,'First unknown question consults LLM exactly once');
 at(DB::one('SELECT ai_state FROM ai_whatsapp_inbox WHERE id=?',[$memoryMsg])['ai_state']==='sent','First memory question answered');
 $stored=AiWhatsAppMemory::recall($memoryQuestion,$memoryBase,$admin,$memTools);
 at($stored===$memoryPlan,'Safe read-only interpretation persisted in idempotency store');
 at(count($sent)===$memBefore+1,'Model answer sent');
 $updatedTitle=DB::one('SELECT title FROM demands WHERE id=1')['title'];
 DB::run('UPDATE demands SET title=? WHERE id=1',['Aula Augusto ALTERADA']);
 DB::insert('ai_whatsapp_inbox',array_merge($memoryBase,['provider_message_id'=>'memory-second-1']));
 $cachedCalls=0;$unexpectedPlanner=static function() use (&$cachedCalls):array {$cachedCalls++;throw new RuntimeException('ai_model_should_not_be_called');};
 $memBefore=count($sent);
 AiWhatsAppWorker::tick(1,$unexpectedPlanner,$verifier,null,$sender,true);
 at($cachedCalls===0,'Second same question must NOT invoke model');
 at(count($sent)===$memBefore+1&&str_contains($sent[$memBefore][1],'Aula Augusto ALTERADA'),
  'Cache is interpretation only and reads fresh task data');
 DB::run('UPDATE demands SET title=? WHERE id=1',[$updatedTitle]);

 // Cache cannot cross different account, chat, group or revised tool permission set.
 at(AiWhatsAppMemory::recall($memoryQuestion,$memoryBase,$staff,$memTools)===null,'Other user cache isolated');
 $otherScope=$memoryBase;$otherScope['chat_id']='some-other-chat@c.us';
 at(AiWhatsAppMemory::recall($memoryQuestion,$otherScope,$admin,$memTools)===null,'Other chat isolated');
 at(AiWhatsAppMemory::recall($memoryQuestion,$memoryBase,$admin,[['function'=>['name'=>'list_tasks','description'=>'changed','parameters'=>[]]]])===null,
  'Catalog policy changes invalidate prior cached plan');
 foreach([
  'CONFIRMAR ABCDEF123456','Task, senha da API','O que tenho para amanhã?',
  'Quanto eu recebo este mês?'
 ] as $secretOrRelative)at(AiWhatsAppMemory::recall($secretOrRelative,$memoryBase,$admin,$memTools)===null,'Sensitive/relative cache disabled');
 AiWhatsAppMemory::remember('Crie uma nova task',$memoryBase,$admin,$memTools,['tool'=>'create_task','arguments'=>['title'=>'x']]);
 at(AiWhatsAppMemory::recall('Crie uma nova task',$memoryBase,$admin,$memTools)===null,'Never persist write action plans');

 // Human-friendly, one-time error reply after permanent failure.
 $badMsg=DB::insert('ai_whatsapp_inbox',array_merge($memoryBase,['provider_message_id'=>'memory-error-unparsed',
   'body'=>'Explica um pedido que a IA não entendeu']));
 $before=count($sent);
 AiWhatsAppWorker::tick(1,static fn()=>throw new RuntimeException('ai_invalid_response'),$verifier,null,$sender,true);
 at(DB::one('SELECT ai_state FROM ai_whatsapp_inbox WHERE id=?',[$badMsg])['ai_state']==='sent','Invalid response reported instead of silent failure');
 at(count($sent)===$before+1&&str_contains($sent[$before][1],'Não entendi'),'User gets actionable parse feedback');
 at((int)DB::one('SELECT ai_attempts FROM ai_whatsapp_inbox WHERE id=?',[$badMsg])['ai_attempts']===1,'Permanent model error does not burn retry quota');

 $quotaMsg=DB::insert('ai_whatsapp_inbox',array_merge($memoryBase,['provider_message_id'=>'memory-error-quota',
   'body'=>'Preciso de um relatório explicado pela IA']));
 $before=count($sent);
 AiWhatsAppWorker::tick(1,static fn()=>throw new RuntimeException('ai_provider_rate_limit'),$verifier,null,$sender,true);
 at(DB::one('SELECT ai_state FROM ai_whatsapp_inbox WHERE id=?',[$quotaMsg])['ai_state']==='sent','Rate limit reported');
 at(count($sent)===$before+1&&str_contains($sent[$before][1],'limite de requisições'),'Rate-limit reason shown');

 // HTTP 400 is a malformed provider request, not a network outage: no repeated billing.
 $formatMsg=DB::insert('ai_whatsapp_inbox',array_merge($memoryBase,[
  'provider_message_id'=>'memory-error-provider-format','body'=>'Faça análise de projeto sem mudar nada']));
 $prior=count($sent);
 AiWhatsAppWorker::tick(1,static fn()=>throw new RuntimeException('ai_provider_bad_request'),$verifier,null,$sender,true);
 $formatState=DB::one('SELECT ai_state,ai_attempts FROM ai_whatsapp_inbox WHERE id=?',[$formatMsg]);
 at($formatState['ai_state']==='sent'&&(int)$formatState['ai_attempts']===1,'Provider HTTP 400 must not be retried');
 at(count($sent)===$prior+1&&str_contains($sent[$prior][1],'formato'),'WhatsApp explains provider format error');

 // Transient transport failure retries twice, then delivers only one honest explanation.
 $transport=DB::insert('ai_whatsapp_inbox',array_merge($memoryBase,['provider_message_id'=>'memory-error-network',
   'body'=>'Dá detalhes completos de projeto específico']));
 $before=count($sent);
 for($n=1;$n<=3;$n++){
  AiWhatsAppWorker::tick(1,static fn()=>throw new RuntimeException('ai_connection_failed'),$verifier,null,$sender,true);
  $state=DB::one('SELECT ai_state,ai_attempts FROM ai_whatsapp_inbox WHERE id=?',[$transport]);
  at((int)$state['ai_attempts']===$n,'Correct retry count');
  if($n<3){
   at($state['ai_state']==='retry'&&count($sent)===$before,'No premature delivery during retry');
   DB::run('UPDATE ai_whatsapp_inbox SET ai_available_at=UTC_TIMESTAMP(6) WHERE id=?',[$transport]);
  }else{
   at($state['ai_state']==='sent'&&count($sent)===$before+1,'Terminal failure sends once');
   at(str_contains($sent[$before][1],'conexão falhou'),'Retry failure explained in Portuguese');
  }
 }
 at(AiWhatsAppWorker::tick(1,$unexpectedPlanner,$verifier,null,$sender,true)['processed']===0,
  'Terminal failure and cached read cannot replay');

 // Reproduce the user's real read-only WhatsApp question; no Gemini/Groq call.
 $sauloQuestion='Task, procure as tarefas relacionadas ao Saulo e me diga em quais etapas do Kanban elas estão. Não altere nenhuma tarefa.';
 $sauloPlan=AiWhatsAppFastPath::plan($sauloQuestion);
 at($sauloPlan===['tool'=>'list_tasks','arguments'=>['query'=>'saulo','limit'=>10]],'Generic Saulo request routes locally');
 $previousSaulo=DB::one('SELECT title FROM demands WHERE id=1');
 DB::run('UPDATE demands SET title=? WHERE id=1',['Material Saulo TESTE DE CONSULTA']);
 $sauloBeforeCount=(int)DB::one('SELECT COUNT(*) n FROM demands')['n'];
 $sauloResult=AiWhatsAppTools::run($sauloPlan['tool'],$sauloPlan['arguments']);
 at(($sauloResult['kind']??'')==='list'&&count(array_filter($sauloResult['tasks'],
  static fn($t)=>(int)$t['id']===1&&str_contains((string)$t['title'],'Saulo')))===1,
  'Saulo question returns authorized tasks and their Kanban stages');
 at((int)DB::one('SELECT COUNT(*) n FROM demands')['n']===$sauloBeforeCount,
  'Read-only question cannot create or delete tasks');
 DB::run('UPDATE demands SET title=? WHERE id=1',[$previousSaulo['title']]);

 // Model-free stage-filtered query remains scoped to the authorized TaskEsc API.
 $classroomRequest='Task, encontre as tarefas relacionadas à edição de aulas e me diga quais ainda estão em produção';
 $classroomPlan=AiWhatsAppFastPath::plan($classroomRequest);
 at(is_array($classroomPlan)&&($classroomPlan['tool']??'')==='list_tasks','Classroom query uses local read plan');
 $classroomResult=AiWhatsAppTools::run('list_tasks',$classroomPlan['arguments']);
 at(($classroomResult['kind']??'')==='list','Classroom query returns live API records');
 foreach($classroomResult['tasks'] as $d){
  at(($d['stage']??'')==='Em produção','Only tasks in requested Kanban stage');
  at(str_contains(mb_strtolower((string)$d['title']),'aula'),'Only class-related task titles');
 }
 // If the requested Kanban column is empty, show only authorized matches from other columns.
 $previousRecord=DB::one('SELECT title,stage_id FROM demands WHERE id=1');
 $otherStage=DB::one("SELECT id FROM stages WHERE active=1 AND name<>'Em produção' AND id<>? ORDER BY id LIMIT 1",[$previousRecord['stage_id']]);
 at((bool)$otherStage,'CI has a non-production stage');
 DB::run('UPDATE demands SET title=?,stage_id=? WHERE id=1',['Aula TESTE ALTERNATIVA SEM MATCH', $otherStage['id']]);
 $alternative=AiWhatsAppTools::run('list_tasks',[
  'query'=>'Aula TESTE ALTERNATIVA SEM MATCH','stage_name'=>'Em produção','show_other_stages'=>true,'limit'=>10]);
 at(($alternative['kind']??'')==='list'&&$alternative['total']===0,'Production match is really empty');
 at(($alternative['alternative_total']??0)>=1,'Alternative stage match visible');
 at(count(array_filter($alternative['alternatives']??[],static fn($t)=>(int)$t['id']===1))===1,
  'Alternative matching task is returned with real Kanban status');
 DB::run('UPDATE demands SET title=?,stage_id=? WHERE id=1',[$previousRecord['title'],$previousRecord['stage_id']]);
 try{AiWhatsAppTools::run('list_tasks',['query'=>'aula','show_other_stages'=>true]);throw new RuntimeException('Missing stage accepted');}
 catch(AppError $e){at($e->status===422,'Alternatives require explicit stage');}
 try{AiWhatsAppTools::run('list_tasks',['query'=>'aula','stage_name'=>'Em produção','show_other_stages'=>'yes']);throw new RuntimeException('Wrong type accepted');}
 catch(AppError $e){at($e->status===422,'Alternatives flag requires boolean');}

  try{AiWhatsAppTools::run('list_tasks',['query'=>'aula','stage_name'=>'Etapa inexistente']);throw new RuntimeException('Invalid stage used');}
 catch(AppError $e){at($e->getMessage()!=='','Stage names resolved only from active DB stages');}
 // Production-style catalog: ADM private tools include no-argument calendar/team functions.
 Auth::$user=DB::one('SELECT * FROM users WHERE id=?',[$admin]);Auth::$actor=Auth::$user;
 $fullCatalog=array_merge(AiWhatsAppTools::catalog(true),AiWhatsAppAdminActions::catalog(true),AiWhatsAppAgendaTools::catalog(true));
 $toolNames=array_column(array_column($fullCatalog,'function'),'name');
 foreach(['agenda_calendars','list_team','list_events'] as $expectedTool)
  at(in_array($expectedTool,$toolNames,true),'Expected zero-argument production tool '.$expectedTool);
 $fullRequest=AiWhatsAppGemini::requestBody('Analise demandas',[], $fullCatalog);
 $gemFunctions=$fullRequest['tools'][0]['functionDeclarations'];
 foreach($gemFunctions as $declaration)if(in_array($declaration['name'],['agenda_calendars','list_team','list_events'],true))
  at(!isset($declaration['parameters']),'No-argument Gemini function must omit optional parameters');
 $normalized=AiWhatsAppToolSchemas::forGroq($fullCatalog);
 $normalizedJson=json($normalized);
 at(!str_contains($normalizedJson,'"properties":[]'),'Full Groq catalog has no invalid empty list for object properties');
 foreach($normalized as $tool){
  $parameters=$tool['function']['parameters'];
  if(($parameters['type']??null)==='object'&&($fullCatalog[array_search($tool['function']['name'],$toolNames,true)]['function']['parameters']['properties']??null)===[])
   at($parameters['properties'] instanceof stdClass,'Groq no-argument tool schema must be JSON object');
 }
 at(count($normalized)===count($fullCatalog),'Schema normalization must preserve authorized tool catalog');

 // User-requested business attention analysis: live TaskEsc reads, no extra AI calls.
 Auth::$user=DB::one('SELECT * FROM users WHERE id=?',[$admin]);Auth::$actor=Auth::$user;
 $attentionCatalog=AiWhatsAppTools::catalog(true);
 at(in_array('attention_report',array_column(array_column($attentionCatalog,'function'),'name'),true),
  'Read-only attention report is offered to authorized user');
 $demandsBefore=(int)DB::one('SELECT COUNT(*) n FROM demands')['n'];
 $attentionRaw=AiWhatsAppTools::run('attention_report',[]);
 at(($attentionRaw['kind']??'')==='attention_report','Attention report fetches the live TaskEsc API');
 $attentionOutput=AiWhatsAppAttentionReport::format($attentionRaw);
 at(str_contains($attentionOutput,'Análise de demandas')&&str_contains($attentionOutput,'Nenhuma tarefa foi alterada.'),
  'Attention report is analytical and explicitly read-only');
 at((int)DB::one('SELECT COUNT(*) n FROM demands')['n']===$demandsBefore,
  'Attention analysis has no task-creation or task-deletion side effect');
 $attentionBody='Task, analise minhas demandas e me diga quais projetos precisam da minha atenção, considerando prazos, prioridades e andamento. Não altere nada.';
 $attentionMsg=DB::insert('ai_whatsapp_inbox',[
  'session_name'=>'taskesc','provider_message_id'=>'attention-report-test-1',
  'chat_id'=>$chat,'sender_id'=>$chat,'chat_type'=>'private','message_type'=>'chat',
  'body'=>$attentionBody,'status'=>'received','auth_status'=>'authorized',
  'auth_reason'=>'user_authorized','received_at'=>now(),'ai_state'=>'queued']);
 $attentionBefore=count($sent);$attentionModelCalls=0;
 $notNeededModel=static function()use(&$attentionModelCalls){$attentionModelCalls++;throw new RuntimeException('Model should not be called');};
 AiWhatsAppWorker::tick(1,$notNeededModel,$verifier,null,$sender,true);
 at($attentionModelCalls===0,'User intent analysis must not consume Gemini/Groq credits');
 at(DB::one('SELECT ai_state FROM ai_whatsapp_inbox WHERE id=?',[$attentionMsg])['ai_state']==='sent',
  'WhatsApp analysis dispatched exactly once');
 at(count($sent)===$attentionBefore+1&&str_contains($sent[$attentionBefore][1],'Análise de demandas'),
  'WhatsApp shows analytical report rather than 30-task raw list');
 at((int)DB::one('SELECT COUNT(*) n FROM demands')['n']===$demandsBefore,'No business mutation from WhatsApp analysis');


 // AI usage step 3: caps stay OFF until ADM explicitly enables; schema remains 29.
 Auth::$user=DB::one('SELECT * FROM users WHERE id=?',[$admin]);Auth::$actor=Auth::$user;
 Auth::$origin='web';Auth::$previewClientId=null;
 at(AiWhatsAppUsagePolicy::current()['enabled']===false,'Usage enforcement must default OFF');
 at(AiWhatsAppUsagePolicy::current()['retention_days']===30,'Minimum retention defaults to 30d');
 AiWhatsAppUsagePolicy::reserve('gemini','2001-01-19'); // disabled, no counter
 at(AiWhatsAppUsagePolicy::reservedToday('2001-01-19')['gemini']===0,'Disabled caps cannot count or block requests');
 try{AiWhatsAppUsagePolicy::save(['retention_days'=>2]);throw new RuntimeException('Unsafe retention accepted');}
 catch(AppError $e){at($e->status===422,'Retention below 30 days rejected');}
 try{AiWhatsAppUsagePolicy::save(['alert_percent'=>10]);throw new RuntimeException('Unsafe alert accepted');}
 catch(AppError $e){at($e->status===422,'Alert percentage validated');}
 $saved=AiWhatsAppUsagePolicy::save(['enabled'=>true,'gemini_daily_calls'=>2,'groq_daily_calls'=>1,
  'alert_percent'=>50,'retention_days'=>30]);
 at($saved['policy']['enabled']===true,'ADMIN explicitly enables limits');
 AiWhatsAppUsagePolicy::reserve('gemini','2001-01-19');
 $warn=AiWhatsAppUsagePolicy::notifyAdmins('2001-01-19');
 at(($warn['alerts']['gemini']['level']??null)==='warning','50 percent generates one warning');
 at(($warn['notifications_created']??0)>=1,'ADM receives in-app warning');
 at(AiWhatsAppUsagePolicy::notifyAdmins('2001-01-19')['notifications_created']===0,
  'Repeated cron run does not duplicate alerts');
 AiWhatsAppUsagePolicy::reserve('gemini','2001-01-19');
 AiWhatsAppUsagePolicy::reserve('groq','2001-01-19');
 try{AiWhatsAppUsagePolicy::reserve('gemini','2001-01-19');throw new RuntimeException('Gemini quota exceeded');}
 catch(RuntimeException $e){at($e->getMessage()==='ai_usage_daily_limit','Gemini hard cap enforced atomically');}
 try{AiWhatsAppUsagePolicy::reserve('groq','2001-01-19');throw new RuntimeException('Groq quota exceeded');}
 catch(RuntimeException $e){at($e->getMessage()==='ai_usage_daily_limit','Groq hard cap enforced separately');}
 at(AiWhatsAppUsagePolicy::reservedToday('2001-01-19')===['gemini'=>2,'groq'=>1],
  'Denied requests do not increment counters');
 $limitAlert=AiWhatsAppUsagePolicy::notifyAdmins('2001-01-19');
 at(($limitAlert['alerts']['gemini']['level']??null)==='limit','Limit threshold detected');
 at(($limitAlert['notifications_created']??0)>=1,'ADM notified when limit reached');
 at(AiWhatsAppUsagePolicy::notifyAdmins('2001-01-19')['notifications_created']===0,'No alert spam');
 at(AiWhatsAppProvider::fallbackEligible('ai_usage_daily_limit'),'Other configured provider can be tried');
 at(!AiWhatsAppErrors::retryable(new RuntimeException('ai_usage_daily_limit')),
  'Quota failure cannot repeatedly consume AI budget');
 $oldPath=AiWhatsAppUsageMetrics::fileForDay('2001-01-05');
 $keepPath=ROOT.'/storage/logs/ai-usage-2001-04-25.jsonl';
 @mkdir(ROOT.'/storage/logs',0700,true);
 file_put_contents($oldPath,"{}\n");file_put_contents($keepPath,"{}\n");
 $clean=AiWhatsAppUsagePolicy::cleanup(new DateTimeImmutable('2001-05-01',new DateTimeZone('UTC')));
 at(!is_file($oldPath)&&is_file($keepPath),'Expired telemetry deleted, recent records retained');
 at($clean['files_deleted']>=1&&$clean['counters_deleted']>=2,'Only expired counters and files deleted');
 @unlink($keepPath);
 $priorActor=Auth::$user;
 Auth::$user=['id'=>9999,'role'=>'staff','active'=>1];Auth::$actor=Auth::$user;
 try{AiWhatsAppUsagePolicy::read();throw new RuntimeException('Staff accessed settings');}
 catch(AppError $e){at($e->status===403,'Staff forbidden from governance');}
 Auth::$user=$priorActor;Auth::$actor=$priorActor;
 Auth::$previewClientId=999;
 try{AiWhatsAppUsagePolicy::read();throw new RuntimeException('Preview accessed settings');}
 catch(AppError $e){at($e->status===403,'Client preview forbidden');}
 Auth::$previewClientId=null;
 AiWhatsAppUsagePolicy::save(['enabled'=>false,'gemini_daily_calls'=>0,'groq_daily_calls'=>0,
  'alert_percent'=>80,'retention_days'=>30]);
 at(setting('schema_version')==='29','Monitoring does not migrate or recreate schema');
 // Financial conversation: short follow-up uses only this ADM/private history, not another chat or user.
 Auth::$user=DB::one('SELECT * FROM users WHERE id=?',[$admin]);Auth::$actor=Auth::$user;
 Auth::$origin='whatsapp';Auth::$previewClientId=null;
 at(AiWhatsAppFinancialConversation::pendingRequest('Task me manda o total das Despesas/custos pendentes')==='needs_period',
  'Initial totals question must request the missing period');
 at(AiWhatsAppFinancialConversation::pendingRequest('Task me manda o total das Despesas/custos pendentes esse mês')==='month',
  'Explicit monthly request must be recognized without Gemini');
 at(AiWhatsAppFinancialConversation::pendingRequest('Task me manda o total das Despesas/custos pendentes e apaga tudo')===null,
  'Financial read-only fast path rejects additional writes');
 at(AiWhatsAppFinancialConversation::plan('esse mês',[],(int)$admin,false)===null,
  'Finance follow-up cannot run outside verified ADM private access');
 $financialFrom=(new DateTimeImmutable('now',new DateTimeZone('America/Fortaleza')))->format('Y-m-01');
 $financialTo=(new DateTimeImmutable('now',new DateTimeZone('America/Fortaleza')))->format('Y-m-t');
 $financeDay=$financialFrom;
 $baselineFinance=TaskEscFinanceReadApi::expenses(['from'=>$financialFrom,'to'=>$financialTo,'payment_status'=>'pending']);
 $expectedFinanceTotal=(int)$baselineFinance['total_cents']+33850;
 $expectedFinanceCount=(int)$baselineFinance['count']+4;
 $expectedBrl='R$ '.number_format($expectedFinanceTotal/100,2,',','.');
 $sampleFinance=[
  ['description'=>'Custo pendente 1','amount_cents'=>1990,'payment_status'=>'pending'],
  ['description'=>'Custo pendente 2','amount_cents'=>10470,'payment_status'=>'pending'],
  ['description'=>'Custo pendente 3','amount_cents'=>11400,'payment_status'=>'pending'],
  ['description'=>'Custo pendente 4','amount_cents'=>9990,'payment_status'=>'pending'],
  ['description'=>'Custo pago excluído','amount_cents'=>7500,'payment_status'=>'paid']
 ];
 foreach($sampleFinance as $entry)
  DB::insert('expenses',[
   'description'=>$entry['description'],'vendor'=>'Fornecedor teste',
   'category'=>'Software','amount_cents'=>$entry['amount_cents'],
   'spent_on'=>$financeDay,'payment_status'=>$entry['payment_status'],
   'created_by'=>$admin,'created_at'=>now(),'updated_at'=>now()
  ]);
 $finArgs=['from'=>$financialFrom,'to'=>$financialTo,'payment_status'=>'pending','limit'=>2];
 $fin=TaskEscFinanceReadApi::expenses($finArgs);
 at($fin['pending_total_cents']===$expectedFinanceTotal&&$fin['total_cents']===$expectedFinanceTotal&&$fin['count']===$expectedFinanceCount,
  'Pending financial amount must sum all 4 records, independent of display limit 2');
 at(count($fin['rows'])===2&&count(array_filter($fin['rows'],static fn($x)=>$x['paid']))===0,
  'Pending-only list excludes paid expenses and respects limit');
 $finReply=(new ReflectionMethod(AiWhatsAppWorker::class,'formatResult'))->invoke(null,$fin);
 at(str_contains($finReply,$expectedBrl)&&str_contains($finReply,'Exibindo 2 de '.$expectedFinanceCount),
  'WhatsApp must show full pending total before truncated details');
 $prevCount=count($sent);
 $financeQ=DB::insert('ai_whatsapp_inbox',[
  'session_name'=>'taskesc','provider_message_id'=>'financial-question-missing-period',
  'chat_id'=>$chat,'sender_id'=>$chat,'auth_user_id'=>$admin,
  'chat_type'=>'private','message_type'=>'chat',
  'body'=>'Task me manda o total das Despesas/custos pendentes',
  'status'=>'received','auth_status'=>'authorized','auth_reason'=>'user_authorized',
  'received_at'=>(new DateTimeImmutable('now',new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u'),
  'ai_state'=>'queued'
 ]);
 $financeAiCalls=0;
 $denyAi=static function()use(&$financeAiCalls){$financeAiCalls++;throw new RuntimeException('No AI needed for financial follow-up');};
 AiWhatsAppWorker::tick(1,$denyAi,$verifier,null,$sender,true);
 $storedQ=DB::one('SELECT ai_state,ai_reply FROM ai_whatsapp_inbox WHERE id=?',[$financeQ]);
 at($storedQ['ai_state']==='sent'&&str_contains($storedQ['ai_reply'],'período'),
  'Missing period must prompt just once for period');
 $financeFollowup=DB::insert('ai_whatsapp_inbox',[
  'session_name'=>'taskesc','provider_message_id'=>'financial-follow-up-month',
  'chat_id'=>$chat,'sender_id'=>$chat,'auth_user_id'=>$admin,
  'chat_type'=>'private','message_type'=>'chat','body'=>'esse mês',
  'status'=>'received','auth_status'=>'authorized','auth_reason'=>'user_authorized',
  'received_at'=>(new DateTimeImmutable('now',new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u'),
  'ai_state'=>'queued'
 ]);
 AiWhatsAppWorker::tick(1,$denyAi,$verifier,null,$sender,true);
 $storedFinance=DB::one('SELECT ai_state,ai_reply FROM ai_whatsapp_inbox WHERE id=?',[$financeFollowup]);
 at($financeAiCalls===0&&$storedFinance['ai_state']==='sent',
  'Contextual monthly answer must run without calling model');
 at(str_contains($storedFinance['ai_reply'],$expectedBrl)&&str_contains($storedFinance['ai_reply'],'Despesas/custos pendentes'),
  'Contextual monthly reply must use live pending-only financial totals');
 $wrongRow=DB::one('SELECT * FROM ai_whatsapp_inbox WHERE id=?',[$financeFollowup]);
 $wrongRow['chat_id']='5585000000000@c.us';
 at(AiWhatsAppFinancialConversation::plan('esse mês',$wrongRow,$admin,true)===null,
  'Follow-up cannot inherit another chat context');
 $wrongRow['chat_id']=$chat;
 at(AiWhatsAppFinancialConversation::plan('esse mês',$wrongRow,$admin+100,true)===null,
  'Follow-up cannot inherit another user context');
 at(count($sent)===$prevCount+2,'Two messages result in two responses, no extra sends');


 // Conversational context: last private authorized READ, 10 minute TTL,
 // never a stored result, and no model calls for short follow-ups.
 Auth::$user=DB::one('SELECT * FROM users WHERE id=?',[$admin]);Auth::$actor=Auth::$user;
 $ctxTools=array_merge(AiWhatsAppTools::catalog(true),AiWhatsAppAdminActions::catalog(true),AiWhatsAppAgendaTools::catalog(true));
 $ctxSeedBase=['session_name'=>'taskesc','chat_id'=>$chat,'sender_id'=>$chat,
  'chat_type'=>'private','message_type'=>'chat','status'=>'received',
  'auth_user_id'=>$admin,'auth_status'=>'authorized','auth_reason'=>'user_authorized',
  'received_at'=>now(),'ai_state'=>'queued'];
 $ctxNoModelCalls=0;
 $ctxNoModel=static function()use(&$ctxNoModelCalls){$ctxNoModelCalls++;throw new RuntimeException('No LLM necessary for validated follow-up');};
 $ctxTasksId=DB::insert('ai_whatsapp_inbox',array_merge($ctxSeedBase,[
  'provider_message_id'=>'context-tasks-first-1','body'=>'Liste minhas tarefas']));
 $ctxTasksBefore=count($sent);
 AiWhatsAppWorker::tick(1,$ctxNoModel,$verifier,null,$sender,true);
 at(DB::one('SELECT ai_state FROM ai_whatsapp_inbox WHERE id=?',[$ctxTasksId])['ai_state']==='sent',
  'Initial local tasks query sent');
 $ctxFollowId=DB::insert('ai_whatsapp_inbox',array_merge($ctxSeedBase,[
  'provider_message_id'=>'context-tasks-saulo-2','body'=>'e as do Saulo?']));
 $ctxFollowRow=DB::one('SELECT * FROM ai_whatsapp_inbox WHERE id=?',[$ctxFollowId]);
 $ctxFollowPlan=AiWhatsAppConversationContext::plan('e as do Saulo?',$ctxFollowRow,$admin,$ctxTools);
 at(($ctxFollowPlan['tool']??null)==='list_tasks'&&($ctxFollowPlan['arguments']['query']??null)==='saulo',
  'Follow-up recovers latest tasks tool and changes only query term');
 $crossChat=$ctxFollowRow;$crossChat['chat_id']='5585000000000@c.us';
 at(AiWhatsAppConversationContext::plan('e as do Saulo?',$crossChat,$admin,$ctxTools)===null,
  'No cross-chat memory');
 at(AiWhatsAppConversationContext::plan('e as do Saulo?',$ctxFollowRow,$admin+11,$ctxTools)===null,
  'No cross-user memory');
 $groupMemory=$ctxFollowRow;$groupMemory['chat_type']='group';
 at(AiWhatsAppConversationContext::plan('e as do Saulo?',$groupMemory,$admin,$ctxTools)===null,
  'No group memory or private information in group');
 at(AiWhatsAppConversationContext::plan('e as do Saulo?',$ctxFollowRow,$admin,[])===null,
  'Tool catalog and permission change invalidates memory');
 at(AiWhatsAppConversationContext::plan('e as do Saulo? e exclua a task #1',$ctxFollowRow,$admin,$ctxTools)===null,
  'Write instruction mixed into follow-up cannot reuse read-only memory');
 AiWhatsAppWorker::tick(1,$ctxNoModel,$verifier,null,$sender,true);
 at(DB::one('SELECT ai_state FROM ai_whatsapp_inbox WHERE id=?',[$ctxFollowId])['ai_state']==='sent',
  'Follow-up dispatched without network AI');
 $ctxOverdueId=DB::insert('ai_whatsapp_inbox',array_merge($ctxSeedBase,[
  'provider_message_id'=>'context-tasks-late-3','body'=>'só as atrasadas']));
 $ctxLateRow=DB::one('SELECT * FROM ai_whatsapp_inbox WHERE id=?',[$ctxOverdueId]);
 $ctxLatePlan=AiWhatsAppConversationContext::plan('só as atrasadas',$ctxLateRow,$admin,$ctxTools);
 at(($ctxLatePlan['arguments']['overdue']??null)===true&&($ctxLatePlan['arguments']['query']??null)==='saulo',
  'Late filter preserves topic and respects current backend overdue constraint');
 AiWhatsAppWorker::tick(1,$ctxNoModel,$verifier,null,$sender,true);
 $ctxLateReply=DB::one('SELECT ai_state,ai_reply FROM ai_whatsapp_inbox WHERE id=?',[$ctxOverdueId]);
 at($ctxLateReply['ai_state']==='sent','Overdue tasks resolved without AI');
 $ctxClosedId=DB::insert('ai_whatsapp_inbox',array_merge($ctxSeedBase,[
  'provider_message_id'=>'context-tasks-done-4','body'=>'e as concluídas?']));
 $ctxClosedRow=DB::one('SELECT * FROM ai_whatsapp_inbox WHERE id=?',[$ctxClosedId]);
 $ctxClosedPlan=AiWhatsAppConversationContext::plan('e as concluídas?',$ctxClosedRow,$admin,$ctxTools);
 at(($ctxClosedPlan['arguments']['stage_name']??null)==='Concluídas',
  'Completed stage is interpreted as a filter, never a move operation');
 AiWhatsAppWorker::tick(1,$ctxNoModel,$verifier,null,$sender,true);
 at($ctxNoModelCalls===0&&count($sent)===$ctxTasksBefore+4,
  'All four independent WhatsApp reads produced replies without calling LLM');
 $ctxUnsafe=DB::one('SELECT * FROM ai_whatsapp_inbox WHERE id=?',[$ctxClosedId]);
 AiWhatsAppConversationContext::remember($ctxUnsafe,$admin,$ctxTools,['tool'=>'delete_task','arguments'=>['task_id'=>1]]);
 // Assert malicious writes cannot replace the last stored safe plan.
 at(AiWhatsAppConversationContext::plan('e as do Saulo?',$ctxUnsafe,$admin,$ctxTools)===null,
  'Current message cannot reuse its own context');
 // A text-only reply or intervening message terminates the previous topic.
 $ctxBreakId=DB::insert('ai_whatsapp_inbox',array_merge($ctxSeedBase,[
  'provider_message_id'=>'context-break-5','body'=>'novo assunto','ai_state'=>'sent']));
 $ctxAfterBreak=DB::insert('ai_whatsapp_inbox',array_merge($ctxSeedBase,[
  'provider_message_id'=>'context-break-6','body'=>'e as do Saulo?']));
 $ctxAfterBreakRow=DB::one('SELECT * FROM ai_whatsapp_inbox WHERE id=?',[$ctxAfterBreak]);
 at(AiWhatsAppConversationContext::plan('e as do Saulo?',$ctxAfterBreakRow,$admin,$ctxTools)===null,
  'Never skip an intervening message to find stale context');
 // Context after new agenda read: "e amanhã?" preserves read scope and re-reads.
 $ctxAgendaSeed=DB::insert('ai_whatsapp_inbox',array_merge($ctxSeedBase,[
  'provider_message_id'=>'context-agenda-7','body'=>'Liste meus compromissos hoje','ai_state'=>'sent']));
 $ctxAgendaRow=DB::one('SELECT * FROM ai_whatsapp_inbox WHERE id=?',[$ctxAgendaSeed]);
 AiWhatsAppConversationContext::remember($ctxAgendaRow,$admin,$ctxTools,[
  'tool'=>'agenda_list','arguments'=>['from'=>date('Y-m-d'),'to'=>date('Y-m-d')]]);
 $ctxAgendaFollow=DB::insert('ai_whatsapp_inbox',array_merge($ctxSeedBase,[
  'provider_message_id'=>'context-agenda-8','body'=>'e amanhã']));
 $ctxAgendaNext=AiWhatsAppConversationContext::plan('e amanhã',
  DB::one('SELECT * FROM ai_whatsapp_inbox WHERE id=?',[$ctxAgendaFollow]),$admin,$ctxTools);
 $nextDate=(new DateTimeImmutable('tomorrow',new DateTimeZone('America/Fortaleza')))->format('Y-m-d');
 at(($ctxAgendaNext['tool']??null)==='agenda_list'&&($ctxAgendaNext['arguments']['from']??null)===$nextDate&&
  ($ctxAgendaNext['arguments']['to']??null)===$nextDate,'Agenda next day preserves private read intent');
 // Finance-only follow-up preserves actual provider-side date filters.
 $ctxFinanceSeed=DB::insert('ai_whatsapp_inbox',array_merge($ctxSeedBase,[
  'provider_message_id'=>'context-finance-9','body'=>'despesas do mês','ai_state'=>'sent']));
 $ctxFinanceRow=DB::one('SELECT * FROM ai_whatsapp_inbox WHERE id=?',[$ctxFinanceSeed]);
 AiWhatsAppConversationContext::remember($ctxFinanceRow,$admin,$ctxTools,[
  'tool'=>'finance_expenses','arguments'=>['from'=>$financialFrom,'to'=>$financialTo,'payment_status'=>'pending']]);
 $ctxFinanceFollow=DB::insert('ai_whatsapp_inbox',array_merge($ctxSeedBase,[
  'provider_message_id'=>'context-finance-10','body'=>'e só as pagas']));
 $ctxFinanceNext=AiWhatsAppConversationContext::plan('e só as pagas',
  DB::one('SELECT * FROM ai_whatsapp_inbox WHERE id=?',[$ctxFinanceFollow]),$admin,$ctxTools);
 at(($ctxFinanceNext['arguments']['payment_status']??null)==='paid'&&
  ($ctxFinanceNext['arguments']['from']??null)===$financialFrom,
  'Finance follow-up safely retains period but changes payment status only');
 $ctxFinanceGroup=DB::one('SELECT * FROM ai_whatsapp_inbox WHERE id=?',[$ctxFinanceFollow]);
 $ctxFinanceGroup['chat_type']='group';
 at(AiWhatsAppConversationContext::plan('e só as pagas',$ctxFinanceGroup,$admin,$ctxTools)===null,
  'Financial context cannot leak to a group');
 echo "AI WhatsApp tools: schema, create once, edit once, protected fields, self assignment, category default, client denial, private reply, group guard OK\n";
}finally{@unlink(ROOT.'/config/config.php');@unlink(ROOT.'/storage/installed.lock');}
