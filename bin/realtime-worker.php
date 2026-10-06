<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';

if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
set_time_limit(0);

if(!config()){fwrite(STDERR,"TaskEsc realtime worker: config indisponível.\n");exit(1);}
try{Migrations::run();DB::one('SELECT 1 AS ok');}catch(Throwable $e){fwrite(STDERR,"TaskEsc realtime worker: ".$e->getMessage()."\n");exit(1);}

$loops=0;
fwrite(STDOUT,"TaskEsc realtime worker iniciado em ".gmdate('c')."\n");
while(true){
 try{
  Infra::heartbeat('realtime-worker',15);
  $published=Realtime::publishBatch(200);
  if((++$loops%300)===0)Realtime::cleanup();
  if($published===0)usleep(200000);
 }catch(Throwable $e){
  error_log('TaskEsc realtime worker loop: '.$e->getMessage());
  usleep(1000000);
 }
}
