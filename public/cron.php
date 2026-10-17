<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
try{
 installed();SaasLegacyBoundary::assertSingleOperation();$key=(string)($_GET['key']??(getenv('TASKESC_CRON_KEY')?:getenv('STUDIOFLOW_CRON_KEY')?:''));if(PHP_SAPI!=='cli'&&!hash_equals((string)config('cron_key'),$key)){http_response_code(403);exit('Forbidden');}
 $result=['commercial_invoices'=>Finance::syncCommercialInvoices(),'cycles'=>Finance::ensureCycles(),'google'=>Google::sync(),'mail'=>Notify::flush(),'ran_at'=>now()];
 DB::run("INSERT INTO settings (`key`,value) VALUES ('last_cron_at',?) ON DUPLICATE KEY UPDATE value=VALUES(value)",[$result['ran_at']]);DB::run("INSERT INTO settings (`key`,value) VALUES ('last_cron_error','') ON DUPLICATE KEY UPDATE value=''");
 $result['health_watch']=SystemHealth::watch();
 // AI alerts/retention are best-effort and never block the existing cron tasks.
 try{$result['ai_usage_maintenance']=AiWhatsAppUsagePolicy::dailyMaintenance();}
 catch(Throwable $e){error_log('TaskEsc AI usage maintenance failed ['.get_class($e).']');$result['ai_usage_maintenance']=['status'=>'error'];}
 if(PHP_SAPI==='cli'){echo json($result).PHP_EOL;}else{header('Content-Type: application/json; charset=utf-8');echo json($result);}
}catch(Throwable $e){
 SystemHealth::capture($e,'cron');try{DB::run("INSERT INTO settings (`key`,value) VALUES ('last_cron_error',?) ON DUPLICATE KEY UPDATE value=VALUES(value)",[mb_substr($e->getMessage(),0,1000)]);}catch(Throwable){}
 error_log((string)$e);http_response_code(500);echo PHP_SAPI==='cli'?$e->getMessage():json(['error'=>'Cron falhou']);
}
