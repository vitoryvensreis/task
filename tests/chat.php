<?php
declare(strict_types=1);

define('ROOT',dirname(__DIR__));
$port=getenv('OUTBOX_TEST_PORT');
if(!$port)throw new RuntimeException('Run only against the disposable CI database.');

$configPath=ROOT.'/config/config.php';
if(is_file($configPath))throw new RuntimeException('Refusing to overwrite an existing config.');
$config=[
 'base_url'=>'https://task.escotilhaprodutora.com',
 'timezone'=>'America/Fortaleza',
 'db'=>['host'=>'127.0.0.1','port'=>(int)$port,'name'=>'taskesc_test','user'=>'root','password'=>'taskesc-ci-only'],
 'app_key'=>bin2hex(random_bytes(32)),
];
file_put_contents($configPath,"<?php\nreturn ".var_export($config,true).";\n");
@mkdir(ROOT.'/storage/sessions',0700,true);
touch(ROOT.'/storage/installed.lock');

require ROOT.'/app/bootstrap.php';

function chatCheck(bool $condition,string $message): void {if(!$condition)throw new RuntimeException($message);}
function chatReject(callable $fn,int $status,string $message): void {
 try{$fn();}catch(AppError $e){chatCheck($e->status===$status,$message.' (status)');return;}
 throw new RuntimeException($message);
}
function chatUser(int $id): array {
 $u=DB::one('SELECT id,name,email,role,active,email_notifications,weekly_report,twofa_enabled,last_login_at FROM users WHERE id=?',[$id]);
 if(!$u)throw new RuntimeException('Missing chat test user.');
 $u['is_preview']=0;return $u;
}
function chatAs(int $id): void {
 Auth::$user=chatUser($id);Auth::$actor=Auth::$user;Auth::$previewClientId=null;Auth::$origin='web';Auth::$scopes='tools.read tools.write';
}

