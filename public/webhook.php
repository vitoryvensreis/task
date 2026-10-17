<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
try{SaasLegacyBoundary::assertSingleOperation();$id=$_SERVER['HTTP_X_GOOG_CHANNEL_ID']??'';$token=$_SERVER['HTTP_X_GOOG_CHANNEL_TOKEN']??'';$resource=$_SERVER['HTTP_X_GOOG_RESOURCE_ID']??'';if(!$id||!$token||!$resource){http_response_code(400);exit;} $c=DB::one('SELECT * FROM calendars WHERE channel_id=? AND channel_token=? AND channel_resource=?',[$id,$token,$resource]);if(!$c){http_response_code(404);exit;}DB::update('calendars',$c['id'],['dirty'=>1]);if(($_SERVER['HTTP_X_GOOG_RESOURCE_STATE']??'')==='exists')Google::sync((int)$c['id']);http_response_code(204);}catch(Throwable $e){error_log('Google webhook: '.(string)$e);http_response_code(204);}
