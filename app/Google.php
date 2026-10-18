<?php
final class Google {
 private const API='https://www.googleapis.com/calendar/v3/';
 private const DRIVE='https://www.googleapis.com/drive/v3/';
 private const DRIVE_UPLOAD='https://www.googleapis.com/upload/drive/v3/';
 private const DRIVE_SCOPE='https://www.googleapis.com/auth/drive.file';
 private const CALENDAR_SCOPE='https://www.googleapis.com/auth/calendar.events';

 public static function http(string $url,string $method='GET',?array $data=null,?string $token=null,array $headers=[],bool $form=false): array {
  $ch=curl_init($url);$h=['Accept: application/json',...$headers];
  if($token)$h[]='Authorization: Bearer '.$token;
  if($data!==null){$h[]=$form?'Content-Type: application/x-www-form-urlencoded':'Content-Type: application/json';curl_setopt($ch,CURLOPT_POSTFIELDS,$form?http_build_query($data):json($data));}
  curl_setopt_array($ch,[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$h,CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>30,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS]);
  $body=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$err=curl_error($ch);curl_close($ch);
  if($body===false)throw new RuntimeException('Conexão com o Google indisponível: '.$err);
  $decoded=$body!==''?(json_decode($body,true)??[]):[];
  if($code<200||$code>=300){
   $googleError=$decoded['error']??null;
   $oauthCode=is_string($googleError)?$googleError:(is_array($googleError)?(string)($googleError['status']??''):'');
   $message=is_array($googleError)?($googleError['message']??null):null;
   $message=$message??$decoded['error_description']??(is_string($googleError)?$googleError:'operação recusada');
   throw new AppError('Google: '.mb_substr((string)$message,0,400).' ('.$code.')',502,['google_status'=>$code,'oauth_error'=>$oauthCode]);
  }
  return $decoded;
 }

 private static function normalizeScopes(string $scope): string {
  $parts=preg_split('/\s+/',trim($scope))?:[];
  return implode(' ',array_values(array_unique(array_filter($parts,fn($v)=>$v!==''))));
 }

 private static function liveScopes(string $accessToken,string $fallback=''): string {
  try{
   $info=self::http('https://oauth2.googleapis.com/tokeninfo?'.http_build_query(['access_token'=>$accessToken]));
   $scope=self::normalizeScopes((string)($info['scope']??''));
   if($scope!=='')return $scope;
  }catch(Throwable $e){error_log('TaskEsc Google tokeninfo: '.$e->getMessage());}
  return self::normalizeScopes($fallback);
 }

