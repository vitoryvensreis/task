<?php
declare(strict_types=1);
/**
 * One-time operator action: re-submit the correct webhook via WPPConnect's
 * official start-session endpoint. NEVER logout, close or erase tokens.
 * Intentionally not exposed via HTTP or to the AI tools.
 */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
if(($argv[1]??'')!=='--once'||count($argv)!==2){
 fwrite(STDERR,"Usage: php bin/wpp-session-rebind.php --once\n");exit(2);
}
require dirname(__DIR__).'/app/bootstrap.php';
try{
 $admin=DB::one("SELECT * FROM users WHERE role='admin' AND active=1 ORDER BY id LIMIT 1");
 if(!$admin)throw new RuntimeException('admin_not_available');
 Auth::$user=$admin;Auth::$actor=$admin;Auth::$previewClientId=null;Auth::$origin='web';
 $before=WppConnect::status();
 if(empty($before['configured'])||empty($before['provider_up'])||empty($before['connected'])){
  echo json(['status'=>'skipped','reason'=>'session_not_connected',
   'provider_up'=>(bool)($before['provider_up']??false)]).PHP_EOL;
  exit(0);
 }
 // The application already knows the authenticated webhook destination.
 // WppConnect::start uses POST /api/<session>/start-session with webhook URL.
 $_SERVER['REQUEST_METHOD']='POST';
 $started=WppConnect::start();
 $after=WppConnect::status();
 echo json(['status'=>'requested','provider_status'=>(int)($started['provider_status']??0),
  'connected_before'=>true,'connected_after'=>(bool)($after['connected']??false),
  'session_reconnected'=>!empty($after['connected']),
  'next_step'=>'Send a NEW WhatsApp message and confirm it is received in AI inbox']).PHP_EOL;
 if(empty($after['connected']))exit(1);
}catch(Throwable $e){
 error_log('TaskEsc WPP session webhook rebind ['.get_class($e).']');
 echo json(['status'=>'error','code'=>'wpp_session_rebind_failed']).PHP_EOL;
 exit(1);
}
