<?php
final class Push {
 private static function b64u(string $v): string {return rtrim(strtr(base64_encode($v),'+/','-_'),'=');}
 private static function b64ud(string $v): string {$pad=(4-strlen($v)%4)%4;return (string)base64_decode(strtr($v.str_repeat('=',$pad),'-_','+/'),true);}
 private static function derJose(string $der): string {
  $p=0;if(ord($der[$p++])!==0x30)throw new RuntimeException('Assinatura VAPID inválida.');
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
  Auth::load();$keys=self::keys();$count=(int)(DB::one('SELECT COUNT(*) AS n FROM push_subscriptions WHERE user_id=? AND active=1',[Auth::$user['id']])['n']??0);
  return ['public_key'=>$keys['public'],'subscribed'=>$count>0,'subscriptions'=>$count,'permission_hint'=>(bool)setting('browser_notifications','1')];
 }
 public static function subscribe(array $p): array {
  Auth::load();$endpoint=trim((string)($p['endpoint']??''));if(!filter_var($endpoint,FILTER_VALIDATE_URL)||parse_url($endpoint,PHP_URL_SCHEME)!=='https')fail('Assinatura de notificação inválida.');
  $hash=hash('sha256',$endpoint);$row=['user_id'=>Auth::$user['id'],'endpoint'=>$endpoint,'endpoint_hash'=>$hash,'p256dh'=>mb_substr((string)($p['p256dh']??''),0,500),'auth_key'=>mb_substr((string)($p['auth']??''),0,500),'user_agent'=>mb_substr((string)($_SERVER['HTTP_USER_AGENT']??''),0,255),'active'=>1,'last_error'=>null,'updated_at'=>now()];
  $old=DB::one('SELECT id FROM push_subscriptions WHERE endpoint_hash=?',[$hash]);if($old)DB::update('push_subscriptions',(int)$old['id'],$row);else DB::insert('push_subscriptions',$row+['created_at'=>now()]);
  audit('push_subscription',(int)($old['id']??0),'subscribed',null,['user_id'=>Auth::$user['id']]);return ['saved'=>true];
 }
 public static function unsubscribe(array $p): array {
  Auth::load();$endpoint=trim((string)($p['endpoint']??''));if($endpoint!=='')DB::run('UPDATE push_subscriptions SET active=0,updated_at=? WHERE user_id=? AND endpoint_hash=?',[now(),Auth::$user['id'],hash('sha256',$endpoint)]);else DB::run('UPDATE push_subscriptions SET active=0,updated_at=? WHERE user_id=?',[now(),Auth::$user['id']]);
  return ['saved'=>true];
 }
 private static function jwt(string $aud,string $private,string $public): string {
  $header=self::b64u(json(['typ'=>'JWT','alg'=>'ES256']));$payload=self::b64u(json(['aud'=>$aud,'exp'=>time()+43200,'sub'=>filter_var(config('smtp',[])['from']??'',FILTER_VALIDATE_EMAIL)?'mailto:'.config('smtp',[])['from']:rtrim((string)config('base_url'),'/')]));
  $input=$header.'.'.$payload;$sig='';if(!openssl_sign($input,$sig,$private,OPENSSL_ALGO_SHA256))throw new RuntimeException('Não foi possível assinar a notificação push.');
  return $input.'.'.self::b64u(self::derJose($sig));
 }
 private static function send(array $sub,array $keys): bool {
  $endpoint=(string)$sub['endpoint'];$parts=parse_url($endpoint);$aud=($parts['scheme']??'https').'://'.($parts['host']??'');if($aud==='https://')throw new RuntimeException('Endpoint push inválido.');
  $jwt=self::jwt($aud,$keys['private'],$keys['public']);$ch=curl_init($endpoint);curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>'',CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>15,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_HTTPHEADER=>['TTL: 120','Urgency: normal','Authorization: vapid t='.$jwt.', k='.$keys['public'],'Content-Length: 0']]);curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$err=curl_error($ch);curl_close($ch);
  if(in_array($code,[404,410],true)){DB::update('push_subscriptions',(int)$sub['id'],['active'=>0,'last_error'=>'Endpoint expirado ('.$code.')','updated_at'=>now()]);return false;}
  if($code<200||$code>=300){DB::update('push_subscriptions',(int)$sub['id'],['last_error'=>mb_substr($err?:('HTTP '.$code),0,1000),'updated_at'=>now()]);return false;}
  DB::update('push_subscriptions',(int)$sub['id'],['last_error'=>null,'updated_at'=>now()]);return true;
 }
 public static function notifyUser(int $userId): void {
  if(setting('browser_notifications','1')!=='1')return;$rows=DB::all('SELECT * FROM push_subscriptions WHERE user_id=? AND active=1',[$userId]);if(!$rows)return;
  try{$keys=self::keys();foreach($rows as $sub)self::send($sub,$keys);}catch(Throwable $e){error_log('TaskEsc push: '.$e->getMessage());}
 }
}
