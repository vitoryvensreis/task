<?php
declare(strict_types=1);

final class Infra {
 private static ?array $env=null;
 private static mixed $redis=null;
 private static bool $redisResolved=false;

 public static function env(string $key,string $default=''): string {
  if(self::$env===null){
   self::$env=[];
   $files=['/etc/taskesc-realtime.env',ROOT.'/config/realtime.env'];
   if(PHP_SAPI==='cli')$files[]='/root/taskesc-realtime-secrets.env';
   foreach($files as $file){
    if(!is_file($file)||!is_readable($file))continue;
    foreach(file($file,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES)?:[] as $line){
     $line=trim($line);if($line===''||str_starts_with($line,'#')||!str_contains($line,'='))continue;
     [$k,$v]=explode('=',$line,2);$k=trim($k);$v=trim($v);
     if($k==='')continue;
     if((str_starts_with($v,'"')&&str_ends_with($v,'"'))||(str_starts_with($v,"'")&&str_ends_with($v,"'")))$v=substr($v,1,-1);
     self::$env[$k]=$v;
    }
   }
  }
  return (string)(self::$env[$key]??getenv($key)?:$default);
 }

 public static function redis(): mixed {
  if(self::$redisResolved)return self::$redis;
  self::$redisResolved=true;
  if(!class_exists('Redis'))return self::$redis=null;
  $url=self::env('REDIS_URL','redis://127.0.0.1:6379');
  $parts=parse_url($url)?:[];$host=(string)($parts['host']??'127.0.0.1');$port=(int)($parts['port']??6379);
  try{
   $r=new Redis();
   $persistentId='taskesc:'.PHP_SAPI;
   if(!$r->pconnect($host,$port,0.20,$persistentId,1000,0.20))return self::$redis=null;
   if(isset($parts['pass'])&&$parts['pass']!=='')$r->auth(isset($parts['user'])&&$parts['user']!==''?[$parts['user'],$parts['pass']]:$parts['pass']);
   $r->ping();
   return self::$redis=$r;
  }catch(Throwable $e){
   error_log('TaskEsc Redis: '.$e->getMessage());
   return self::$redis=null;
  }
 }

 public static function configureSession(): void {
  if(PHP_SAPI==='cli'||session_status()===PHP_SESSION_ACTIVE)return;
  $r=self::redis();if(!$r)return;
  try{
   $ttl=max(86400,(int)config('session_lifetime_seconds',2592000));
   session_set_save_handler(new TaskEscRedisSessionHandler($r,$ttl),true);
  }catch(Throwable $e){error_log('TaskEsc Redis session fallback: '.$e->getMessage());}
 }

 public static function rate(string $name,int $max,int $seconds,string $subject=''): ?int {
  $r=self::redis();if(!$r)return null;
  $key='taskesc:rate:'.hash('sha256',$name.'|'.$subject);
  try{
   $n=(int)$r->incr($key);
   if($n===1)$r->expire($key,max(1,$seconds));
   return $n;
  }catch(Throwable $e){error_log('TaskEsc Redis rate: '.$e->getMessage());return null;}
 }

 public static function cacheGet(string $key): mixed {
  $r=self::redis();if(!$r)return null;
  try{$v=$r->get('taskesc:cache:'.$key);if($v===false)return null;return json_decode((string)$v,true,512,JSON_THROW_ON_ERROR);}catch(Throwable){return null;}
 }

 public static function cacheSet(string $key,mixed $value,int $ttl=60): void {
  $r=self::redis();if(!$r)return;
  try{$r->setex('taskesc:cache:'.$key,max(1,$ttl),json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));}catch(Throwable){}
 }

 public static function cacheDelete(string $key): void {
  $r=self::redis();if(!$r)return;
  try{$r->del('taskesc:cache:'.$key);}catch(Throwable){}
 }

 public static function heartbeat(string $name,int $ttl=15): void {
  $r=self::redis();if(!$r)return;
  try{$r->setex('taskesc:heartbeat:'.$name,max(5,$ttl),(string)time());}catch(Throwable){}
 }

 public static function heartbeatAge(string $name): ?int {
  $r=self::redis();if(!$r)return null;
  try{$v=$r->get('taskesc:heartbeat:'.$name);return $v===false?null:max(0,time()-(int)$v);}catch(Throwable){return null;}
 }

 public static function status(): array {
  $r=self::redis();$ok=false;$memory=null;
  if($r)try{$ok=(string)$r->ping()!=='';$info=$r->info('memory');$memory=(int)($info['used_memory']??0);}catch(Throwable){}
  return ['redis'=>$ok,'redis_memory_bytes'=>$memory,'realtime_config'=>self::env('CENTRIFUGO_API_URL')!==''&&self::env('CENTRIFUGO_API_KEY')!==''&&self::env('CENTRIFUGO_HMAC_SECRET')!==''];
 }
}

final class TaskEscRedisSessionHandler implements SessionHandlerInterface {
 private string $prefix='taskesc:session:';
 private ?string $lockKey=null;
 private ?string $lockToken=null;

 public function __construct(private mixed $redis,private int $ttl){}

 public function open(string $path,string $name): bool {return true;}
 public function close(): bool {$this->release();return true;}

 private function acquire(string $id): void {
  $this->lockKey='taskesc:session-lock:'.hash('sha256',$id);
  $this->lockToken=bin2hex(random_bytes(16));
  for($i=0;$i<20;$i++){
   try{$ok=$this->redis->set($this->lockKey,$this->lockToken,['nx','px'=>30000]);if($ok)return;}catch(Throwable){return;}
   usleep(25000);
  }
 }

 private function release(): void {
  if(!$this->lockKey||!$this->lockToken)return;
  try{$this->redis->eval("if redis.call('get',KEYS[1]) == ARGV[1] then return redis.call('del',KEYS[1]) else return 0 end",[$this->lockKey,$this->lockToken],1);}catch(Throwable){}
  $this->lockKey=$this->lockToken=null;
 }

 public function read(string $id): string|false {
  $this->acquire($id);
  try{
   $v=$this->redis->get($this->prefix.$id);
   if($v!==false)return (string)$v;
   $legacy=ROOT.'/storage/sessions/sess_'.$id;
   if(is_file($legacy)&&is_readable($legacy)){
    $data=(string)@file_get_contents($legacy);
    if($data!==''){$this->redis->setex($this->prefix.$id,$this->ttl,$data);@unlink($legacy);return $data;}
   }
   return '';
  }catch(Throwable){return '';}
 }

 public function write(string $id,string $data): bool {
  try{return (bool)$this->redis->setex($this->prefix.$id,$this->ttl,$data);}catch(Throwable){return false;}finally{$this->release();}
 }

 public function destroy(string $id): bool {
  try{$this->redis->del($this->prefix.$id);return true;}catch(Throwable{return false;}finally{$this->release();}
 }

 public function gc(int $max_lifetime): int|false {return 0;}
}
