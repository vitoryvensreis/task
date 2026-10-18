<?php
final class SystemHealth {
 public static function capture(Throwable $e,string $context='app',array $details=[]): void {
  try{
   if(!DB::one("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='system_errors'"))return;
   DB::insert('system_errors',['user_id'=>Auth::$user['id']??null,'level'=>'error','context'=>mb_substr($context,0,100),'message'=>mb_substr($e->getMessage(),0,5000),'request_uri'=>mb_substr((string)($_SERVER['REQUEST_URI']??''),0,2000),'details_json'=>json(['type'=>get_class($e),'code'=>$e->getCode(),'file'=>$e->getFile(),'line'=>$e->getLine()]+$details),'created_at'=>now()]);
  }catch(Throwable){}
 }
 private static function item(string $key,string $label,string $status,string $detail,mixed $meta=null): array {return compact('key','label','status','detail','meta');}
 /** Ajuda de diagnóstico exibida apenas ao administrador; não expõe tokens. */
 public static function googleDiagnostic(string $error,string $status='connected'): array {
  $normalized=mb_strtolower($error);
  if($status==='reconnect'||str_contains($normalized,'invalid_grant')||str_contains($normalized,'revoked')||str_contains($normalized,'expired or revoked'))
   return ['type'=>'authorization','hint'=>'Autorização Google inválida. Reconecte a mesma conta e confira se o OAuth está publicado em Produção.'];
  if(str_contains($normalized,'insufficient')||str_contains($normalized,'permission')||preg_match('/\b403\b/',$normalized))
   return ['type'=>'permission','hint'=>'Confira as permissões e se esta agenda ainda é compartilhada com a conta conectada.'];
  if(str_contains($normalized,'rate limit')||str_contains($normalized,'quota')||preg_match('/\b429\b/',$normalized))
   return ['type'=>'quota','hint'=>'Limite da API Google atingido; aguarde e tente sincronizar novamente.'];
  if(str_contains($normalized,'timeout')||str_contains($normalized,'conexão')||str_contains($normalized,'could not resolve')||preg_match('/\b(408|500|502|503|504)\b/',$normalized))
   return ['type'=>'temporary','hint'=>'Falha temporária de comunicação. O sistema tentará novamente sem revogar a conexão.'];
  if(str_contains($normalized,'not found')||preg_match('/\b404\b/',$normalized))
   return ['type'=>'missing','hint'=>'A agenda pode ter sido removida ou deixado de estar disponível nesta conta.'];
  if($error==='')return ['type'=>'pending','hint'=>'Ainda não houve sincronização bem-sucedida. Confira a conta e tente sincronizar.'];
  return ['type'=>'other','hint'=>'Consulte o erro retornado pelo Google e tente sincronizar novamente.'];
 }

 private static function googleAccounts(): array {
  $rows=DB::all("SELECT id,email,status,calendar_enabled,drive_enabled,last_error,expires_at FROM google_accounts WHERE status<>'disconnected' ORDER BY id");
  foreach($rows as &$row)$row+=self::googleDiagnostic((string)($row['last_error']??''),(string)$row['status']);
  unset($row);return $rows;
 }

