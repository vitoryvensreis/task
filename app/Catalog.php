<?php
final class Catalog {
public static function meta(): array {
  [$w,$a]=Auth::clientFilter('c.id');$clients=DB::all('SELECT c.id,c.name,c.brand,c.commercial_type,c.monthly_amount_cents,c.monthly_payment_day,c.drive_account_id,c.email,c.phone,c.whatsapp_phone,ga.email AS own_drive_email,ga.status AS own_drive_status,ga.drive_enabled AS own_drive_enabled,ga.scopes AS own_drive_scopes FROM clients c LEFT JOIN google_accounts ga ON ga.id=c.drive_account_id WHERE '.$w.' AND c.active=1 ORDER BY c.name',$a);
  $users=Auth::$user['role']==='admin'?DB::all('SELECT u.id,u.name,u.email,u.whatsapp_phone,u.role,u.active,u.ai_assistant_enabled,GROUP_CONCAT(uc.client_id ORDER BY uc.client_id) AS client_ids_csv FROM users u LEFT JOIN user_clients uc ON uc.user_id=u.id GROUP BY u.id,u.name,u.email,u.whatsapp_phone,u.role,u.active ORDER BY u.name'):DB::all("SELECT u.id,u.name,u.whatsapp_phone,u.role,u.active,GROUP_CONCAT(uc.client_id ORDER BY uc.client_id) AS client_ids_csv FROM users u LEFT JOIN user_clients uc ON uc.user_id=u.id WHERE u.role IN ('admin','staff') AND u.active=1 GROUP BY u.id,u.name,u.whatsapp_phone,u.role,u.active ORDER BY u.name");foreach($users as &$metaUser){$metaUser['client_ids']=array_values(array_filter(array_map('intval',explode(',',(string)($metaUser['client_ids_csv']??''))),fn($v)=>$v>0));unset($metaUser['client_ids_csv']);}unset($metaUser);
  [$pw,$pa]=Auth::clientFilter('client_id');$driveId=(int)setting('drive_account_id','0');$defaultDrive=$driveId?DB::one("SELECT id,email FROM google_accounts WHERE id=? AND status='connected' AND drive_enabled=1 AND scopes LIKE ?",[$driveId,'%https://www.googleapis.com/auth/drive.file%']):null;
  foreach($clients as &$c){if(!empty($c['drive_account_id'])){$ready=$c['own_drive_status']==='connected'&&(bool)$c['own_drive_enabled']&&str_contains((string)($c['own_drive_scopes']??''),'https://www.googleapis.com/auth/drive.file');$c['drive_ready']=$ready;if(Auth::$user['role']==='admin')$c['drive_email']=$c['own_drive_email']??'';}else{$c['drive_ready']=(bool)$defaultDrive;if(Auth::$user['role']==='admin')$c['drive_email']=$defaultDrive['email']??'';}unset($c['own_drive_email'],$c['own_drive_status'],$c['own_drive_enabled'],$c['own_drive_scopes']);}unset($c);
  $pwaIcon=ROOT.'/public/uploads/branding/pwa-192.png';$pwaIconUrl=is_file($pwaIcon)?base('uploads/branding/pwa-192.png?v='.filemtime($pwaIcon)):base('assets/taskesc-logo.svg');
  return ['user'=>Auth::$user,'csrf'=>csrf(),'clients'=>$clients,'projects'=>DB::all('SELECT * FROM projects WHERE '.$pw.' ORDER BY name',$pa),'contracts'=>Auth::$user['role']==='admin'?DB::all("SELECT id,client_id,name,status FROM contracts WHERE status<>'cancelled' ORDER BY name"):[],'users'=>$users,'stages'=>DB::all('SELECT * FROM stages WHERE active=1 ORDER BY position,id'),'categories'=>DB::all('SELECT * FROM categories WHERE active=1 ORDER BY name'),'abilities'=>Auth::abilities(),'brand'=>['name'=>setting('brand_name','TaskEsc'),'color'=>setting('brand_color','#ffffff'),'logo'=>setting('logo_url'),'pwa_icon'=>$pwaIconUrl],'timezone'=>config('timezone'),'upload_mb'=>(int)setting('upload_mb','0'),'server_time'=>now(),'min_delivery_business_hours'=>minDeliveryHours(),'min_delivery_at'=>minDeliveryLocal(),'drive_ready'=>(bool)$defaultDrive,'permissions'=>Auth::isPreview()?[['client_id'=>Auth::$previewClientId,'can_approve'=>1,'can_finance'=>1]]:(Auth::$user['role']==='client'?DB::all('SELECT client_id,can_approve,can_finance FROM user_clients WHERE user_id=?',[Auth::$user['id']]):[])];
 }
 public static function clients(array $p): array {Auth::admin();return DB::all('SELECT c.*,ga.email AS drive_email,ga.status AS drive_status,ga.drive_enabled FROM clients c LEFT JOIN google_accounts ga ON ga.id=c.drive_account_id WHERE c.active=1 AND c.name LIKE ? ORDER BY c.name',['%'.($p['q']??'').'%']);}
public static function clientDetail(array $p): array {
  Auth::admin();$id=integer($p['id']??0,1);$client=DB::one('SELECT c.*,ga.email AS drive_email,ga.status AS drive_status,ga.last_error AS drive_error FROM clients c LEFT JOIN google_accounts ga ON ga.id=c.drive_account_id WHERE c.id=?',[$id]);if(!$client)fail('Cliente não encontrado.',404);
  $taskSummary=DB::one("SELECT COUNT(*) total,SUM(CASE WHEN s.kind='completed' THEN 1 ELSE 0 END) completed,SUM(CASE WHEN s.kind NOT IN ('completed','cancelled','archived') THEN 1 ELSE 0 END) open,SUM(CASE WHEN d.due_at<? AND s.kind NOT IN ('completed','cancelled','archived') THEN 1 ELSE 0 END) overdue FROM demands d JOIN stages s ON s.id=d.stage_id WHERE d.client_id=? AND d.deleted_at IS NULL",[now(),$id]);
  $tasks=DB::all("SELECT d.id,d.title,d.due_at,d.priority,d.amount_cents,d.billing_mode,d.updated_at,s.name stage_name,s.kind stage_kind,s.color stage_color,ca.name category_name,u.name assigned_name FROM demands d JOIN stages s ON s.id=d.stage_id JOIN categories ca ON ca.id=d.category_id LEFT JOIN users u ON u.id=d.assigned_to WHERE d.client_id=? AND d.deleted_at IS NULL ORDER BY d.updated_at DESC LIMIT 30",[$id]);
  $financial=DB::one("SELECT COALESCE(SUM(CASE WHEN i.status<>'cancelled' AND i.deleted_at IS NULL THEN i.amount_cents ELSE 0 END),0) billed_cents,COALESCE(SUM(CASE WHEN i.status<>'cancelled' AND i.deleted_at IS NULL THEN (SELECT COALESCE(SUM(p.amount_cents),0) FROM payments p WHERE p.invoice_id=i.id) ELSE 0 END),0) paid_cents,COALESCE(SUM(CASE WHEN i.status<>'cancelled' AND i.deleted_at IS NULL THEN (SELECT COALESCE(SUM(p.discount_cents),0) FROM payments p WHERE p.invoice_id=i.id) ELSE 0 END),0) discount_cents,COALESCE(SUM(CASE WHEN i.status<>'cancelled' AND i.deleted_at IS NULL THEN GREATEST(0,i.amount_cents-(SELECT COALESCE(SUM(p.amount_cents+COALESCE(p.discount_cents,0)),0) FROM payments p WHERE p.invoice_id=i.id)) ELSE 0 END),0) open_cents FROM invoices i WHERE i.client_id=?",[$id]);
  $invoices=DB::all("SELECT i.*,COALESCE((SELECT SUM(p.amount_cents) FROM payments p WHERE p.invoice_id=i.id),0) paid_cents,COALESCE((SELECT SUM(p.discount_cents) FROM payments p WHERE p.invoice_id=i.id),0) discount_cents FROM invoices i WHERE i.client_id=? AND i.deleted_at IS NULL ORDER BY i.due_on DESC,i.id DESC LIMIT 20",[$id]);
  $users=DB::all("SELECT u.id,u.name,u.email,u.active,uc.can_approve,uc.can_finance FROM user_clients uc JOIN users u ON u.id=uc.user_id WHERE uc.client_id=? ORDER BY u.name",[$id]);
  $files=(int)(DB::one("SELECT COUNT(*) n FROM files f JOIN demands d ON d.id=f.demand_id WHERE d.client_id=? AND d.deleted_at IS NULL",[$id])['n']??0);
  $activity=DB::all("SELECT a.*,u.name user_name FROM audit_log a LEFT JOIN users u ON u.id=a.user_id WHERE (a.entity='client' AND a.entity_id=?) OR (a.entity='demand' AND a.entity_id IN (SELECT id FROM demands WHERE client_id=?)) ORDER BY a.id DESC LIMIT 40",[$id,$id]);
  return ['client'=>$client,'task_summary'=>$taskSummary,'tasks'=>$tasks,'financial'=>$financial,'invoices'=>$invoices,'users'=>$users,'files_count'=>$files,'activity'=>$activity];
 }
public static function clientSave(array $p): array {
  Auth::admin();$id=(int)($p['id']??0);$old=$id?Auth::client($id):null;$isNew=!$old;$type=enumv($p['commercial_type']??($old['commercial_type']??'one_off'),['one_off','monthly'],'modelo comercial');$monthly=$type==='monthly'?integer($p['monthly_amount_cents']??($old['monthly_amount_cents']??0),0,1000000000):0;$paymentDay=$type==='monthly'?integer($p['monthly_payment_day']??($old['monthly_payment_day']??10),1,31):10;$driveId=array_key_exists('drive_account_id',$p)?(int)$p['drive_account_id']:(int)($old['drive_account_id']??0);
  if($driveId&&!DB::one("SELECT id FROM google_accounts WHERE id=? AND status='connected' AND drive_enabled=1 AND scopes LIKE ?",[$driveId,'%https://www.googleapis.com/auth/drive.file%']))fail('O Google Drive selecionado não está disponível. Reconecte essa conta como Drive.');
  $whatsapp=WppConnect::normalizePhone(required($p,'whatsapp_phone',20));
  $row=['name'=>required($p,'name',190),'brand'=>mb_substr($p['brand']??($old['brand']??''),0,190),'commercial_type'=>$type,'monthly_amount_cents'=>$monthly,'monthly_payment_day'=>$paymentDay,'drive_account_id'=>$driveId?:null,'email'=>trim($p['email']??($old['email']??'')),'phone'=>mb_substr($p['phone']??($old['phone']??''),0,60),'whatsapp_phone'=>$whatsapp,'document'=>mb_substr($p['document']??($old['document']??''),0,40),'notes'=>$p['notes']??($old['notes']??''),'brand_url'=>urlv($p['brand_url']??($old['brand_url']??'')),'active'=>(int)(bool)($p['active']??1)];
  if($row['email']&&!filter_var($row['email'],FILTER_VALIDATE_EMAIL))fail('E-mail inválido.');
  $createAccess=$isNew&&!empty($p['create_access']);$accessEmail='';$accessName='';$accessHash=null;
  if($createAccess){
   $accessEmail=strtolower(trim((string)($p['access_email']??$row['email'])));if($accessEmail===''||!filter_var($accessEmail,FILTER_VALIDATE_EMAIL))fail('Informe um e-mail válido para a conta de acesso.');
   if(DB::one('SELECT id FROM users WHERE email=?',[$accessEmail]))fail('Já existe uma conta de acesso com este e-mail.',409);
   $accessName=trim((string)($p['access_name']??$row['name']));if($accessName==='')$accessName=$row['name'];$accessName=mb_substr($accessName,0,150);
   $accessHash=Auth::password((string)($p['access_password']??''));
  }
  $driveChanged=$old&&((int)($old['drive_account_id']??0)!==$driveId);if($driveChanged)$row['drive_folder_id']=null;
  $result=DB::tx(function() use(&$id,$old,$isNew,$row,$driveChanged,$createAccess,$accessEmail,$accessName,$accessHash): array {
   if($id)DB::update('clients',$id,$row);else $id=DB::insert('clients',$row+['drive_folder_id'=>null,'created_at'=>now()]);
   if($driveChanged)DB::run('UPDATE demands SET drive_folder_id=NULL WHERE client_id=?',[$id]);
   audit('client',$id,$isNew?'created':'save',$old,$row);
   if($isNew)AutomationEvent::emit('client.created','client',$id,['client_id'=>$id,'change'=>'client.created'],(int)Auth::$user['id']);
   $userId=null;
   if($createAccess){
    $userId=DB::insert('users',['name'=>$accessName,'email'=>$accessEmail,'whatsapp_phone'=>$row['whatsapp_phone'],'password_hash'=>$accessHash,'role'=>'client','active'=>1,'email_notifications'=>1,'created_at'=>now()]);
    if(!WorkspaceContext::legacyCiFixture())WorkspaceContext::syncUser($userId,Auth::workspaceId(),'client',true);
    DB::insert('user_clients',['user_id'=>$userId,'client_id'=>$id,'can_approve'=>1,'can_finance'=>0]);
    audit('user',$userId,'client_access.created',null,['client_id'=>$id,'client_name'=>$row['name'],'email'=>$accessEmail]);
    audit('client',$id,'access.created',null,['user_id'=>$userId,'email'=>$accessEmail]);
   }
   return ['id'=>$id,'access_created'=>(bool)$createAccess,'access_user_id'=>$userId,'access_email'=>$createAccess?$accessEmail:null];
  });
  Notify::clientLifecycle($row+['id'=>$id],$isNew);return $result;
 }
 public static function clientDelete(array $p): array {
  Auth::admin();$id=integer($p['id']??0,1);$reason=required($p,'reason',500);if(mb_strlen($reason)<5)fail('Informe um motivo de exclusão com pelo menos 5 caracteres.');
  $old=Auth::client($id);if(!(int)($old['active']??0))fail('Este cliente já está excluído ou inativo.',409);
  $revoked=[];
  DB::tx(function() use($id,$reason,$old,&$revoked): void {
   $linked=DB::all("SELECT u.id,u.name,u.email FROM users u JOIN user_clients uc ON uc.user_id=u.id WHERE uc.client_id=? AND u.role='client'",[$id]);
   DB::run('DELETE FROM user_clients WHERE client_id=?',[$id]);
   foreach($linked as $u){
    $other=DB::one('SELECT client_id FROM user_clients WHERE user_id=? LIMIT 1',[$u['id']]);
    if(!$other){DB::update('users',(int)$u['id'],['active'=>0]);if(!WorkspaceContext::legacyCiFixture())WorkspaceContext::syncUser((int)$u['id'],Auth::workspaceId(),'client',false);$revoked[]=['id'=>(int)$u['id'],'name'=>$u['name'],'email'=>$u['email']];audit('user',(int)$u['id'],'client_access.revoked',null,['client_id'=>$id,'client_name'=>$old['name'],'reason'=>$reason]);}
   }
   DB::update('clients',$id,['active'=>0]);
   audit('client',$id,'deleted',$old,['active'=>0,'reason'=>$reason,'revoked_access_users'=>$revoked,'deleted_by_name'=>Auth::$user['name']??null]);
  });
  Notify::team('Cliente excluído',$old['name'].' foi excluído do TaskEsc. Motivo: '.$reason);
  return ['deleted'=>true,'id'=>$id,'revoked_access_users'=>$revoked];
 }
 public static function clientLog(array $p=[]): array {
  Auth::admin();$limit=max(1,min(200,(int)($p['limit']??100)));
  return DB::all("SELECT a.id,a.entity_id AS client_id,a.action,a.before_json,a.after_json,a.origin,a.created_at,u.name AS user_name,u.email AS user_email,c.name AS client_name FROM audit_log a LEFT JOIN users u ON u.id=a.user_id LEFT JOIN clients c ON c.id=a.entity_id WHERE a.entity='client' ORDER BY a.id DESC LIMIT ".$limit);
 }
 public static function projectSave(array $p): array {Auth::admin();$client=integer($p['client_id']??0,1);Auth::client($client);$id=(int)($p['id']??0);if($id&&!DB::one('SELECT id FROM projects WHERE id=?',[$id]))fail('Projeto não encontrado.',404);$row=['client_id'=>$client,'name'=>required($p,'name',190),'description'=>$p['description']??''];if($id)DB::update('projects',$id,$row);else $id=DB::insert('projects',$row+['created_at'=>now()]);audit('project',$id,'save',null,$row);return ['id'=>$id];}
 public static function users(): array {Auth::admin();$ws=Auth::workspaceId();if(WorkspaceContext::legacyCiFixture())return DB::all('SELECT id,name,email,whatsapp_phone,role,active,email_notifications,weekly_report,ai_assistant_enabled FROM users ORDER BY name');$rows=DB::all('SELECT u.id,u.name,u.email,u.whatsapp_phone,u.role,u.active,u.email_notifications,u.weekly_report,u.ai_assistant_enabled FROM users u JOIN workspace_members wm ON wm.user_id=u.id WHERE wm.workspace_id=? ORDER BY u.name',[$ws]);foreach($rows as &$r)$r['clients']=DB::all('SELECT * FROM user_clients WHERE user_id=? AND workspace_id=?',[$r['id'],$ws]);return $rows;}
 public static function userSave(array $p): array {
  Auth::admin();
  $id=(int)($p['id']??0);$old=$id?DB::one('SELECT id,role,active,whatsapp_phone,ai_assistant_enabled FROM users WHERE id=?',[$id]):null;if($id&&!$old)fail('Usuário não encontrado.',404);if($id&&!WorkspaceContext::legacyCiFixture())WorkspaceContext::assertUserBelongs($id,Auth::workspaceId());
  $email=strtolower(required($p,'email',190));if(!filter_var($email,FILTER_VALIDATE_EMAIL))fail('E-mail inválido.');if(DB::one('SELECT id FROM users WHERE email=? AND id<>?',[$email,$id]))fail('Este e-mail já está em uso.',409);
  $role=enumv($p['role']??'client',['admin','staff','client'],'perfil');$whatsapp=WppConnect::normalizePhone(required($p,'whatsapp_phone',20));$row=['name'=>required($p,'name',150),'email'=>$email,'whatsapp_phone'=>$whatsapp,'role'=>$role,'active'=>(int)(bool)($p['active']??1),'email_notifications'=>(int)(bool)($p['email_notifications']??1),
   'ai_assistant_enabled'=>$role==='admin'?0:(array_key_exists('ai_assistant_enabled',$p)?(int)(bool)$p['ai_assistant_enabled']:(int)($old['ai_assistant_enabled']??0))];
  // One number can authenticate only one user. Existing duplicates fail closed in the resolver.
  if(DB::one('SELECT id FROM users WHERE whatsapp_phone=? AND id<>? LIMIT 1',[$whatsapp,$id]))fail('Este WhatsApp já está vinculado a outro usuário.',409);
  if($id==Auth::$user['id']&&($role!=='admin'||!$row['active']))fail('Não é possível remover seu próprio acesso administrativo.');
  if($id&&!WorkspaceContext::legacyCiFixture()){$owner=DB::one('SELECT role FROM workspace_members WHERE workspace_id=? AND user_id=?',[Auth::workspaceId(),$id]);if(($owner['role']??'')==='owner'&&($role!=='admin'||!$row['active']))fail('O proprietário do workspace deve permanecer administrador ativo.',403);}
  if(!$id||!empty($p['password']))$row['password_hash']=Auth::password($p['password']??'');
  $links=$p['clients']??[];if(!is_array($links))fail('Vínculos de clientes inválidos.',422);$cleanLinks=[];
  if($role!=='admin')foreach($links as $link){$cid=integer($link['client_id']??0,1);Auth::client($cid);if(isset($cleanLinks[$cid]))continue;$cleanLinks[$cid]=['client_id'=>$cid,'can_approve'=>(int)(bool)($link['can_approve']??1),'can_finance'=>$role==='client'?(int)(bool)($link['can_finance']??0):0];}
  $workspaceId=Auth::workspaceId();
  DB::tx(function() use(&$id,$old,$row,$cleanLinks,$workspaceId): void {
   $isNew=!$old;
   if($id)DB::update('users',$id,$row);else $id=DB::insert('users',$row+['created_at'=>now()]);
   if(!WorkspaceContext::legacyCiFixture())WorkspaceContext::syncUser($id,$workspaceId,(string)$row['role'],(int)$row['active']===1,$old!==null&&(int)$old['active']===0&&(int)$row['active']===1);
   DB::run('DELETE FROM user_clients WHERE user_id=?',[$id]);
   foreach($cleanLinks as $link){
    DB::insert('user_clients',['user_id'=>$id,'client_id'=>$link['client_id'],'can_approve'=>$link['can_approve'],'can_finance'=>$link['can_finance']]);
    if($isNew&&($row['role']??'')==='staff')AutomationEvent::emit('client.team_member_added','client',(int)$link['client_id'],[
     'client_id'=>(int)$link['client_id'],'user_id'=>$id,'role'=>'staff','change'=>'team_member.created'
    ],(int)Auth::$user['id']);
   }
   $auditRow=$row;unset($auditRow['password_hash']);$auditRow['clients']=array_values($cleanLinks);audit('user',$id,'save',$old,$auditRow);
  });
  return ['id'=>$id];
 }
public static function settingsGet(): array {
  Auth::admin();$s=[];foreach(DB::all('SELECT * FROM settings') as $r)$s[$r['key']]=$r['value'];$s['base_url']=config('base_url');$s['google_client_id']=config('google_client_id','');$s['google_secret_set']=config('google_client_secret','')!=='';$smtp=config('smtp',[]);unset($smtp['password']);$s['smtp']=$smtp;$s['smtp_password_set']=!empty(config('smtp',[])['password']);$s['drive_account_id']=(int)($s['drive_account_id']??0);$s['min_delivery_business_hours']=(int)($s['min_delivery_business_hours']??24);$s['cron_url']=base('cron.php?key='.config('cron_key'));$s['cron_command']='php '.ROOT.'/bin/cron.php';$s['mcp_url']=base('mcp.php');$s['api_url']=base('api/v1');$s['openapi_url']=base('openapi.json');$s['tokens']=DB::all('SELECT id,name,scopes,expires_at,revoked,created_at FROM api_tokens WHERE user_id=? ORDER BY id DESC',[Auth::$user['id']]);$s['mail_log']=Notify::log(100);return $s;
 }
public static function settingsSave(array $p): array {
  Auth::admin();$oldLogo=setting('logo_url','');$newLogo=$oldLogo;foreach(['brand_name','brand_color','logo_url','upload_mb','retention_days','min_delivery_business_hours'] as $k){if(!array_key_exists($k,$p))continue;$v=(string)$p[$k];if($k==='brand_color'&&!preg_match('/^#[a-fA-F0-9]{6}$/',$v))fail('Cor inválida.');if($k==='logo_url'){$v=urlv($v);$newLogo=$v;}if($k==='upload_mb')$v=(string)integer($v,0,5242880);if($k==='retention_days')$v=(string)integer($v,1,3650);if($k==='min_delivery_business_hours')$v=(string)integer($v,1,720);if(strlen($v)>1000)fail('Configuração longa demais.');DB::run('INSERT INTO settings (`key`,value) VALUES (?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)',[$k,$v]);}
  if(array_key_exists('logo_url',$p)){self::removeManagedLogoFile($oldLogo,$newLogo);if($newLogo!==$oldLogo)self::clearPwaIcons();}
  if(array_key_exists('drive_account_id',$p)){$id=(int)$p['drive_account_id'];if($id&&!DB::one("SELECT id FROM google_accounts WHERE id=? AND status='connected' AND drive_enabled=1 AND scopes LIKE ?",[$id,'%https://www.googleapis.com/auth/drive.file%']))fail('Esta conta precisa ser reconectada com permissão do Google Drive.');$old=(int)setting('drive_account_id','0');DB::run('INSERT INTO settings (`key`,value) VALUES (?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)',['drive_account_id',(string)$id]);if($old!==$id){DB::run("INSERT INTO settings (`key`,value) VALUES ('drive_root_folder_id','') ON DUPLICATE KEY UPDATE value=''");DB::run('UPDATE demands d JOIN clients c ON c.id=d.client_id SET d.drive_folder_id=NULL WHERE c.drive_account_id IS NULL');}}return ['saved'=>true];
 }
 public static function settingsLogoUpload(): array {
  Auth::admin();$file=$_FILES['logo']??null;if(!is_array($file)||($file['error']??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_NO_FILE)fail('Selecione uma imagem para a logo.',400);if(($file['error']??UPLOAD_ERR_OK)!==UPLOAD_ERR_OK)fail('Não foi possível receber a imagem da logo.',400);if((int)($file['size']??0)<1)fail('A imagem enviada está vazia.');if((int)$file['size']>5*1024*1024)fail('A logo deve ter no máximo 5 MB.');$tmp=(string)($file['tmp_name']??'');if($tmp===''||!is_uploaded_file($tmp))fail('Upload de logo inválido.',400);$mime=(new finfo(FILEINFO_MIME_TYPE))->file($tmp)?:'';$allowed=['image/png'=>'png','image/jpeg'=>'jpg','image/webp'=>'webp','image/gif'=>'gif'];$ext=$allowed[$mime]??'';if($ext==='')fail('Formato inválido. Envie PNG, JPG, WEBP ou GIF.',422);if(@getimagesize($tmp)===false)fail('A imagem enviada é inválida.',422);$dir=ROOT.'/public/uploads/branding';if(!is_dir($dir)&&!@mkdir($dir,0755,true)&&!is_dir($dir))fail('Sem permissão para criar a pasta de logos.',500);$filename='logo-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(4)).'.'.$ext;$dest=$dir.'/'.$filename;if(!move_uploaded_file($tmp,$dest))fail('Não foi possível salvar a imagem da logo no servidor.',500);@chmod($dest,0644);self::clearPwaIcons();foreach(['icon_180'=>'pwa-180.png','icon_192'=>'pwa-192.png','icon_512'=>'pwa-512.png','icon_maskable_512'=>'pwa-maskable-512.png'] as $key=>$name){$icon=$_FILES[$key]??null;if(!is_array($icon)||($icon['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK)continue;$iconTmp=(string)($icon['tmp_name']??'');if($iconTmp===''||!is_uploaded_file($iconTmp)||(int)($icon['size']??0)>3*1024*1024)continue;$iconMime=(new finfo(FILEINFO_MIME_TYPE))->file($iconTmp)?:'';if($iconMime!=='image/png'||@getimagesize($iconTmp)===false)continue;@move_uploaded_file($iconTmp,$dir.'/'.$name);@chmod($dir.'/'.$name,0644);} $old=setting('logo_url','');$url=base('uploads/branding/'.$filename);DB::run("INSERT INTO settings (`key`,value) VALUES ('logo_url',?) ON DUPLICATE KEY UPDATE value=VALUES(value)",[$url]);self::removeManagedLogoFile($old,$url);audit('settings',0,'logo.upload',['logo_url'=>$old],['logo_url'=>$url]);return ['saved'=>true,'logo_url'=>$url,'pwa_icon'=>is_file($dir.'/pwa-192.png')?base('uploads/branding/pwa-192.png?v='.filemtime($dir.'/pwa-192.png')):base('assets/taskesc-logo.svg')];
 }
 private static function clearPwaIcons(): void {
  $dir=ROOT.'/public/uploads/branding';foreach(['pwa-180.png','pwa-192.png','pwa-512.png','pwa-maskable-512.png'] as $name){$file=$dir.'/'.$name;if(is_file($file))@unlink($file);}
 }
 private static function removeManagedLogoFile(string $oldUrl,string $keepUrl=''): void {
  $oldUrl=trim($oldUrl);$keepUrl=trim($keepUrl);if($oldUrl===''||$oldUrl===$keepUrl)return;$basePath=rtrim((string)(parse_url((string)config('base_url',''),PHP_URL_PATH)??''),'/');$path=(string)(parse_url($oldUrl,PHP_URL_PATH)??'');$prefix=($basePath?:'').'/uploads/branding/';if($path===''||!str_starts_with($path,$prefix))return;$relative=substr($path,strlen($basePath));$file=ROOT.'/public'.$relative;if(is_file($file))@unlink($file);
 }
 public static function integrationSave(array $p): array {Auth::admin();$c=config();foreach(['google_client_id','google_client_secret'] as $key)if(isset($p[$key])&&$p[$key]!=='')$c[$key]=trim($p[$key]);if(isset($p['smtp'])){$s=$p['smtp'];$host=trim($s['host']??'');if($host!==''&&!preg_match('/^[a-zA-Z0-9.-]+$/',$host))fail('Host SMTP inválido.');$c['smtp']=array_merge($c['smtp']??[],['host'=>$host,'port'=>integer($s['port']??587,1,65535),'encryption'=>enumv($s['encryption']??'tls',['tls','ssl'],'criptografia'),'username'=>$s['username']??'','from'=>$s['from']??'','from_name'=>$s['from_name']??'TaskEsc']);if(!empty($s['password']))$c['smtp']['password']=$s['password'];if($c['smtp']['from']&&!filter_var($c['smtp']['from'],FILTER_VALIDATE_EMAIL))fail('Remetente inválido.');}$tmp=ROOT.'/config/config.php.tmp';if(file_put_contents($tmp,"<?php\nreturn ".var_export($c,true).";\n",LOCK_EX)===false)fail('Sem permissão para salvar config/config.php.',500);chmod($tmp,0600);rename($tmp,ROOT.'/config/config.php');audit('settings',0,'integration.save',null,['google_configured'=>!empty($c['google_client_id']),'smtp_configured'=>!empty($c['smtp']['host'])]);return ['saved'=>true];}
 public static function customize(array $p): array {Auth::admin();$type=enumv($p['type']??'',['stage','category'],'tipo');$id=(int)($p['id']??0);$row=['name'=>required($p,'name',80),'active'=>(int)(bool)($p['active']??1)];if($type==='stage'){$row+=['kind'=>enumv($p['kind']??'production',['inbox','analysis','waiting','planned','production','approval','completed','cancelled','archived'],'grupo'),'color'=>$p['color']??'#737373','position'=>integer($p['position']??10,0,1000)];if(!preg_match('/^#[a-f0-9]{6}$/i',$row['color']))fail('Cor inválida.');if($id&&in_array($id,[1,6,7,8,9,10],true)){unset($row['kind']);$row['active']=1;}}else{$fields=$p['fields']??[];if(!is_array($fields)||count($fields)>30)fail('Até 30 campos.');$row['fields_json']=json(array_values(array_map(fn($v)=>mb_substr((string)$v,0,100),$fields)));}$table=$type==='stage'?'stages':'categories';if($id)DB::update($table,$id,$row);else $id=DB::insert($table,$row);return ['id'=>$id];}

 public static function services(): array {
  Auth::admin();
  return DB::all("SELECT c.*,COALESCE((SELECT COUNT(*) FROM demands d WHERE d.category_id=c.id AND d.deleted_at IS NULL),0) AS task_count FROM categories c ORDER BY c.active DESC,c.name");
 }
 public static function serviceSave(array $p): array {
  Auth::admin();$id=(int)($p['id']??0);$old=$id?DB::one('SELECT * FROM categories WHERE id=?',[$id]):null;if($id&&!$old)fail('Serviço não encontrado.',404);
  $name=required($p,'name',80);if(DB::one('SELECT id FROM categories WHERE name=? AND id<>?',[$name,$id]))fail('Já existe um serviço com este nome.',409);
  $check=$p['checklist']??decode($old['checklist_json']??'[]');if(!is_array($check))$check=[];$check=array_values(array_filter(array_map(fn($v)=>mb_substr(trim((string)$v),0,255),$check),fn($v)=>$v!==''));if(count($check)>30)fail('O checklist padrão aceita até 30 itens.');$assigned=!empty($p['default_assigned_to'])?(int)$p['default_assigned_to']:null;if($assigned&&!DB::one("SELECT id FROM users WHERE id=? AND active=1 AND role IN ('admin','staff')",[$assigned]))fail('Responsável padrão inválido.');$row=['name'=>$name,'active'=>(int)(bool)($p['active']??1),'fields_json'=>$old['fields_json']??'[]','default_amount_cents'=>integer($p['default_amount_cents']??($old['default_amount_cents']??0),0,1000000000),'default_due_hours'=>integer($p['default_due_hours']??($old['default_due_hours']??24),1,720),'default_revisions'=>integer($p['default_revisions']??($old['default_revisions']??2),0,50),'default_priority'=>enumv($p['default_priority']??($old['default_priority']??'normal'),['low','normal','high','urgent'],'prioridade padrão'),'default_assigned_to'=>$assigned,'checklist_json'=>json($check)];
  if($id)DB::update('categories',$id,$row);else $id=DB::insert('categories',$row);
  audit('category',$id,$old?'updated':'created',$old,$row);return ['id'=>$id];
 }
 public static function serviceDelete(array $p): array {
  Auth::admin();$id=integer($p['id']??0,1);$old=DB::one('SELECT * FROM categories WHERE id=?',[$id]);if(!$old)fail('Serviço não encontrado.',404);
  $count=(int)(DB::one('SELECT COUNT(*) AS n FROM demands WHERE category_id=? AND deleted_at IS NULL',[$id])['n']??0);
  DB::update('categories',$id,['active'=>0]);audit('category',$id,'deactivated',$old,['active'=>0,'active_tasks'=>$count]);
  return ['saved'=>true,'id'=>$id,'active_tasks'=>$count];
 }
 public static function stageSave(array $p): array {Auth::admin();$id=(int)($p['id']??0);$row=['name'=>required($p,'name',80),'kind'=>enumv($p['kind']??'production',['inbox','analysis','waiting','planned','production','approval','completed','cancelled','archived'],'grupo'),'color'=>$p['color']??'#7a7a7a','active'=>1];if(!preg_match('/^#[a-f0-9]{6}$/i',$row['color']))fail('Cor inválida.');if($id){$old=DB::one('SELECT * FROM stages WHERE id=?',[$id]);if(!$old)fail('Coluna não encontrada.',404);$row['position']=$old['position'];DB::update('stages',$id,$row);audit('stage',$id,'updated',$old,$row);}else{$row['position']=(int)(DB::one('SELECT COALESCE(MAX(position),0)+1 AS n FROM stages')['n']??1);$id=DB::insert('stages',$row);audit('stage',$id,'created',null,$row);}return ['id'=>$id];}
 public static function stageReorder(array $p): array {Auth::admin();$ids=$p['stage_ids']??[];if(!is_array($ids)||!$ids)fail('Ordem de colunas inválida.');$existing=DB::all('SELECT id FROM stages WHERE active=1 ORDER BY position,id');$expected=array_map(fn($r)=>(int)$r['id'],$existing);$clean=array_values(array_unique(array_map('intval',$ids)));sort($expected);$sorted=$clean;sort($sorted);if($expected!==$sorted)fail('A lista de colunas está desatualizada. Atualize o Kanban.',409);foreach($clean as $i=>$id)DB::update('stages',$id,['position'=>$i+1]);return ['saved'=>true];}
 public static function stageDelete(array $p): array {
  Auth::admin();$id=integer($p['id']??0,1);$stage=DB::one('SELECT * FROM stages WHERE id=? AND active=1',[$id]);if(!$stage)fail('Coluna não encontrada.',404);
  if((int)(DB::one('SELECT COUNT(*) AS n FROM stages WHERE active=1 AND id<>?',[$id])['n']??0)<1)fail('O Kanban precisa ter pelo menos uma coluna.');
  $cards=DB::all('SELECT d.*,s.kind AS stage_kind FROM demands d JOIN stages s ON s.id=d.stage_id WHERE d.stage_id=? AND d.deleted_at IS NULL',[$id]);$count=count($cards);$target=(int)($p['move_to_stage_id']??0);$targetStage=null;
  if($count){
   $targetStage=$target&&$target!==$id?DB::one('SELECT * FROM stages WHERE id=? AND active=1',[$target]):null;if(!$targetStage)fail('Escolha outra coluna para receber os cards existentes.');
   foreach($cards as $card){
    $row=['stage_id'=>$targetStage['id'],'version'=>(int)$card['version']+1,'updated_at'=>now(),'completed_at'=>$targetStage['kind']==='completed'?($card['completed_at']??now()):null];
    if($targetStage['kind']==='completed')$row['first_completed_at']=$card['first_completed_at']??now();if(in_array($targetStage['kind'],['planned','production','approval','completed'],true))$row['accepted_at']=$card['accepted_at']??now();
    DB::update('demands',$card['id'],$row);DB::insert('transitions',['demand_id'=>$card['id'],'from_stage'=>$id,'to_stage'=>$targetStage['id'],'actor_id'=>Auth::$user['id'],'happened_at'=>now()]);Notify::demand($card,'Etapa atualizada',$card['title'].' • '.$targetStage['name']);
   }
  }
  DB::update('stages',$id,['active'=>0]);audit('stage',$id,'deleted',$stage,['moved_cards'=>$count,'target'=>$targetStage['id']??null]);return ['saved'=>true,'moved_cards'=>$count];
 }
 public static function templates(array $p): array {if(Auth::$user['role']==='client')return DB::all('SELECT id,name,category_id,brief,details_json,checklist_json FROM templates WHERE active=1 AND recurring_client_id IS NULL');Auth::admin();return DB::all('SELECT * FROM templates ORDER BY name');}
 public static function templateSave(array $p): array {Auth::admin();$id=(int)($p['id']??0);$client=!empty($p['recurring_client_id'])?(int)$p['recurring_client_id']:null;if($client)Auth::client($client);$row=['name'=>required($p,'name',190),'category_id'=>integer($p['category_id'],1),'brief'=>required($p,'brief'),'details_json'=>json($p['details']??new stdClass()),'checklist_json'=>json($p['checklist']??[]),'recurring_client_id'=>$client,'next_on'=>datev($p['next_on']??null),'interval_days'=>integer($p['interval_days']??7,1,365),'active'=>(int)(bool)($p['active']??1)];if($client&&!$row['next_on'])fail('Informe a próxima data.');if($id)DB::update('templates',$id,$row);else $id=DB::insert('templates',$row);return ['id'=>$id];}
}
