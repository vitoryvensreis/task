<?php
declare(strict_types=1);
define('ROOT',dirname(__DIR__));
$port=getenv('OUTBOX_TEST_PORT');if(!$port)throw new RuntimeException('CI database required');
@unlink(ROOT.'/config/config.php');
$c=['base_url'=>'https://task.escotilhaprodutora.com','timezone'=>'America/Fortaleza','db'=>['host'=>'127.0.0.1','port'=>(int)$port,'name'=>'taskesc_test','user'=>'root','password'=>'taskesc-ci-only'],'app_key'=>bin2hex(random_bytes(32)),'ai_whatsapp'=>['enabled'=>false,'groq_api_key'=>'']];
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

 echo "AI WhatsApp tools: schema, create once, edit once, protected fields, private reply, group guard OK\n";
}finally{@unlink(ROOT.'/config/config.php');@unlink(ROOT.'/storage/installed.lock');}
