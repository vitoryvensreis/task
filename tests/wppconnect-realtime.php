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
 'wppconnect'=>[
   'internal_url'=>'http://127.0.0.1:21465',
   'public_url'=>'https://wppconnect.escotilhaprodutora.com',
   'session'=>'taskesc',
   'secret'=>str_repeat('a',64),
   'webhook_url'=>'',
 ],
];
file_put_contents($configPath,"<?php\nreturn ".var_export($config,true).";\n");
@mkdir(ROOT.'/storage/sessions',0700,true);
touch(ROOT.'/storage/installed.lock');

require ROOT.'/app/bootstrap.php';

function wppRtCheck(bool $condition,string $message): void {if(!$condition)throw new RuntimeException($message);}

try{
 $db=DB::conn();
 $db->exec('SET FOREIGN_KEY_CHECKS=0');
 foreach(DB::all('SHOW TABLES') as $row){$table=(string)array_values($row)[0];if(!preg_match('/\A[A-Za-z0-9_]+\z/D',$table))throw new RuntimeException('Unexpected table.');$db->exec('DROP TABLE `'.$table.'`');}
 $db->exec('SET FOREIGN_KEY_CHECKS=1');
 $db->exec(file_get_contents(ROOT.'/database/schema.sql'));

 $url=WppConnect::webhookUrl();
 parse_str((string)parse_url($url,PHP_URL_QUERY),$query);
 $token=(string)($query['token']??'');
 wppRtCheck(str_starts_with($url,'https://task.escotilhaprodutora.com/wppconnect-webhook.php?token='),'Webhook URL must remain on the TaskEsc HTTPS origin.');
 wppRtCheck(strlen($token)===64&&WppConnect::verifyWebhookToken($token),'Derived webhook token must validate.');
 wppRtCheck(!WppConnect::verifyWebhookToken(str_repeat('b',64)),'Invalid webhook token must be rejected.');

 $qr=str_repeat('QR-SECRET-CONTENT-',50);
 $q=WppWebhook::handle(['event'=>'qrcode','qrcode'=>$qr,'session'=>'taskesc']);
 wppRtCheck($q['accepted']===true&&$q['qr_available']===true,'QR lifecycle event must be accepted.');
 $row=DB::one("SELECT * FROM event_outbox WHERE event_type='wpp.changed' ORDER BY id DESC LIMIT 1");
 wppRtCheck($row&&$row['channel']==='wpp/global'&&$row['entity_type']==='wpp_session','QR event must target only the WPP admin channel.');
 wppRtCheck(!str_contains((string)$row['payload_json'],$qr),'Raw QR content must never enter the Outbox.');
 $payload=json_decode((string)$row['payload_json'],true,16,JSON_THROW_ON_ERROR);
 wppRtCheck(($payload['kind']??'')==='qrcode'&&($payload['qr_available']??false)===true,'QR invalidation payload mismatch.');

 $status=WppWebhook::handle(['event'=>'status-find','status'=>'isLogged']);
 wppRtCheck($status['accepted']===true&&$status['connected']===true,'Logged status must normalize as connected.');
 $row=DB::one("SELECT * FROM event_outbox WHERE event_type='wpp.changed' ORDER BY id DESC LIMIT 1");
 $payload=json_decode((string)$row['payload_json'],true,16,JSON_THROW_ON_ERROR);
 wppRtCheck(($payload['connected']??null)===true&&($payload['status']??'')==='islogged','Connected realtime payload mismatch.');

 $before=(int)(DB::one("SELECT COUNT(*) n FROM event_outbox WHERE event_type='wpp.changed'")['n']??0);
 $ignored=WppWebhook::handle(['event'=>'onmessage','body'=>'private message content','from'=>'558500000000']);
 $after=(int)(DB::one("SELECT COUNT(*) n FROM event_outbox WHERE event_type='wpp.changed'")['n']??0);
 wppRtCheck($ignored['accepted']===false&&$before===$after,'Message payloads must not enter the WPP status realtime channel.');

 echo "WPPConnect realtime: signed webhook, lifecycle filtering and sanitized admin Outbox OK\n";
}finally{
 @unlink($configPath);
 @unlink(ROOT.'/storage/installed.lock');
}