try{
 $db=DB::conn();
 $db->exec('SET FOREIGN_KEY_CHECKS=0');
 foreach(DB::all('SHOW TABLES') as $row){$table=(string)array_values($row)[0];if(!preg_match('/\A[A-Za-z0-9_]+\z/D',$table))throw new RuntimeException('Unexpected table.');$db->exec('DROP TABLE `'.$table.'`');}
 $db->exec('SET FOREIGN_KEY_CHECKS=1');
 $db->exec(file_get_contents(ROOT.'/database/schema.sql'));

 chatCheck(!Migrations::chatIssues(),'Fresh schema 22 must contain a valid chat structure.');
 $db->exec('DROP TABLE chat_messages');
 $db->exec('DROP TABLE chat_threads');
 DB::run("UPDATE settings SET value='21' WHERE `key`='schema_version'");
 $migration=Migrations::chat();
 chatCheck($migration['previous']===21&&$migration['current']===22&&$migration['changed']===true,'Schema 21 must upgrade to 22.');
 chatCheck(!Migrations::chatIssues(),'Migrated chat schema must validate.');
 chatCheck(Migrations::chat()['changed']===false,'Repeated chat migration must be a no-op.');

 $password=password_hash('Taskesc-chat-ci!',PASSWORD_DEFAULT);
 $admin=DB::insert('users',['name'=>'Admin Chat','email'=>'admin-chat@ci.invalid','password_hash'=>$password,'role'=>'admin','active'=>1,'created_at'=>now()]);
 $staff=DB::insert('users',['name'=>'Staff Chat','email'=>'staff-chat@ci.invalid','password_hash'=>$password,'role'=>'staff','active'=>1,'created_at'=>now()]);
 $clientA=DB::insert('users',['name'=>'Cliente A','email'=>'client-a-chat@ci.invalid','password_hash'=>$password,'role'=>'client','active'=>1,'created_at'=>now()]);
 $clientB=DB::insert('users',['name'=>'Cliente B','email'=>'client-b-chat@ci.invalid','password_hash'=>$password,'role'=>'client','active'=>1,'created_at'=>now()]);
 $c1=DB::insert('clients',['name'=>'Cliente Chat 1','created_at'=>now()]);
 $c2=DB::insert('clients',['name'=>'Cliente Chat 2','created_at'=>now()]);
 DB::run('INSERT INTO user_clients (user_id,client_id,can_approve,can_finance) VALUES (?,?,1,0),(?,?,1,0),(?,?,1,0)',[$staff,$c1,$clientA,$c1,$clientB,$c2]);

 chatAs($clientA);
 $contacts=Chat::contacts();
 chatCheck(in_array($admin,array_column($contacts['contacts'],'id'),true),'Client must see admin in chat.');
 chatCheck(in_array($staff,array_column($contacts['contacts'],'id'),true),'Client must see staff sharing a client.');
 chatCheck(!in_array($clientB,array_column($contacts['contacts'],'id'),true),'Client must not see unrelated client.');
 chatReject(fn()=>Chat::messages(['peer_id'=>$clientB]),403,'Cross-client chat must be denied.');

 chatAs($admin);
 $nonce='0123456789abcdef0123456789abcdef';
 $sent=Chat::send(['peer_id'=>$clientA,'body'=>'Mensagem privada de teste','client_nonce'=>$nonce]);
 $messageId=(int)$sent['message']['id'];$threadId=(int)$sent['message']['thread_id'];
 chatCheck($messageId>0&&$threadId>0&&!$sent['duplicate'],'First send must create one message.');
 $again=Chat::send(['peer_id'=>$clientA,'body'=>'Mensagem privada de teste','client_nonce'=>$nonce]);
 chatCheck($again['duplicate']&&(int)$again['message']['id']===$messageId,'Client nonce must make chat send idempotent.');
 chatCheck((int)(DB::one('SELECT COUNT(*) n FROM chat_messages')['n']??0)===1,'Idempotent retry must not duplicate chat message.');

 $events=DB::all("SELECT channel,event_type,payload_json FROM event_outbox WHERE entity_type='chat_message' AND entity_id=? ORDER BY id",[$messageId]);
 chatCheck(count($events)===2,'Chat message must fan out to sender and recipient personal channels.');
 chatCheck(array_column($events,'channel')===['user:'.$admin,'user:'.$clientA]||array_column($events,'channel')===['user:'.$clientA,'user:'.$admin],'Chat channels must be personal.');
 foreach($events as $event){
  chatCheck($event['event_type']==='chat.message','Chat message event type mismatch.');
  $payload=json_decode($event['payload_json'],true,16,JSON_THROW_ON_ERROR);
  chatCheck(!array_key_exists('body',$payload),'Chat body must not be published through Centrifugo.');
  chatCheck((int)$payload['message_id']===$messageId&&(int)$payload['thread_id']===$threadId,'Chat event must contain fetch identifiers.');
 }

 chatAs($clientA);
 $contacts=Chat::contacts();
 $adminContact=array_values(array_filter($contacts['contacts'],fn($x)=>(int)$x['id']===$admin))[0]??null;
 chatCheck($adminContact&&(int)$adminContact['unread']===1&&(int)$contacts['unread_total']===1,'Recipient must see unread chat count.');
 $history=Chat::messages(['peer_id'=>$admin]);
 chatCheck(count($history['messages'])===1&&$history['messages'][0]['body']==='Mensagem privada de teste','Participant must read chat history over HTTP.');
 $read=Chat::read(['peer_id'=>$admin]);
 chatCheck((int)$read['read']===1,'Reading a conversation must mark unread message.');
 chatCheck((int)(DB::one('SELECT COUNT(*) n FROM chat_messages WHERE recipient_id=? AND read_at IS NULL',[$clientA])['n']??0)===0,'Unread row must be cleared.');
 chatCheck((int)(DB::one("SELECT COUNT(*) n FROM event_outbox WHERE event_type='chat.read' AND entity_type='chat_thread' AND entity_id=?",[$threadId])['n']??0)===2,'Read receipt must fan out to both users.');

 Auth::$previewClientId=$c1;Auth::$user=chatUser($admin);Auth::$user['role']='client';Auth::$user['is_preview']=1;
 chatReject(fn()=>Chat::contacts(),403,'Preview mode must not expose chat.');

 echo "Chat: schema 22 migration, ACL, idempotent send, unread/read state and personal-channel realtime fanout OK\n";
}finally{
 Auth::$user=null;Auth::$actor=null;Auth::$previewClientId=null;
 @unlink($configPath);@unlink(ROOT.'/storage/installed.lock');
}
