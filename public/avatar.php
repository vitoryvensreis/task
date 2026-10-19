<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
try{
 $id=filter_input(INPUT_GET,'id',FILTER_VALIDATE_INT);
 if(!$id||$id<1)fail('Imagem não encontrada.',404);
 UserAvatars::serve((int)$id);
}catch(AppError $e){
 http_response_code($e->status);header('Content-Type: text/plain; charset=utf-8');echo 'Imagem indisponível.';
}catch(Throwable $e){
 http_response_code(500);header('Content-Type: text/plain; charset=utf-8');echo 'Imagem indisponível.';
}
