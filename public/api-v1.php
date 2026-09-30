<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
try{
 if(($_SERVER['REQUEST_METHOD']??'GET')==='OPTIONS'){header('Allow: GET, POST, PUT, PATCH, DELETE, OPTIONS');http_response_code(204);exit;}
 installed();$raw=file_get_contents('php://input');$body=[];$type=strtolower($_SERVER['CONTENT_TYPE']??'');if($raw!==''&&str_contains($type,'application/json')){$body=json_decode($raw,true,512,JSON_THROW_ON_ERROR);if(!is_array($body))fail('JSON inválido.');}
 $p=[...$_GET,...$_POST,...$body];$uri=(string)(parse_url($_SERVER['REQUEST_URI']??'',PHP_URL_PATH)??'');$basePath=(string)(parse_url((string)config('base_url',''),PHP_URL_PATH)??'');if($basePath&&str_starts_with($uri,$basePath))$uri=substr($uri,strlen($basePath));$pos=strpos($uri,'/api/v1');$path=$pos===false?'':substr($uri,$pos+strlen('/api/v1'));
 out(ApiV1::handle($_SERVER['REQUEST_METHOD']??'GET',$path,$p));
}catch(AppError $e){out(['error'=>$e->getMessage(),'details'=>$e->details],$e->status);}catch(PDOException $e){error_log((string)$e);out(['error'=>'Não foi possível concluir a operação no banco de dados.'],500);}catch(Throwable $e){error_log((string)$e);out(['error'=>'Ocorreu um erro interno na API. Consulte o log se o problema persistir.'],500);}
