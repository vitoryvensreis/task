<?php
final class Auth {
 public static ?array $user=null;public static ?array $actor=null;public static ?array $workspace=null;public static ?int $previewClientId=null;public static string $origin='web';public static string $scopes='tools.read tools.write';
 public static function load(bool $required=true): ?array {
  if(self::$user)return self::$user;
  $bearer=$_SERVER['HTTP_AUTHORIZATION']??$_SERVER['REDIRECT_HTTP_AUTHORIZATION']??'';$id=null;
  if(preg_match('/^Bearer\s+(.+)$/i',$bearer,$m)){
   $t=DB::one('SELECT * FROM api_tokens WHERE token_hash=? AND revoked=0 AND expires_at>?',[hash('sha256',$m[1]),now()]);
   if(!$t)fail('Token inválido ou expirado.',401);self::$scopes=(string)$t['scopes'];self::$origin='api';$id=$t['user_id'];
  }else{$id=$_SESSION['user_id']??null;}
  $actor=$id?DB::one('SELECT id,name,email,role,active,email_notifications,weekly_report,twofa_enabled,last_login_at FROM users WHERE id=? AND active=1',[$id]):null;self::$actor=$actor;
  self::$workspace=$actor?WorkspaceContext::forUser($actor):null;
  if($actor&&self::$origin==='web'&&$actor['role']==='admin'&&!empty($_SESSION['preview_client_id'])){
   $cid=(int)$_SESSION['preview_client_id'];$client=DB::one('SELECT id,name FROM clients WHERE id=? AND active=1 AND workspace_id=?',[$cid,self::$workspace['id']]);
   if($client){self::$previewClientId=$cid;self::$user=$actor;self::$user['actor_role']='admin';self::$user['role']='client';self::$user['is_preview']=1;self::$user['preview_client_id']=$cid;self::$user['preview_client_name']=$client['name'];}
   else unset($_SESSION['preview_client_id']);
  }
  if(!self::$user&&$actor){self::$user=$actor;self::$user['is_preview']=0;}
  if(self::$user&&self::$workspace){self::$user['workspace_id']=self::$workspace['id'];self::$user['workspace_role']=self::$workspace['role'];}
  if($required&&!self::$user)fail('Entre para continuar.',401);return self::$user;
 }
 public static function workspaceId(): int {self::load();if(!self::$workspace)fail('Workspace indisponível.',403);return (int)self::$workspace['id'];}
 public static function workspace(): array {self::load();if(!self::$workspace)fail('Workspace indisponível.',403);return self::$workspace;}
 public static function isPreview(): bool {self::load(false);return self::$previewClientId!==null;}
 public static function previewStart(array $p): array {self::load();if((self::$actor['role']??'')!=='admin')fail('Acesso restrito ao administrador.',403);$id=integer($p['client_id']??0,1);$c=DB::one('SELECT id,name FROM clients WHERE id=? AND active=1 AND workspace_id=?',[$id,self::workspaceId()]);if(!$c)fail('Cliente não encontrado.',404);$_SESSION['preview_client_id']=$id;self::$user=null;self::$previewClientId=null;self::load();return ['saved'=>true,'client'=>$c];}
 public static function previewStop(): array {self::load();if((self::$actor['role']??'')!=='admin')fail('Visualização de cliente não está ativa.',403);unset($_SESSION['preview_client_id']);self::$user=null;self::$previewClientId=null;self::load();return ['saved'=>true];}
 public static function guardPreviewWrite(): void {if(self::isPreview())fail('A visualização de cliente é somente leitura. Saia do modo de visualização para fazer alterações.',403);}
 public static function scope(string $scope): void {if(self::$origin!=='api')return;$all=preg_split('/\s+/',trim(self::$scopes))?:[];$aliases=$scope==='api.read'?['api.read','tools.read']:($scope==='api.write'?['api.write','tools.write']:[$scope]);foreach($aliases as $x)if(in_array($x,$all,true))return;fail('Token sem permissão '.$scope.'.',403);}
 public static function admin(): void {self::load();if(self::$user['role']!=='admin')fail('Acesso restrito ao administrador.',403);}
 public static function staff(): void {self::load();if(self::$user['role']==='client')fail('Ação restrita à equipe.',403);}
 public static function rate(string $name,int $max=15,int $seconds=900): void {$key=hash('sha256',$name.'|'.($_SERVER['REMOTE_ADDR']??'cli'));DB::run('INSERT INTO rate_limits (bucket,attempts,expires_at) VALUES (?,1,?) ON DUPLICATE KEY UPDATE attempts=IF(expires_at<?,1,attempts+1),expires_at=IF(expires_at<?,VALUES(expires_at),expires_at)',[$key,gmdate('Y-m-d H:i:s',time()+$seconds),now(),now()]);$r=DB::one('SELECT attempts FROM rate_limits WHERE bucket=?',[$key]);if((int)$r['attempts']>$max)fail('Muitas tentativas. Tente mais tarde.',429);}
 public static function client(int $id,string $permission='read'): array {self::load();$c=DB::one('SELECT * FROM clients WHERE id=? AND workspace_id=?',[$id,self::workspaceId()]);if(!$c)fail('Cliente não encontrado.',404);if(self::isPreview()){if($id!==self::$previewClientId)fail('Sem acesso a este cliente.',403);return $c;}if(self::$user['role']==='admin')return $c;
  if(self::$user['role']==='staff'){$link=DB::one('SELECT client_id FROM user_clients WHERE user_id=? AND client_id=?',[self::$user['id'],$id]);if(!$link||$permission!=='read')fail('Sem acesso ao cliente.',403);return $c;}
  $link=DB::one('SELECT * FROM user_clients WHERE user_id=? AND client_id=?',[self::$user['id'],$id]);if(!$link||($permission==='finance'&&!$link['can_finance'])||($permission==='approve'&&!$link['can_approve']))fail('Sem acesso a este cliente.',403);return $c;
 }
 public static function demand(int $id,bool $lock=false): array {self::load();$d=DB::one('SELECT d.*,s.kind AS stage_kind FROM demands d JOIN stages s ON s.id=d.stage_id AND s.workspace_id=d.workspace_id WHERE d.id=? AND d.workspace_id=? AND d.deleted_at IS NULL'.($lock?' FOR UPDATE':''),[$id,self::workspaceId()]);if(!$d)fail('Demanda não encontrada.',404);self::client((int)$d['client_id']);return $d;}
 public static function demandFilter(string $alias='d'): array {
  self::load();$ws=self::workspaceId();
  // Only call with known SQL aliases (never user-controlled strings).
  if(!preg_match('/^[a-zA-Z][a-zA-Z0-9_]*$/D',$alias))throw new InvalidArgumentException('Invalid demand alias.');
  $base="$alias.workspace_id=? AND $alias.deleted_at IS NULL";
  if(self::isPreview())return [$base." AND $alias.client_id=?",[$ws,self::$previewClientId]];
  if(self::$user['role']==='admin')return [$base,[$ws]];
  return [$base." AND $alias.client_id IN (SELECT client_id FROM user_clients WHERE user_id=? AND workspace_id=?)",[$ws,(int)self::$user['id'],$ws]];
 }
 public static function clientFilter(string $column='client_id',bool $finance=false): array {
  self::load();$ws=self::workspaceId();
  if(!preg_match('/^[a-zA-Z][a-zA-Z0-9_]*(?:\\.[a-zA-Z][a-zA-Z0-9_]*)?$/D',$column))
   throw new InvalidArgumentException('Invalid client column.');
  if(self::isPreview())return ["$column IN (SELECT id FROM clients WHERE workspace_id=? AND id=?)",[$ws,self::$previewClientId]];
  if(self::$user['role']==='admin')return ["$column IN (SELECT id FROM clients WHERE workspace_id=?)",[$ws]];
  return ["$column IN (SELECT uc.client_id FROM user_clients uc JOIN clients c ON c.id=uc.client_id AND c.workspace_id=uc.workspace_id WHERE uc.workspace_id=? AND uc.user_id=?".($finance?' AND uc.can_finance=1':'').")",[$ws,(int)self::$user['id']]];
 }
 public static function abilities(): array {
  self::load();$role=(string)self::$user['role'];
  $map=[
   'admin'=>['chat.use','dashboard.view','tasks.read','tasks.create','tasks.edit','tasks.move','tasks.delete','tasks.comment','tasks.files','agenda.read','agenda.write','clients.manage','services.manage','finance.read','finance.manage','reports.read','settings.manage','logs.read','users.manage'],
   'staff'=>['dashboard.view','tasks.read','tasks.edit','tasks.move','tasks.delete','tasks.comment','tasks.files','agenda.read','agenda.write','reports.read'],
   'client'=>['dashboard.view','tasks.read','tasks.create','tasks.edit','tasks.move','tasks.comment','tasks.files','agenda.read']
  ];
  return $map[$role]??[];
 }
 public static function can(string $ability): bool {return in_array($ability,self::abilities(),true);}
 public static function requireAbility(string $ability): void {if(!self::can($ability))fail('Você não possui permissão para esta ação.',403);}

