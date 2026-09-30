<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
try {
 if(($_SERVER['REQUEST_METHOD']??'GET')==='OPTIONS'){http_response_code(204);exit;}
 $action=(string)($_GET['action']??$_POST['action']??'');
 $raw=file_get_contents('php://input');$body=[];
 if(str_contains(strtolower($_SERVER['CONTENT_TYPE']??''),'application/json')&&$raw!==''){$body=json_decode($raw,true,512,JSON_THROW_ON_ERROR);if(!is_array($body))fail('JSON inválido.');}
 $p=[...$_GET,...$_POST,...$body];unset($p['action']);
 if($action==='auth.login'){installed();out(Auth::login($p));}
 if($action==='auth.logout'){session_start();$_SESSION=[];if(ini_get('session.use_cookies')){$params=session_get_cookie_params();setcookie(session_name(),'',['expires'=>time()-42000,'path'=>$params['path'],'domain'=>$params['domain'],'secure'=>$params['secure'],'httponly'=>$params['httponly'],'samesite'=>$params['samesite']??'Lax']);}session_destroy();out(['logged_out'=>true]);}
 installed();
 if($action==='auth.me'){Auth::load();out(['user'=>Auth::$user,'csrf'=>csrf()]);}
 if($action==='meta'){Auth::load();out(Catalog::meta());}
 Auth::load();
 if($action==='preview.start'){checkCsrf();out(Auth::previewStart($p));}
 if($action==='preview.stop'){checkCsrf();out(Auth::previewStop());}
 if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET'&&Auth::$origin!=='api'){checkCsrf();Auth::guardPreviewWrite();}
 $result=match($action){
  'dashboard'=>Reports::dashboard($p),
  'report'=>Reports::report($p),
  'report.csv'=>null,
  'demands.list'=>Demands::list($p),
  'demands.get'=>Demands::get($p),
  'demands.save'=>Demands::save($p),
  'demands.stage'=>Demands::stage($p),
  'demands.reorder'=>Demands::reorder($p),
  'demands.delete'=>Demands::delete($p),
  'demands.comment'=>Demands::comment($p),
  'demands.checklist'=>Demands::checklist($p),
  'demands.time'=>Demands::time($p),
  'demands.change'=>Demands::change($p),
  'demands.quote'=>Demands::quote($p),
  'clients.list'=>Catalog::clients($p),
  'clients.save'=>Catalog::clientSave($p),
  'clients.delete'=>Catalog::clientDelete($p),
  'projects.save'=>Catalog::projectSave($p),
  'users.list'=>Catalog::users(),
  'users.save'=>Catalog::userSave($p),
  'settings.get'=>Catalog::settingsGet(),
  'settings.save'=>Catalog::settingsSave($p),
  'settings.integrations'=>Catalog::integrationSave($p),
  'tokens.create'=>Tokens::create($p),
  'tokens.revoke'=>Tokens::revoke($p),
  'catalog.save'=>Catalog::customize($p),
  'stages.save'=>Catalog::stageSave($p),
  'stages.reorder'=>Catalog::stageReorder($p),
  'stages.delete'=>Catalog::stageDelete($p),
  'templates.list'=>Catalog::templates($p),
  'templates.save'=>Catalog::templateSave($p),
  'contracts.list'=>Finance::contracts($p),
  'contracts.save'=>Finance::contractSave($p),
  'contracts.cycles'=>Finance::cycles(integer($p['id']??0,1)),
  'invoices.list'=>Finance::invoices($p),
  'invoices.create'=>Finance::invoiceCreate($p),
  'invoices.from_demand'=>Finance::invoiceDemand($p),
  'invoices.payment'=>Finance::payment($p),
  'invoices.cancel'=>Finance::cancel($p),
  'costs.add'=>Finance::cost($p),
  'files.add'=>Files::add($p),
  'files.upload_start'=>Files::uploadStart($p),
  'files.delete'=>Files::delete($p),
  'files.review'=>Files::review($p),
  'agenda.list'=>Agenda::list($p),
  'agenda.calendars'=>Agenda::calendars(),
  'agenda.save'=>Agenda::save($p),
  'agenda.cancel'=>Agenda::cancel($p),
  'agenda.resolve'=>Agenda::resolve($p),
  'agenda.calendar_select'=>Agenda::calendarSelect($p),
  'google.accounts'=>Google::accounts(),
  'google.connect'=>Google::connect($p),
  'google.disconnect'=>Google::disconnect($p),
  'google.sync'=>Auth::admin()?:Google::sync(!empty($p['calendar_id'])?(int)$p['calendar_id']:null),
  'notifications'=>Auth::isPreview()?[]:DB::all('SELECT * FROM notifications WHERE user_id=? ORDER BY id DESC LIMIT 50',[Auth::$user['id']]),
  'notifications.seen'=>DB::run('UPDATE notifications SET seen=1 WHERE user_id=?',[Auth::$user['id']])&&['saved'=>true],
  default=>fail('Ação não encontrada.',404),
 };
 if($action==='report.csv'){$csv=Reports::csv($p);header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="taskesc-'.$p['type'].'-'.gmdate('Ymd-His').'.csv"');echo "\xEF\xBB\xBF".$csv;exit;}
 out($result??['saved'=>true]);
}catch(AppError $e){out(['error'=>$e->getMessage(),'details'=>$e->details],$e->status);}catch(PDOException $e){error_log((string)$e);out(['error'=>'Não foi possível concluir a operação no banco de dados.'],500);}catch(Throwable $e){error_log((string)$e);out(['error'=>'Ocorreu um erro interno. Consulte o log se o problema persistir.'],500);}
