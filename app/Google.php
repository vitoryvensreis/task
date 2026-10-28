<?php
final class Google {
 private const DRIVE='https://www.googleapis.com/drive/v3/';
 private const DRIVE_UPLOAD='https://www.googleapis.com/upload/drive/v3/';
 private const DRIVE_SCOPE='https://www.googleapis.com/auth/drive.file';

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
  $purpose='drive';$returnTo=enumv($p['return_to']??'settings',['clients','settings'],'retorno');
  // Reautoriza a conta original vinculada ao arquivo; nunca altera a identidade silenciosamente.
  $reconnectId=(int)($p['account_id']??0);$reconnectAccount=null;
  if($reconnectId>0){
   $reconnectAccount=DB::one('SELECT id,email,google_sub FROM google_accounts WHERE id=? AND user_id=?'.(WorkspaceContext::legacyCiFixture()?'':' AND workspace_id=?'),WorkspaceContext::legacyCiFixture()?[$reconnectId,(int)Auth::$user['id']]:[$reconnectId,(int)Auth::$user['id'],Auth::workspaceId()]);
   if(!$reconnectAccount||empty($reconnectAccount['google_sub']))fail('Esta conta Google pertence a outro usuário ou não está disponível. Peça ao proprietário original para reconectá-la.',403);
  }
  $state=bin2hex(random_bytes(24));$verifier=bin2hex(random_bytes(32));
  $_SESSION['google_state']=['state'=>$state,'verifier'=>$verifier,'expires'=>time()+600,'user_id'=>Auth::$user['id'],'workspace_id'=>Auth::workspaceId(),'purpose'=>$purpose,'return_to'=>$returnTo,'account_id'=>$reconnectId];
  $scopes=['openid','email'];
  $scopes[]=self::DRIVE_SCOPE;
  $params=['client_id'=>config('google_client_id'),'redirect_uri'=>base('google-callback.php'),'response_type'=>'code','scope'=>implode(' ',$scopes),'access_type'=>'offline','prompt'=>'consent select_account','include_granted_scopes'=>'true','state'=>$state,'code_challenge'=>rtrim(strtr(base64_encode(hash('sha256',$verifier,true)),'+/','-_'),'='),'code_challenge_method'=>'S256'];if($reconnectAccount)$params['login_hint']=(string)$reconnectAccount['email'];
  return ['url'=>'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query($params),'purpose'=>$purpose];
 }

 public static function callback(array $q): array {
  Auth::admin();$s=$_SESSION['google_state']??null;unset($_SESSION['google_state']);
  if(!$s||$s['expires']<time()||$s['user_id']!=Auth::$user['id']||!hash_equals($s['state'],$q['state']??'')||(int)($s['workspace_id']??0)!==Auth::workspaceId())fail('Autorização expirada, inválida ou pertencente a outra empresa.',400);
  if(isset($q['error']))fail('A autorização do Google foi cancelada.');
  $purpose=$s['purpose']??'';if($purpose!=='drive')fail('Conexão Google legada não permitida.',403);$returnTo=$s['return_to']??'settings';
  $tokens=self::http('https://oauth2.googleapis.com/token','POST',['code'=>required($q,'code'),'client_id'=>config('google_client_id'),'client_secret'=>config('google_client_secret'),'redirect_uri'=>base('google-callback.php'),'grant_type'=>'authorization_code','code_verifier'=>$s['verifier']],null,[],true);
  $info=self::http('https://www.googleapis.com/oauth2/v3/userinfo','GET',null,$tokens['access_token']);
  $old=DB::one('SELECT * FROM google_accounts WHERE user_id=? AND google_sub=?'.(WorkspaceContext::legacyCiFixture()?'':' AND workspace_id=?'),WorkspaceContext::legacyCiFixture()?[Auth::$user['id'],$info['sub']]:[Auth::$user['id'],$info['sub'],Auth::workspaceId()]);
  if((int)($s['account_id']??0)>0&&(!$old||(int)$old['id']!==(int)$s['account_id']))
   fail('Você autorizou uma conta Google diferente da conta original deste arquivo. Refaça a conexão escolhendo o mesmo e-mail para preservar o vínculo com os anexos.',409);
  $refresh=$tokens['refresh_token']??($old&&!empty($old['refresh_token'])?secretDecrypt($old['refresh_token']):'');
  if(!$refresh)fail('O Google não forneceu acesso offline. Revogue a conexão anterior e conecte novamente.');
  $scopes=self::liveScopes((string)$tokens['access_token'],(string)($tokens['scope']??''));
  $row=['user_id'=>Auth::$user['id'],'google_sub'=>$info['sub'],'email'=>$info['email'],'access_token'=>secretEncrypt($tokens['access_token']),'refresh_token'=>secretEncrypt($refresh),'scopes'=>$scopes,'expires_at'=>gmdate('Y-m-d H:i:s',time()+(int)$tokens['expires_in']),'status'=>'connected','calendar_enabled'=>0,'drive_enabled'=>(int)str_contains($scopes,self::DRIVE_SCOPE),'last_error'=>null];
  if($old){$id=(int)$old['id'];DB::update('google_accounts',$id,$row);}else $id=DB::insert('google_accounts',$row+(WorkspaceContext::legacyCiFixture()?[]:['workspace_id'=>Auth::workspaceId()]));
  if($purpose==='drive'&&$returnTo==='settings'&&(int)WorkspacePreferences::get('drive_account_id','0')===0)WorkspacePreferences::save('drive_account_id',(string)$id);
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
    'calendar_enabled'=>0,
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
 private static function driveApi(int $account,string $path,string $method='GET',?array $data=null,array $headers=[]): array {return self::authenticatedApi($account,self::DRIVE,$path,$method,$data,$headers);}

 public static function accounts(): array {
  Auth::admin();$rows=DB::all('SELECT id,email,status,last_error,expires_at,scopes,calendar_enabled,drive_enabled FROM google_accounts WHERE user_id=?'.(WorkspaceContext::legacyCiFixture()?'':' AND workspace_id=?').' ORDER BY id',WorkspaceContext::legacyCiFixture()?[Auth::$user['id']]:[Auth::$user['id'],Auth::workspaceId()]);
  foreach($rows as &$r){$r['drive_ready']=$r['status']==='connected'&&(bool)$r['drive_enabled']&&str_contains((string)($r['scopes']??''),self::DRIVE_SCOPE);$r['calendar_ready']=false;}
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
  DB::update('google_accounts',$a['id'],['status'=>'disconnected','access_token'=>'','refresh_token'=>'']);
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
  curl_setopt_array($ch,[CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$token,'Accept: */*'],CURLOPT_FOLLOWLOCATION=>true,CURLOPT_MAXREDIRS=>5,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>0,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_FILE=>$fh]);
  $ok=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$err=curl_error($ch);curl_close($ch);fclose($fh);
  if($ok===false||$code<200||$code>=300){@unlink($path);throw new RuntimeException('Não foi possível baixar um arquivo do Google Drive'.($err?': '.$err:'.'));}
 }

