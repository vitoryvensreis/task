<?php
declare(strict_types=1);
define('ROOT', dirname(__DIR__));
spl_autoload_register(function(string $name): void { $file=__DIR__.'/'.str_replace('\\','/',$name).'.php'; if(is_file($file)) require $file; });
function config(?string $key=null, mixed $default=null): mixed { static $c; if($c===null) $c=is_file(ROOT.'/config/config.php')?require ROOT.'/config/config.php':[]; return $key===null?$c:($c[$key]??$default); }
date_default_timezone_set('UTC');
ini_set('display_errors','0');
ini_set('log_errors','1');
ini_set('error_log',ROOT.'/storage/logs/php.log');
if(PHP_SAPI!=='cli'){
 header('X-Content-Type-Options: nosniff'); header('Referrer-Policy: same-origin'); header('X-Frame-Options: DENY');
 $baseUrl=(string)config('base_url','');$host=(string)(parse_url($baseUrl,PHP_URL_HOST)??'');$port=parse_url($baseUrl,PHP_URL_PORT);$scheme=(string)(parse_url($baseUrl,PHP_URL_SCHEME)??'https');$wsOrigin='';
 if($host!==''&&preg_match('/\\A[A-Za-z0-9.-]+\\z/D',$host))$wsOrigin=' '.($scheme==='http'?'ws':'wss').'://'.$host.($port?':'.(int)$port:'');
 header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; connect-src 'self'".$wsOrigin." https://www.googleapis.com https://*.googleapis.com; media-src 'self'; frame-ancestors 'none'; form-action 'self'; base-uri 'self'");
 header('Cache-Control: no-store');
 if(session_status()!==PHP_SESSION_ACTIVE){
  $sessionDir=ROOT.'/storage/sessions';if(!is_dir($sessionDir))@mkdir($sessionDir,0700,true);if(is_dir($sessionDir))ini_set('session.save_path',$sessionDir);
  $sessionLifetime=max(86400,(int)config('session_lifetime_seconds',2592000));
  ini_set('session.gc_maxlifetime',(string)$sessionLifetime);
  session_name('taskesc');
  session_set_cookie_params(['lifetime'=>$sessionLifetime,'path'=>'/','secure'=>str_starts_with((string)config('base_url'),'https://'),'httponly'=>true,'samesite'=>'Lax']);
  ini_set('session.use_strict_mode','1'); session_start();
 }
}
function now(): string {return gmdate('Y-m-d H:i:s');}
function json(mixed $value): string { return json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR); }
function decode(?string $value, mixed $fallback=[]): mixed {return $value?json_decode($value,true,512,JSON_THROW_ON_ERROR):$fallback;}
function out(mixed $data,int $status=200): never {http_response_code($status);header('Content-Type: application/json; charset=utf-8');echo json($data);exit;}
function fail(string $message,int $status=422,array $details=[]): never {throw new AppError($message,$status,$details);}
function e(mixed $value): string {return htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function base(string $path=''): string {return rtrim((string)config('base_url'),'/').'/'.ltrim($path,'/');}
function required(array $data,string $field,int $max=10000): string {$v=trim((string)($data[$field]??''));if($v===''||mb_strlen($v)>$max)fail("Preencha $field (máximo $max caracteres).");return $v;}
function enumv(mixed $v,array $allowed,string $label): string {if(!is_string($v)||!in_array($v,$allowed,true))fail("Valor inválido: $label.");return $v;}
function integer(mixed $v,int $min=0,int $max=1000000000): int {if(filter_var($v,FILTER_VALIDATE_INT)===false||(int)$v<$min||(int)$v>$max)fail('Número inteiro fora do intervalo permitido.');return (int)$v;}
function datev(mixed $v,bool $nullable=true): ?string {if(($v===null||$v==='')&&$nullable)return null;$d=DateTimeImmutable::createFromFormat('!Y-m-d',(string)$v);if(!$d||$d->format('Y-m-d')!==$v)fail('Data inválida. Use AAAA-MM-DD.');return (string)$v;}
function timestamp(mixed $v): ?string {if(!$v)return null;try{$d=new DateTimeImmutable((string)$v,new DateTimeZone(config('timezone','America/Fortaleza')));return $d->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');}catch(Throwable){fail('Data e horário inválidos.');}}
function businessDeadline(int $hours, ?DateTimeImmutable $start=null): DateTimeImmutable {
 $hours=max(1,$hours);$tz=new DateTimeZone(config('timezone','America/Fortaleza'));$cursor=$start?$start->setTimezone($tz):new DateTimeImmutable('now',$tz);$cursor=$cursor->setTime((int)$cursor->format('H'),(int)$cursor->format('i'),0);$remaining=$hours;
 while($remaining>0){$cursor=$cursor->modify('+1 hour');if((int)$cursor->format('N')<=5)$remaining--;}
 return $cursor;
}
function minDeliveryHours(): int {try{return max(1,min(720,(int)setting('min_delivery_business_hours','24')));}catch(Throwable){return 24;}}
function minDeliveryLocal(): string {return businessDeadline(minDeliveryHours())->format('Y-m-d\TH:i');}
function validateDelivery(string $utc, ?string $oldUtc=null): void {
 if($oldUtc!==null&&$oldUtc!==''&&$utc===$oldUtc)return;
 $min=businessDeadline(minDeliveryHours());$due=(new DateTimeImmutable($utc,new DateTimeZone('UTC')))->setTimezone(new DateTimeZone(config('timezone','America/Fortaleza')));
 if($due<$min)fail('A data de entrega precisa respeitar o prazo mínimo de '.minDeliveryHours().' horas úteis. Primeiro horário disponível: '.$min->format('d/m/Y H:i').'.');
}
function urlv(mixed $v): string {$v=trim((string)$v);if($v!==''&&(!filter_var($v,FILTER_VALIDATE_URL)||!in_array(parse_url($v,PHP_URL_SCHEME),['https','http'],true)))fail('Informe um link HTTP ou HTTPS válido.');return $v;}
function csrf(): string {return $_SESSION['csrf']??=bin2hex(random_bytes(24));}
function checkCsrf(): void {if(!hash_equals(csrf(),(string)($_SERVER['HTTP_X_CSRF_TOKEN']??$_POST['csrf']??'')))fail('Sessão expirada. Atualize a página.',419);}
function audit(string $entity,int $id,string $action,mixed $before=null,mixed $after=null): void {
 $support=(bool)(Auth::$user['is_support']??false);
 $actorId=$support?(int)(Auth::$actor['id']??0):(int)(Auth::$user['id']??0);
 if($support)$after=['support_session_id'=>(int)(Auth::$workspace['support_session_id']??0),
   'effective_user_id'=>(int)(Auth::$user['id']??0),'data'=>$after];
 $row=['user_id'=>$actorId?:null,'entity'=>$entity,'entity_id'=>$id,'action'=>$action,
   'before_json'=>$before===null?null:json($before),
   'after_json'=>$after===null?null:json($after),
   'origin'=>$support?'support':Auth::$origin,'created_at'=>now()];
 $workspace=(int)(Auth::$workspace['id']??WorkspaceJobScope::currentId());
 if(!WorkspaceContext::legacyCiFixture()&&$workspace>0)
  $row['workspace_id']=$workspace;
 if(WorkspaceTenantMode::enabled()&&$workspace===0){
  if(!MasterSupport::isActor())throw new LogicException('Platform audit requires verified Master identity.');
  DB::insert('platform_audit_log',$row);
 }else DB::insert('audit_log',$row);
}
function setting(string $key,string $default=''): string {return (string)(DB::one('SELECT value FROM settings WHERE `key`=?',[$key])['value']??$default);}
function installed(): void {if(!config()||!is_file(ROOT.'/storage/installed.lock'))fail('Instale o sistema em install.php.',503);Migrations::run();}
function secretEncrypt(string $plain): string {$iv=random_bytes(12);$tag='';$cipher=openssl_encrypt($plain,'aes-256-gcm',hex2bin(config('app_key')),OPENSSL_RAW_DATA,$iv,$tag);if($cipher===false)throw new RuntimeException('Encryption failed');return base64_encode($iv.$tag.$cipher);}
function secretDecrypt(string $encoded): string {$raw=base64_decode($encoded,true);if($raw===false||strlen($raw)<28)throw new RuntimeException('Invalid encrypted token');$v=openssl_decrypt(substr($raw,28),'aes-256-gcm',hex2bin(config('app_key')),OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,12,16));if($v===false)throw new RuntimeException('Cannot decrypt token');return $v;}
