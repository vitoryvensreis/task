<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
try {
 installed();Auth::load();SaasLegacyBoundary::enforce();$id=integer($_GET['id']??0,1);$inline=!empty($_GET['inline']);
 $f=DB::one('SELECT f.*,d.client_id FROM files f JOIN demands d ON d.id=f.demand_id WHERE f.id=? AND d.deleted_at IS NULL',[$id]);
 if(!$f)fail('Arquivo não encontrado.',404);Auth::demand((int)$f['demand_id']);if(Auth::$user['role']==='client'&&!$f['shared'])fail('Arquivo privado.',403);
 if($f['url']){header('Location: '.$f['url'],true,302);exit;}
 if($inline){header('X-Frame-Options: SAMEORIGIN',true);header("Content-Security-Policy: default-src 'none'; frame-ancestors 'self'; sandbox",true);}
 if(($f['storage_provider']??'local')==='drive'&&!empty($f['drive_file_id'])&&!empty($f['drive_account_id']))Google::streamDriveFile((int)$f['drive_account_id'],$f['drive_file_id'],$f['mime']?:'application/octet-stream',$f['original_name']?:$f['label'],$inline);
 $path=ROOT.'/storage/uploads/'.basename((string)$f['disk_name']);if(!$f['disk_name']||!is_file($path))fail('Arquivo indisponível.',404);
 header('Content-Type: '.($f['mime']?:'application/octet-stream'));header('Content-Length: '.(string)filesize($path));header('Content-Disposition: '.($inline?'inline':'attachment').'; filename*=UTF-8\'\''.rawurlencode((string)$f['original_name']));header('X-Content-Type-Options: nosniff');readfile($path);exit;
} catch(AppError $e) {http_response_code($e->status);header('Content-Type: text/plain; charset=utf-8');echo $e->getMessage();}
catch(Throwable $e) {error_log('Download: '.(string)$e);http_response_code(500);header('Content-Type: text/plain; charset=utf-8');echo 'Não foi possível abrir o arquivo.';}
