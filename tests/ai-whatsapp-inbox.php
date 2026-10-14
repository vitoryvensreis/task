<?php
declare(strict_types=1);
define('ROOT',dirname(__DIR__));
$port=getenv('OUTBOX_TEST_PORT');
if(!$port)throw new RuntimeException('Inbox AI test must use disposable CI database.');
$configPath=ROOT.'/config/config.php';
@unlink($configPath);
$cfg=[
 'base_url'=>'https://task.escotilhaprodutora.com',
 'db'=>['host'=>'127.0.0.1','port'=>(int)$port,'name'=>'taskesc_test','user'=>'root','password'=>'taskesc-ci-only'],
 'app_key'=>bin2hex(random_bytes(32)),
 'wppconnect'=>['internal_url'=>'http://127.0.0.1:21465','session'=>'taskesc','secret'=>str_repeat('a',64),'webhook_url'=>'']
];
file_put_contents($configPath,"<?php\nreturn ".var_export($cfg,true).";\n");
@mkdir(ROOT.'/storage/sessions',0700,true);
touch(ROOT.'/storage/installed.lock');
require ROOT.'/app/bootstrap.php';
function aiCheck(bool $value,string $reason): void {if(!$value)throw new RuntimeException($reason);}
try{
 $db=DB::conn();
 $db->exec('SET FOREIGN_KEY_CHECKS=0');
 foreach(DB::all('SHOW TABLES') as $row){
  $table=(string)array_values($row)[0];
  if(!preg_match('/\A[A-Za-z0-9_]+\z/D',$table))throw new RuntimeException('Unexpected CI table.');
  $db->exec('DROP TABLE `'.$table.'`');
 }
 $db->exec('SET FOREIGN_KEY_CHECKS=1');
 $db->exec(file_get_contents(ROOT.'/database/schema.sql'));
 aiCheck((string)DB::one("SELECT value FROM settings WHERE `key`='schema_version'")['value']==='27','Fresh schema must be 27.');
 aiCheck(Migrations::assistantInboxIssues()===[],'Fresh inbox schema must be structurally valid.');
 aiCheck(Migrations::assistantInbox()['changed']===false,'Schema 27 migration must be idempotent.');

 // Simulate a schema 26 upgrade; the migration recreates only the missing inbox table.
 DB::run('DROP TABLE ai_whatsapp_inbox');
 DB::run("UPDATE settings SET value='26' WHERE `key`='schema_version'");
 $migration=Migrations::assistantInbox();
 aiCheck($migration['previous']===26&&$migration['current']===27&&$migration['changed'],'Schema 26->27 upgrade must succeed.');
 aiCheck(Migrations::assistantInboxIssues()===[],'Upgraded inbox schema must be structurally valid.');
 aiCheck(Migrations::assistantInbox()['changed']===false,'Schema 27 migration retry must not change anything.');
 aiCheck(Migrations::automation()['changed']===false,'Automation migration must be a no-op on schema 27.');
 aiCheck(Migrations::chat()['changed']===false,'Chat migration must be a no-op on schema 27.');
 aiCheck(Migrations::outbox()['changed']===false,'Outbox migration must be a no-op on schema 27.');

 $private=['event'=>'onmessage','session'=>'taskesc','body'=>[
  'id'=>['_serialized'=>'false_5585999991000@c.us_ABC123'],
  'from'=>'5585999991000@c.us','body'=>'Crie uma task para Vorp',
  'type'=>'chat','fromMe'=>false
 ]];
 $first=WppWebhook::handle($private);
 aiCheck(($first['stored']??false)===true&&($first['chat_type']??'')==='private','Private incoming message must be stored.');
 $dup=WppWebhook::handle($private);
 aiCheck(($dup['duplicate']??false)===true&&($dup['stored']??true)===false,'Repeated delivery must deduplicate.');
 $row=DB::one('SELECT * FROM ai_whatsapp_inbox WHERE id=1');
 aiCheck($row&&$row['chat_type']==='private'&&$row['sender_id']==='5585999991000@c.us','Private sender metadata mismatch.');
 aiCheck($row['status']==='received'&&$row['body']==='Crie uma task para Vorp','Inbox must store message content as received, without execution.');


 // WPPConnect tambem envia envelope plano em algumas versoes:
 // o campo body e texto, nao um objeto de mensagem.
 $flat=[
  'event'=>'onmessage','session'=>'taskesc',
  'id'=>['_serialized'=>'false_5585999993000@c.us_FLAT01'],
  'from'=>'5585999993000@c.us','body'=>'Teste privado no formato plano',
  'type'=>'chat','fromMe'=>false
 ];
 $flatResult=WppWebhook::handle($flat);
 aiCheck(($flatResult['stored']??false)&&$flatResult['chat_type']==='private','Flat private text envelope must be accepted.');
 aiCheck(WppWebhook::handle($flat)['duplicate']===true,'Flat private retry must be deduplicated.');
 $flatRow=DB::one("SELECT * FROM ai_whatsapp_inbox WHERE provider_message_id='false_5585999993000@c.us_FLAT01'");
 aiCheck($flatRow&&$flatRow['body']==='Teste privado no formato plano'&&$flatRow['sender_id']==='5585999993000@c.us','Flat private payload must persist text and sender.');

 $data=[
  'event'=>'onmessage','session'=>'taskesc',
  'data'=>['id'=>'false_5585999994000@c.us_DATA01','from'=>'5585999994000@c.us',
           'body'=>'Teste privado em data','type'=>'chat','fromMe'=>false]
 ];
 aiCheck(WppWebhook::handle($data)['stored']===true,'Nested data private envelope must be accepted.');
 $dataRow=DB::one("SELECT * FROM ai_whatsapp_inbox WHERE provider_message_id='false_5585999994000@c.us_DATA01'");
 aiCheck($dataRow&&$dataRow['body']==='Teste privado em data','Private data payload must retain body.');

 $second=$private;$second['body']['id']='false_5585999991000@c.us_ABC124';
 aiCheck(WppWebhook::handle($second)['stored']===true,'Different provider ID with same body must be accepted.');
 $group=['event'=>'onmessage','body'=>[
  'id'=>'false_120363012345678901@g.us_ABC125','from'=>'120363012345678901@g.us',
  'author'=>'125987654321012@lid','body'=>'@TaskEsc listar tasks','type'=>'chat','isGroupMsg'=>true
 ]];
 $g=WppWebhook::handle($group);
 aiCheck(($g['stored']??false)===true&&$g['chat_type']==='group','Group message must be stored.');
 $groupRow=DB::one("SELECT * FROM ai_whatsapp_inbox WHERE chat_type='group' LIMIT 1");
 aiCheck($groupRow&&$groupRow['sender_id']==='125987654321012@lid','Group author must retain LID, not be mistaken for group ID.');
 aiCheck(WppWebhook::handle($group)['duplicate']===true,'Group delivery retry must deduplicate.');

 $bad=$group;unset($bad['body']['author']);
 aiCheck(WppWebhook::handle($bad)['accepted']===false,'Groups without verified sender identity must not enter inbox.');
 $bad=$private;unset($bad['body']['id']);
 aiCheck(WppWebhook::handle($bad)['accepted']===false,'Messages without stable provider ID must be discarded.');
 $bad=$private;$bad['body']['fromMe']=true;
 aiCheck(WppWebhook::handle($bad)['accepted']===false,'Messages from our WhatsApp must be discarded.');
 $bad=$private;$bad['session']='other-session';
 aiCheck(WppWebhook::handle($bad)['accepted']===false,'Wrong provider sessions must not enter inbox.');
 $bad=$private;$bad['body']['from']='hello@unknown.invalid';
 aiCheck(WppWebhook::handle($bad)['accepted']===false,'Invalid senders must not enter inbox.');
 $bad=$private;$bad['event']='onack';
 aiCheck(WppWebhook::handle($bad)['accepted']===false,'ACK/delivery events must not be stored.');

 $media=['event'=>'onmessage','body'=>[
   'id'=>'false_5585999991000@c.us_MEDIA','from'=>'5585999991000@c.us','type'=>'image',
   'body'=>['base64'=>'SUPER_SECRET_BYTES'],'mediaData'=>'SECRET_PROVIDER_MEDIA'
 ]];
 aiCheck(WppWebhook::handle($media)['stored']===true,'Media metadata must be accepted without downloading content.');
 $mediaRow=DB::one("SELECT * FROM ai_whatsapp_inbox WHERE message_type='image' LIMIT 1");
 aiCheck($mediaRow&&$mediaRow['body']===null,'Media binary and provider payload must never be copied to inbox.');

 $qr=WppWebhook::handle(['event'=>'status-find','status'=>'isLogged']);
 aiCheck(($qr['accepted']??false)&&($qr['connected']??false),'Existing WPP connection status must still work.');
 aiCheck((int)(DB::one('SELECT COUNT(*) n FROM ai_whatsapp_inbox')['n']??0)===6,'Expected 4 private + 1 group + 1 media message.');
 aiCheck((int)(DB::one('SELECT COUNT(*) n FROM whatsapp_messages')['n']??0)===0,'Passive inbox must never send outbound WhatsApp messages.');
 aiCheck((int)(DB::one('SELECT COUNT(*) n FROM automation_runs')['n']??0)===0,'Passive inbox must never start automations.');
 aiCheck((int)(DB::one("SELECT COUNT(*) n FROM event_outbox WHERE event_type LIKE 'ai.%'")['n']??0)===0,'Message contents must not enter realtime/outbox.');

 echo "TaskEsc AI inbox: schema 27 migration, private/group intake, stable-ID dedupe, sender validation, passive mode OK\n";
}finally{
 @unlink($configPath);
 @unlink(ROOT.'/storage/installed.lock');
}
