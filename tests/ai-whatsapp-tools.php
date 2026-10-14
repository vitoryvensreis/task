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

 echo "AI WhatsApp tools: schema, create once, private reply, group mention guard OK\n";
}finally{@unlink(ROOT.'/config/config.php');@unlink(ROOT.'/storage/installed.lock');}
