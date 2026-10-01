<?php
final class SystemHealth {
 public static function capture(Throwable $e,string $context='app',array $details=[]): void {
  try{
   if(!DB::one("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='system_errors'"))return;
   DB::insert('system_errors',['user_id'=>Auth::$user['id']??null,'level'=>'error','context'=>mb_substr($context,0,100),'message'=>mb_substr($e->getMessage(),0,5000),'request_uri'=>mb_substr((string)($_SERVER['REQUEST_URI']??''),0,2000),'details_json'=>json(['type'=>get_class($e),'code'=>$e->getCode(),'file'=>$e->getFile(),'line'=>$e->getLine()]+$details),'created_at'=>now()]);
  }catch(Throwable){}
 }
 private static function item(string $key,string $label,string $status,string $detail,mixed $meta=null): array {return compact('key','label','status','detail','meta');}
 public static function health(): array {
  Auth::admin();$items=[];$overall='ok';
  try{DB::one('SELECT 1 AS ok');$items[]=self::item('database','Banco de dados','ok','Conexão ativa');}catch(Throwable $e){$items[]=self::item('database','Banco de dados','error','Falha de conexão');$overall='error';}
  $last=setting('last_cron_at','');$lastTs=$last?strtotime($last.' UTC'):0;$age=$lastTs?time()-$lastTs:PHP_INT_MAX;$cronStatus=$age<=7200?'ok':($age<=21600?'warn':'error');$items[]=self::item('cron','Rotinas automáticas',$cronStatus,$last?'Última execução: '.$last:'Nenhuma execução registrada',['last_error'=>setting('last_cron_error','')]);if($cronStatus==='error')$overall='error';elseif($cronStatus==='warn'&&$overall==='ok')$overall='warn';
  $smtp=config('smtp',[]);$failed=(int)(DB::one("SELECT COUNT(*) AS n FROM mail_queue WHERE status='failed'")['n']??0);$pending=(int)(DB::one("SELECT COUNT(*) AS n FROM mail_queue WHERE status='pending' AND next_attempt<?",[gmdate('Y-m-d H:i:s',time()-1800)])['n']??0);$smtpStatus=empty($smtp['host'])?'warn':(($failed||$pending)?'warn':'ok');$items[]=self::item('smtp','E-mail / SMTP',$smtpStatus,empty($smtp['host'])?'SMTP não configurado':($failed.' falha(s), '.$pending.' pendente(s) atrasado(s)'));if($smtpStatus==='warn'&&$overall==='ok')$overall='warn';
  $google=DB::all('SELECT id,email,status,calendar_enabled,drive_enabled,last_error,expires_at FROM google_accounts ORDER BY id');$googleBad=array_values(array_filter($google,fn($g)=>$g['status']!=='connected'||!empty($g['last_error'])));$gStatus=$googleBad?'warn':'ok';$items[]=self::item('google','Contas Google',$gStatus,count($google).' conta(s), '.count($googleBad).' com atenção',$google);if($gStatus==='warn'&&$overall==='ok')$overall='warn';
  $cal=DB::all("SELECT id,name,last_error,synced_at,selected FROM calendars WHERE selected=1 ORDER BY name");$calBad=array_values(array_filter($cal,fn($x)=>!empty($x['last_error'])||(!$x['synced_at']&&$x['selected'])));$cStatus=$calBad?'warn':'ok';$items[]=self::item('calendar','Google Calendar',$cStatus,count($cal).' agenda(s) ativa(s), '.count($calBad).' com atenção',$cal);if($cStatus==='warn'&&$overall==='ok')$overall='warn';
  $root=ROOT;$free=@disk_free_space($root);$total=@disk_total_space($root);$pct=($free!==false&&$total)?round(($free/$total)*100,1):null;$diskStatus=$pct!==null&&$pct<10?'error':($pct!==null&&$pct<20?'warn':'ok');$items[]=self::item('disk','Armazenamento da VPS',$diskStatus,$pct===null?'Não foi possível medir':$pct.'% livre',['free'=>$free,'total'=>$total]);if($diskStatus==='error')$overall='error';elseif($diskStatus==='warn'&&$overall==='ok')$overall='warn';
  $openErrors=(int)(DB::one('SELECT COUNT(*) AS n FROM system_errors WHERE resolved_at IS NULL AND created_at>=?',[gmdate('Y-m-d H:i:s',time()-86400*7)])['n']??0);$eStatus=$openErrors>10?'error':($openErrors>0?'warn':'ok');$items[]=self::item('errors','Erros internos',$eStatus,$openErrors.' erro(s) aberto(s) nos últimos 7 dias');if($eStatus==='error')$overall='error';elseif($eStatus==='warn'&&$overall==='ok')$overall='warn';
  $pushBad=(int)(DB::one("SELECT COUNT(*) AS n FROM push_subscriptions WHERE active=1 AND last_error IS NOT NULL AND last_error<>''")['n']??0);$pushAll=(int)(DB::one('SELECT COUNT(*) AS n FROM push_subscriptions WHERE active=1')['n']??0);$pStatus=$pushBad?'warn':'ok';$items[]=self::item('push','Notificações Push',$pStatus,$pushAll.' dispositivo(s) ativo(s), '.$pushBad.' com erro');if($pStatus==='warn'&&$overall==='ok')$overall='warn';
  return ['status'=>$overall,'checked_at'=>now(),'items'=>$items];
 }
 public static function logs(array $p=[]): array {
  Auth::admin();$limit=max(10,min(300,(int)($p['limit']??100)));$q=trim((string)($p['q']??''));
  $aw='1=1';$aa=[];if($q!==''){$aw.=' AND (a.entity LIKE ? OR a.action LIKE ? OR u.name LIKE ?)';$like='%'.$q.'%';$aa=[$like,$like,$like];}
  $audit=DB::all("SELECT a.*,u.name AS user_name FROM audit_log a LEFT JOIN users u ON u.id=a.user_id WHERE $aw ORDER BY a.id DESC LIMIT ".$limit,$aa);
  $errors=DB::all('SELECT e.*,u.name AS user_name FROM system_errors e LEFT JOIN users u ON u.id=e.user_id ORDER BY e.id DESC LIMIT '.$limit);
  $mail=DB::all('SELECT id,recipient,subject,status,attempts,error,last_attempt_at,sent_at,created_at FROM mail_queue ORDER BY id DESC LIMIT '.$limit);
  return ['audit'=>$audit,'errors'=>$errors,'mail'=>$mail];
 }
 public static function resolve(array $p): array {Auth::admin();$id=integer($p['id']??0,1);if(!DB::one('SELECT id FROM system_errors WHERE id=?',[$id]))fail('Erro não encontrado.',404);DB::update('system_errors',$id,['resolved_at'=>now()]);audit('system_error',$id,'resolved');return ['saved'=>true];}
 public static function watch(): array {
  $issues=[];$last=setting('last_cron_at','');if($last&&strtotime($last.' UTC')<time()-21600)$issues[]='Rotina automática sem execução recente.';
  $failed=(int)(DB::one("SELECT COUNT(*) AS n FROM mail_queue WHERE status='failed' AND created_at>=?",[gmdate('Y-m-d H:i:s',time()-86400)])['n']??0);if($failed)$issues[]=$failed.' e-mail(s) falharam nas últimas 24h.';
  $g=(int)(DB::one("SELECT COUNT(*) AS n FROM google_accounts WHERE status<>'connected' OR (last_error IS NOT NULL AND last_error<>'')")['n']??0);if($g)$issues[]=$g.' conta(s) Google precisam de atenção.';
  $c=(int)(DB::one("SELECT COUNT(*) AS n FROM calendars WHERE selected=1 AND last_error IS NOT NULL AND last_error<>''")['n']??0);if($c)$issues[]=$c.' agenda(s) com erro de sincronização.';
  if($issues){$key='system-health:'.gmdate('YmdH');foreach(DB::all("SELECT id FROM users WHERE active=1 AND role='admin'") as $u)Notify::user((int)$u['id'],'Atenção no TaskEsc',implode("\n",$issues),null,$key.':'.$u['id']);}
  return ['issues'=>$issues];
 }
}
