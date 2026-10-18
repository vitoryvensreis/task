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
 if(in_array($action,['master.support_unread','master.support_seen','master.wpp_status','master.wpp_start','master.wpp_qr','master.wpp_test','master.wpp_close','master.wpp_disconnect','master.customers','master.workspace_members','master.workspace_profile_save','master.workspace_limit_save','master.admin_access','master.audit','master.account','master.profile_save','master.password_save','master.twofa_setup','master.twofa_enable','master.twofa_disable','master.invite_additional','master.asaas','master.asaas_save','master.health','master.logs','master.error_resolve','master.settings','master.settings_save','master.brand_upload','master.workspaces','master.dashboard','master.administrators','master.reset_2fa','master.plans','master.plan_save','master.plan_assign','master.start','master.stop','master.invite'],true)){
  if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET')checkCsrf();
  Auth::load();
   if($action==='master.support_unread'&&($_SERVER['REQUEST_METHOD']??'GET')==='GET'){
   if(!MasterSupport::isActor()||MasterSupport::isSupport())fail('Acesso restrito ao ADM Master.',403);
   out(['count'=>(int)(DB::one("SELECT COUNT(*) n FROM notifications WHERE user_id=? AND seen=0
    AND dedupe_key LIKE 'support.message.%'",[(int)Auth::$actor['id']])['n']??0)]);
  }
  if($action==='master.support_seen'&&($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
   if(!MasterSupport::isActor()||MasterSupport::isSupport())fail('Acesso restrito ao ADM Master.',403);
   $masterId=(int)Auth::$actor['id'];
   if(WorkspaceTenantMode::enabled()){
    $workspaces=DB::all("SELECT DISTINCT workspace_id FROM notifications
      WHERE user_id=? AND seen=0 AND dedupe_key LIKE 'support.message.%'",[$masterId]);
    foreach($workspaces as $ws)WorkspaceJobScope::forWorkspace((int)$ws['workspace_id'],
      static function()use($masterId,$ws):void {
       DB::run("UPDATE notifications SET seen=1 WHERE workspace_id=? AND user_id=? AND seen=0
         AND dedupe_key LIKE 'support.message.%'",[(int)$ws['workspace_id'],$masterId]);
      });
   }else DB::run("UPDATE notifications SET seen=1 WHERE user_id=? AND seen=0
    AND dedupe_key LIKE 'support.message.%'",[$masterId]);
   out(['saved'=>true]);
  }
  if(in_array($action,['master.wpp_status','master.wpp_start','master.wpp_qr','master.wpp_test','master.wpp_close','master.wpp_disconnect'],true)){
   if(!MasterSupport::isActor()||MasterSupport::isSupport())fail('Integração reservada ao ADM Master.',403);
  if($action==='master.wpp_status'&&($_SERVER['REQUEST_METHOD']??'GET')==='GET')out(WppConnect::status());
   if($action==='master.wpp_start'&&($_SERVER['REQUEST_METHOD']??'GET')==='POST')out(WppConnect::start());
   if($action==='master.wpp_qr'&&($_SERVER['REQUEST_METHOD']??'GET')==='GET')out(WppConnect::qr());
   if($action==='master.wpp_test'&&($_SERVER['REQUEST_METHOD']??'GET')==='POST')out(WppConnect::sendTest($p));
   if($action==='master.wpp_close'&&($_SERVER['REQUEST_METHOD']??'GET')==='POST')out(WppConnect::close($p));
   if($action==='master.wpp_disconnect'&&($_SERVER['REQUEST_METHOD']??'GET')==='POST')out(WppConnect::disconnect($p));
   fail('Método não permitido.',405);
  }
  if($action==='master.customers'&&($_SERVER['REQUEST_METHOD']??'GET')==='GET')out(MasterSupport::customerDirectory($p));
  if($action==='master.workspace_members'&&($_SERVER['REQUEST_METHOD']??'GET')==='GET')
   out(MasterWorkspaceManagement::members($p));
  if($action==='master.workspace_profile_save'&&($_SERVER['REQUEST_METHOD']??'GET')==='POST')
   out(MasterWorkspaceManagement::saveProfile($p));
  if($action==='master.workspace_limit_save'&&($_SERVER['REQUEST_METHOD']??'GET')==='POST')
   out(MasterWorkspaceManagement::saveLimit($p));
  if($action==='master.admin_access'&&($_SERVER['REQUEST_METHOD']??'GET')==='POST')
   out(MasterWorkspaceManagement::adminAccess($p));
  if($action==='master.audit'&&($_SERVER['REQUEST_METHOD']??'GET')==='GET')out(MasterSupport::platformAudit($p));
  if($action==='master.account'&&($_SERVER['REQUEST_METHOD']??'GET')==='GET')out(MasterAccount::status());
  if($action==='master.profile_save'&&($_SERVER['REQUEST_METHOD']??'GET')==='POST')out(MasterAccount::profile($p));
  if($action==='master.password_save'&&($_SERVER['REQUEST_METHOD']??'GET')==='POST')out(MasterAccount::password($p));
  if($action==='master.twofa_setup'&&($_SERVER['REQUEST_METHOD']??'GET')==='POST')out(MasterAccount::twofaSetup());
  if($action==='master.twofa_enable'&&($_SERVER['REQUEST_METHOD']??'GET')==='POST')out(MasterAccount::twofaEnable($p));
  if($action==='master.twofa_disable'&&($_SERVER['REQUEST_METHOD']??'GET')==='POST')out(MasterAccount::twofaDisable($p));
  if($action==='master.invite_additional'&&($_SERVER['REQUEST_METHOD']??'GET')==='POST')out(MasterAccount::invite($p));
  if($action==='master.asaas'&&($_SERVER['REQUEST_METHOD']??'GET')==='GET')out(PlatformAsaasCredentials::get());
  if($action==='master.asaas_save'&&($_SERVER['REQUEST_METHOD']??'GET')==='POST')out(PlatformAsaasCredentials::save($p));
  if(in_array($action,['master.health','master.logs','master.error_resolve'],true)){
   if(!MasterSupport::isActor()||Auth::$origin!=='web')fail('ADM Master obrigatório.',403);
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
 if(in_array($action,['system.health','system.logs','system.error_resolve'],true))fail('Disponível somente no ADM Master.',403);
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
  'automation.catalog'=>Automation::catalog(),
  'automation.list'=>Automation::list($p),
  'automation.get'=>Automation::get($p),
  'automation.save'=>Automation::save($p),
  'automation.toggle'=>Automation::toggle($p),
  'automation.runs'=>Automation::runs($p),
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
  'team.summary'=>WorkspaceTeams::list(),
  'team.invite'=>($_SERVER['REQUEST_METHOD']??'GET')==='POST'?WorkspaceTeams::invite($p):fail('Método não permitido.',405),
  'team.invite_revoke'=>($_SERVER['REQUEST_METHOD']??'GET')==='POST'?WorkspaceTeams::revoke($p):fail('Método não permitido.',405),
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
  'company.drive_default'=>Google::companyDefaultDrive(($_SERVER['REQUEST_METHOD']??'GET')==='GET'?null:$p),
  'google.accounts'=>Google::accounts(),
  'google.connect'=>Google::connect($p),
  'google.disconnect'=>Google::disconnect($p),
  'notifications'=>Auth::isPreview()?[]:DB::all('SELECT * FROM notifications WHERE user_id=? ORDER BY id DESC LIMIT 50',[Auth::$user['id']]),
  'notifications.seen'=>DB::run('UPDATE notifications SET seen=1 WHERE user_id=?',[Auth::$user['id']])&&['saved'=>true],
  default=>fail('Ação não encontrada.',404),
 };
 if($action==='report.csv'){$csv=Reports::csv($p);header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="taskesc-'.$p['type'].'-'.gmdate('Ymd-His').'.csv"');echo "\xEF\xBB\xBF".$csv;exit;}
 out($result??['saved'=>true]);
}catch(AppError $e){out(['error'=>$e->getMessage(),'details'=>$e->details],$e->status);}catch(PDOException $e){SystemHealth::capture($e,'api.database');error_log((string)$e);out(['error'=>'Não foi possível concluir a operação no banco de dados.'],500);}catch(Throwable $e){SystemHealth::capture($e,'api');error_log((string)$e);out(['error'=>'Ocorreu um erro interno. Consulte o log se o problema persistir.'],500);}
