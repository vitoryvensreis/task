<?php
declare(strict_types=1);
/** Read-only production audit; deliberately omits addresses, messages and credentials. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/app/bootstrap.php';
try{
 installed();$since=gmdate('Y-m-d H:i:s',time()-86400);
 $count=static function(string $table,string $where='1=1',array $args=[]):int {
   if(!DB::one('SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',[$table]))return 0;
   return (int)(DB::one('SELECT COUNT(*) n FROM `'.$table.'` WHERE '.$where,$args)['n']??0);
 };
 $last=setting('last_cron_at','');$lastTs=$last?strtotime($last.' UTC'):0;
 $smtpConfigured=trim((string)(config('smtp',[])['host']??''))!=='';
 $sent=$count('mail_queue',"status='sent' AND sent_at>=?",[$since])+
   $count('platform_mail_queue',"status='sent' AND sent_at>=?",[$since]);
 $pending=$count('mail_queue',"status='pending'")+$count('platform_mail_queue',"status='pending'");
 $failed=$count('mail_queue',"status='failed'")+$count('platform_mail_queue',"status='failed'");
 $ws=RealtimeWorkerHealth::read();
 echo json_encode([
  'smtp'=>['configured'=>$smtpConfigured,'sent_24h'=>$sent,'pending'=>$pending,'failed'=>$failed,
    'last_cron_at'=>$last?:null,'cron_age_minutes'=>$lastTs?(int)round((time()-$lastTs)/60):null],
  'push'=>['devices_active'=>$count('push_subscriptions','active=1'),
    'provider_accepted_24h'=>$count('push_delivery_log',"status='sent' AND created_at>=?",[$since]),
    'failed_24h'=>$count('push_delivery_log',"status='failed' AND created_at>=?",[$since])],
  'websocket'=>['worker'=>$ws['status']??'missing','last_published_at'=>$ws['last_published_at']??null],
  'notification_events_24h'=>$count('event_outbox',"event_type='notification.created' AND created_at>=?",[$since]),
 ],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES).PHP_EOL;
}catch(Throwable $e){
 error_log('TaskEsc notification audit: '.get_class($e));
 echo json_encode(['status'=>'unavailable']).PHP_EOL;exit(1);
}
