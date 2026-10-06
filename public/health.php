<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

$status=200;
$payload=[
 'status'=>'ok',
 'database'=>false,
 'installed'=>is_file(ROOT.'/storage/installed.lock'),
 'schema'=>null,
 'time'=>gmdate('c'),
];

try{
 if(!config())throw new RuntimeException('Configuração indisponível.');
 DB::one('SELECT 1 AS ok');
 $payload['database']=true;
 try{
  $payload['schema']=(string)(DB::one('SELECT value FROM settings WHERE `key`=? LIMIT 1',['schema_version'])['value']??'unknown');
 }catch(Throwable){
  $payload['schema']='unknown';
 }
 if(!$payload['installed'])throw new RuntimeException('Instalação não confirmada.');
}catch(Throwable){
 $status=503;
 $payload['status']='error';
}

http_response_code($status);
echo json($payload);
