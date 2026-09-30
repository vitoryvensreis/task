<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
try{installed();$key=(string)($_GET['key']??(getenv('TASKESC_CRON_KEY')?:getenv('STUDIOFLOW_CRON_KEY')?:''));if(PHP_SAPI!=='cli'&&!hash_equals((string)config('cron_key'),$key)){http_response_code(403);exit('Forbidden');}$result=['commercial_invoices'=>Finance::syncCommercialInvoices(),'cycles'=>Finance::ensureCycles(),'google'=>Google::sync(),'mail'=>Notify::flush(),'ran_at'=>now()];if(PHP_SAPI==='cli'){echo json($result).PHP_EOL;}else{header('Content-Type: application/json; charset=utf-8');echo json($result);}}catch(Throwable $e){error_log((string)$e);http_response_code(500);echo PHP_SAPI==='cli'?$e->getMessage():json(['error'=>'Cron falhou']);}
