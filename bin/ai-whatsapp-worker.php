<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/app/bootstrap.php';
try{
 if(in_array('--health',$argv,true)){
  $health=AiWhatsAppWorker::health();echo json($health).PHP_EOL;
  exit($health['status']==='ok'?0:1);
 }
 if(in_array('--once',$argv,true)){echo json(AiWhatsAppWorker::tick()).PHP_EOL;exit(0);}
 $running=true;
 if(function_exists('pcntl_async_signals')){
  pcntl_async_signals(true);
  pcntl_signal(SIGTERM,static function() use (&$running): void {$running=false;});
  pcntl_signal(SIGINT,static function() use (&$running): void {$running=false;});
 }
 while($running){
  try{AiWhatsAppWorker::tick(3);}
  catch(Throwable $e){error_log('TaskEsc AI worker loop '.get_class($e));}
  usleep(1200000);
 }
}catch(Throwable $e){
 error_log('TaskEsc AI worker fatal '.get_class($e));
 fwrite(STDERR,json(['status'=>'error','code'=>'ai_worker_error']).PHP_EOL);
 exit(1);
}
