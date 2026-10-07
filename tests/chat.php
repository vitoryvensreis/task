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

 $password=password_hash('Taskesc-chat-ci!',PASSWORD_DEFAULT);
 $adminA=DB::insert('users',['name'=>'Admin A','email'=>'admin-a-chat@ci.invalid','password_hash'=>$password,'role'=>'admin','active'=>1,'created_at'=>now()]);
 $adminB=DB::insert('users',['name'=>'Admin B','email'=>'admin-b-chat@ci.invalid','password_hash'=>$password,'role'=>'admin','active'=>1,'created_at'=>now()]);
 $staff=DB::insert('users',['name'=>'Staff Chat','email'=>'staff-chat@ci.invalid','password_hash'=>$password,'role'=>'staff','active'=>1,'created_at'=>now()]);
 $client=DB::insert('users',['name'=>'Cliente Chat','email'=>'client-chat@ci.invalid','password_hash'=>$password,'role'=>'client','active'=>1,'created_at'=>now()]);

 chatAs($staff);
 chatReject(fn()=>Chat::contacts(),403,'Staff must not access admin chat.');
 chatReject(fn()=>Chat::send(['peer_id'=>$adminA,'body'=>'x','client_nonce'=>'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa']),403,'Staff must not send admin chat messages.');

 chatAs($client);
 chatReject(fn()=>Chat::contacts(),403,'Client must not access admin chat.');

 chatAs($adminA);
 $contacts=Chat::contacts();
 chatCheck(array_column($contacts['contacts'],'id')===[$adminB],'Admin contacts must contain only other active admins.');
 chatReject(fn()=>Chat::messages(['peer_id'=>$staff]),404,'Admin must not open a staff conversation.');
 chatReject(fn()=>Chat::messages(['peer_id'=>$client]),404,'Admin must not open a client conversation.');

 $nonce='0123456789abcdef0123456789abcdef';
 $sent=Chat::send(['peer_id'=>$adminB,'body'=>'Mensagem privada entre admins','client_nonce'=>$nonce]);
 $messageId=(int)$sent['message']['id'];$threadId=(int)$sent['message']['thread_id'];
 chatCheck($messageId>0&&$threadId>0&&!$sent['duplicate'],'First admin send must create one message.');
 $again=Chat::send(['peer_id'=>$adminB,'body'=>'Mensagem privada entre admins','client_nonce'=>$nonce]);
 chatCheck($again['duplicate']&&(int)$again['message']['id']===$messageId,'Client nonce must keep admin send idempotent.');
 chatCheck((int)(DB::one('SELECT COUNT(*) n FROM chat_messages')['n']??0)===1,'Idempotent retry must not duplicate admin message.');

 $events=DB::all("SELECT channel,event_type,payload_json FROM event_outbox WHERE entity_type='chat_message' AND entity_id=? ORDER BY id",[$messageId]);
 chatCheck(count($events)===2,'Admin message must fan out to both personal channels.');
 $channels=array_column($events,'channel');sort($channels);
 $expected=['user:'.$adminA,'user:'.$adminB];sort($expected);
 chatCheck($channels===$expected,'Admin chat channels must be personal.');
 foreach($events as $event){
  chatCheck($event['event_type']==='chat.message','Chat message event type mismatch.');
  $payload=json_decode($event['payload_json'],true,16,JSON_THROW_ON_ERROR);
  chatCheck(!array_key_exists('body',$payload),'Chat body must not be published through Centrifugo.');
  chatCheck((int)$payload['message_id']===$messageId&&(int)$payload['thread_id']===$threadId,'Chat event must contain fetch identifiers.');
 }

 chatAs($adminB);
 $contacts=Chat::contacts();
 $contact=$contacts['contacts'][0]??null;
 chatCheck($contact&&(int)$contact['id']===$adminA&&(int)$contact['unread']===1&&(int)$contacts['unread_total']===1,'Recipient admin must see unread count.');
 $history=Chat::messages(['peer_id'=>$adminA]);
 chatCheck(count($history['messages'])===1&&$history['messages'][0]['body']==='Mensagem privada entre admins','Admin participant must read history over HTTP.');
 $read=Chat::read(['peer_id'=>$adminA]);
 chatCheck((int)$read['read']===1,'Reading an admin conversation must mark unread message.');
 chatCheck((int)(DB::one('SELECT COUNT(*) n FROM chat_messages WHERE recipient_id=? AND read_at IS NULL',[$adminB])['n']??0)===0,'Unread admin row must be cleared.');

 Auth::$origin='api';
 chatReject(fn()=>Chat::contacts(),403,'API tokens must not expose admin chat.');

 Auth::$origin='web';Auth::$previewClientId=1;Auth::$user=chatUser($adminB);Auth::$user['role']='client';Auth::$user['is_preview']=1;
 chatReject(fn()=>Chat::contacts(),403,'Preview mode must not expose admin chat.');

 echo "Chat admin-only: ACL, idempotency, unread/read state and realtime fanout OK\n";
}finally{
 Auth::$user=null;Auth::$actor=null;Auth::$previewClientId=null;
 @unlink($configPath);@unlink(ROOT.'/storage/installed.lock');
}