 public static function connect(array $p=[]): array {
  Auth::admin();
  if(!config('google_client_id')||!config('google_client_secret'))fail('Configure Client ID e Client Secret nas integrações.');
  $purpose=enumv($p['purpose']??'calendar',['calendar','drive'],'tipo de conexão');if($purpose==='calendar')MasterSupport::requireCalendar();$returnTo=enumv($p['return_to']??($purpose==='drive'?'settings':'agenda'),['agenda','clients','settings'],'retorno');
  $state=bin2hex(random_bytes(24));$verifier=bin2hex(random_bytes(32));
  $_SESSION['google_state']=['state'=>$state,'verifier'=>$verifier,'expires'=>time()+600,'user_id'=>Auth::$user['id'],'workspace_id'=>Auth::workspaceId(),'purpose'=>$purpose,'return_to'=>$returnTo];
  $scopes=['openid','email'];
  if($purpose==='drive')$scopes[]=self::DRIVE_SCOPE;
  else array_push($scopes,'https://www.googleapis.com/auth/calendar.calendarlist.readonly',self::CALENDAR_SCOPE,'https://www.googleapis.com/auth/calendar.freebusy');
  $params=['client_id'=>config('google_client_id'),'redirect_uri'=>base('google-callback.php'),'response_type'=>'code','scope'=>implode(' ',$scopes),'access_type'=>'offline','prompt'=>'consent select_account','include_granted_scopes'=>'true','state'=>$state,'code_challenge'=>rtrim(strtr(base64_encode(hash('sha256',$verifier,true)),'+/','-_'),'='),'code_challenge_method'=>'S256'];
  return ['url'=>'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query($params),'purpose'=>$purpose];
 }

 public static function callback(array $q): array {
  Auth::admin();$s=$_SESSION['google_state']??null;unset($_SESSION['google_state']);
  if(!$s||$s['expires']<time()||$s['user_id']!=Auth::$user['id']||!hash_equals($s['state'],$q['state']??'')||(int)($s['workspace_id']??0)!==Auth::workspaceId())fail('Autorização expirada, inválida ou pertencente a outra empresa.',400);
  if(isset($q['error']))fail('A autorização do Google foi cancelada.');
  $purpose=$s['purpose']??'calendar';if($purpose==='calendar')MasterSupport::requireCalendar();$returnTo=$s['return_to']??($purpose==='drive'?'settings':'agenda');
  $tokens=self::http('https://oauth2.googleapis.com/token','POST',['code'=>required($q,'code'),'client_id'=>config('google_client_id'),'client_secret'=>config('google_client_secret'),'redirect_uri'=>base('google-callback.php'),'grant_type'=>'authorization_code','code_verifier'=>$s['verifier']],null,[],true);
  $info=self::http('https://www.googleapis.com/oauth2/v3/userinfo','GET',null,$tokens['access_token']);
  $old=DB::one('SELECT * FROM google_accounts WHERE user_id=? AND google_sub=?'.(WorkspaceContext::legacyCiFixture()?'':' AND workspace_id=?'),WorkspaceContext::legacyCiFixture()?[Auth::$user['id'],$info['sub']]:[Auth::$user['id'],$info['sub'],Auth::workspaceId()]);
  $refresh=$tokens['refresh_token']??($old&&!empty($old['refresh_token'])?secretDecrypt($old['refresh_token']):'');
  if(!$refresh)fail('O Google não forneceu acesso offline. Revogue a conexão anterior e conecte novamente.');
  $scopes=self::liveScopes((string)$tokens['access_token'],(string)($tokens['scope']??''));
  $row=['user_id'=>Auth::$user['id'],'google_sub'=>$info['sub'],'email'=>$info['email'],'access_token'=>secretEncrypt($tokens['access_token']),'refresh_token'=>secretEncrypt($refresh),'scopes'=>$scopes,'expires_at'=>gmdate('Y-m-d H:i:s',time()+(int)$tokens['expires_in']),'status'=>'connected','calendar_enabled'=>(int)str_contains($scopes,self::CALENDAR_SCOPE),'drive_enabled'=>(int)str_contains($scopes,self::DRIVE_SCOPE),'last_error'=>null];
  if($old){$id=(int)$old['id'];DB::update('google_accounts',$id,$row);}else $id=DB::insert('google_accounts',$row+(WorkspaceContext::legacyCiFixture()?[]:['workspace_id'=>Auth::workspaceId()]));
  if($purpose==='drive'&&$returnTo==='settings'&&(int)WorkspacePreferences::get('drive_account_id','0')===0)WorkspacePreferences::save('drive_account_id',(string)$id);
  if($purpose==='calendar')self::discover($id);
  return ['account_id'=>$id,'email'=>$info['email'],'purpose'=>$purpose,'return_to'=>$returnTo];
 }

 /** Classificação independente do Google: apenas rejeições definitivas exigem reconexão. */
 public static function refreshErrorCategory(Throwable $e): string {
  if($e instanceof AppError){
   $oauth=strtolower((string)($e->details['oauth_error']??''));
   if(in_array($oauth,['invalid_grant','invalid_client','unauthorized_client','access_denied'],true))return 'reconnect';
   $status=(int)($e->details['google_status']??0);
   if(in_array($status,[408,429,500,502,503,504],true))return 'retry';
   return 'review';
  }
  return 'retry'; // Timeout, DNS e falhas de transporte não revogam credenciais.
 }

 private static function refreshTokenWithRetry(array $a): array {
  for($attempt=0;$attempt<3;$attempt++){
   try{return self::http('https://oauth2.googleapis.com/token','POST',[
    'client_id'=>config('google_client_id'),'client_secret'=>config('google_client_secret'),
    'refresh_token'=>secretDecrypt($a['refresh_token']),'grant_type'=>'refresh_token'
   ],null,[],true);}
   catch(Throwable $e){
    if(self::refreshErrorCategory($e)!=='retry'||$attempt===2)throw $e;
    usleep(250000*(1<<$attempt));
   }
  }
  throw new RuntimeException('Não foi possível renovar o token Google.');
 }

 /**
  * Production multiworkspace mode never refreshes a token without a validated
  * tenant context. CLI workers must explicitly use WorkspaceJobScope.
  * Single-tenant legacy behavior remains unchanged until cutover.
  */
 private static function integrationScope(): int {
  if(WorkspaceContext::legacyCiFixture()||!WorkspaceTenantMode::enabled())return 0;
  $workspaceId=(int)(Auth::$workspace['id']??0);
  if($workspaceId<1)$workspaceId=WorkspaceJobScope::currentId();
  if($workspaceId<1)throw new RuntimeException('Google integration requires an explicit workspace context.');
  return $workspaceId;
 }

 public static function token(int $id,bool $forceRefresh=false): string {
  $workspaceId=self::integrationScope();
  $a=DB::one('SELECT * FROM google_accounts WHERE id=?'.($workspaceId?' AND workspace_id=?':''),$workspaceId?[$id,$workspaceId]:[$id]);
  if(!$a||$a['status']==='disconnected')throw new RuntimeException('Conta Google não encontrada neste workspace ou desconectada.');
  if(!$forceRefresh&&strtotime($a['expires_at'].' UTC')>time()+60)return secretDecrypt($a['access_token']);
  try{
   $r=self::refreshTokenWithRetry($a);
   $liveScopes=self::liveScopes((string)$r['access_token'],(string)($r['scope']??$a['scopes']??''));
   DB::update('google_accounts',$id,[
    'access_token'=>secretEncrypt($r['access_token']),
    'expires_at'=>gmdate('Y-m-d H:i:s',time()+(int)$r['expires_in']),
    'status'=>'connected','last_error'=>null,'scopes'=>$liveScopes,
    'calendar_enabled'=>(int)str_contains($liveScopes,self::CALENDAR_SCOPE),
    'drive_enabled'=>(int)str_contains($liveScopes,self::DRIVE_SCOPE)
   ]);
   return $r['access_token'];
  }catch(Throwable $e){
   $error=['last_error'=>mb_substr($e->getMessage(),0,1000)];
   if(self::refreshErrorCategory($e)==='reconnect')$error['status']='reconnect';
   DB::update('google_accounts',$id,$error);
   throw $e;
  }
 }

 private static function authenticatedApi(int $account,string $url,string $path,string $method='GET',?array $data=null,array $headers=[]): array {
  try{return self::http($url.$path,$method,$data,self::token($account),$headers);}
  catch(AppError $e){
   // Um access token pode ser revogado antes do vencimento guardado no banco.
   if((int)($e->details['google_status']??0)!==401)throw $e;
   return self::http($url.$path,$method,$data,self::token($account,true),$headers);
  }
 }
 private static function api(int $account,string $path,string $method='GET',?array $data=null,array $headers=[]): array {return self::authenticatedApi($account,self::API,$path,$method,$data,$headers);}
 private static function driveApi(int $account,string $path,string $method='GET',?array $data=null,array $headers=[]): array {return self::authenticatedApi($account,self::DRIVE,$path,$method,$data,$headers);}

 public static function discover(int $account): void {
  $next='';do{$data=self::api($account,'users/me/calendarList?maxResults=250'.($next?'&pageToken='.rawurlencode($next):''));foreach($data['items']??[] as $c){$old=DB::one('SELECT * FROM calendars WHERE account_id=? AND google_id=?',[$account,$c['id']]);$role=$c['accessRole']??'reader';$writable=in_array($role,['owner','writer','writerWithoutPrivateAccess'],true);$row=['name'=>$c['summaryOverride']??$c['summary']??$c['id'],'color'=>$c['backgroundColor']??'#8a8a8a','access_role'=>$role];if($old){if(!$writable)$row['write_enabled']=0;DB::update('calendars',$old['id'],$row);}else DB::insert('calendars',$row+['account_id'=>$account,'google_id'=>$c['id'],'selected'=>1,'write_enabled'=>(int)$writable]);}$next=$data['nextPageToken']??'';}while($next);
 }

 public static function accounts(): array {
  Auth::admin();$rows=DB::all('SELECT id,email,status,last_error,expires_at,scopes,calendar_enabled,drive_enabled FROM google_accounts WHERE user_id=?'.(WorkspaceContext::legacyCiFixture()?'':' AND workspace_id=?').' ORDER BY id',WorkspaceContext::legacyCiFixture()?[Auth::$user['id']]:[Auth::$user['id'],Auth::workspaceId()]);
  foreach($rows as &$r){$r['drive_ready']=$r['status']==='connected'&&(bool)$r['drive_enabled']&&str_contains((string)($r['scopes']??''),self::DRIVE_SCOPE);$r['calendar_ready']=$r['status']==='connected'&&(bool)$r['calendar_enabled']&&str_contains((string)($r['scopes']??''),self::CALENDAR_SCOPE);}
  return $rows;
 }

 /** Expose/select only the acting company's own connected Google Drive. */
 public static function companyDefaultDrive(?array $input=null): array {
  Auth::load();Auth::admin();
  if((Auth::$user['role']??'')!=='admin')fail('Disponível somente ao administrador.',403);
  $id=(int)WorkspacePreferences::get('drive_account_id','0');
  if($input===null){
   $owned=$id>0&&DB::one('SELECT id FROM google_accounts WHERE id=? AND user_id=? AND status=?'
       .(WorkspaceContext::legacyCiFixture()?'':' AND workspace_id=?'),
       WorkspaceContext::legacyCiFixture()?[$id,(int)Auth::$user['id'],'connected']:[$id,(int)Auth::$user['id'],'connected',Auth::workspaceId()]);
   return ['account_id'=>$owned?$id:0,'requires_google_drive'=>true];
  }
  $target=integer($input['account_id']??0,0);
  if($target>0){
   WorkspaceCatalog::validateDrive($target);
   $row=DB::one("SELECT id FROM google_accounts WHERE id=? AND user_id=? AND status='connected' AND drive_enabled=1 AND scopes LIKE ?".
     (WorkspaceContext::legacyCiFixture()?'':' AND workspace_id=?'),
     WorkspaceContext::legacyCiFixture()?[$target,(int)Auth::$user['id'],'%'.self::DRIVE_SCOPE.'%']:[$target,(int)Auth::$user['id'],'%'.self::DRIVE_SCOPE.'%',Auth::workspaceId()]);
   if(!$row)fail('Conecte uma conta Google Drive própria antes de selecioná-la.',409);
  }
  WorkspacePreferences::save('drive_account_id',(string)$target);
  if($id!==$target)WorkspacePreferences::save('drive_root_folder_id','');
  audit('google_account',$target,'company.default_drive',null,['account_id'=>$target]);
  return ['saved'=>true,'account_id'=>$target];
 }

 public static function disconnect(array $p): array {
  Auth::admin();$a=DB::one('SELECT * FROM google_accounts WHERE id=? AND user_id=?'.(WorkspaceContext::legacyCiFixture()?'':' AND workspace_id=?'),WorkspaceContext::legacyCiFixture()?[integer($p['id'],1),Auth::$user['id']]:[integer($p['id'],1),Auth::$user['id'],Auth::workspaceId()]);if(!$a)fail('Conta não encontrada.');
  DB::update('google_accounts',$a['id'],['status'=>'disconnected','access_token'=>'','refresh_token'=>'']);DB::run('UPDATE calendars SET selected=0,write_enabled=0 WHERE account_id=?',[$a['id']]);
  if((int)WorkspacePreferences::get('drive_account_id','0')===(int)$a['id']){WorkspacePreferences::save('drive_account_id','');WorkspacePreferences::save('drive_root_folder_id','');}
  audit('google_account',$a['id'],'disconnected');return ['saved'=>true];
 }

 public static function driveAccountId(?int $clientId=null): int {
  if($clientId){if(!WorkspaceContext::legacyCiFixture())WorkspaceCatalog::requireRecord('clients',$clientId);$client=DB::one('SELECT id,name,drive_account_id FROM clients WHERE id=?',[$clientId]);if(!$client)fail('Cliente não encontrado.',404);$id=(int)($client['drive_account_id']??0);if($id){if(!WorkspaceContext::legacyCiFixture())WorkspaceCatalog::validateDrive($id);$a=DB::one("SELECT id,scopes,status,drive_enabled FROM google_accounts WHERE id=?",[$id]);if($a&&$a['status']==='connected'&&(bool)$a['drive_enabled']&&str_contains((string)($a['scopes']??''),self::DRIVE_SCOPE))return $id;fail('O Google Drive configurado para '.$client['name'].' precisa ser reconectado no cadastro do cliente.',409);}}
  $id=(int)WorkspacePreferences::get('drive_account_id','0');
  if($id){if(!WorkspaceContext::legacyCiFixture())WorkspaceCatalog::validateDrive($id);$a=DB::one("SELECT id,scopes,status,drive_enabled FROM google_accounts WHERE id=? AND status='connected'",[$id]);if($a&&(bool)$a['drive_enabled']&&str_contains((string)($a['scopes']??''),self::DRIVE_SCOPE))return $id;}
  fail('Defina um Google Drive para este cliente ou configure um Drive padrão em Configurações.',409);
 }

 private static function createDriveFolder(int $account,string $name,?string $parent=null): array {
  $body=['name'=>mb_substr($name,0,180),'mimeType'=>'application/vnd.google-apps.folder'];if($parent)$body['parents']=[$parent];
  return self::driveApi($account,'files?fields=id,name,webViewLink','POST',$body);
 }

 private static function driveFolderValid(int $account,string $id): bool {
  if($id==='')return false;try{$f=self::driveApi($account,'files/'.rawurlencode($id).'?fields=id,mimeType,trashed&supportsAllDrives=true');return empty($f['trashed'])&&($f['mimeType']??'')==='application/vnd.google-apps.folder';}catch(Throwable){return false;}
 }

 public static function ensureDemandFolder(array $d): array {
  $client=DB::one('SELECT id,name,drive_account_id,drive_folder_id FROM clients WHERE id=?'.(WorkspaceContext::legacyCiFixture()?'':' AND workspace_id=?'),WorkspaceContext::legacyCiFixture()?[(int)$d['client_id']]:[(int)$d['client_id'],Auth::workspaceId()]);if(!$client)fail('Cliente da task não encontrado.',404);$account=self::driveAccountId((int)$client['id']);
  if(!empty($d['drive_folder_id'])&&self::driveFolderValid($account,(string)$d['drive_folder_id']))return ['account_id'=>$account,'folder_id'=>$d['drive_folder_id']];
  if(!empty($d['drive_folder_id']))DB::update('demands',(int)$d['id'],['drive_folder_id'=>null]);
  $clientRoot=(string)($client['drive_folder_id']??'');
  if($clientRoot&&!self::driveFolderValid($account,$clientRoot)){$clientRoot='';DB::update('clients',(int)$client['id'],['drive_folder_id'=>null]);}
  if(!$clientRoot){
   if(!empty($client['drive_account_id'])){$folder=self::createDriveFolder($account,'TaskEsc - '.$client['name']);$clientRoot=$folder['id'];DB::update('clients',(int)$client['id'],['drive_folder_id'=>$clientRoot]);}
   else{$clientRoot=WorkspacePreferences::get('drive_root_folder_id','');if($clientRoot&&!self::driveFolderValid($account,$clientRoot)){$clientRoot='';WorkspacePreferences::save('drive_root_folder_id','');}if(!$clientRoot){$folder=self::createDriveFolder($account,'TaskEsc');$clientRoot=$folder['id'];WorkspacePreferences::save('drive_root_folder_id',$clientRoot);}}
  }
  $name='Job #'.$d['id'].' - '.($d['title']??'Sem título');$folder=self::createDriveFolder($account,$name,$clientRoot);DB::update('demands',(int)$d['id'],['drive_folder_id'=>$folder['id']]);return ['account_id'=>$account,'folder_id'=>$folder['id']];
 }

 public static function ensureDemandFileFolder(array $d,string $kind='input'): array {
  $root=self::ensureDemandFolder($d);
  if($kind!=='final')return $root;
  $account=(int)$root['account_id'];$parent=(string)$root['folder_id'];$folder=self::findDemandFinalFolder($account,$parent);
  if(!$folder){$folder=self::createDriveFolder($account,'Entrega final',$parent);}
  $share=self::ensurePublicFolderShare($account,(string)$folder['id']);
  return ['account_id'=>$account,'folder_id'=>$folder['id'],'share_url'=>$share];
 }

 private static function findDemandFinalFolder(int $account,string $parent): ?array {
  $name='Entrega final';$q="'".str_replace(["\\","'"],["\\\\","\\'"],$parent)."' in parents and name='".str_replace(["\\","'"],["\\\\","\\'"],$name)."' and mimeType='application/vnd.google-apps.folder' and trashed=false";
  try{
   $found=self::driveApi($account,'files?'.http_build_query(['q'=>$q,'fields'=>'files(id,name,webViewLink)','pageSize'=>10,'spaces'=>'drive','supportsAllDrives'=>'true','includeItemsFromAllDrives'=>'true']));
   foreach($found['files']??[] as $f)if(!empty($f['id'])&&self::driveFolderValid($account,(string)$f['id']))return $f;
  }catch(Throwable $e){error_log('TaskEsc final folder lookup: '.$e->getMessage());}
  return null;
 }

 private static function ensurePublicFolderShare(int $account,string $folderId): string {
  try{
   $path='files/'.rawurlencode($folderId).'/permissions?'.http_build_query(['fields'=>'permissions(id,type,role,allowFileDiscovery)','supportsAllDrives'=>'true']);
   $permissions=self::driveApi($account,$path);
   $public=false;foreach($permissions['permissions']??[] as $p)if(($p['type']??'')==='anyone'&&in_array(($p['role']??''),['reader','commenter','writer'],true)){$public=true;break;}
   if(!$public){
    self::driveApi($account,'files/'.rawurlencode($folderId).'/permissions?'.http_build_query(['fields'=>'id','supportsAllDrives'=>'true','sendNotificationEmail'=>'false']),'POST',['type'=>'anyone','role'=>'reader','allowFileDiscovery'=>false]);
   }
  }catch(Throwable $e){
   error_log('TaskEsc public final folder: '.$e->getMessage());
   throw new AppError('Não foi possível liberar a pasta de entrega final para acesso por link no Google Drive. Verifique se esta conta permite compartilhamento público.',409);
  }
  return 'https://drive.google.com/drive/folders/'.rawurlencode($folderId);
 }

 public static function finalDeliveryShareLink(array $d,?array $finalFile=null): ?string {
  try{
   $account=(int)($finalFile['drive_account_id']??0);
   $fileId=trim((string)($finalFile['drive_file_id']??''));

   // Prefer the actual parent of a final file. This survives client Drive routing changes
   // and does not create/alter folders merely because someone opened a task.
   if($account>0&&$fileId!==''){
    $meta=self::driveApi($account,'files/'.rawurlencode($fileId).'?'.http_build_query(['fields'=>'id,parents,trashed','supportsAllDrives'=>'true']));
    if(empty($meta['trashed'])){
     foreach(($meta['parents']??[]) as $parentId){
      if(!$parentId)continue;
      try{
       $parent=self::driveApi($account,'files/'.rawurlencode((string)$parentId).'?'.http_build_query(['fields'=>'id,name,mimeType,trashed','supportsAllDrives'=>'true']));
       $name=mb_strtolower(trim((string)($parent['name']??'')));
       if(empty($parent['trashed'])&&($parent['mimeType']??'')==='application/vnd.google-apps.folder'&&str_contains($name,'entrega')&&str_contains($name,'final')){
        try{return self::ensurePublicFolderShare($account,(string)$parent['id']);}
        catch(Throwable $e){error_log('TaskEsc final folder share fallback: '.$e->getMessage());return 'https://drive.google.com/drive/folders/'.rawurlencode((string)$parent['id']);}
       }
      }catch(Throwable $e){error_log('TaskEsc final parent lookup: '.$e->getMessage());}
     }
    }
   }

   // Compatibility fallback for older tasks that already store the job folder.
   if(!empty($d['drive_folder_id'])){
    if($account<1)$account=self::driveAccountId((int)$d['client_id']);
    if(self::driveFolderValid($account,(string)$d['drive_folder_id'])){
     $folder=self::findDemandFinalFolder($account,(string)$d['drive_folder_id']);
     if($folder&&!empty($folder['id'])){try{return self::ensurePublicFolderShare($account,(string)$folder['id']);}catch(Throwable $e){error_log('TaskEsc final legacy share fallback: '.$e->getMessage());return 'https://drive.google.com/drive/folders/'.rawurlencode((string)$folder['id']);}}
    }
   }
  }catch(Throwable $e){error_log('TaskEsc final share link: '.$e->getMessage());}
  return null;
 }

 public static function startDriveUpload(int $account,string $name,string $mime,int $size,string $folderId): string {
  if($size<1)fail('O arquivo está vazio.');
  $metadata=['name'=>mb_substr($name,0,240),'parents'=>[$folderId]];$location='';
  $ch=curl_init(self::DRIVE_UPLOAD.'files?uploadType=resumable&fields=id,name,mimeType,size,webViewLink,webContentLink');
  curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json($metadata),CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>30,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.self::token($account),'Accept: application/json','Content-Type: application/json; charset=UTF-8','X-Upload-Content-Type: '.$mime,'X-Upload-Content-Length: '.$size,'Expect:'],CURLOPT_HEADERFUNCTION=>function($ch,$line)use(&$location){$len=strlen($line);$parts=explode(':',$line,2);if(count($parts)===2&&strtolower(trim($parts[0]))==='location')$location=trim($parts[1]);return $len;}]);
  $body=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$err=curl_error($ch);curl_close($ch);
  if($body===false||$code<200||$code>=300||!$location){$decoded=json_decode((string)$body,true)??[];$message=$decoded['error']['message']??$err?:('HTTP '.$code);throw new AppError('Não foi possível iniciar o upload no Google Drive: '.$message,502,['google_status'=>$code]);}
  $host=strtolower((string)parse_url($location,PHP_URL_HOST));if($host===''||($host!=='www.googleapis.com'&&!str_ends_with($host,'.googleapis.com')))throw new RuntimeException('O Google retornou uma sessão de upload inválida.');
  return $location;
 }

 public static function uploadDriveChunk(int $account,string $location,string $chunk,int $offset,int $total,string $mime='application/octet-stream'): array {
  $len=strlen($chunk);if($len<1)fail('Bloco de upload vazio.');if($offset<0||$offset+$len>$total)fail('Bloco de upload inválido.');
  $host=strtolower((string)parse_url($location,PHP_URL_HOST));if($host===''||($host!=='www.googleapis.com'&&!str_ends_with($host,'.googleapis.com')))throw new RuntimeException('Sessão de upload inválida.');
  $end=$offset+$len-1;$range='';
  $ch=curl_init($location);curl_setopt_array($ch,[CURLOPT_CUSTOMREQUEST=>'PUT',CURLOPT_POSTFIELDS=>$chunk,CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>180,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.self::token($account),'Content-Type: '.$mime,'Content-Length: '.$len,'Content-Range: bytes '.$offset.'-'.$end.'/'.$total,'Expect:'],CURLOPT_HEADERFUNCTION=>function($ch,$line)use(&$range){$l=strlen($line);$p=explode(':',$line,2);if(count($p)===2&&strtolower(trim($p[0]))==='range')$range=trim($p[1]);return $l;}]);
  $resp=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$err=curl_error($ch);curl_close($ch);
  if($resp===false||!in_array($status,[200,201,308],true)){$dec=json_decode((string)$resp,true)??[];throw new AppError('Upload no Google Drive falhou: '.($dec['error']['message']??$err?:('HTTP '.$status)),502,['google_status'=>$status]);}
  if($status===308){$next=$offset+$len;if($range&&preg_match('/bytes=0-(\d+)/i',$range,$m))$next=(int)$m[1]+1;return ['complete'=>false,'next_offset'=>$next];}
  $result=json_decode((string)$resp,true)??[];if(empty($result['id']))throw new RuntimeException('O Google Drive não confirmou o arquivo enviado.');return ['complete'=>true,'next_offset'=>$total,'file'=>$result];
 }

 public static function driveUploadStatus(int $account,string $location,int $total): array {
  $host=strtolower((string)parse_url($location,PHP_URL_HOST));if($host===''||($host!=='www.googleapis.com'&&!str_ends_with($host,'.googleapis.com')))throw new RuntimeException('Sessão de upload inválida.');$range='';
  $ch=curl_init($location);curl_setopt_array($ch,[CURLOPT_CUSTOMREQUEST=>'PUT',CURLOPT_POSTFIELDS=>'',CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>45,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.self::token($account),'Content-Length: 0','Content-Range: bytes */'.$total,'Expect:'],CURLOPT_HEADERFUNCTION=>function($ch,$line)use(&$range){$l=strlen($line);$p=explode(':',$line,2);if(count($p)===2&&strtolower(trim($p[0]))==='range')$range=trim($p[1]);return $l;}]);
  $resp=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$err=curl_error($ch);curl_close($ch);
  if($resp===false)throw new RuntimeException('Não foi possível consultar o estado do upload: '.$err);
  if(in_array($status,[200,201],true)){$result=json_decode((string)$resp,true)??[];if(empty($result['id']))throw new RuntimeException('O Google confirmou o upload sem retornar o arquivo.');return ['complete'=>true,'next_offset'=>$total,'file'=>$result];}
  if($status===308){$next=0;if($range&&preg_match('/bytes=0-(\d+)/i',$range,$m))$next=(int)$m[1]+1;return ['complete'=>false,'next_offset'=>$next];}
  $dec=json_decode((string)$resp,true)??[];throw new AppError('Não foi possível consultar o estado do upload: '.($dec['error']['message']??$err?:('HTTP '.$status)),502,['google_status'=>$status]);
 }

 public static function uploadToDrive(int $account,string $path,string $name,string $mime,string $folderId): array {
  $size=filesize($path);if($size===false||$size<1)fail('O arquivo está vazio ou não pôde ser lido.');
  $metadata=['name'=>mb_substr($name,0,240),'parents'=>[$folderId]];$location='';$responseHeaders=[];
  $ch=curl_init(self::DRIVE_UPLOAD.'files?uploadType=resumable&fields=id,name,mimeType,size,webViewLink,webContentLink');
  curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json($metadata),CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>30,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.self::token($account),'Accept: application/json','Content-Type: application/json; charset=UTF-8','X-Upload-Content-Type: '.$mime,'X-Upload-Content-Length: '.$size],CURLOPT_HEADERFUNCTION=>function($ch,$line)use(&$location,&$responseHeaders){$len=strlen($line);$parts=explode(':',$line,2);if(count($parts)===2){$k=strtolower(trim($parts[0]));$v=trim($parts[1]);$responseHeaders[$k]=$v;if($k==='location')$location=$v;}return $len;}]);
  $body=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$err=curl_error($ch);curl_close($ch);
  if($body===false||$code<200||$code>=300||!$location)throw new RuntimeException('Não foi possível iniciar o upload no Google Drive'.($err?': '.$err:'.'));
  $fh=fopen($path,'rb');if(!$fh)throw new RuntimeException('Não foi possível ler o arquivo enviado.');$offset=0;$chunkSize=8*1024*1024;$result=[];
  try{while(!feof($fh)){$chunk=fread($fh,$chunkSize);if($chunk===false)throw new RuntimeException('Falha ao ler o arquivo.');if($chunk==='')break;$len=strlen($chunk);$end=$offset+$len-1;$up=curl_init($location);curl_setopt_array($up,[CURLOPT_CUSTOMREQUEST=>'PUT',CURLOPT_POSTFIELDS=>$chunk,CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>180,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.self::token($account),'Content-Type: '.$mime,'Content-Length: '.$len,'Content-Range: bytes '.$offset.'-'.$end.'/'.$size]]);$resp=curl_exec($up);$status=(int)curl_getinfo($up,CURLINFO_HTTP_CODE);$uerr=curl_error($up);curl_close($up);if($resp===false||(!in_array($status,[200,201,308],true))){$dec=json_decode((string)$resp,true)??[];throw new RuntimeException('Upload no Google Drive falhou: '.($dec['error']['message']??$uerr?:('HTTP '.$status)));}if($status===200||$status===201)$result=json_decode((string)$resp,true)??[];$offset+=$len;}}
  finally{fclose($fh);}if(empty($result['id']))throw new RuntimeException('O Google Drive não confirmou o arquivo enviado.');return $result;
 }

 public static function deleteDriveFile(int $account,string $fileId): void {
  if($fileId==='')return;try{self::driveApi($account,'files/'.rawurlencode($fileId).'?supportsAllDrives=true','DELETE');}catch(AppError $e){if(!in_array((int)($e->details['google_status']??0),[404,410],true))throw $e;}
 }

 public static function downloadDriveFileToPath(int $account,string $fileId,string $path): void {
  $token=self::token($account);$fh=fopen($path,'wb');if(!$fh)throw new RuntimeException('Não foi possível preparar o arquivo temporário.');
  $ch=curl_init(self::DRIVE.'files/'.rawurlencode($fileId).'?alt=media');
  curl_setopt_array($ch,[CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$token,'Accept: */*'],CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>0,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_FILE=>$fh]);
  $ok=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$err=curl_error($ch);curl_close($ch);fclose($fh);
  if($ok===false||$code<200||$code>=300){@unlink($path);throw new RuntimeException('Não foi possível baixar um arquivo do Google Drive'.($err?': '.$err:'.'));}
 }

 public static function streamDriveFile(int $account,string $fileId,string $mime,string $name,bool $inline=false): never {
  $token=self::token($account);$url=self::DRIVE.'files/'.rawurlencode($fileId).'?alt=media';$headers=['Authorization: Bearer '.$token,'Accept: */*'];
  $range=$_SERVER['HTTP_RANGE']??'';if($range&&preg_match('/^bytes=\d*-\d*$/',$range))$headers[]='Range: '.$range;
  header('Content-Type: '.($mime?:'application/octet-stream'));header('Content-Disposition: '.($inline?'inline':'attachment').'; filename*=UTF-8\'\''.rawurlencode($name));header('Accept-Ranges: bytes');header('X-Content-Type-Options: nosniff');
  $ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_HTTPHEADER=>$headers,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>0,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_HEADERFUNCTION=>function($ch,$line){$len=strlen($line);if(preg_match('#^HTTP/\S+\s+(\d{3})#',$line,$m))http_response_code((int)$m[1]);$p=explode(':',$line,2);if(count($p)===2){$k=strtolower(trim($p[0]));$v=trim($p[1]);if(in_array($k,['content-length','content-range','accept-ranges'],true))header(ucwords($k,'-').': '.$v);}return $len;},CURLOPT_WRITEFUNCTION=>function($ch,$data){echo $data;flush();return strlen($data);}]);
  $ok=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$err=curl_error($ch);curl_close($ch);if($ok===false||$code>=400){error_log('Drive stream: '.$err.' HTTP '.$code);if(!headers_sent())http_response_code(502);}exit;
 }

 public static function eventFields(array $e): array {
  $all=!empty($e['start']['date']);$zone=$e['start']['timeZone']??config('timezone');$start=$all?(new DateTimeImmutable($e['start']['date'],new DateTimeZone($zone)))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'):timestamp($e['start']['dateTime']??null);$end=$all?(new DateTimeImmutable($e['end']['date'],new DateTimeZone($zone)))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'):timestamp($e['end']['dateTime']??null);if(!$start||!$end)throw new RuntimeException('Evento Google sem horário.');
  $organizer=is_array($e['organizer']??null)?$e['organizer']:[];
  $attendees=[];foreach(array_slice(is_array($e['attendees']??null)?$e['attendees']:[],0,500) as $a){if(!is_array($a))continue;$attendees[]=['name'=>mb_substr((string)($a['displayName']??''),0,190),'email'=>mb_substr((string)($a['email']??''),0,190),'status'=>(string)($a['responseStatus']??'needsAction'),'organizer'=>(int)(bool)($a['organizer']??false),'self'=>(int)(bool)($a['self']??false),'optional'=>(int)(bool)($a['optional']??false),'additional_guests'=>(int)($a['additionalGuests']??0),'comment'=>mb_substr((string)($a['comment']??''),0,500)];}
  $entryPoints=[];foreach(is_array($e['conferenceData']['entryPoints']??null)?$e['conferenceData']['entryPoints']:[] as $p){if(!is_array($p))continue;$entryPoints[]=['type'=>(string)($p['entryPointType']??''),'uri'=>(string)($p['uri']??''),'label'=>mb_substr((string)($p['label']??''),0,190),'pin'=>mb_substr((string)($p['pin']??''),0,80),'access_code'=>mb_substr((string)($p['accessCode']??''),0,80),'meeting_code'=>mb_substr((string)($p['meetingCode']??''),0,80),'password'=>mb_substr((string)($p['password']??''),0,80)];}
  $meetUrl=trim((string)($e['hangoutLink']??''));if($meetUrl==='')foreach($entryPoints as $p)if($p['type']==='video'&&$p['uri']!==''){$meetUrl=$p['uri'];break;}
  $attachments=[];foreach(array_slice(is_array($e['attachments']??null)?$e['attachments']:[],0,50) as $a){if(!is_array($a))continue;$attachments[]=['title'=>mb_substr((string)($a['title']??''),0,255),'url'=>(string)($a['fileUrl']??''),'mime'=>(string)($a['mimeType']??''),'file_id'=>(string)($a['fileId']??'')];}
  return ['title'=>mb_substr($e['summary']??'Ocupado',0,255),'starts_at'=>$start,'ends_at'=>$end,'all_day'=>(int)$all,'location'=>$e['location']??'','description'=>$e['description']??'','organizer_name'=>mb_substr((string)($organizer['displayName']??''),0,190)?:null,'organizer_email'=>mb_substr((string)($organizer['email']??''),0,190)?:null,'attendees_json'=>$attendees?json($attendees):null,'meet_url'=>$meetUrl?:null,'conference_json'=>$entryPoints?json($entryPoints):null,'event_url'=>trim((string)($e['htmlLink']??''))?:null,'attachments_json'=>$attachments?json($attachments):null,'private'=>(int)(($e['visibility']??'')==='private'),'status'=>($e['status']??'')==='cancelled'?'cancelled':'confirmed','google_etag'=>$e['etag']??null,'recurring_id'=>$e['recurringEventId']??null];
 }

 public static function refreshEvent(int $eventId): void {
  $e=DB::one("SELECT e.id,e.calendar_id,e.demand_id,e.google_id,e.google_etag,e.version,e.status,e.updated_at,e.sync_status,c.account_id,c.google_id AS calendar_google_id,c.access_role FROM events e JOIN calendars c ON c.id=e.calendar_id WHERE e.id=?",[$eventId]);
  if(!$e||!$e['account_id']||!$e['google_id']||$e['access_role']==='freeBusyReader'||in_array($e['sync_status'],['pending','error','conflict'],true))return;
  $remote=self::api((int)$e['account_id'],'calendars/'.rawurlencode((string)$e['calendar_google_id']).'/events/'.rawurlencode((string)$e['google_id']));
  if(($remote['status']??'')==='cancelled'){
   if(($e['status']??'')==='cancelled')return;
   DB::tx(function() use($eventId,$e): void {$row=['status'=>'cancelled','version'=>(int)$e['version']+1,'updated_at'=>now()];DB::update('events',$eventId,$row);AgendaRealtime::event('agenda.google_changed',array_merge($e,$row),'google.refresh.cancelled',null);});
   return;
  }
  $fields=self::eventFields($remote);$changed=(($e['google_etag']??'')!==($remote['etag']??''));
  if(!$changed)return;
  DB::tx(function() use($eventId,$e,$fields): void {$row=$fields+['sync_status'=>'synced','remote_json'=>null,'version'=>(int)$e['version']+1,'updated_at'=>now()];DB::update('events',$eventId,$row);AgendaRealtime::event('agenda.google_changed',array_merge($e,$row),'google.refresh.updated',null);});
 }

 public static function syncCalendarNow(int $calendarId): array {
  $workspaceId=self::integrationScope();
  $c=DB::one("SELECT c.* FROM calendars c JOIN google_accounts a ON a.id=c.account_id WHERE c.id=? AND a.status<>'disconnected' AND a.calendar_enabled=1".($workspaceId?' AND a.workspace_id=?':''),$workspaceId?[$calendarId,$workspaceId]:[$calendarId]);if(!$c)return ['synced'=>false,'message'=>'Agenda local ou conta Google indisponível.'];try{self::push($c);DB::update('calendars',$c['id'],['synced_at'=>now(),'last_error'=>null,'dirty'=>0]);return ['synced'=>true];}catch(Throwable $e){$message=mb_substr($e->getMessage(),0,1000);DB::update('calendars',$c['id'],['last_error'=>$message]);return ['synced'=>false,'message'=>$message];}
 }

 public static function sync(?int $only=null): array {
  $workspaceId=self::integrationScope();
  $params=$only?[$only]:[];if($workspaceId)$params[]=$workspaceId;
  $ok=0;$errors=[];$rows=DB::all("SELECT c.* FROM calendars c JOIN google_accounts a ON a.id=c.account_id WHERE c.selected=1 AND a.status<>'disconnected' AND a.calendar_enabled=1".($only?' AND c.id=?':'').($workspaceId?' AND a.workspace_id=?':''),$params);foreach($rows as $c){try{self::push($c);self::pull($c);self::watch($c);DB::update('calendars',$c['id'],['synced_at'=>now(),'last_error'=>null,'dirty'=>0]);DB::run("UPDATE google_accounts SET status='connected',last_error=NULL WHERE id=? AND status<>'disconnected' AND last_error IS NOT NULL",[(int)$c['account_id']]);$ok++;}catch(Throwable $e){$message=mb_substr($e->getMessage(),0,1000);DB::update('calendars',$c['id'],['last_error'=>$message]);$errors[]=['calendar_id'=>$c['id'],'message'=>$message];}}return ['synced'=>$ok,'errors'=>$errors,'updated_at'=>now()];
 }

 private static function push(array $c): void {
  if(!$c['write_enabled'])return;$prefix='calendars/'.rawurlencode($c['google_id']).'/events';
  foreach(DB::all("SELECT * FROM events WHERE calendar_id=? AND sync_status IN ('pending','error') ORDER BY id LIMIT 30",[$c['id']]) as $e){try{$path=$prefix.'/'.rawurlencode($e['google_id']);if($e['status']==='cancelled'){try{self::api($c['account_id'],$path,'DELETE',null,$e['google_etag']?['If-Match: '.$e['google_etag']]:[]);}catch(AppError $x){if(!in_array($x->details['google_status']??0,[404,410]))throw $x;}$r=[];}else{$start=$e['all_day']?['date'=>(new DateTimeImmutable($e['starts_at'],new DateTimeZone('UTC')))->setTimezone(new DateTimeZone(config('timezone')))->format('Y-m-d')]:['dateTime'=>str_replace(' ','T',$e['starts_at']).'Z','timeZone'=>config('timezone')];$end=$e['all_day']?['date'=>(new DateTimeImmutable($e['ends_at'],new DateTimeZone('UTC')))->setTimezone(new DateTimeZone(config('timezone')))->format('Y-m-d')]:['dateTime'=>str_replace(' ','T',$e['ends_at']).'Z','timeZone'=>config('timezone')];$body=['summary'=>$e['title'],'start'=>$start,'end'=>$end,'location'=>$e['location'],'description'=>$e['description'],'visibility'=>$e['private']?'private':'default'];if($e['google_etag'])$r=self::api($c['account_id'],$path,'PATCH',$body,['If-Match: '.$e['google_etag']]);else{try{$r=self::api($c['account_id'],$prefix,'POST',$body);}catch(AppError $x){if(($x->details['google_status']??0)!==409)throw $x;$r=self::api($c['account_id'],$path);}}}if(!empty($r['id'])){$remote=self::eventFields($r);DB::update('events',$e['id'],$remote+['google_id'=>$r['id'],'sync_status'=>'synced','sync_error'=>null,'remote_json'=>null,'version'=>$e['version'],'updated_at'=>now()]);}else DB::run("UPDATE events SET sync_status='synced',sync_error=NULL,updated_at=? WHERE id=? AND version=?",[now(),$e['id'],$e['version']]);}
   catch(Throwable $x){$code=$x instanceof AppError?($x->details['google_status']??0):0;$conflict=in_array($code,[404,410,412]);$remote=[];if($conflict){try{$remote=self::api($c['account_id'],$prefix.'/'.rawurlencode($e['google_id']));}catch(Throwable){$remote=['status'=>'cancelled'];}}DB::update('events',$e['id'],['sync_status'=>$conflict?'conflict':'error','sync_error'=>mb_substr($x->getMessage(),0,1000),'remote_json'=>json($remote)]);}}
 }

 private static function pages(int $account,string $path,array $params): array {$items=[];$next='';$sync=null;$count=0;do{$q=$params;if($next)$q['pageToken']=$next;$r=self::api($account,$path.'?'.http_build_query($q));array_push($items,...($r['items']??[]));$next=$r['nextPageToken']??'';$sync=$r['nextSyncToken']??$sync;if(++$count>200)throw new RuntimeException('Agenda muito extensa; reduza o histórico sincronizado.');}while($next);return [$items,$sync];}

 private static function pull(array $c): void {
  $account=(int)$c['account_id'];$path='calendars/'.rawurlencode($c['google_id']).'/events';$from=gmdate('Y-m-d\T00:00:00\Z',strtotime('-60 days'));$to=gmdate('Y-m-d\T00:00:00\Z',strtotime('+400 days'));
  if($c['access_role']==='freeBusyReader'){
   $r=self::api($account,'freeBusy','POST',['timeMin'=>$from,'timeMax'=>$to,'items'=>[['id'=>$c['google_id']]]]);$busy=$r['calendars'][$c['google_id']]??[];if(!empty($busy['errors']))throw new RuntimeException('Sem acesso à disponibilidade dessa agenda.');
   DB::tx(function() use($c,$busy): void {
    $before=(int)(DB::one("SELECT COUNT(*) AS n FROM events WHERE calendar_id=? AND origin='google'",[$c['id']])['n']??0);DB::run("DELETE FROM events WHERE calendar_id=? AND origin='google'",[$c['id']]);$created=0;
    foreach($busy['busy']??[] as $b){DB::insert('events',['calendar_id'=>$c['id'],'title'=>'Ocupado','starts_at'=>timestamp($b['start']),'ends_at'=>timestamp($b['end']),'private'=>1,'origin'=>'google','sync_status'=>'synced','created_at'=>now(),'updated_at'=>now()]);$created++;}
    if($before||$created)AgendaRealtime::calendar('agenda.calendar_refreshed',(int)$c['id'],'google.freebusy',null,$before+$created);
   });
   return;
  }
  $params=['maxResults'=>2500,'showDeleted'=>'true','singleEvents'=>'false'];if($c['sync_token'])$params['syncToken']=$c['sync_token'];try{[$changes,$token]=self::pages($account,$path,$params);}catch(AppError $x){if(($x->details['google_status']??0)!==410)throw $x;unset($params['syncToken']);[$changes,$token]=self::pages($account,$path,$params);}
  [$events]=self::pages($account,$path,['maxResults'=>2500,'singleEvents'=>'true','timeMin'=>$from,'timeMax'=>$to]);
  DB::tx(function() use($c,$changes,$token,$events,$from,$to): void {
   $changedCount=0;
   foreach($changes as $g){
    if(($g['status']??'')!=='cancelled')continue;
    $rows=DB::all("SELECT id,version,status FROM events WHERE calendar_id=? AND (google_id=? OR recurring_id=?) AND sync_status='synced'",[$c['id'],$g['id'],$g['id']]);
    foreach($rows as $row)if(($row['status']??'')!=='cancelled'){DB::update('events',(int)$row['id'],['status'=>'cancelled','version'=>(int)$row['version']+1,'updated_at'=>now()]);$changedCount++;}
   }
   $seen=[];
   foreach($events as $g){
    if(($g['status']??'')==='cancelled')continue;$seen[]=$g['id'];$old=DB::one('SELECT * FROM events WHERE calendar_id=? AND google_id=?',[$c['id'],$g['id']]);$fields=self::eventFields($g);
    if($old){
     if(in_array($old['sync_status'],['pending','error','conflict'],true))continue;$changed=(($old['google_etag']??'')!==($g['etag']??''));if(!$changed)continue;DB::update('events',$old['id'],$fields+['sync_status'=>'synced','remote_json'=>null,'version'=>$old['version']+1,'updated_at'=>now()]);$changedCount++;
    }else{DB::insert('events',$fields+['calendar_id'=>$c['id'],'google_id'=>$g['id'],'origin'=>'google','sync_status'=>'synced','created_at'=>now(),'updated_at'=>now()]);$changedCount++;}
   }
   $existing=DB::all("SELECT id,google_id,version,status FROM events WHERE calendar_id=? AND origin='google' AND sync_status='synced' AND starts_at>=? AND starts_at<?",[$c['id'],timestamp($from),timestamp($to)]);$lookup=array_flip($seen);
   foreach($existing as $e)if(!isset($lookup[$e['google_id']])&&($e['status']??'')!=='cancelled'){DB::update('events',$e['id'],['status'=>'cancelled','version'=>(int)$e['version']+1,'updated_at'=>now()]);$changedCount++;}
   DB::update('calendars',$c['id'],['sync_token'=>$token]);
   if($changedCount>0)AgendaRealtime::calendar('agenda.calendar_refreshed',(int)$c['id'],'google.pull',null,$changedCount);
  });
 }

 private static function watch(array $c): void {
  if(!str_starts_with(base(),'https://')||$c['access_role']==='freeBusyReader')return;if($c['channel_expires']&&strtotime($c['channel_expires'].' UTC')>time()+3600)return;$id=bin2hex(random_bytes(16));$token=bin2hex(random_bytes(24));
  try{$r=self::api($c['account_id'],'calendars/'.rawurlencode($c['google_id']).'/events/watch','POST',['id'=>$id,'type'=>'web_hook','address'=>base('webhook.php'),'token'=>$token,'params'=>['ttl'=>'604800']]);DB::update('calendars',$c['id'],['channel_id'=>$id,'channel_token'=>$token,'channel_resource'=>$r['resourceId'],'channel_expires'=>gmdate('Y-m-d H:i:s',(int)($r['expiration']/1000))]);}catch(Throwable $e){error_log('Calendar watch: '.$e->getMessage());}
 }
}
