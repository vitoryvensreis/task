<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
@set_time_limit(0);
try {
 if(($_SERVER['REQUEST_METHOD']??'')!=='POST')fail('Método não permitido.',405);
 installed();Auth::load();Auth::requireAbility('tasks.files');checkCsrf();
 $token=(string)($_GET['token']??'');$offset=integer($_SERVER['HTTP_X_CHUNK_START']??-1,0,PHP_INT_MAX);
 $binary=file_get_contents('php://input');if($binary===false)fail('Não foi possível ler o bloco enviado.',400);
 out(Files::uploadChunk($token,$binary,$offset));
} catch(AppError $e) {out(['error'=>$e->getMessage(),'details'=>$e->details],$e->status);} catch(Throwable $e) {error_log('Drive upload chunk: '.(string)$e);out(['error'=>'Não foi possível concluir o upload no Google Drive. Consulte o log se o problema persistir.'],500);}
