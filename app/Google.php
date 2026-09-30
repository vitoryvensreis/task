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
  if($code<200||$code>=300)throw new AppError('Google: '.($decoded['error']['message']??$decoded['error_description']??'operação recusada').' ('.$code.')',502,['google_status'=>$code]);
  return $decoded;
 }

 public static function connect(array $p=[]): array {
  Auth::admin();
  if(!config('google_client_id')||!config('google_client_secret'))fail('Configure Client ID e Client Secret nas integrações.');
  $purpose=enumv($p['purpose']??'calendar',['calendar','drive'],'tipo de conexão');$returnTo=enumv($p['return_to']??($purpose==='drive'?'settings':'agenda'),['agenda','clients','settings'],'retorno');
  $state=bin2hex(random_bytes(24));$verifier=bin2hex(random_bytes(32));
  $_SESSION['google_state']=['state'=>$state,'verifier'=>$verifier,'expires'=>time()+600,'user_id'=>Auth::$user['id'],'purpose'=>$purpose,'return_to'=>$returnTo];
  $scopes=['openid','email'];
  if($purpose==='drive')$scopes[]=self::DRIVE_SCOPE;
  else array_push($scopes,'https://www.googleapis.com/auth/calendar.calendarlist.readonly',self::CALENDAR_SCOPE,'https://www.googleapis.com/auth/calendar.freebusy');
  $params=['client_id'=>config('google_client_id'),'redirect_uri'=>base('google-callback.php'),'response_type'=>'code','scope'=>implode(' ',$scopes),'access_type'=>'offline','prompt'=>'consent select_account','include_granted_scopes'=>'true','state'=>$state,'code_challenge'=>rtrim(strtr(base64_encode(hash('sha256',$verifier,true)),'+/','-_'),'='),'code_challenge_method'=>'S256'];
  return ['url'=>'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query($params),'purpose'=>$purpose];
 }

 public static function callback(array $q): array {
  Auth::admin();$s=$_SESSION['google_state']??null;unset($_SESSION['google_state']);
  if(!$s||$s['expires']<time()||$s['user_id']!=Auth::$user['id']||!hash_equals($s['state'],$q['state']??''))fail('Autorização expirada ou inválida.',400);
  if(isset($q['error']))fail('A autorização do Google foi cancelada.');
  $purpose=$s['purpose']??'calendar';$returnTo=$s['return_to']??($purpose==='drive'?'settings':'agenda');
  $tokens=self::http('https://oauth2.googleapis.com/token','POST',['code'=>required($q,'code'),'client_id'=>config('google_client_id'),'client_secret'=>config('google_client_secret'),'redirect_uri'=>base('google-callback.php'),'grant_type'=>'authorization_code','code_verifier'=>$s['verifier']],null,[],true);
  $info=self::http('https://www.googleapis.com/oauth2/v3/userinfo','GET',null,$tokens['access_token']);
  $old=DB::one('SELECT * FROM google_accounts WHERE user_id=? AND google_sub=?',[Auth::$user['id'],$info['sub']]);
  $refresh=$tokens['refresh_token']??($old&&!empty($old['refresh_token'])?secretDecrypt($old['refresh_token']):'');
  if(!$refresh)fail('O Google não forneceu acesso offline. Revogue a conexão anterior e conecte novamente.');
  $scopeParts=preg_split('/\s+/',trim((string)($tokens['scope']??'')))?:[];if($old&&!empty($old['scopes']))$scopeParts=array_merge($scopeParts,preg_split('/\s+/',trim((string)$old['scopes']))?:[]);$scopes=implode(' ',array_values(array_unique(array_filter($scopeParts))));
  $row=['user_id'=>Auth::$user['id'],'google_sub'=>$info['sub'],'email'=>$info['email'],'access_token'=>secretEncrypt($tokens['access_token']),'refresh_token'=>secretEncrypt($refresh),'scopes'=>$scopes,'expires_at'=>gmdate('Y-m-d H:i:s',time()+(int)$tokens['expires_in']),'status'=>'connected','calendar_enabled'=>(int)(($purpose==='calendar')||($old&&$old['calendar_enabled'])),'drive_enabled'=>(int)(($purpose==='drive')||($old&&$old['drive_enabled'])||str_contains($scopes,self::DRIVE_SCOPE)),'last_error'=>null];
  if($old){$id=(int)$old['id'];DB::update('google_accounts',$id,$row);}else $id=DB::insert('google_accounts',$row);
  if($purpose==='drive'&&$returnTo==='settings'&&(int)setting('drive_account_id','0')===0)DB::run("INSERT INTO settings (`key`,value) VALUES ('drive_account_id',?) ON DUPLICATE KEY UPDATE value=VALUES(value)",[(string)$id]);
  if($purpose==='calendar')self::discover($id);
  return ['account_id'=>$id,'email'=>$info['email'],'purpose'=>$purpose,'return_to'=>$returnTo];
 }

 public static function token(int $id): string {
  $a=DB::one('SELECT * FROM google_accounts WHERE id=?',[$id]);
  if(!$a||$a['status']==='disconnected')throw new RuntimeException('Conta Google desconectada.');
  if(strtotime($a['expires_at'].' UTC')>time()+60)return secretDecrypt($a['access_token']);
  try{$r=self::http('https://oauth2.googleapis.com/token','POST',['client_id'=>config('google_client_id'),'client_secret'=>config('google_client_secret'),'refresh_token'=>secretDecrypt($a['refresh_token']),'grant_type'=>'refresh_token'],null,[],true);$update=['access_token'=>secretEncrypt($r['access_token']),'expires_at'=>gmdate('Y-m-d H:i:s',time()+(int)$r['expires_in']),'status'=>'connected','last_error'=>null];if(!empty($r['scope'])){$parts=array_merge(preg_split('/\s+/',trim((string)$a['scopes']))?:[],preg_split('/\s+/',trim((string)$r['scope']))?:[]);$update['scopes']=implode(' ',array_values(array_unique(array_filter($parts))));}DB::update('google_accounts',$id,$update);return $r['access_token'];}
  catch(Throwable $e){DB::update('google_accounts',$id,['status'=>'reconnect','last_error'=>$e->getMessage()]);throw $e;}
 }

 private static function api(int $account,string $path,string $method='GET',?array $data=null,array $headers=[]): array {return self::http(self::API.$path,$method,$data,self::token($account),$headers);}
 private static function driveApi(int $account,string $path,string $method='GET',?array $data=null,array $headers=[]): array {return self::http(self::DRIVE.$path,$method,$data,self::token($account),$headers);}

 public static function discover(int $account): void {
  $next='';do{$data=self::api($account,'users/me/calendarList?maxResults=250'.($next?'&pageToken='.rawurlencode($next):''));foreach($data['items']??[] as $c){$old=DB::one('SELECT * FROM calendars WHERE account_id=? AND google_id=?',[$account,$c['id']]);$role=$c['accessRole']??'reader';$writable=in_array($role,['owner','writer','writerWithoutPrivateAccess'],true);$row=['name'=>$c['summaryOverride']??$c['summary']??$c['id'],'color'=>$c['backgroundColor']??'#8a8a8a','access_role'=>$role];if($old){if(!$writable)$row['write_enabled']=0;DB::update('calendars',$old['id'],$row);}else DB::insert('calendars',$row+['account_id'=>$account,'google_id'=>$c['id'],'selected'=>1,'write_enabled'=>(int)$writable]);}$next=$data['nextPageToken']??'';}while($next);
 }

 public static function accounts(): array {
  Auth::admin();$rows=DB::all('SELECT id,email,status,last_error,expires_at,scopes,calendar_enabled,drive_enabled FROM google_accounts WHERE user_id=? ORDER BY id',[Auth::$user['id']]);
  foreach($rows as &$r){$r['drive_ready']=$r['status']==='connected'&&(bool)$r['drive_enabled']&&str_contains((string)($r['scopes']??''),self::DRIVE_SCOPE);$r['calendar_ready']=$r['status']==='connected'&&(bool)$r['calendar_enabled']&&str_contains((string)($r['scopes']??''),self::CALENDAR_SCOPE);}
  return $rows;
 }

 public static function disconnect(array $p): array {
  Auth::admin();$a=DB::one('SELECT * FROM google_accounts WHERE id=? AND user_id=?',[integer($p['id'],1),Auth::$user['id']]);if(!$a)fail('Conta não encontrada.');
  DB::update('google_accounts',$a['id'],['status'=>'disconnected','access_token'=>'','refresh_token'=>'']);DB::run('UPDATE calendars SET selected=0,write_enabled=0 WHERE account_id=?',[$a['id']]);
  if((int)setting('drive_account_id','0')===(int)$a['id']){DB::run("UPDATE settings SET value='' WHERE `key` IN ('drive_account_id','drive_root_folder_id')");}
  audit('google_account',$a['id'],'disconnected');return ['saved'=>true];
 }

 public static function driveAccountId(?int $clientId=null): int {
  if($clientId){$client=DB::one('SELECT id,name,drive_account_id FROM clients WHERE id=?',[$clientId]);if(!$client)fail('Cliente não encontrado.',404);$id=(int)($client['drive_account_id']??0);if($id){$a=DB::one("SELECT id,scopes,status,drive_enabled FROM google_accounts WHERE id=?",[$id]);if($a&&$a['status']==='connected'&&(bool)$a['drive_enabled']&&str_contains((string)($a['scopes']??''),self::DRIVE_SCOPE))return $id;fail('O Google Drive configurado para '.$client['name'].' precisa ser reconectado no cadastro do cliente.',409);}}
  $id=(int)setting('drive_account_id','0');
  if($id){$a=DB::one("SELECT id,scopes,status,drive_enabled FROM google_accounts WHERE id=? AND status='connected'",[$id]);if($a&&(bool)$a['drive_enabled']&&str_contains((string)($a['scopes']??''),self::DRIVE_SCOPE))return $id;}
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
  $client=DB::one('SELECT id,name,drive_account_id,drive_folder_id FROM clients WHERE id=?',[(int)$d['client_id']]);if(!$client)fail('Cliente da task não encontrado.',404);$account=self::driveAccountId((int)$client['id']);
  if(!empty($d['drive_folder_id'])&&self::driveFolderValid($account,(string)$d['drive_folder_id']))return ['account_id'=>$account,'folder_id'=>$d['drive_folder_id']];
  if(!empty($d['drive_folder_id']))DB::update('demands',(int)$d['id'],['drive_folder_id'=>null]);
  $clientRoot=(string)($client['drive_folder_id']??'');
  if($clientRoot&&!self::driveFolderValid($account,$clientRoot)){$clientRoot='';DB::update('clients',(int)$client['id'],['drive_folder_id'=>null]);}
  if(!$clientRoot){
   if(!empty($client['drive_account_id'])){$folder=self::createDriveFolder($account,'TaskEsc - '.$client['name']);$clientRoot=$folder['id'];DB::update('clients',(int)$client['id'],['drive_folder_id'=>$clientRoot]);}
   else{$clientRoot=setting('drive_root_folder_id','');if($clientRoot&&!self::driveFolderValid($account,$clientRoot)){$clientRoot='';DB::run("INSERT INTO settings (`key`,value) VALUES ('drive_root_folder_id','') ON DUPLICATE KEY UPDATE value=''");}if(!$clientRoot){$folder=self::createDriveFolder($account,'TaskEsc');$clientRoot=$folder['id'];DB::run("INSERT INTO settings (`key`,value) VALUES ('drive_root_folder_id',?) ON DUPLICATE KEY UPDATE value=VALUES(value)",[$clientRoot]);}}
  }
  $name='Job #'.$d['id'].' - '.($d['title']??'Sem título');$folder=self::createDriveFolder($account,$name,$clientRoot);DB::update('demands',(int)$d['id'],['drive_folder_id'=>$folder['id']]);return ['account_id'=>$account,'folder_id'=>$folder['id']];
 }

 public static function ensureDemandFileFolder(array $d,string $kind='input'): array {
  $root=self::ensureDemandFolder($d);
  if($kind!=='final')return $root;
  $account=(int)$root['account_id'];$parent=(string)$root['folder_id'];$name='Entrega final';
  $q="'".str_replace(["\\","'"],["\\\\","\\'"],$parent)."' in parents and name='".str_replace(["\\","'"],["\\\\","\\'"],$name)."' and mimeType='application/vnd.google-apps.folder' and trashed=false";
  try{
   $found=self::driveApi($account,'files?'.http_build_query(['q'=>$q,'fields'=>'files(id,name,webViewLink)','pageSize'=>10,'spaces'=>'drive','supportsAllDrives'=>'true','includeItemsFromAllDrives'=>'true']));
   foreach($found['files']??[] as $f)if(!empty($f['id'])&&self::driveFolderValid($account,(string)$f['id']))return ['account_id'=>$account,'folder_id'=>$f['id']];
  }catch(Throwable $e){error_log('TaskEsc final folder lookup: '.$e->getMessage());}
  $folder=self::createDriveFolder($account,$name,$parent);
  return ['account_id'=>$account,'folder_id'=>$folder['id']];
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

 public static function streamDriveFile(int $account,string $fileId,string $mime,string $name,bool $inline=false): never {
  $token=self::token($account);$url=self::DRIVE.'files/'.rawurlencode($fileId).'?alt=media';$headers=['Authorization: Bearer '.$token,'Accept: */*'];
  $range=$_SERVER['HTTP_RANGE']??'';if($range&&preg_match('/^bytes=\d*-\d*$/',$range))$headers[]='Range: '.$range;
  header('Content-Type: '.($mime?:'application/octet-stream'));header('Content-Disposition: '.($inline?'inline':'attachment').'; filename*=UTF-8\'\''.rawurlencode($name));header('Accept-Ranges: bytes');header('X-Content-Type-Options: nosniff');
  $ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_HTTPHEADER=>$headers,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>0,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_HEADERFUNCTION=>function($ch,$line){$len=strlen($line);if(preg_match('#^HTTP/\S+\s+(\d{3})#',$line,$m))http_response_code((int)$m[1]);$p=explode(':',$line,2);if(count($p)===2){$k=strtolower(trim($p[0]));$v=trim($p[1]);if(in_array($k,['content-length','content-range','accept-ranges'],true))header(ucwords($k,'-').': '.$v);}return $len;},CURLOPT_WRITEFUNCTION=>function($ch,$data){echo $data;flush();return strlen($data);}]);
  $ok=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$err=curl_error($ch);curl_close($ch);if($ok===false||$code>=400){error_log('Drive stream: '.$err.' HTTP '.$code);if(!headers_sent())http_response_code(502);}exit;
 }

 public static function eventFields(array $e): array {
  $all=!empty($e['start']['date']);$zone=$e['start']['timeZone']??config('timezone');$start=$all?(new DateTimeImmutable($e['start']['date'],new DateTimeZone($zone)))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'):timestamp($e['start']['dateTime']??null);$end=$all?(new DateTimeImmutable($e['end']['date'],new DateTimeZone($zone)))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'):timestamp($e['end']['dateTime']??null);if(!$start||!$end)throw new RuntimeException('Evento Google sem horário.');return ['title'=>mb_substr($e['summary']??'Ocupado',0,255),'starts_at'=>$start,'ends_at'=>$end,'all_day'=>(int)$all,'location'=>$e['location']??'','description'=>$e['description']??'','private'=>(int)(($e['visibility']??'')==='private'),'status'=>($e['status']??'')==='cancelled'?'cancelled':'confirmed','google_etag'=>$e['etag']??null,'recurring_id'=>$e['recurringEventId']??null];
 }

 public static function syncCalendarNow(int $calendarId): array {
  $c=DB::one("SELECT c.* FROM calendars c JOIN google_accounts a ON a.id=c.account_id WHERE c.id=? AND a.status<>'disconnected' AND a.calendar_enabled=1",[$calendarId]);if(!$c)return ['synced'=>false,'message'=>'Agenda local ou conta Google indisponível.'];try{self::push($c);DB::update('calendars',$c['id'],['synced_at'=>now(),'last_error'=>null,'dirty'=>0]);return ['synced'=>true];}catch(Throwable $e){$message=mb_substr($e->getMessage(),0,1000);DB::update('calendars',$c['id'],['last_error'=>$message]);return ['synced'=>false,'message'=>$message];}
 }

 public static function sync(?int $only=null): array {
  $ok=0;$errors=[];$rows=DB::all("SELECT c.* FROM calendars c JOIN google_accounts a ON a.id=c.account_id WHERE c.selected=1 AND a.status<>'disconnected' AND a.calendar_enabled=1".($only?' AND c.id=?':''),$only?[$only]:[]);foreach($rows as $c){try{self::push($c);self::pull($c);self::watch($c);DB::update('calendars',$c['id'],['synced_at'=>now(),'last_error'=>null,'dirty'=>0]);$ok++;}catch(Throwable $e){$message=mb_substr($e->getMessage(),0,1000);DB::update('calendars',$c['id'],['last_error'=>$message]);$errors[]=['calendar_id'=>$c['id'],'message'=>$message];}}return ['synced'=>$ok,'errors'=>$errors,'updated_at'=>now()];
 }

 private static function push(array $c): void {
  if(!$c['write_enabled'])return;$prefix='calendars/'.rawurlencode($c['google_id']).'/events';
  foreach(DB::all("SELECT * FROM events WHERE calendar_id=? AND sync_status IN ('pending','error') ORDER BY id LIMIT 30",[$c['id']]) as $e){try{$path=$prefix.'/'.rawurlencode($e['google_id']);if($e['status']==='cancelled'){try{self::api($c['account_id'],$path,'DELETE',null,$e['google_etag']?['If-Match: '.$e['google_etag']]:[]);}catch(AppError $x){if(!in_array($x->details['google_status']??0,[404,410]))throw $x;}$r=[];}else{$start=$e['all_day']?['date'=>(new DateTimeImmutable($e['starts_at'],new DateTimeZone('UTC')))->setTimezone(new DateTimeZone(config('timezone')))->format('Y-m-d')]:['dateTime'=>str_replace(' ','T',$e['starts_at']).'Z','timeZone'=>config('timezone')];$end=$e['all_day']?['date'=>(new DateTimeImmutable($e['ends_at'],new DateTimeZone('UTC')))->setTimezone(new DateTimeZone(config('timezone')))->format('Y-m-d')]:['dateTime'=>str_replace(' ','T',$e['ends_at']).'Z','timeZone'=>config('timezone')];$body=['summary'=>$e['title'],'start'=>$start,'end'=>$end,'location'=>$e['location'],'description'=>$e['description'],'visibility'=>$e['private']?'private':'default'];if($e['google_etag'])$r=self::api($c['account_id'],$path,'PATCH',$body,['If-Match: '.$e['google_etag']]);else{try{$r=self::api($c['account_id'],$prefix,'POST',$body);}catch(AppError $x){if(($x->details['google_status']??0)!==409)throw $x;$r=self::api($c['account_id'],$path);}}}DB::run("UPDATE events SET google_id=COALESCE(?,google_id),sync_status='synced',sync_error=NULL,google_etag=?,updated_at=? WHERE id=? AND version=?",[$r['id']??null,$r['etag']??$e['google_etag'],now(),$e['id'],$e['version']]);}
   catch(Throwable $x){$code=$x instanceof AppError?($x->details['google_status']??0):0;$conflict=in_array($code,[404,410,412]);$remote=[];if($conflict){try{$remote=self::api($c['account_id'],$prefix.'/'.rawurlencode($e['google_id']));}catch(Throwable){$remote=['status'=>'cancelled'];}}DB::update('events',$e['id'],['sync_status'=>$conflict?'conflict':'error','sync_error'=>mb_substr($x->getMessage(),0,1000),'remote_json'=>json($remote)]);}}
 }

 private static function pages(int $account,string $path,array $params): array {$items=[];$next='';$sync=null;$count=0;do{$q=$params;if($next)$q['pageToken']=$next;$r=self::api($account,$path.'?'.http_build_query($q));array_push($items,...($r['items']??[]));$next=$r['nextPageToken']??'';$sync=$r['nextSyncToken']??$sync;if(++$count>200)throw new RuntimeException('Agenda muito extensa; reduza o histórico sincronizado.');}while($next);return [$items,$sync];}

 private static function pull(array $c): void {
  $account=(int)$c['account_id'];$path='calendars/'.rawurlencode($c['google_id']).'/events';$from=gmdate('Y-m-d\T00:00:00\Z',strtotime('-60 days'));$to=gmdate('Y-m-d\T00:00:00\Z',strtotime('+400 days'));
  if($c['access_role']==='freeBusyReader'){$r=self::api($account,'freeBusy','POST',['timeMin'=>$from,'timeMax'=>$to,'items'=>[['id'=>$c['google_id']]]]);$busy=$r['calendars'][$c['google_id']]??[];if(!empty($busy['errors']))throw new RuntimeException('Sem acesso à disponibilidade dessa agenda.');DB::run("DELETE FROM events WHERE calendar_id=? AND origin='google'",[$c['id']]);foreach($busy['busy']??[] as $b)DB::insert('events',['calendar_id'=>$c['id'],'title'=>'Ocupado','starts_at'=>timestamp($b['start']),'ends_at'=>timestamp($b['end']),'private'=>1,'origin'=>'google','sync_status'=>'synced','created_at'=>now(),'updated_at'=>now()]);return;}
  $params=['maxResults'=>2500,'showDeleted'=>'true','singleEvents'=>'false'];if($c['sync_token'])$params['syncToken']=$c['sync_token'];try{[$changes,$token]=self::pages($account,$path,$params);}catch(AppError $x){if(($x->details['google_status']??0)!==410)throw $x;unset($params['syncToken']);[$changes,$token]=self::pages($account,$path,$params);}foreach($changes as $g)if(($g['status']??'')==='cancelled')DB::run("UPDATE events SET status='cancelled',updated_at=? WHERE calendar_id=? AND (google_id=? OR recurring_id=?) AND sync_status='synced'",[now(),$c['id'],$g['id'],$g['id']]);
  [$events]=self::pages($account,$path,['maxResults'=>2500,'singleEvents'=>'true','timeMin'=>$from,'timeMax'=>$to]);$seen=[];foreach($events as $g){if(($g['status']??'')==='cancelled')continue;$seen[]=$g['id'];$old=DB::one('SELECT * FROM events WHERE calendar_id=? AND google_id=?',[$c['id'],$g['id']]);$fields=self::eventFields($g);if($old){if(in_array($old['sync_status'],['pending','error','conflict']))continue;if(($old['google_etag']??'')!==($g['etag']??''))DB::update('events',$old['id'],$fields+['sync_status'=>'synced','version'=>$old['version']+1,'updated_at'=>now()]);}else DB::insert('events',$fields+['calendar_id'=>$c['id'],'google_id'=>$g['id'],'origin'=>'google','sync_status'=>'synced','created_at'=>now(),'updated_at'=>now()]);}
  $existing=DB::all("SELECT id,google_id FROM events WHERE calendar_id=? AND origin='google' AND sync_status='synced' AND starts_at>=? AND starts_at<?",[$c['id'],timestamp($from),timestamp($to)]);$lookup=array_flip($seen);foreach($existing as $e)if(!isset($lookup[$e['google_id']]))DB::update('events',$e['id'],['status'=>'cancelled']);DB::update('calendars',$c['id'],['sync_token'=>$token]);
 }

 private static function watch(array $c): void {
  if(!str_starts_with(base(),'https://')||$c['access_role']==='freeBusyReader')return;if($c['channel_expires']&&strtotime($c['channel_expires'].' UTC')>time()+3600)return;$id=bin2hex(random_bytes(16));$token=bin2hex(random_bytes(24));
  try{$r=self::api($c['account_id'],'calendars/'.rawurlencode($c['google_id']).'/events/watch','POST',['id'=>$id,'type'=>'web_hook','address'=>base('webhook.php'),'token'=>$token,'params'=>['ttl'=>'604800']]);DB::update('calendars',$c['id'],['channel_id'=>$id,'channel_token'=>$token,'channel_resource'=>$r['resourceId'],'channel_expires'=>gmdate('Y-m-d H:i:s',(int)($r['expiration']/1000))]);}catch(Throwable $e){error_log('Calendar watch: '.$e->getMessage());}
 }
}
