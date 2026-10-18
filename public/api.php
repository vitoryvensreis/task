<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
try {
 if(($_SERVER['REQUEST_METHOD']??'GET')==='OPTIONS'){http_response_code(204);exit;}
 $action=(string)($_GET['action']??$_POST['action']??'');
 $raw=file_get_contents('php://input');$body=[];
 if(str_contains(strtolower($_SERVER['CONTENT_TYPE']??''),'application/json')&&$raw!==''){$body=json_decode($raw,true,512,JSON_THROW_ON_ERROR);if(!is_array($body))fail('JSON inválido.');}
 $p=[...$_GET,...$_POST,...$body];unset($p['action']);
 if($action==='plans.public'){installed();out(PlatformPlans::publicList());}
 if($action==='master.activate'){
  if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST')fail('Método não permitido.',405);
  installed();out(MasterSupport::activate($p));
 }
 if($action==='auth.login'){installed();out(Auth::login($p));}
 if($action==='auth.2fa'){installed();out(Auth::twoFactorVerify($p));}
 if($action==='auth.logout'){session_start();$_SESSION=[];if(ini_get('session.use_cookies')){$params=session_get_cookie_params();setcookie(session_name(),'',['expires'=>time()-42000,'path'=>$params['path'],'domain'=>$params['domain'],'secure'=>$params['secure'],'httponly'=>$params['httponly'],'samesite'=>$params['samesite']??'Lax']);}session_destroy();out(['logged_out'=>true]);}
 installed();
 if(in_array($action,['master.asaas','master.asaas_save','master.ai_usage','master.ai_policy','master.ai_policy_save','master.health','master.logs','master.error_resolve','master.settings','master.settings_save','master.brand_upload','master.workspaces','master.dashboard','master.administrators','master.reset_2fa','master.plans','master.plan_save','master.plan_assign','master.start','master.stop','master.invite'],true)){
  if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET')checkCsrf();
  Auth::load();
  if($action==='master.asaas'&&($_SERVER['REQUEST_METHOD']??'GET')==='GET')out(PlatformAsaasCredentials::get());
  if($action==='master.asaas_save'&&($_SERVER['REQUEST_METHOD']??'GET')==='POST')out(PlatformAsaasCredentials::save($p));
  if(in_array($action,['master.ai_usage','master.ai_policy','master.ai_policy_save','master.health','master.logs','master.error_resolve'],true)){
   if(!MasterSupport::isActor()||Auth::$origin!=='web')fail('Somente o ADM Master pode acessar o monitoramento.',403);
   if($action==='master.ai_usage'&&($_SERVER['REQUEST_METHOD']??'GET')==='GET'){
    $days=integer($p['days']??7,1,30);if(!in_array($days,[1,7,14,30],true))fail('Período inválido.',422);
    $provider=enumv($p['provider']??'all',['all','gemini','groq'],'provedor');
    out(AiWhatsAppUsageDashboard::aggregate($days,$provider));
   }
   if($action==='master.ai_policy'&&($_SERVER['REQUEST_METHOD']??'GET')==='GET'){
    $policy=AiWhatsAppUsagePolicy::current();$counts=AiWhatsAppUsagePolicy::reservedToday();
    out(['policy'=>$policy,'today_utc'=>gmdate('Y-m-d'),'usage_today'=>$counts,
      'alerts'=>AiWhatsAppUsagePolicy::alertFlags($policy,$counts)]);
   }
   if($action==='master.ai_policy_save'&&($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
    try{$policy=AiWhatsAppUsagePolicy::validate($p);}catch(InvalidArgumentException){fail('Política de IA inválida.',422);}
    DB::run('INSERT INTO settings (`key`,value) VALUES (?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)',['ai_usage_policy_v1',json($policy)]);
    audit('platform',0,'master.ai_usage_policy.save',null,['fields'=>array_keys($policy)]);
    out(['saved'=>true,'policy'=>$policy]);
   }
   if($action==='master.health'&&($_SERVER['REQUEST_METHOD']??'GET')==='GET')out(SystemHealth::health());
   if($action==='master.logs'&&($_SERVER['REQUEST_METHOD']??'GET')==='GET')out(SystemHealth::logs($p));
   if($action==='master.error_resolve'&&($_SERVER['REQUEST_METHOD']??'GET')==='POST')out(SystemHealth::resolve($p));
   fail('Método não permitido.',405);
  }
  if($action==='master.settings'&&($_SERVER['REQUEST_METHOD']??'GET')==='GET')out(PlatformSettings::get());
  if($action==='master.settings_save'&&($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
   $section=(string)($p['section']??'');
   out($section==='branding'?PlatformSettings::saveBrand($p):PlatformSettings::saveIntegration($p));
  }
  if($action==='master.brand_upload'&&($_SERVER['REQUEST_METHOD']??'GET')==='POST')
   out(PlatformSettings::uploadBranding($_FILES['logo']??null,$_FILES));
  if($action==='master.workspaces'&&($_SERVER['REQUEST_METHOD']??'GET')==='GET')out(MasterSupport::workspaceList());
  if($action==='master.dashboard'&&($_SERVER['REQUEST_METHOD']??'GET')==='GET')out(MasterSupport::dashboard());
  if($action==='master.administrators'&&($_SERVER['REQUEST_METHOD']??'GET')==='GET')out(MasterSupport::administrators());
  if($action==='master.plans'&&($_SERVER['REQUEST_METHOD']??'GET')==='GET')out(PlatformPlans::all());
  if($action==='master.plan_save'&&($_SERVER['REQUEST_METHOD']??'GET')==='POST')out(PlatformPlans::save($p));
  if($action==='master.plan_assign'&&($_SERVER['REQUEST_METHOD']??'GET')==='POST')out(PlatformPlans::assign($p));
  if($action==='master.reset_2fa'&&($_SERVER['REQUEST_METHOD']??'GET')==='POST')out(MasterSupport::resetAdminTwoFactor($p));
  if($action==='master.start'&&($_SERVER['REQUEST_METHOD']??'GET')==='POST')out(MasterSupport::start($p));
  if($action==='master.stop'&&($_SERVER['REQUEST_METHOD']??'GET')==='POST')out(MasterSupport::stop());
  if($action==='master.invite'&&($_SERVER['REQUEST_METHOD']??'GET')==='POST')out(MasterSupport::invite());
  fail('Método não permitido.',405);
 }
 if($action==='auth.me'){Auth::load();out(['user'=>Auth::$user,'csrf'=>csrf()]);}
 if($action==='meta'){Auth::load();out(Catalog::meta());}
 Auth::load();
 if(str_starts_with($action,'support.')){
  if($action==='support.list'&&($_SERVER['REQUEST_METHOD']??'GET')==='GET')out(SupportDesk::threads());
  if($action==='support.messages'&&($_SERVER['REQUEST_METHOD']??'GET')==='GET')out(SupportDesk::messages($p));
  if($action==='support.send'&&($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
   checkCsrf();
   $result=SupportDesk::send($p,$_FILES['image']??null);
   $recipient=(int)($result['_recipient']??0);
   $notificationId=(int)($result['_notification_id']??0);
   $title=(string)($result['_title']??'Novo atendimento TaskEsc');
   $preview=(string)($result['_preview']??'Nova mensagem no suporte.');
   $recipientIsMaster=(bool)($result['_master_recipient']??false);
   foreach(array_keys($result) as $key)if(str_starts_with((string)$key,'_'))unset($result[$key]);
   header('Content-Type: application/json; charset=utf-8');
   header('Cache-Control: no-store');
   echo json($result);
   if(function_exists('fastcgi_finish_request'))fastcgi_finish_request();
   else {if(ob_get_level())@ob_flush();@flush();}
   if($recipient>0&&$notificationId>0){
    try{
     $unread=(int)(DB::one('SELECT COUNT(*) n FROM notifications WHERE user_id=? AND seen=0',[$recipient])['n']??0);
     Push::notifyUser($recipient,[
      'notification_id'=>$notificationId,'title'=>$title,'body'=>$preview,
      'url'=>base($recipientIsMaster?'master/suporte':'dashboard?suporte=1'),
      'tag'=>'taskesc-support-'.$notificationId,'scope'=>'support','badge_count'=>$unread
     ]);
    }catch(Throwable $e){error_log('TaskEsc support push: '.get_class($e));}
   }
   exit;
  }
  fail('Método não permitido.',405);
 }
 if((Auth::$user['role']??'')==='master')fail('Escolha um workspace no painel do ADM Master.',403);
 if($action==='preview.start'){checkCsrf();out(Auth::previewStart($p));}
 if($action==='preview.stop'){checkCsrf();out(Auth::previewStop());}
 if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET'&&Auth::$origin!=='api'){checkCsrf();Auth::guardPreviewWrite();}
 WorkspaceRoles::requireForAction($action,$p);
 if(in_array($action,['ai.usage_dashboard','ai.usage_policy','ai.usage_policy_save','system.health','system.logs','system.error_resolve'],true))fail('Disponível somente no ADM Master.',403);
 if(str_starts_with($action,'wpp.')||str_starts_with($action,'tokens.'))MasterSupport::requirePlatformSettings();
 if(str_starts_with($action,'demands.')||str_starts_with($action,'services.')||str_starts_with($action,'templates.'))PlatformPlans::requireModule('tasks');
 if(str_starts_with($action,'automation.'))PlatformPlans::requireModule('automations');
 if(str_starts_with($action,'whatsapp_contacts.'))PlatformPlans::requireModule('contacts');
 $result=match($action){
  'account.get'=>Auth::profile(),
  'account.save'=>Auth::profileSave($p),
  'account.password'=>Auth::passwordChange($p),
  'account.2fa_status'=>Auth::twoFactorStatus(),
  'account.2fa_setup'=>Auth::twoFactorSetup(),
  'account.2fa_enable'=>Auth::twoFactorEnable($p),
  'account.2fa_disable'=>Auth::twoFactorDisable($p),
  'push.status'=>Push::status(),
  'push.subscribe'=>Push::subscribe($p),
  'push.unsubscribe'=>Push::unsubscribe($p),
  'push.test'=>Push::test(),
  'wpp.status'=>WppConnect::status(),
  'wpp.groups'=>WppConnect::groups(),
  'wpp.start'=>WppConnect::start(),
  'wpp.qr'=>WppConnect::qr(),
  'wpp.send_test'=>WppConnect::sendTest($p),
  'automation.catalog'=>Automation::catalog(),
  'automation.list'=>Automation::list($p),
  'automation.get'=>Automation::get($p),
  'automation.save'=>Automation::save($p),
  'automation.toggle'=>Automation::toggle($p),
  'automation.runs'=>Automation::runs($p),
  'ai.usage_dashboard'=>AiWhatsAppUsageDashboard::read($p),
  'ai.usage_policy'=>AiWhatsAppUsagePolicy::read(),
  'ai.usage_policy_save'=>($_SERVER['REQUEST_METHOD']??'GET')==='POST'?AiWhatsAppUsagePolicy::save($p):fail('Método não permitido.',405),
  'system.health'=>SystemHealth::health(),
  'system.logs'=>SystemHealth::logs($p),
  'system.error_resolve'=>SystemHealth::resolve($p),
  'chat.contacts'=>Chat::contacts(),
  'chat.messages'=>Chat::messages($p),
  'chat.tasks'=>Chat::tasks($p),
  'chat.send'=>Chat::send($p),
  'chat.read'=>Chat::read($p),
  'dashboard'=>Reports::dashboard($p),
  'report'=>Reports::report($p),
  'report.csv'=>null,
  'demands.list'=>Demands::list($p),
  'demands.get'=>Demands::get($p),
  'demands.save'=>Demands::save($p),
  'whatsapp_contacts.list'=>WhatsappContacts::list($p),
  'whatsapp_contacts.save'=>($_SERVER['REQUEST_METHOD']??'GET')==='POST'?WhatsappContacts::save($p):fail('Método não permitido.',405),
  'whatsapp_contacts.delete'=>($_SERVER['REQUEST_METHOD']??'GET')==='POST'?WhatsappContacts::delete($p):fail('Método não permitido.',405),
  'demands.stage'=>Demands::stage($p),
  'demands.reorder'=>Demands::reorder($p),
  'demands.bulk'=>Demands::bulk($p),
  'demands.delete'=>Demands::delete($p),
  'demands.comment'=>Demands::comment($p),
  'demands.checklist'=>Demands::checklist($p),
  'demands.time'=>Demands::time($p),
  'demands.change'=>Demands::change($p),
  'demands.quote'=>Demands::quote($p),
  'clients.list'=>Catalog::clients($p),
  'clients.detail'=>Catalog::clientDetail($p),
  'clients.log'=>Catalog::clientLog($p),
  'clients.save'=>Catalog::clientSave($p),
  'clients.delete'=>Catalog::clientDelete($p),
  'projects.save'=>Catalog::projectSave($p),
  'users.list'=>Catalog::users(),
  'users.save'=>Catalog::userSave($p),
  'company.roles'=>WorkspaceRoles::all(),
  'company.role_save'=>($_SERVER['REQUEST_METHOD']??'GET')==='POST'?WorkspaceRoles::save($p):fail('Método não permitido.',405),
  'settings.get'=>Catalog::settingsGet(),
  'settings.save'=>Catalog::settingsSave($p),
  'settings.logo_upload'=>Catalog::settingsLogoUpload(),
  'settings.integrations'=>Catalog::integrationSave($p),
  'tokens.create'=>Tokens::create($p),
  'tokens.revoke'=>Tokens::revoke($p),
  'services.list'=>Catalog::services(),
  'services.save'=>Catalog::serviceSave($p),
  'services.delete'=>Catalog::serviceDelete($p),
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
  'finance.log'=>Finance::log($p),
  'invoices.create'=>Finance::invoiceCreate($p),
  'invoices.from_demand'=>Finance::invoiceDemand($p),
  'invoices.payment'=>Finance::payment($p),
  'invoices.cancel'=>Finance::cancel($p),
  'invoices.delete'=>Finance::deleteInvoice($p),
  'costs.add'=>Finance::cost($p),
  'expenses.list'=>Finance::expenses($p),
  'expenses.save'=>Finance::expenseSave($p),
  'expenses.payment'=>Finance::expensePayment($p),
  'expenses.delete'=>Finance::expenseDelete($p),
  'expense_categories.list'=>Finance::expenseCategories($p),
  'expense_categories.save'=>Finance::expenseCategorySave($p),
  'expense_categories.delete'=>Finance::expenseCategoryDelete($p),
  'expense_rules.list'=>Finance::expenseRules($p),
  'expense_rules.save'=>Finance::expenseRuleSave($p),
  'expense_rules.delete'=>Finance::expenseRuleDelete($p),
  'finance.analysis'=>Finance::analysis($p),
  'finance.cash_breakdown'=>Finance::cashBreakdown($p),
  'finance.monthly_status'=>Finance::monthlyBillingStatus($p),
  'files.add'=>Files::add($p),
  'files.upload_start'=>Files::uploadStart($p),
  'files.upload_status'=>Files::uploadStatus($p),
  'files.delete'=>Files::delete($p),
  'files.review'=>Files::review($p),
  'agenda.list'=>Agenda::list($p),
  'agenda.detail'=>Agenda::detail($p),
  'agenda.calendars'=>Agenda::calendars(),
  'agenda.save'=>Agenda::save($p),
  'agenda.cancel'=>Agenda::cancel($p),
  'agenda.resolve'=>Agenda::resolve($p),
  'agenda.calendar_select'=>Agenda::calendarSelect($p),
  'company.drive_default'=>Google::companyDefaultDrive(($_SERVER['REQUEST_METHOD']??'GET')==='GET'?null:$p),
  'google.accounts'=>Google::accounts(),
  'google.connect'=>Google::connect($p),
  'google.disconnect'=>Google::disconnect($p),
  'google.sync'=>MasterSupport::requireCalendar()?:Google::sync(!empty($p['calendar_id'])?(int)$p['calendar_id']:null),
  'notifications'=>Auth::isPreview()?[]:DB::all('SELECT * FROM notifications WHERE user_id=? ORDER BY id DESC LIMIT 50',[Auth::$user['id']]),
  'notifications.seen'=>DB::run('UPDATE notifications SET seen=1 WHERE user_id=?',[Auth::$user['id']])&&['saved'=>true],
  default=>fail('Ação não encontrada.',404),
 };
 if($action==='report.csv'){$csv=Reports::csv($p);header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="taskesc-'.$p['type'].'-'.gmdate('Ymd-His').'.csv"');echo "\xEF\xBB\xBF".$csv;exit;}
 out($result??['saved'=>true]);
}catch(AppError $e){out(['error'=>$e->getMessage(),'details'=>$e->details],$e->status);}catch(PDOException $e){SystemHealth::capture($e,'api.database');error_log((string)$e);out(['error'=>'Não foi possível concluir a operação no banco de dados.'],500);}catch(Throwable $e){SystemHealth::capture($e,'api');error_log((string)$e);out(['error'=>'Ocorreu um erro interno. Consulte o log se o problema persistir.'],500);}
