<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
try{
 installed();
 SupportDesk::serveImage($_GET);
}catch(AppError $e){
 http_response_code(in_array($e->status,[401,403,404],true)?404:$e->status);
 header('Content-Type: text/plain; charset=utf-8');
 echo 'Imagem indisponível.';
}catch(Throwable $e){
 error_log('TaskEsc support image: '.$e->getMessage());
 http_response_code(500);header('Content-Type: text/plain; charset=utf-8');echo 'Imagem indisponível.';
}
