<?php
declare(strict_types=1);

final class Realtime {
 public static function enabled(): bool {
  return Infra::env('CENTRIFUGO_API_URL')!==''&&Infra::env('CENTRIFUGO_API_KEY')!==''&&Infra::env('CENTRIFUGO_HMAC_SECRET')!=='';
 }

 private static function b64url(string $raw): string {return rtrim(strtr(base64_encode($raw),'+/','-_'),'=');}

 private static function jwt(array $claims): string {
  $header=self::b64url(json_encode(['alg'=>'HS256','typ'=>'JWT'],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
  $payload=self::b64url(json_encode($claims,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
  $sig=hash_hmac('sha256',$header.'.'.$payload,Infra::env('CENTRIFUGO_HMAC_SECRET'),true);
  return $header.'.'.$payload.'.'.self::b64url($sig);
 }

 public static function connection(): array {
  Auth::load();
  if(!self::enabled())return ['enabled'=>false,'endpoint'=>'','token'=>'','ttl'=>0];
  $uid=(int)Auth::$user['id'];$role=(string)Auth::$user['role'];$channels=['user:'.$uid,'app:catalog'];
  if($role==='admin'){
   array_push($channels,'app:admin','app:agenda','app:finance');
  }elseif($role==='staff'){
   $channels[]='app:agenda';
   foreach(DB::all('SELECT client_id FROM user_clients WHERE user_id=?',[$uid]) as $r)$channels[]='app:client.'.(int)$r['client_id'];
  }else{
   if(Auth::isPreview()&&Auth::$previewClientId)$channels[]='app:client.'.(int)Auth::$previewClientId;
   else foreach(DB::all('SELECT client_id FROM user_clients WHERE user_id=?',[$uid]) as $r)$channels[]='app:client.'.(int)$r['client_id'];
  }
  $channels=array_values(array_unique($channels));
  $ttl=600;$now=time();
  $claims=['sub'=>(string)$uid,'iat'=>$now,'exp'=>$now+$ttl,'channels'=>$channels,'info'=>['name'=>(string)(Auth::$user['name']??''),'role'=>$role]];
  $endpoint=Infra::env('CENTRIFUGO_PUBLIC_WS');
  if($endpoint===''){
   $base=(string)config('base_url','');$parts=parse_url($base)?:[];$host=(string)($parts['host']??'');
   $endpoint=($host!==''?'wss://'.$host:'').'/realtime/connection/websocket';
  }
  return ['enabled'=>true,'endpoint'=>$endpoint,'token'=>self::jwt($claims),'ttl'=>$ttl,'channels_count'=>count($channels)];
 }

 public static function fromAudit(string $entity,int $id,string $action,mixed $before=null,mixed $after=null): void {
  if(!self::enabled())return;
  if($entity==='user'&&in_array($action,['login','password.change'],true))return;
  try{
   $scope=self::scopeFor($entity,$action);
   if($scope==='catalog')Infra::cacheDelete('catalog-global');
   $clientId=self::clientFor($entity,$id,$before,$after);
   $channels=self::channelsFor($scope,$entity,$id,$clientId);
   $payload=['scope'=>$scope,'entity'=>$entity,'entity_id'=>$id,'actor_id'=>(int)(Auth::$user['id']??0),'at'=>now()];
   self::enqueue($scope,$entity,$id,$action,$clientId,$channels,$payload);
  }catch(Throwable $e){error_log('TaskEsc realtime audit: '.$e->getMessage());}
 }

 public static function notification(int $notificationId,int $userId,?int $demandId=null): void {
  if(!self::enabled())return;
  try{
   self::enqueue('notifications','notification',$notificationId,'created',null,['user:'.$userId],[
    'scope'=>'notifications','entity'=>'notification','entity_id'=>$notificationId,'demand_id'=>$demandId,'actor_id'=>(int)(Auth::$user['id']??0),'at'=>now()
   ]);
  }catch(Throwable $e){error_log('TaskEsc realtime notification: '.$e->getMessage());}
 }

 public static function signal(string $scope,string $action='changed',int $entityId=0,?int $clientId=null): void {
  if(!self::enabled())return;
  try{
   $scope=in_array($scope,['tasks','agenda','finance','catalog','admin','notifications'],true)?$scope:'admin';
   $channels=self::channelsFor($scope,$scope,$entityId,$clientId);
   self::enqueue($scope,$scope,$entityId,$action,$clientId,$channels,[
    'scope'=>$scope,'entity'=>$scope,'entity_id'=>$entityId,'actor_id'=>(int)(Auth::$user['id']??0),'at'=>now()
   ]);
  }catch(Throwable $e){error_log('TaskEsc realtime signal: '.$e->getMessage());}
 }

 private static function enqueue(string $scope,string $entity,int $entityId,string $action,?int $clientId,array $channels,array $payload): void {
  $channels=array_values(array_unique(array_filter($channels)));
  if(!$channels)return;
  DB::insert('event_outbox',[
   'event_type'=>$scope.'.changed',
   'entity'=>$entity,
   'entity_id'=>$entityId,
   'action'=>mb_substr($action,0,100),
   'client_id'=>$clientId,
   'actor_id'=>(int)(Auth::$user['id']??0)?:null,
   'channels_json'=>json($channels),
   'payload_json'=>json($payload),
   'status'=>'pending',
   'attempts'=>0,
   'available_at'=>now(),
   'created_at'=>now()
  ]);
 }

 private static function scopeFor(string $entity,string $action): string {
  if(in_array($entity,['demand','comment','file','checklist','time_entry','change_request','review'],true))return 'tasks';
  if(in_array($entity,['event','calendar'],true))return 'agenda';
  if(in_array($entity,['invoice','payment','contract','expense','expense_rule','expense_category','cost'],true))return 'finance';
  if(in_array($entity,['client','project','stage','category','service','template','settings','user'],true))return 'catalog';
  if($entity==='notification')return 'notifications';
  return 'admin';
 }

 private static function clientFor(string $entity,int $id,mixed $before=null,mixed $after=null): ?int {
  $pick=function(mixed $v,string $key): int {return is_array($v)?(int)($v[$key]??0):0;};
  $client=$pick($after,'client_id')?:$pick($before,'client_id');
  if($client>0)return $client;
  if($entity==='client')return $id>0?$id:null;
  if($entity==='demand'){
   $r=DB::one('SELECT client_id FROM demands WHERE id=?',[$id]);return $r?(int)$r['client_id']:null;
  }
  if($entity==='project'||$entity==='contract'||$entity==='invoice'){
   $table=$entity==='project'?'projects':($entity==='contract'?'contracts':'invoices');$r=DB::one("SELECT client_id FROM $table WHERE id=?",[$id]);return $r?(int)$r['client_id']:null;
  }
  if($entity==='payment'){
   $r=DB::one('SELECT i.client_id FROM payments p JOIN invoices i ON i.id=p.invoice_id WHERE p.id=?',[$id]);return $r?(int)$r['client_id']:null;
  }
  if($entity==='cost'){
   $r=DB::one('SELECT d.client_id FROM costs c JOIN demands d ON d.id=c.demand_id WHERE c.id=?',[$id]);return $r?(int)$r['client_id']:null;
  }
  return null;
 }

 private static function channelsFor(string $scope,string $entity,int $entityId,?int $clientId): array {
  if($scope==='notifications')return [];
  if($scope==='tasks'){
   if($entity==='demand'&&$entityId>0){
    $d=DB::one('SELECT client_id,draft,created_by FROM demands WHERE id=?',[$entityId]);
    if($d){
     if((int)$d['draft']===1)return array_values(array_filter(['app:admin',(int)$d['created_by']>0?'user:'.(int)$d['created_by']:null]));
     $clientId=(int)$d['client_id'];
    }
   }
   return array_values(array_filter([$clientId?'app:client.'.$clientId:null,'app:admin']));
  }
  if($scope==='agenda')return ['app:agenda','app:admin'];
  if($scope==='finance')return ['app:finance','app:admin'];
  if($scope==='catalog')return array_values(array_filter(['app:catalog','app:admin',$clientId?'app:client.'.$clientId:null]));
  return ['app:admin'];
 }

 private static function taskSnapshot(int $id): ?array {
  $r=DB::one("SELECT d.id,d.client_id,d.project_id,d.category_id,d.stage_id,d.title,d.due_at,d.priority,d.assigned_to,d.version,d.sort_order,d.draft,d.deleted_at,d.updated_at,
   c.name AS client_name,ca.name AS category_name,s.name AS stage_name,s.kind AS stage_kind,s.color AS stage_color,u.name AS assigned_name
   FROM demands d
   JOIN clients c ON c.id=d.client_id
   JOIN categories ca ON ca.id=d.category_id
   JOIN stages s ON s.id=d.stage_id
   LEFT JOIN users u ON u.id=d.assigned_to
   WHERE d.id=?",[$id]);
  if(!$r||!empty($r['deleted_at']))return null;
  unset($r['deleted_at']);
  return $r;
 }

 private static function enrich(array $row): array {
  $payload=decode((string)$row['payload_json'],[]);
  $payload['outbox_id']=(int)$row['id'];
  $payload['event_type']=(string)$row['event_type'];
  if(($row['entity']??'')==='demand'){
   $task=self::taskSnapshot((int)$row['entity_id']);
   $payload['task']=$task;
   $payload['demand_id']=(int)$row['entity_id'];
   $payload['deleted']=$task===null;
  }
  return $payload;
 }

 public static function publishBatch(int $limit=100): int {
  if(!self::enabled())return 0;
  $limit=max(1,min(500,$limit));$worker=gethostname().':'.getmypid().':'.bin2hex(random_bytes(3));
  $rows=DB::tx(function() use($limit,$worker): array {
   try{$rows=DB::all("SELECT * FROM event_outbox WHERE status='pending' AND available_at<=? AND (locked_at IS NULL OR locked_at<?) ORDER BY id LIMIT $limit FOR UPDATE SKIP LOCKED",[now(),gmdate('Y-m-d H:i:s',time()-60)]);}
   catch(Throwable){$rows=DB::all("SELECT * FROM event_outbox WHERE status='pending' AND available_at<=? AND (locked_at IS NULL OR locked_at<?) ORDER BY id LIMIT $limit FOR UPDATE",[now(),gmdate('Y-m-d H:i:s',time()-60)]);}
   foreach($rows as $row)DB::run("UPDATE event_outbox SET status='processing',locked_at=?,locked_by=?,attempts=attempts+1 WHERE id=?",[now(),$worker,$row['id']]);
   return $rows;
  });
  if(!$rows)return 0;
  $done=0;
  foreach($rows as $row){
   try{
    $channels=decode((string)$row['channels_json'],[]);
    if($channels)self::broadcast($channels,self::enrich($row));
    DB::run("UPDATE event_outbox SET status='published',published_at=?,locked_at=NULL,locked_by=NULL,last_error=NULL WHERE id=?",[now(),$row['id']]);$done++;
   }catch(Throwable $e){
    $attempt=(int)$row['attempts']+1;$terminal=$attempt>=20;$delay=min(60,max(1,2**min(6,$attempt)));
    DB::run("UPDATE event_outbox SET status=?,available_at=?,locked_at=NULL,locked_by=NULL,last_error=? WHERE id=?",[$terminal?'failed':'pending',gmdate('Y-m-d H:i:s',time()+$delay),mb_substr($e->getMessage(),0,1000),$row['id']]);
    error_log('TaskEsc realtime publish #'.$row['id'].': '.$e->getMessage());
   }
  }
  return $done;
 }

 private static function broadcast(array $channels,array $data): void {
  $url=rtrim(Infra::env('CENTRIFUGO_API_URL'),'/').'/broadcast';$key=Infra::env('CENTRIFUGO_API_KEY');
  if($url==='/broadcast'||$key==='')throw new RuntimeException('Centrifugo API não configurada.');
  $body=['channels'=>array_values($channels),'data'=>$data];if(!empty($data['outbox_id']))$body['idempotency_key']='taskesc-outbox-'.(int)$data['outbox_id'];
  $ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json($body),CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>2,CURLOPT_TIMEOUT=>5,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_HTTPHEADER=>['Content-Type: application/json','Accept: application/json','X-API-Key: '.$key,'X-Centrifugo-Error-Mode: transport']]);
  $body=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$err=curl_error($ch);curl_close($ch);
  if($body===false||$status<200||$status>=300)throw new RuntimeException('Centrifugo HTTP '.($status?:0).': '.($err?:'falha'));
  $decoded=json_decode((string)$body,true);if(!is_array($decoded))throw new RuntimeException('Resposta inválida do Centrifugo.');
  if(!empty($decoded['error']))throw new RuntimeException('Centrifugo: '.($decoded['error']['message']??'erro'));
 }

 public static function cleanup(): void {
  try{DB::run("DELETE FROM event_outbox WHERE status='published' AND published_at<? LIMIT 5000",[gmdate('Y-m-d H:i:s',time()-86400)]);DB::run("DELETE FROM event_outbox WHERE status='failed' AND created_at<? LIMIT 1000",[gmdate('Y-m-d H:i:s',time()-86400*7)]);}catch(Throwable){}
 }

 public static function status(): array {
  Auth::admin();$pending=(int)(DB::one("SELECT COUNT(*) n FROM event_outbox WHERE status IN ('pending','processing')")['n']??0);$failed=(int)(DB::one("SELECT COUNT(*) n FROM event_outbox WHERE status='failed' AND created_at>=?",[gmdate('Y-m-d H:i:s',time()-86400)])['n']??0);
  return ['enabled'=>self::enabled(),'worker_age_seconds'=>Infra::heartbeatAge('realtime-worker'),'pending'=>$pending,'failed_24h'=>$failed,'infra'=>Infra::status()];
 }
}
