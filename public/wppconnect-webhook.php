<?php
declare(strict_types=1);

require dirname(__DIR__).'/app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST'){
    http_response_code(405);
    echo json(['ok'=>false,'error'=>'method_not_allowed']);
    exit;
}

$length=(int)($_SERVER['CONTENT_LENGTH']??0);
if($length>1048576){
    http_response_code(413);
    echo json(['ok'=>false,'error'=>'invalid_payload_size']);
    exit;
}

$token=(string)($_GET['token']??'');
if(!WppConnect::verifyWebhookToken($token)){
    http_response_code(403);
    echo json(['ok'=>false,'error'=>'forbidden']);
    exit;
}

$raw=file_get_contents('php://input');
if($raw===false||trim($raw)===''){
    http_response_code(400);
    echo json(['ok'=>false,'error'=>'empty_payload']);
    exit;
}
if(strlen($raw)>1048576){
    http_response_code(413);
    echo json(['ok'=>false,'error'=>'invalid_payload_size']);
    exit;
}

try{
    $payload=json_decode($raw,true,64,JSON_THROW_ON_ERROR);
    if(!is_array($payload))throw new JsonException('Object required');
    $result=WppWebhook::handle($payload);
    http_response_code(202);
    echo json(['ok'=>true]+$result);
}catch(JsonException){
    http_response_code(400);
    echo json(['ok'=>false,'error'=>'invalid_json']);
}catch(Throwable $e){
    error_log('TaskEsc WPP webhook: '.$e->getMessage());
    http_response_code(500);
    echo json(['ok'=>false,'error'=>'webhook_failed']);
}
