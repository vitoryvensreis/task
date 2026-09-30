<?php
final class Notify {
 public static function team(string $title,string $body,?int $demand=null): void {
  foreach(DB::all("SELECT id FROM users WHERE active=1 AND role IN ('admin','staff')") as $u){
   if((int)$u['id']!==(int)(Auth::$user['id']??0))self::user((int)$u['id'],$title,$body,$demand);
  }
 }

 public static function clientLifecycle(array $client,bool $created): void {
  self::team($created?'Novo cliente cadastrado':'Cliente atualizado',($client['name']??'Cliente').($created?' foi adicionado ao TaskEsc.':' teve sua ficha atualizada.'));
 }

 public static function user(int $id,string $title,string $body,?int $demand=null,?string $key=null): ?string {
  $u=DB::one('SELECT * FROM users WHERE id=? AND active=1',[$id]);
  if(!$u)return null;
  if($key&&DB::one('SELECT id FROM notifications WHERE dedupe_key=?',[$key]))return null;
  DB::insert('notifications',['user_id'=>$id,'title'=>mb_substr($title,0,190),'body'=>$body,'demand_id'=>$demand,'dedupe_key'=>$key,'created_at'=>now()]);
  if(!$u['email_notifications'])return null;
  $email=strtolower(trim((string)$u['email']));
  self::mail($email,$title,$body."\n\n".base($demand?'kanban/task/'.$demand:'dashboard'));
  return $email;
 }

 public static function demand(array $d,string $title,string $body,bool $internal=false): void {
  $args=$internal?[$d['assigned_to']??0]:[$d['assigned_to']??0,$d['client_id']];
  $rows=DB::all("SELECT DISTINCT u.id FROM users u LEFT JOIN user_clients uc ON uc.user_id=u.id WHERE u.active=1 AND (u.role='admin' OR u.id=?".($internal?'':" OR (u.role='client' AND uc.client_id=?)").')',$args);
  $emailed=[];
  foreach($rows as $u){
   if((int)$u['id']===(int)(Auth::$user['id']??0))continue;
   $email=self::user((int)$u['id'],$title,$body,(int)$d['id']);
   if($email)$emailed[$email]=true;
  }
  if($internal)return;
  $client=DB::one('SELECT name,email FROM clients WHERE id=?',[(int)$d['client_id']]);
  $clientEmail=strtolower(trim((string)($client['email']??'')));
  if(filter_var($clientEmail,FILTER_VALIDATE_EMAIL)&&!isset($emailed[$clientEmail])){
   $taskTitle=trim((string)($d['title']??''));
   $message=($client['name']??'Cliente').", houve uma atualização na task".($taskTitle!==''?' “'.$taskTitle.'”':'').".\n\n".$body."\n\n".base('kanban/task/'.(int)$d['id']);
   self::mail($clientEmail,$title,$message);
  }
 }

 public static function mail(string $to,string $subject,string $body): void {
  $to=strtolower(trim($to));
  if(!filter_var($to,FILTER_VALIDATE_EMAIL))return;
  DB::insert('mail_queue',['recipient'=>$to,'subject'=>mb_substr($subject,0,190),'body'=>$body,'status'=>'pending','attempts'=>0,'next_attempt'=>now(),'created_at'=>now()]);
 }

 public static function log(int $limit=100): array {
  Auth::admin();$limit=max(1,min(300,$limit));
  return DB::all('SELECT id,recipient,subject,status,attempts,error,next_attempt,last_attempt_at,sent_at,created_at FROM mail_queue ORDER BY id DESC LIMIT '.$limit);
 }

 public static function flush(): array {
  $sent=0;$failed=0;
  if(empty(config('smtp',[])['host']))return ['sent'=>0,'failed'=>0,'status'=>'SMTP não configurado'];
  foreach(DB::all("SELECT * FROM mail_queue WHERE status='pending' AND next_attempt<=? AND attempts<5 ORDER BY id LIMIT 20",[now()]) as $m){
   $attempt=(int)$m['attempts']+1;$attemptAt=now();
   try{
    self::smtp($m['recipient'],$m['subject'],$m['body']);
    DB::update('mail_queue',$m['id'],['status'=>'sent','attempts'=>$attempt,'error'=>null,'last_attempt_at'=>$attemptAt,'sent_at'=>now()]);
    $sent++;
   }catch(Throwable $e){
    $terminal=$attempt>=5;
    DB::update('mail_queue',$m['id'],['status'=>$terminal?'failed':'pending','attempts'=>$attempt,'error'=>mb_substr($e->getMessage(),0,1000),'last_attempt_at'=>$attemptAt,'next_attempt'=>gmdate('Y-m-d H:i:s',time()+min(3600,60*2**max(0,$attempt-1)))]);
    $failed++;
   }
  }
  return ['sent'=>$sent,'failed'=>$failed];
 }

 public static function smtp(string $to,string $subject,string $body): void {
  $c=config('smtp',[]);$host=$c['host']??'';if(!$host)throw new RuntimeException('Configure o SMTP nas integrações.');
  $ctx=stream_context_create(['ssl'=>['verify_peer'=>true,'verify_peer_name'=>true,'peer_name'=>$host]]);
  $s=stream_socket_client(($c['encryption']==='ssl'?'ssl://':'tcp://').$host.':'.$c['port'],$errno,$error,15,STREAM_CLIENT_CONNECT,$ctx);if(!$s)throw new RuntimeException('Falha ao conectar ao SMTP.');
  stream_set_timeout($s,15);
  $read=function(array $allowed)use($s){$line='';do{$part=fgets($s,4096);if($part===false)throw new RuntimeException('SMTP sem resposta.');$line.=$part;}while(isset($part[3])&&$part[3]==='-');$code=(int)substr($line,0,3);if(!in_array($code,$allowed,true))throw new RuntimeException('SMTP recusou a operação ('.$code.').');};
  $cmd=function(string $msg,array $allowed)use($s,$read){fwrite($s,$msg."\r\n");$read($allowed);};
  try{
   $read([220]);$cmd('EHLO taskesc.local',[250]);
   if($c['encryption']==='tls'){$cmd('STARTTLS',[220]);if(!stream_socket_enable_crypto($s,true,STREAM_CRYPTO_METHOD_TLS_CLIENT))throw new RuntimeException('Falha ao estabelecer TLS no SMTP.');$cmd('EHLO taskesc.local',[250]);}
   if($c['username']!==''){$cmd('AUTH LOGIN',[334]);$cmd(base64_encode($c['username']),[334]);$cmd(base64_encode($c['password']),[235]);}
   $from=$c['from'];if(!filter_var($from,FILTER_VALIDATE_EMAIL)||!filter_var($to,FILTER_VALIDATE_EMAIL))throw new RuntimeException('E-mail inválido.');
   $cmd('MAIL FROM:<'.$from.'>',[250]);$cmd('RCPT TO:<'.$to.'>',[250,251]);$cmd('DATA',[354]);
   $safeSubject='=?UTF-8?B?'.base64_encode(str_replace(["\r","\n"],'',$subject)).'?=';$safeName='=?UTF-8?B?'.base64_encode($c['from_name']??'TaskEsc').'?=';
   $msg='From: '.$safeName.' <'.$from.">\r\nTo: ".$to."\r\nSubject: ".$safeSubject."\r\nDate: ".gmdate('r')."\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n".chunk_split(base64_encode($body),76,"\r\n");
   $cmd($msg."\r\n.",[250]);$cmd('QUIT',[221]);
  }finally{fclose($s);}
 }
}
