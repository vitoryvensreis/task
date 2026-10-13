<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/app/bootstrap.php';

$once=in_array('--once',$argv,true);
$health=in_array('--health',$argv,true);
$batch=100;$runs=20;
foreach($argv as $arg){
 if(str_starts_with($arg,'--batch='))$batch=max(1,min(500,(int)substr($arg,8)));
 if(str_starts_with($arg,'--runs='))$runs=max(1,min(100,(int)substr($arg,7)));
}

try{
 if($health){
  $data=AutomationWorker::health();
  echo json($data).PHP_EOL;
  exit(($data['status']??'degraded')==='ok'?0:1);
 }
 if($once){
  echo json(AutomationWorker::tick($batch,$runs)).PHP_EOL;
  exit(0);
 }
 $running=true;
 if(function_exists('pcntl_async_signals')){
  pcntl_async_signals(true);
  pcntl_signal(SIGTERM,static function() use (&$running): void {$running=false;});
  pcntl_signal(SIGINT,static function() use (&$running): void {$running=false;});
 }
 while($running){
  try{AutomationWorker::tick($batch,$runs);}
  catch(Throwable $e){error_log('TaskEsc automation worker: '.$e->getMessage());}
  usleep(500000);
 }
 exit(0);
}catch(Throwable $e){
 error_log('TaskEsc automation worker fatal: '.$e->getMessage());
 fwrite(STDERR,json(['status'=>'error','code'=>'automation_worker_failed']).PHP_EOL);
 exit(1);
}
