<?php
final class Auth {
 public static ?array $user=null;public static ?array $actor=null;public static ?int $previewClientId=null;public static string $origin='web';public static string $scopes='tools.read tools.write';
 public static function load(bool $required=true): ?array {
  if(self::$user)return self::$user;
  $bearer=$_SERVER['HTTP_AUTHORIZATION']??$_SERVER['REDIRECT_HTTP_AUTHORIZATION']??'';$id=null;
  if(preg_match('/^Bearer\s+(.+)$/i',$bearer,$m)){
   $t=DB::one('SELECT * FROM api_tokens WHERE token_hash=? AND revoked=0 AND expires_at>?',[hash('sha256',$m[1]),now()]);
   if(!$t)fail('Token inválido ou expirado.',401);self::$scopes=(string)$t['scopes'];self::$origin='api';$id=$t['user_id'];
  }else{$id=$_SESSION['user_id']??null;}
  $actor=$id?DB::one('SELECT id,name,email,role,active,email_notifications,weekly_report FROM users WHERE id=? AND active=1',[$id]):null;self::$actor=$actor;
  if($actor&&self::$origin==='web'&&$actor['role']==='admin'&&!empty($_SESSION['preview_client_id'])){
   $cid=(int)$_SESSION['preview_client_id'];$client=DB::one('SELECT id,name FROM clients WHERE id=? AND active=1',[$cid]);
   if($client){self::$previewClientId=$cid;self::$user=$actor;self::$user['actor_role']='admin';self::$user['role']='client';self::$user['is_preview']=1;self::$user['preview_client_id']=$cid;self::$user['preview_client_name']=$client['name'];}
   else unset($_SESSION['preview_client_id']);
  }
  if(!self::$user&&$actor){self::$user=$actor;self::$user['is_preview']=0;}
  if($required&&!self::$user)fail('Entre para continuar.',401);return self::$user;
 }
 public static function isPreview(): bool {self::load(false);return self::$previewClientId!==null;}
 public static function previewStart(array $p): array {self::load();if((self::$actor['role']??'')!=='admin')fail('Acesso restrito ao administrador.',403);$id=integer($p['client_id']??0,1);$c=DB::one('SELECT id,name FROM clients WHERE id=? AND active=1',[$id]);if(!$c)fail('Cliente não encontrado.',404);$_SESSION['preview_client_id']=$id;self::$user=null;self::$previewClientId=null;self::load();return ['saved'=>true,'client'=>$c];}
 public static function previewStop(): array {self::load();if((self::$actor['role']??'')!=='admin')fail('Visualização de cliente não está ativa.',403);unset($_SESSION['preview_client_id']);self::$user=null;self::$previewClientId=null;self::load();return ['saved'=>true];}
 public static function guardPreviewWrite(): void {if(self::isPreview())fail('A visualização de cliente é somente leitura. Saia do modo de visualização para fazer alterações.',403);}
 public static function scope(string $scope): void {if(self::$origin!=='api')return;$all=preg_split('/\s+/',trim(self::$scopes))?:[];$aliases=$scope==='api.read'?['api.read','tools.read']:($scope==='api.write'?['api.write','tools.write']:[$scope]);foreach($aliases as $x)if(in_array($x,$all,true))return;fail('Token sem permissão '.$scope.'.',403);}
 public static function admin(): void {self::load();if(self::$user['role']!=='admin')fail('Acesso restrito ao administrador.',403);}
 public static function staff(): void {self::load();if(self::$user['role']==='client')fail('Ação restrita à equipe.',403);}
 public static function rate(string $name,int $max=15,int $seconds=900): void {$key=hash('sha256',$name.'|'.($_SERVER['REMOTE_ADDR']??'cli'));DB::run('INSERT INTO rate_limits (bucket,attempts,expires_at) VALUES (?,1,?) ON DUPLICATE KEY UPDATE attempts=IF(expires_at<?,1,attempts+1),expires_at=IF(expires_at<?,VALUES(expires_at),expires_at)',[$key,gmdate('Y-m-d H:i:s',time()+$seconds),now(),now()]);$r=DB::one('SELECT attempts FROM rate_limits WHERE bucket=?',[$key]);if((int)$r['attempts']>$max)fail('Muitas tentativas. Tente mais tarde.',429);}
 public static function client(int $id,string $permission='read'): array {$c=DB::one('SELECT * FROM clients WHERE id=?',[$id]);if(!$c)fail('Cliente não encontrado.',404);if(self::isPreview()){if($id!==self::$previewClientId)fail('Sem acesso a este cliente.',403);return $c;}if(self::$user['role']==='admin')return $c;
  if(self::$user['role']==='staff'){$ok=DB::one('SELECT id FROM demands WHERE client_id=? AND assigned_to=? AND deleted_at IS NULL LIMIT 1',[$id,self::$user['id']]);if(!$ok||$permission!=='read')fail('Sem acesso ao cliente.',403);return $c;}
  $link=DB::one('SELECT * FROM user_clients WHERE user_id=? AND client_id=?',[self::$user['id'],$id]);if(!$link||($permission==='finance'&&!$link['can_finance'])||($permission==='approve'&&!$link['can_approve']))fail('Sem acesso a este cliente.',403);return $c;
 }
 public static function demand(int $id,bool $lock=false): array {$d=DB::one('SELECT d.*,s.kind AS stage_kind FROM demands d JOIN stages s ON s.id=d.stage_id WHERE d.id=? AND d.deleted_at IS NULL'.($lock?' FOR UPDATE':''),[$id]);if(!$d)fail('Demanda não encontrada.',404);self::client((int)$d['client_id']);if(self::$user['role']==='staff'&&(int)$d['assigned_to']!==(int)self::$user['id'])fail('Demanda não atribuída a você.',403);return $d;}
 public static function demandFilter(string $alias='d'): array {if(self::isPreview())return ["$alias.client_id=? AND $alias.deleted_at IS NULL",[self::$previewClientId]];if(self::$user['role']==='admin')return ["$alias.deleted_at IS NULL",[]];if(self::$user['role']==='staff')return ["$alias.assigned_to=? AND $alias.deleted_at IS NULL",[self::$user['id']]];return ["$alias.client_id IN (SELECT client_id FROM user_clients WHERE user_id=?) AND $alias.deleted_at IS NULL",[self::$user['id']]];}
 public static function clientFilter(string $column='client_id',bool $finance=false): array {if(self::isPreview())return ["$column=?",[self::$previewClientId]];if(self::$user['role']==='admin')return ['1=1',[]];if(self::$user['role']==='staff')return ["$column IN (SELECT client_id FROM demands WHERE assigned_to=? AND deleted_at IS NULL)",[self::$user['id']]];return ["$column IN (SELECT client_id FROM user_clients WHERE user_id=?".($finance?' AND can_finance=1':'').')',[self::$user['id']]];}
 public static function login(array $p): array {self::rate('login',15);$u=DB::one('SELECT * FROM users WHERE email=? AND active=1',[strtolower(trim($p['email']??''))]);if(!$u||!password_verify($p['password']??'',$u['password_hash']))fail('E-mail ou senha incorretos.',401);session_regenerate_id(true);$_SESSION['user_id']=$u['id'];unset($_SESSION['preview_client_id']);$_SESSION['csrf']=bin2hex(random_bytes(24));unset($u['password_hash']);self::$actor=$u;self::$user=$u;self::$user['is_preview']=0;return ['user'=>self::$user,'csrf'=>csrf()];}
 public static function password(string $p): string {if(strlen($p)<10||strlen($p)>200)fail('A senha deve ter entre 10 e 200 caracteres.');return password_hash($p,PASSWORD_DEFAULT);}
}