 public static function health(): array {
  Auth::load();if(!MasterSupport::isActor()&&!(PHP_SAPI==='cli'&&defined('TASKESC_MASTER_HEALTH_TEST_FIXTURE')))fail('Acesso restrito ao ADM Master.',403);$items=[];$overall='ok';
  try{DB::one('SELECT 1 AS ok');$items[]=self::item('database','Banco de dados','ok','Conexão ativa');}catch(Throwable $e){$items[]=self::item('database','Banco de dados','error','Falha de conexão');$overall='error';}
  $last=setting('last_cron_at','');$lastTs=$last?strtotime($last.' UTC'):0;$age=$lastTs?time()-$lastTs:PHP_INT_MAX;$cronStatus=$age<=7200?'ok':($age<=21600?'warn':'error');$items[]=self::item('cron','Rotinas automáticas',$cronStatus,$last?'Última execução: '.$last:'Nenhuma execução registrada',['last_error'=>setting('last_cron_error','')]);if($cronStatus==='error')$overall='error';elseif($cronStatus==='warn'&&$overall==='ok')$overall='warn';
  $smtp=config('smtp',[]);$failed=(int)(DB::one("SELECT COUNT(*) AS n FROM mail_queue WHERE status='failed'")['n']??0);$pending=(int)(DB::one("SELECT COUNT(*) AS n FROM mail_queue WHERE status='pending' AND next_attempt<?",[gmdate('Y-m-d H:i:s',time()-1800)])['n']??0);$smtpStatus=empty($smtp['host'])?'warn':(($failed||$pending)?'warn':'ok');$items[]=self::item('smtp','E-mail / SMTP',$smtpStatus,empty($smtp['host'])?'SMTP não configurado':($failed.' falha(s), '.$pending.' pendente(s) atrasado(s)'));if($smtpStatus==='warn'&&$overall==='ok')$overall='warn';
  $google=self::googleAccounts();$googleBad=array_values(array_filter($google,static fn($g)=>$g['status']!=='connected'||!empty($g['last_error'])));$gStatus=$googleBad?'warn':'ok';$items[]=self::item('google','Contas Google',$gStatus,count($google).' conta(s) em uso, '.count($googleBad).' com atenção',$google);if($gStatus==='warn'&&$overall==='ok')$overall='warn';  $root=ROOT;$free=@disk_free_space($root);$total=@disk_total_space($root);$pct=($free!==false&&$total)?round(($free/$total)*100,1):null;$diskStatus=$pct!==null&&$pct<10?'error':($pct!==null&&$pct<20?'warn':'ok');$items[]=self::item('disk','Armazenamento da VPS',$diskStatus,$pct===null?'Não foi possível medir':$pct.'% livre',['free'=>$free,'total'=>$total]);if($diskStatus==='error')$overall='error';elseif($diskStatus==='warn'&&$overall==='ok')$overall='warn';
  $openErrors=(int)(DB::one('SELECT COUNT(*) AS n FROM system_errors WHERE resolved_at IS NULL AND created_at>=?',[gmdate('Y-m-d H:i:s',time()-86400*7)])['n']??0);$eStatus=$openErrors>10?'error':($openErrors>0?'warn':'ok');$items[]=self::item('errors','Erros internos',$eStatus,$openErrors.' erro(s) aberto(s) nos últimos 7 dias');if($eStatus==='error')$overall='error';elseif($eStatus==='warn'&&$overall==='ok')$overall='warn';
  $pushBad=(int)(DB::one("SELECT COUNT(*) AS n FROM push_subscriptions WHERE active=1 AND last_error IS NOT NULL AND last_error<>''")['n']??0);$pushAll=(int)(DB::one('SELECT COUNT(*) AS n FROM push_subscriptions WHERE active=1')['n']??0);$pushFailed=(int)(DB::one("SELECT COUNT(*) AS n FROM push_delivery_log WHERE status='failed' AND created_at>=?",[gmdate('Y-m-d H:i:s',time()-86400)])['n']??0);$pStatus=($pushBad||$pushFailed)?'warn':'ok';$items[]=self::item('push','Notificações Push',$pStatus,$pushAll.' dispositivo(s) ativo(s), '.$pushBad.' assinatura(s) com erro, '.$pushFailed.' falha(s) de entrega nas últimas 24h');if($pStatus==='warn'&&$overall==='ok')$overall='warn';
  return ['status'=>$overall,'checked_at'=>now(),'items'=>$items];
 }
 public static function logs(array $p=[]): array {
  Auth::load();if(!MasterSupport::isActor()&&!(PHP_SAPI==='cli'&&defined('TASKESC_MASTER_HEALTH_TEST_FIXTURE')))fail('Acesso restrito ao ADM Master.',403);$limit=max(10,min(300,(int)($p['limit']??100)));$q=trim((string)($p['q']??''));
  $aw='1=1';$aa=[];if($q!==''){$aw.=' AND (a.entity LIKE ? OR a.action LIKE ? OR u.name LIKE ?)';$like='%'.$q.'%';$aa=[$like,$like,$like];}
  $audit=DB::all("SELECT a.*,u.name AS user_name FROM audit_log a LEFT JOIN users u ON u.id=a.user_id WHERE $aw ORDER BY a.id DESC LIMIT ".$limit,$aa);
  $errors=DB::all('SELECT e.*,u.name AS user_name FROM system_errors e LEFT JOIN users u ON u.id=e.user_id ORDER BY e.id DESC LIMIT '.$limit);
  $mail=DB::all('SELECT id,recipient,subject,status,attempts,error,last_attempt_at,sent_at,created_at FROM mail_queue ORDER BY id DESC LIMIT '.$limit);
  $push=DB::all("SELECT l.id,l.user_id,l.subscription_id,l.notification_id,l.demand_id,l.scope,l.title,l.body,l.url,l.status,l.http_code,l.error,l.user_agent,l.created_at,u.name AS user_name,u.email AS user_email,d.title AS demand_title,ru.name AS responsible_name
   FROM push_delivery_log l
   LEFT JOIN users u ON u.id=l.user_id
   LEFT JOIN demands d ON d.id=l.demand_id
   LEFT JOIN users ru ON ru.id=d.assigned_to
   ORDER BY l.id DESC LIMIT ".$limit);
  return ['audit'=>$audit,'errors'=>$errors,'mail'=>$mail,'push'=>$push];
 }
 public static function resolve(array $p): array {Auth::load();if(!MasterSupport::isActor()&&!(PHP_SAPI==='cli'&&defined('TASKESC_MASTER_HEALTH_TEST_FIXTURE')))fail('Acesso restrito ao ADM Master.',403);$id=integer($p['id']??0,1);if(!DB::one('SELECT id FROM system_errors WHERE id=?',[$id]))fail('Erro não encontrado.',404);DB::update('system_errors',$id,['resolved_at'=>now()]);audit('system_error',$id,'resolved');return ['saved'=>true];}
 private static function currentIssues(): array {
  $issues=[];$fingerprints=[];
  $last=setting('last_cron_at','');if($last&&strtotime($last.' UTC')<time()-21600){$issues[]='Rotina automática sem execução recente.';$fingerprints[]='cron';}
  $failed=(int)(DB::one("SELECT COUNT(*) AS n FROM mail_queue WHERE status='failed' AND created_at>=?",[gmdate('Y-m-d H:i:s',time()-86400)])['n']??0);
  if($failed){$issues[]=$failed.' e-mail(s) falharam nas últimas 24h.';$fingerprints[]='mail';}
  foreach(self::googleAccounts() as $account){
   if($account['status']==='connected'&&empty($account['last_error']))continue;
   $reason=self::googleDiagnostic((string)($account['last_error']??''),(string)$account['status']);
   $issues[]='Google '.$account['email'].': '.($account['status']==='reconnect'?'reconexão necessária':'falha de integração').'.';
   $fingerprints[]='google:'.$account['id'].':'.$account['status'].':'.$reason['type'];
  }
  sort($fingerprints,SORT_STRING);
  return [$issues,$fingerprints?hash('sha256',implode('|',$fingerprints)):''];
 }

 /** Avisa na primeira falha, na mudança da causa e no máximo uma vez a cada 24h. */
 public static function watch(): array {
  [$issues,$fingerprint]=self::currentIssues();
  // As chaves ficam em settings para não exigir migração ou zerar notificações antigas.
  foreach(['system_health_watch_fingerprint'=>'','system_health_watch_last_sent_at'=>'','system_health_watch_sequence'=>'0'] as $key=>$default){
   DB::run('INSERT IGNORE INTO settings (`key`,value) VALUES (?,?)',[$key,$default]);
  }
  return DB::tx(static function() use($issues,$fingerprint): array {
   $state=DB::all("SELECT `key`,value FROM settings WHERE `key` IN ('system_health_watch_fingerprint','system_health_watch_last_sent_at','system_health_watch_sequence') ORDER BY `key` FOR UPDATE");
   $values=[];foreach($state as $item)$values[$item['key']]=(string)$item['value'];
   $previous=$values['system_health_watch_fingerprint']??'';
   $lastSent=(string)($values['system_health_watch_last_sent_at']??'');
   $elapsed=$lastSent!==''?(time()-(strtotime($lastSent.' UTC')?:0)):PHP_INT_MAX;
   $recovered=$previous!==''&&$fingerprint==='';
   $notify=$recovered||($fingerprint!==''&&($previous!==$fingerprint||$elapsed>=86400));
   if($notify){
    $sequence=(int)($values['system_health_watch_sequence']??0)+1;
    DB::run('UPDATE settings SET value=? WHERE `key`=?',[(string)$sequence,'system_health_watch_sequence']);
    DB::run('UPDATE settings SET value=? WHERE `key`=?',[now(),'system_health_watch_last_sent_at']);
    $title=$recovered?'Alertas do TaskEsc normalizados':'Atenção no TaskEsc';
    $body=$recovered?'As falhas monitoradas foram resolvidas.':implode("\n",$issues);
    foreach((PHP_SAPI==='cli'&&defined('TASKESC_MASTER_HEALTH_TEST_FIXTURE')
      ? DB::all("SELECT id FROM users WHERE active=1 AND role='admin'")
      : DB::all("SELECT u.id FROM users u JOIN platform_master_identities p ON p.user_id=u.id
      WHERE p.email=? AND p.status='verified' AND u.role='admin' AND u.active=1",[WorkspaceFoundation::MASTER_EMAIL])) as $user){
     Notify::user((int)$user['id'],$title,$body,null,'system-health:v2:'.$sequence.':'.$user['id']);
    }
   }
   if($previous!==$fingerprint)DB::run('UPDATE settings SET value=? WHERE `key`=?',[$fingerprint,'system_health_watch_fingerprint']);
   return ['issues'=>$issues,'notified'=>$notify,'recovered'=>$recovered];
  });
 }
}
