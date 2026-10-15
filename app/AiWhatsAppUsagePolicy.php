<?php
declare(strict_types=1);
/** MySQL-atomic per-provider quotas, admin alerts and log retention. */
final class AiWhatsAppUsagePolicy {
 public static function defaults(): array {return ['enabled'=>false,'gemini_daily_calls'=>0,'groq_daily_calls'=>0,'alert_percent'=>80,'retention_days'=>30];}
 public static function validate(array $p): array {
  $out=[];
  foreach(self::defaults() as $key=>$default){
   $v=$p[$key]??$default;
   if($key==='enabled'){
    if(!in_array($v,[true,false,0,1,'0','1'],true))throw new InvalidArgumentException('enabled');
    $out[$key]=in_array($v,[true,1,'1'],true);continue;
   }
   if(!is_int($v)&&!(is_string($v)&&ctype_digit($v)))throw new InvalidArgumentException($key);
   $n=filter_var($v,FILTER_VALIDATE_INT);
   if($n===false||!(match($key){
    'gemini_daily_calls','groq_daily_calls'=>$n>=0&&$n<=100000,
    'alert_percent'=>$n>=50&&$n<=99,
    'retention_days'=>$n>=30&&$n<=365,
    default=>false}))throw new InvalidArgumentException($key);
   $out[$key]=$n;
  }
  return $out;
 }
 public static function current(): array {
  $row=DB::one('SELECT value FROM settings WHERE `key`=?',['ai_usage_policy_v1']);
  if(!$row)return self::defaults();
  try{
   $cfg=json_decode((string)$row['value'],true,8,JSON_THROW_ON_ERROR);
   return is_array($cfg)?self::validate($cfg):self::defaults();
  }catch(Throwable){return self::defaults();}
 }
 private static function auth(bool $write=false): void {
  Auth::admin();
  if(Auth::isPreview())fail('Saia da visualização de cliente.',403);
  if(Auth::$origin==='api')Auth::scope($write?'api.write':'api.read');
 }
 public static function read(): array {
  self::auth();
  $cfg=self::current();$counts=self::reservedToday();
  return ['policy'=>$cfg,'today_utc'=>gmdate('Y-m-d'),'usage_today'=>$counts,
   'alerts'=>self::alertFlags($cfg,$counts),'note'=>'Teto por chamada de modelo. Consultas diretas e cache não são bloqueados.'];
 }
 public static function save(array $p): array {
  self::auth(true);
  try{$next=self::validate($p);}catch(InvalidArgumentException){fail('Configuração inválida. Revise limites e retenção.',422);}
  DB::run('INSERT INTO settings (`key`,value) VALUES (?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)',
   ['ai_usage_policy_v1',json($next)]);
  return self::read();
 }
 private static function counterKey(string $provider,string $day): string {
  if(!in_array($provider,['gemini','groq'],true)||!preg_match('/\A\d{4}-\d{2}-\d{2}\z/D',$day))throw new InvalidArgumentException('counter');
  return 'ai_usage_calls_'.$day.'_'.$provider;
 }
 public static function reservedToday(?string $day=null): array {
  $day??=gmdate('Y-m-d');$out=['gemini'=>0,'groq'=>0];
  foreach(array_keys($out) as $p){
   $r=DB::one('SELECT value FROM settings WHERE `key`=?',[self::counterKey($p,$day)]);
   $out[$p]=max(0,(int)($r['value']??0));
  }
  return $out;
 }
 /** Counter increments before HTTP; no refund on failed requests. */
 public static function reserve(string $provider,?string $day=null): void {
  $cfg=self::current();if(!$cfg['enabled'])return;
  if(!in_array($provider,['gemini','groq'],true))throw new RuntimeException('ai_provider_invalid');
  $cap=(int)$cfg[$provider.'_daily_calls'];if($cap===0)return;
  $key=self::counterKey($provider,$day??gmdate('Y-m-d'));
  DB::tx(static function()use($key,$cap){
   DB::run('INSERT IGNORE INTO settings (`key`,value) VALUES (?,?)',[$key,'0']);
   $r=DB::one('SELECT value FROM settings WHERE `key`=? FOR UPDATE',[$key]);
   $n=max(0,(int)($r['value']??0));
   if($n>=$cap)throw new RuntimeException('ai_usage_daily_limit');
   DB::run('UPDATE settings SET value=? WHERE `key`=?',[(string)($n+1),$key]);
  });
 }
 public static function alertFlags(array $cfg,array $used): array {
  $alerts=[];if(empty($cfg['enabled']))return $alerts;
  foreach(['gemini','groq'] as $p){
   $max=(int)$cfg[$p.'_daily_calls'];if($max<=0)continue;
   $n=max(0,(int)($used[$p]??0));$pct=(int)ceil($n*100/$max);
   if($n>=$max)$alerts[$p]=['level'=>'limit','percent'=>100,'used'=>$n,'max'=>$max];
   elseif($pct>=(int)$cfg['alert_percent'])$alerts[$p]=['level'=>'warning','percent'=>$pct,'used'=>$n,'max'=>$max];
  }
  return $alerts;
 }
 /** Idempotent notifications to admins only, no client/e-mail/WhatsApp. */
 public static function notifyAdmins(?string $day=null): array {
  $day??=gmdate('Y-m-d');
  $flags=self::alertFlags(self::current(),self::reservedToday($day));$created=0;
  foreach($flags as $provider=>$f){
   $title=$f['level']==='limit'?'TaskEsc IA: limite diário atingido':'TaskEsc IA: consumo próximo do limite';
   $body=ucfirst($provider).': '.$f['used'].' de '.$f['max'].' tentativas reservadas em '.$day.'. Verifique Monitoramento IA.';
   foreach(DB::all("SELECT id FROM users WHERE role='admin' AND active=1") as $u){
    $id=(int)$u['id'];$dedupe='ai-usage-'.$day.'-'.$provider.'-'.$f['level'].'-'.$id;
    $created+=DB::run('INSERT IGNORE INTO notifications (user_id,title,body,dedupe_key,created_at) VALUES (?,?,?,?,?)',
     [$id,$title,$body,$dedupe,now()])->rowCount();
   }
  }
  return ['alerts'=>$flags,'notifications_created'=>$created];
 }
 public static function cleanup(?DateTimeImmutable $now=null): array {
  $days=(int)self::current()['retention_days'];$utc=new DateTimeZone('UTC');
  $today=($now??new DateTimeImmutable('now',$utc))->setTimezone($utc);
  $cutoff=$today->modify('-'.$days.' days')->format('Y-m-d');
  $removed=0;$dir=ROOT.'/storage/logs';
  if(is_dir($dir))foreach(new DirectoryIterator($dir) as $file){
   if(!$file->isFile()||$file->isLink()||!preg_match('/\Aai-usage-(\d{4}-\d{2}-\d{2})\.jsonl\z/D',$file->getFilename(),$m)||$m[1]>=$cutoff)continue;
   if(@unlink($file->getPathname()))$removed++;
  }
  $counters=0;
  foreach(DB::all("SELECT `key` FROM settings WHERE `key` LIKE 'ai_usage_calls_%'") as $row){
   $key=(string)$row['key'];
   if(!preg_match('/\Aai_usage_calls_(\d{4}-\d{2}-\d{2})_(?:gemini|groq)\z/D',$key,$m)||$m[1]>=$cutoff)continue;
   $counters+=DB::run('DELETE FROM settings WHERE `key`=?',[$key])->rowCount();
  }
  return ['retention_days'=>$days,'files_deleted'=>$removed,'counters_deleted'=>$counters];
 }
 public static function dailyMaintenance(): array {
  return self::notifyAdmins()+['cleanup'=>self::cleanup()];
 }
}