 private static function finalizeLogin(array $u): array {
  $workspace=WorkspaceContext::forUser($u);
  session_regenerate_id(true);$_SESSION['user_id']=$u['id'];unset($_SESSION['preview_client_id'],$_SESSION['pending_2fa_user_id'],$_SESSION['pending_2fa_until'],$_SESSION['workspace_id'],$_SESSION['workspace_support_id']);$_SESSION['csrf']=bin2hex(random_bytes(24));
  DB::update('users',(int)$u['id'],['last_login_at'=>now(),'last_login_ip'=>mb_substr((string)($_SERVER['REMOTE_ADDR']??''),0,45)]);
  unset($u['password_hash'],$u['twofa_secret'],$u['twofa_recovery_json']);self::$actor=$u;self::$workspace=$workspace;self::$user=$u;self::$user['is_preview']=0;self::$user['workspace_id']=$workspace['id'];self::$user['workspace_role']=$workspace['role'];audit('user',(int)$u['id'],'login',null,['ip'=>$_SERVER['REMOTE_ADDR']??'']);
  return ['user'=>self::$user,'csrf'=>csrf()];
 }
 public static function login(array $p): array {
  self::rate('login',15);$u=DB::one('SELECT * FROM users WHERE email=? AND active=1',[strtolower(trim($p['email']??''))]);if(!$u||!password_verify($p['password']??'',$u['password_hash']))fail('E-mail ou senha incorretos.',401);
  WorkspaceContext::forUser($u);
  if($u['role']==='admin'&&!empty($u['twofa_enabled'])){
   session_regenerate_id(true);$_SESSION['pending_2fa_user_id']=(int)$u['id'];$_SESSION['pending_2fa_until']=time()+300;unset($_SESSION['user_id'],$_SESSION['preview_client_id'],$_SESSION['workspace_id'],$_SESSION['workspace_support_id']);return ['twofa_required'=>true,'email'=>$u['email']];
  }
  return self::finalizeLogin($u);
 }
 public static function twoFactorVerify(array $p): array {
  self::rate('twofa',12,600);$id=(int)($_SESSION['pending_2fa_user_id']??0);$until=(int)($_SESSION['pending_2fa_until']??0);if(!$id||$until<time())fail('A verificação em duas etapas expirou. Entre novamente.',401);
  $u=DB::one('SELECT * FROM users WHERE id=? AND active=1',[$id]);if(!$u||empty($u['twofa_enabled']))fail('Verificação em duas etapas indisponível.',401);
  $code=trim((string)($p['code']??''));if(!self::twoFactorAccept($u,$code))fail('Código de verificação inválido.',401);
  return self::finalizeLogin($u);
 }
 private static function base32Encode(string $data): string {
  $alphabet='ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';$bits='';foreach(str_split($data) as $ch)$bits.=str_pad(decbin(ord($ch)),8,'0',STR_PAD_LEFT);$out='';foreach(str_split($bits,5) as $chunk){$chunk=str_pad($chunk,5,'0',STR_PAD_RIGHT);$out.=$alphabet[bindec($chunk)];}return $out;
 }
 private static function base32Decode(string $data): string {
  $alphabet='ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';$data=strtoupper(preg_replace('/[^A-Z2-7]/i','',$data)??'');$bits='';foreach(str_split($data) as $ch){$p=strpos($alphabet,$ch);if($p===false)continue;$bits.=str_pad(decbin($p),5,'0',STR_PAD_LEFT);}$out='';foreach(str_split($bits,8) as $chunk)if(strlen($chunk)===8)$out.=chr(bindec($chunk));return $out;
 }
 private static function totp(string $secret,int $counter): string {
  $key=self::base32Decode($secret);$bin=pack('N*',0).pack('N*',$counter);$hash=hash_hmac('sha1',$bin,$key,true);$offset=ord($hash[19])&0xf;$num=((ord($hash[$offset])&0x7f)<<24)|((ord($hash[$offset+1])&0xff)<<16)|((ord($hash[$offset+2])&0xff)<<8)|(ord($hash[$offset+3])&0xff);return str_pad((string)($num%1000000),6,'0',STR_PAD_LEFT);
 }
 private static function verifyTotp(string $secret,string $code): bool {
  if(!preg_match('/^\d{6}$/',$code))return false;$counter=intdiv(time(),30);for($i=-1;$i<=1;$i++)if(hash_equals(self::totp($secret,$counter+$i),$code))return true;return false;
 }
 private static function twoFactorSecret(array $u): string {try{return !empty($u['twofa_secret'])?secretDecrypt((string)$u['twofa_secret']):'';}catch(Throwable){return '';}}
 private static function twoFactorAccept(array $u,string $code): bool {
  $secret=self::twoFactorSecret($u);if($secret!==''&&self::verifyTotp($secret,$code))return true;
  $normalized=strtoupper(preg_replace('/[^A-Z0-9]/','',$code)??'');if(strlen($normalized)<8)return false;$codes=decode($u['twofa_recovery_json']??'',[]);
  foreach($codes as $i=>$hash)if(hash_equals((string)$hash,hash('sha256',$normalized))){unset($codes[$i]);DB::update('users',(int)$u['id'],['twofa_recovery_json'=>json(array_values($codes))]);return true;}return false;
 }
 public static function twoFactorStatus(): array {self::load();return ['enabled'=>(bool)(self::$user['twofa_enabled']??0),'admin'=>self::$user['role']==='admin'];}
 public static function twoFactorSetup(): array {
  self::admin();$secret=self::base32Encode(random_bytes(20));DB::update('users',(int)self::$user['id'],['twofa_secret'=>secretEncrypt($secret),'twofa_enabled'=>0,'twofa_recovery_json'=>null]);$issuer=rawurlencode(trim(setting('brand_name','TaskEsc'))?:'TaskEsc');$label=rawurlencode((string)self::$user['email']);$uri='otpauth://totp/'.$issuer.':'.$label.'?secret='.$secret.'&issuer='.$issuer.'&digits=6&period=30';return ['secret'=>$secret,'otpauth_uri'=>$uri];
 }
 public static function twoFactorEnable(array $p): array {
  self::admin();$u=DB::one('SELECT * FROM users WHERE id=?',[self::$user['id']]);$secret=self::twoFactorSecret($u);if($secret===''||!self::verifyTotp($secret,trim((string)($p['code']??''))))fail('Código do autenticador inválido.');
  $plain=[];$hashed=[];for($i=0;$i<8;$i++){$raw=strtoupper(bin2hex(random_bytes(4)));$code=substr($raw,0,4).'-'.substr($raw,4,4);$plain[]=$code;$hashed[]=hash('sha256',str_replace('-','',$code));}
  DB::update('users',(int)$u['id'],['twofa_enabled'=>1,'twofa_recovery_json'=>json($hashed)]);self::$user['twofa_enabled']=1;audit('user',(int)$u['id'],'2fa.enabled');return ['saved'=>true,'recovery_codes'=>$plain];
 }
 public static function twoFactorDisable(array $p): array {
  self::admin();$u=DB::one('SELECT * FROM users WHERE id=?',[self::$user['id']]);$password=(string)($p['current_password']??'');if($password===''||!password_verify($password,(string)$u['password_hash']))fail('Senha atual incorreta.');
  $code=trim((string)($p['code']??''));if(!self::twoFactorAccept($u,$code))fail('Código de verificação inválido.');
  DB::update('users',(int)$u['id'],['twofa_enabled'=>0,'twofa_secret'=>null,'twofa_recovery_json'=>null]);self::$user['twofa_enabled']=0;audit('user',(int)$u['id'],'2fa.disabled');return ['saved'=>true];
 }
 public static function profile(): array {
  self::load();$u=DB::one('SELECT id,name,email,role,email_notifications,twofa_enabled,last_login_at FROM users WHERE id=? AND active=1',[self::$user['id']]);if(!$u)fail('Usuário não encontrado.',404);return $u;
 }
 public static function profileSave(array $p): array {
  self::load();$u=DB::one('SELECT * FROM users WHERE id=? AND active=1',[self::$user['id']]);if(!$u)fail('Usuário não encontrado.',404);
  $name=required($p,'name',150);$email=strtolower(required($p,'email',190));if(!filter_var($email,FILTER_VALIDATE_EMAIL))fail('E-mail inválido.');
  if($email!==strtolower((string)$u['email'])){$current=(string)($p['current_password']??'');if($current===''||!password_verify($current,(string)$u['password_hash']))fail('Informe sua senha atual para alterar o e-mail.',422);if(DB::one('SELECT id FROM users WHERE email=? AND id<>?',[$email,$u['id']]))fail('Este e-mail já está em uso.',409);}
  $row=['name'=>$name,'email'=>$email,'email_notifications'=>(int)(bool)($p['email_notifications']??0)];DB::update('users',(int)$u['id'],$row);audit('user',(int)$u['id'],'profile.update',['name'=>$u['name'],'email'=>$u['email'],'email_notifications'=>$u['email_notifications']],$row);self::$user=array_merge(self::$user,$row);return ['saved'=>true,'user'=>self::$user];
 }
 public static function passwordChange(array $p): array {
  self::load();$u=DB::one('SELECT * FROM users WHERE id=? AND active=1',[self::$user['id']]);if(!$u)fail('Usuário não encontrado.',404);
  $current=(string)($p['current_password']??'');if($current===''||!password_verify($current,(string)$u['password_hash']))fail('Senha atual incorreta.',422);
  $new=(string)($p['new_password']??'');$confirm=(string)($p['confirm_password']??'');if($new!==$confirm)fail('A confirmação da nova senha não confere.',422);if(password_verify($new,(string)$u['password_hash']))fail('Escolha uma senha diferente da atual.',422);
  $hash=self::password($new);DB::update('users',(int)$u['id'],['password_hash'=>$hash]);audit('user',(int)$u['id'],'password.change');if(session_status()===PHP_SESSION_ACTIVE)session_regenerate_id(true);return ['saved'=>true];
 }
 public static function password(string $p): string {if(strlen($p)<10||strlen($p)>200)fail('A senha deve ter entre 10 e 200 caracteres.');return password_hash($p,PASSWORD_DEFAULT);}
}
