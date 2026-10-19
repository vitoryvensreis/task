<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if(($_SERVER['REQUEST_METHOD']??'')!=='POST'){http_response_code(405);echo '{"ok":false}';exit;}
try{
 installed();
 $env=(string)($_GET['environment']??'');
 $token=(string)($_SERVER['HTTP_ASAAS_ACCESS_TOKEN']??'');
 $length=(int)($_SERVER['CONTENT_LENGTH']??0);
 if($length>131072)throw new RuntimeException('asaas_webhook_oversized');
 $raw=(string)file_get_contents('php://input',false,null,0,131073);
 if(strlen($raw)>131072)throw new RuntimeException('asaas_webhook_oversized');
 $payload=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
 if(!is_array($payload))throw new RuntimeException('asaas_webhook_invalid_payload');
 $result=PlatformAsaasBilling::webhook($env,$token,$payload);
 http_response_code(200);echo json_encode($result);
}catch(Throwable $e){
 $msg=$e->getMessage();
 $status=$msg==='asaas_webhook_unauthorized'?401:
   (in_array($msg,['asaas_webhook_invalid','asaas_webhook_oversized','asaas_webhook_invalid_payload','asaas_webhook_missing_object'],true)?400:503);
 error_log('TaskEsc Asaas webhook rejected: '.$msg);
 http_response_code($status);echo '{"ok":false}';
}