 public static function streamDriveFile(int $account,string $fileId,string $mime,string $name,bool $inline=false): never {
  $url=self::DRIVE.'files/'.rawurlencode($fileId).'?alt=media';
  $headers=['Authorization: Bearer '.self::token($account),'Accept: */*'];
  $range=(string)($_SERVER['HTTP_RANGE']??'');
  if(preg_match('/^bytes=\d*-\d*$/D',$range))$headers[]='Range: '.$range;
  if(!preg_match('#^[a-z0-9.+-]+/[a-z0-9.+-]+$#iD',$mime))$mime='application/octet-stream';
  $status=0;$sent=false;$upstreamHeaders=[];
  $sendHeaders=function()use(&$sent,&$status,&$upstreamHeaders,$mime,$name,$inline): void {
   if($sent)return;
   http_response_code($status===206?206:200);
   header('Content-Type: '.$mime);
   header('Content-Disposition: '.($inline?'inline':'attachment')."; filename*=UTF-8''".rawurlencode($name));
   header('X-Content-Type-Options: nosniff');
   header('Cache-Control: private, no-store');
   header('Accept-Ranges: bytes');
   foreach(['content-length'=>'Content-Length','content-range'=>'Content-Range'] as $key=>$title)if(isset($upstreamHeaders[$key]))header($title.': '.$upstreamHeaders[$key]);
   $sent=true;
  };
  $ch=curl_init($url);
  $options=[
   CURLOPT_HTTPHEADER=>$headers,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_MAXREDIRS=>5,
   CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>0,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
   CURLOPT_HEADERFUNCTION=>function($ch,$line)use(&$status,&$upstreamHeaders): int {
    if(preg_match('#^HTTP/\S+\s+(\d{3})#',$line,$match)){$status=(int)$match[1];$upstreamHeaders=[];return strlen($line);}
    $parts=explode(':',$line,2);
    if(count($parts)===2){
     $key=strtolower(trim($parts[0]));$value=trim($parts[1]);
     if(in_array($key,['content-length','content-range'],true)&&preg_match('/^[0-9\/ *-]+$/D',$value))$upstreamHeaders[$key]=$value;
    }
    return strlen($line);
   },
   CURLOPT_WRITEFUNCTION=>function($ch,$chunk)use(&$status,$sendHeaders): int {
    if($status===200||$status===206){$sendHeaders();echo $chunk;flush();}
    return strlen($chunk);
   }
  ];
  if(defined('CURLOPT_REDIR_PROTOCOLS_STR'))$options[constant('CURLOPT_REDIR_PROTOCOLS_STR')]='https';
  elseif(defined('CURLOPT_REDIR_PROTOCOLS'))$options[constant('CURLOPT_REDIR_PROTOCOLS')]=CURLPROTO_HTTPS;
  curl_setopt_array($ch,$options);
  $ok=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$error=curl_error($ch);curl_close($ch);
  if($ok===false||!in_array($code,[200,206],true)){
   error_log('TaskEsc Drive stream failed HTTP '.$code.' account '.$account.($error!==''?' curl: '.$error:''));
   if(!$sent){
    http_response_code(in_array($code,[401,403,404],true)?$code:502);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: private, no-store');
    echo 'Não foi possível abrir este anexo no Google Drive. Verifique a conexão e as permissões da conta original.';
   }
   exit;
  }
  if(!$sent)$sendHeaders();
  exit;
 }


}
