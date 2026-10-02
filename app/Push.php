<?php
final class Push {
 private static function b64u(string $v): string {return rtrim(strtr(base64_encode($v),'+/','-_'),'=');}
 private static function b64ud(string $v): string {
  $pad=(4-strlen($v)%4)%4;$raw=base64_decode(strtr($v.str_repeat('=',$pad),'-_','+/'),true);
  if($raw===false)throw new RuntimeException('Chave push inválida.');return $raw;
 }
 private static function derJose(string $der): string {
  $p=0;if(!isset($der[$p])||ord($der[$p++])!==0x30)throw new RuntimeException('Assinatura VAPID inválida.');
  $len=ord($der[$p++]);if($len&0x80){$n=$len&0x7f;$len=0;for($i=0;$i<$n;$i++)$len=($len<<8)|ord($der[$p++]);}
  if(ord($der[$p++])!==0x02)throw new RuntimeException('Assinatura VAPID inválida.');$rl=ord($der[$p++]);$r=substr($der,$p,$rl);$p+=$rl;
  if(ord($der[$p++])!==0x02)throw new RuntimeException('Assinatura VAPID inválida.');$sl=ord($der[$p++]);$s=substr($der,$p,$sl);
  $r=ltrim($r,"\0");$s=ltrim($s,"\0");$r=str_pad(substr($r,-32),32,"\0",STR_PAD_LEFT);$s=str_pad(substr($s,-32),32,"\0",STR_PAD_LEFT);
  return $r.$s;
 }
 private static function keys(): array {
  $pub=setting('vapid_public','');$enc=setting('vapid_private','');
  if($pub!==''&&$enc!==''){try{return ['public'=>$pub,'private'=>secretDecrypt($enc)];}catch(Throwable){}}
  $key=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_EC,'curve_name'=>'prime256v1']);if(!$key)throw new RuntimeException('OpenSSL não conseguiu gerar a chave de push.');
  $pem='';if(!openssl_pkey_export($key,$pem))throw new RuntimeException('OpenSSL não conseguiu exportar a chave de push.');
  $details=openssl_pkey_get_details($key);$ec=$details['ec']??[];$x=$ec['x']??null;$y=$ec['y']??null;
  if(!is_string($x)||!is_string($y))throw new RuntimeException('A versão do OpenSSL não expôs a chave pública EC.');
  $pub=self::b64u("\x04".str_pad($x,32,"\0",STR_PAD_LEFT).str_pad($y,32,"\0",STR_PAD_LEFT));
  DB::run("INSERT INTO settings (`key`,value) VALUES ('vapid_public',?) ON DUPLICATE KEY UPDATE value=VALUES(value)",[$pub]);
  DB::run("INSERT INTO settings (`key`,value) VALUES ('vapid_private',?) ON DUPLICATE KEY UPDATE value=VALUES(value)",[secretEncrypt($pem)]);
  return ['public'=>$pub,'private'=>$pem];
 }
 public static function status(): array {
  Auth::load();$keys=self::keys();$rows=DB::all('SELECT id,user_agent,last_error,created_at,updated_at FROM push_subscriptions WHERE user_id=? AND active=1 ORDER BY updated_at DESC',[Auth::$user['id']]);
  return ['public_key'=>$keys['public'],'subscribed'=>count($rows)>0,'subscriptions'=>count($rows),'devices'=>$rows,'permission_hint'=>(bool)setting('browser_notifications','1')];
 }
 public static function subscribe(array $p): array {
  Auth::load();$endpoint=trim((string)($p['endpoint']??''));if(!filter_var($endpoint,FILTER_VALIDATE_URL)||parse_url($endpoint,PHP_URL_SCHEME)!=='https')fail('Assinatura de notificação inválida.');
  $p256dh=trim((string)($p['p256dh']??''));$auth=trim((string)($p['auth']??''));if($p256dh===''||$auth==='')fail('Chaves da assinatura push ausentes.');
  $rawKey=self::b64ud($p256dh);$rawAuth=self::b64ud($auth);if(strlen($rawKey)!==65||ord($rawKey[0])!==4||strlen($rawAuth)<16)fail('Chaves da assinatura push inválidas.');
  $hash=hash('sha256',$endpoint);$row=['user_id'=>Auth::$user['id'],'endpoint'=>$endpoint,'endpoint_hash'=>$hash,'p256dh'=>mb_substr($p256dh,0,500),'auth_key'=>mb_substr($auth,0,500),'user_agent'=>mb_substr((string)($_SERVER['HTTP_USER_AGENT']??''),0,255),'active'=>1,'last_error'=>null,'updated_at'=>now()];
  $old=DB::one('SELECT id FROM push_subscriptions WHERE endpoint_hash=?',[$hash]);if($old){$id=(int)$old['id'];DB::update('push_subscriptions',$id,$row);}else{$id=DB::insert('push_subscriptions',$row+['created_at'=>now()]);}
  audit('push_subscription',$id,'subscribed',null,['user_id'=>Auth::$user['id']]);return ['saved'=>true,'id'=>$id];
 }
 public static function unsubscribe(array $p): array {
  Auth::load();$endpoint=trim((string)($p['endpoint']??''));if($endpoint!=='')DB::run('UPDATE push_subscriptions SET active=0,updated_at=? WHERE user_id=? AND endpoint_hash=?',[now(),Auth::$user['id'],hash('sha256',$endpoint)]);else DB::run('UPDATE push_subscriptions SET active=0,updated_at=? WHERE user_id=?',[now(),Auth::$user['id']]);
  return ['saved'=>true];
 }
 public static function test(): array {
  Auth::load();$count=self::notifyUser((int)Auth::$user['id'],[
   'title'=>'TaskEsc · teste de notificação',
   'body'=>'Se você recebeu isto, as notificações push estão funcionando neste dispositivo.',
   'url'=>base('configuracoes'),
   'tag'=>'taskesc-push-test-'.time(),
   'scope'=>'test',
   'test'=>true
  ]);
  return ['sent'=>$count,'subscriptions'=>(int)(DB::one('SELECT COUNT(*) AS n FROM push_subscriptions WHERE user_id=? AND active=1',[Auth::$user['id']])['n']??0)];
 }
 private static function jwt(string $aud,string $private,string $public): string {
  $from=(string)(config('smtp',[])['from']??'');$subject=filter_var($from,FILTER_VALIDATE_EMAIL)?'mailto:'.$from:rtrim((string)config('base_url'),'/');
  $header=self::b64u(json(['typ'=>'JWT','alg'=>'ES256']));$payload=self::b64u(json(['aud'=>$aud,'exp'=>time()+43200,'sub'=>$subject]));
  $input=$header.'.'.$payload;$sig='';if(!openssl_sign($input,$sig,$private,OPENSSL_ALGO_SHA256))throw new RuntimeException('Não foi possível assinar a notificação push.');
  return $input.'.'.self::b64u(self::derJose($sig));
 }
 private static function hkdfExpand(string $prk,string $info,int $length): string {
  $out='';$t='';$i=1;while(strlen($out)<$length){$t=hash_hmac('sha256',$t.$info.chr($i),$prk,true);$out.=$t;$i++;if($i>255)throw new RuntimeException('Falha ao derivar chave push.');}return substr($out,0,$length);
 }
 private static function ecPublicPem(string $raw): string {
  if(strlen($raw)!==65||ord($raw[0])!==4)throw new RuntimeException('Chave pública push inválida.');
  $prefix=hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200');if($prefix===false)throw new RuntimeException('Falha ao preparar chave push.');
  return "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($prefix.$raw),64,"\n")."-----END PUBLIC KEY-----\n";
 }
 private static function encryptPayload(array $sub,array $payload): string {
  if(!function_exists('openssl_pkey_derive'))throw new RuntimeException('O servidor precisa de OpenSSL com suporte a ECDH para Web Push.');
  $userPub=self::b64ud((string)$sub['p256dh']);$auth=self::b64ud((string)$sub['auth_key']);$userKey=openssl_pkey_get_public(self::ecPublicPem($userPub));if(!$userKey)throw new RuntimeException('Não foi possível abrir a chave pública do dispositivo.');
  $serverKey=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_EC,'curve_name'=>'prime256v1']);if(!$serverKey)throw new RuntimeException('Não foi possível gerar a chave efêmera do Web Push.');
  $details=openssl_pkey_get_details($serverKey);$ec=$details['ec']??[];$x=$ec['x']??null;$y=$ec['y']??null;if(!is_string($x)||!is_string($y))throw new RuntimeException('Não foi possível obter a chave pública efêmera.');
  $serverPub="\x04".str_pad($x,32,"\0",STR_PAD_LEFT).str_pad($y,32,"\0",STR_PAD_LEFT);$shared=openssl_pkey_derive($userKey,$serverKey,32);if(!is_string($shared)||strlen($shared)!==32)throw new RuntimeException('Falha na derivação ECDH do Web Push.');
  $prkKey=hash_hmac('sha256',$shared,$auth,true);$ikm=self::hkdfExpand($prkKey,"WebPush: info\x00".$userPub.$serverPub,32);$salt=random_bytes(16);$prk=hash_hmac('sha256',$ikm,$salt,true);
  $cek=self::hkdfExpand($prk,"Content-Encoding: aes128gcm\x00",16);$nonce=self::hkdfExpand($prk,"Content-Encoding: nonce\x00",12);
  $plain=json($payload)."\x02";$tag='';$cipher=openssl_encrypt($plain,'aes-128-gcm',$cek,OPENSSL_RAW_DATA,$nonce,$tag,'',16);if($cipher===false||strlen($tag)!==16)throw new RuntimeException('Falha ao criptografar o payload push.');
  $recordSize=4096;return $salt.pack('N',$recordSize).chr(strlen($serverPub)).$serverPub.$cipher.$tag;
 }
 private static function logDelivery(int $userId,?array $sub,array $payload,string $status,?int $httpCode=null,?string $error=null): void {
  try{
   DB::insert('push_delivery_log',[
    'user_id'=>$userId,
    'subscription_id'=>$sub?(int)$sub['id']:null,
    'notification_id'=>!empty($payload['notification_id'])?(int)$payload['notification_id']:null,
    'demand_id'=>!empty($payload['demand_id'])?(int)$payload['demand_id']:null,
    'scope'=>mb_substr((string)($payload['scope']??(!empty($payload['test'])?'test':(!empty($payload['demand_id'])?'task_responsible':'system'))),0,40),
    'title'=>mb_substr((string)($payload['title']??'TaskEsc'),0,190),
    'body'=>(string)($payload['body']??''),
    'url'=>mb_substr((string)($payload['url']??''),0,2000),
    'status'=>mb_substr($status,0,30),
    'http_code'=>$httpCode,
    'error'=>$error!==null?mb_substr($error,0,2000):null,
    'user_agent'=>$sub?mb_substr((string)($sub['user_agent']??''),0,255):null,
    'created_at'=>now()
   ]);
  }catch(Throwable $e){error_log('TaskEsc push log: '.$e->getMessage());}
 }
 private static function send(array $sub,array $keys,array $payload): array {
  $endpoint=(string)$sub['endpoint'];$parts=parse_url($endpoint);$scheme=(string)($parts['scheme']??'');$host=(string)($parts['host']??'');if($scheme!=='https'||$host==='')throw new RuntimeException('Endpoint push inválido.');
  $port=isset($parts['port'])?':'.(int)$parts['port']:'';$aud=$scheme.'://'.$host.$port;$jwt=self::jwt($aud,$keys['private'],$keys['public']);$body=self::encryptPayload($sub,$payload);
  $ch=curl_init($endpoint);curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>20,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_HTTPHEADER=>[
   'TTL: 86400','Urgency: normal','Content-Encoding: aes128gcm','Content-Type: application/octet-stream','Authorization: vapid t='.$jwt.', k='.$keys['public'],'Content-Length: '.strlen($body)
  ]]);curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$err=curl_error($ch);curl_close($ch);
  if(in_array($code,[404,410],true)){$msg='Endpoint expirado ('.$code.')';DB::update('push_subscriptions',(int)$sub['id'],['active'=>0,'last_error'=>$msg,'updated_at'=>now()]);return ['ok'=>false,'http_code'=>$code,'error'=>$msg];}
  if($code<200||$code>=300){$msg=mb_substr($err?:('HTTP '.$code),0,1000);DB::update('push_subscriptions',(int)$sub['id'],['last_error'=>$msg,'updated_at'=>now()]);return ['ok'=>false,'http_code'=>$code?:null,'error'=>$msg];}
  DB::update('push_subscriptions',(int)$sub['id'],['last_error'=>null,'updated_at'=>now()]);return ['ok'=>true,'http_code'=>$code,'error'=>null];
 }
 public static function notifyUser(int $userId,array $payload=[]): int {
  $payload+=['title'=>'TaskEsc','body'=>'Você tem uma nova atualização no TaskEsc.','url'=>base('dashboard'),'tag'=>'taskesc-update-'.time(),'scope'=>!empty($payload['demand_id'])?'task_responsible':'system'];
  $payload['title']=mb_substr((string)$payload['title'],0,120);$payload['body']=mb_substr((string)$payload['body'],0,360);$payload['url']=filter_var((string)$payload['url'],FILTER_VALIDATE_URL)?(string)$payload['url']:base('dashboard');
  if(setting('browser_notifications','1')!=='1'){self::logDelivery($userId,null,$payload,'disabled',null,'Notificações push desativadas nas configurações.');return 0;}
  $rows=DB::all('SELECT * FROM push_subscriptions WHERE user_id=? AND active=1',[$userId]);if(!$rows){self::logDelivery($userId,null,$payload,'no_subscription',null,'Usuário sem dispositivo push ativo.');return 0;}
  try{$keys=self::keys();}catch(Throwable $e){$msg=mb_substr($e->getMessage(),0,1000);self::logDelivery($userId,null,$payload,'failed',null,$msg);error_log('TaskEsc push keys: '.$msg);return 0;}
  $sent=0;foreach($rows as $sub){try{$result=self::send($sub,$keys,$payload);self::logDelivery($userId,$sub,$payload,$result['ok']?'sent':'failed',$result['http_code']??null,$result['error']??null);if($result['ok'])$sent++;}catch(Throwable $e){$msg=mb_substr($e->getMessage(),0,1000);try{DB::update('push_subscriptions',(int)$sub['id'],['last_error'=>$msg,'updated_at'=>now()]);}catch(Throwable){}self::logDelivery($userId,$sub,$payload,'failed',null,$msg);error_log('TaskEsc push: '.$msg);}}return $sent;
 }
}
