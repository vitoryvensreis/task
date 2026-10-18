<?php
declare(strict_types=1);

/**
 * Platform Master: explicit email activation and short-lived audited support.
 * Does not authorize a new tenant. Master has no tenant context by default.
 */
final class MasterSupport {
 private const SESSION_SECONDS=7200;
 private static function singleWorkspace(): array {
  $count=(int)(DB::one('SELECT COUNT(*) n FROM workspaces')['n']??0);
  $w=DB::one('SELECT w.id,w.name,w.slug,w.owner_user_id,w.status,u.active AS owner_active,u.role AS owner_role,u.email AS owner_email
    FROM workspaces w JOIN users u ON u.id=w.owner_user_id WHERE w.slug=?',
    [WorkspaceFoundation::SLUG]);
  if($count!==1||!$w||$w['status']!==WorkspaceFoundation::STATUS||
     (int)$w['owner_active']!==1||$w['owner_role']!=='admin'||strtolower((string)$w['owner_email'])!==WorkspaceFoundation::OWNER_EMAIL)
   fail('Modo suporte indisponível: workspace único não verificado.',503);
  return $w;
 }
 public static function isVerified(array $user): bool {
  if(strtolower((string)($user['email']??''))!==WorkspaceFoundation::MASTER_EMAIL||
     ($user['role']??'')!=='admin'||(int)($user['active']??0)!==1)return false;
  return (bool)DB::one("SELECT 1 FROM platform_master_identities
    WHERE email=? AND user_id=? AND status='verified' LIMIT 1",
    [WorkspaceFoundation::MASTER_EMAIL,(int)$user['id']]);
 }
 public static function contextFor(array $user): array {
  if(!self::isVerified($user))fail('Identidade do ADM Master ainda não verificada.',403);
  $w=self::singleWorkspace();
  $sessionId=(int)($_SESSION['workspace_support_id']??0);
  if(Auth::$origin!=='web'||$sessionId<1)return ['id'=>0,'name'=>'Plataforma TaskEsc','slug'=>'platform','role'=>'master'];
  $session=DB::one('SELECT s.id,s.workspace_id,s.master_user_id,s.expires_at,s.ended_at
    FROM workspace_support_sessions s WHERE s.id=? AND s.master_user_id=? AND s.workspace_id=?',
    [$sessionId,(int)$user['id'],(int)$w['id']]);
  if(!$session||$session['ended_at']!==null||$session['expires_at']<=now()){
   unset($_SESSION['workspace_support_id']);
   return ['id'=>0,'name'=>'Plataforma TaskEsc','slug'=>'platform','role'=>'master'];
  }
  return ['id'=>(int)$w['id'],'name'=>(string)$w['name'],
    'slug'=>(string)$w['slug'],'role'=>'owner',
    'support_session_id'=>$sessionId,'owner_user_id'=>(int)$w['owner_user_id']];
 }
 public static function isActor(): bool {
  return Auth::$origin==='web'&&Auth::$actor!==null&&self::isVerified(Auth::$actor);
 }
 public static function isSupport(): bool {
  return self::isActor()&&(bool)(Auth::$workspace['support_session_id']??0);
 }
 public static function requireCalendar(): void {
  if(PHP_SAPI==='cli'&&getenv('GITHUB_ACTIONS')==='true'&&((WorkspaceContext::legacyCiFixture()&&defined('TASKESC_REALTIME_DOMAIN_FIXTURE'))||defined('TASKESC_LEGACY_AI_CALENDAR_FIXTURE')))return;
  Auth::load();
  if(!self::isSupport())fail('A agenda é exclusiva do ADM Master em modo suporte.',403);
 }
 public static function workspaceList(): array {
  Auth::load();if(!self::isActor())fail('Área exclusiva do ADM Master.',403);
  $w=self::singleWorkspace();
  return ['master'=>(string)Auth::$actor['email'],
    'support_active'=>self::isSupport(),
    'support_expires_at'=>self::isSupport()
     ?(DB::one('SELECT expires_at FROM workspace_support_sessions WHERE id=?',
        [(int)Auth::$workspace['support_session_id']])['expires_at']??null):null,
    'workspaces'=>[['id'=>(int)$w['id'],'name'=>(string)$w['name'],
      'slug'=>(string)$w['slug'],'status'=>'staged']],
    'multiworkspace_enabled'=>false];
 }
 public static function start(array $input): array {
  Auth::load();
  if(!self::isActor())fail('Área exclusiva do ADM Master.',403);
  $workspaceId=integer($input['workspace_id']??0,1);
  $reason=required($input,'reason',190);
  if(mb_strlen($reason)<8)fail('Informe o motivo do suporte (mínimo 8 caracteres).',422);
  $w=self::singleWorkspace();
  if($workspaceId!==(int)$w['id'])fail('Workspace indisponível.',404);
  $masterId=(int)Auth::$actor['id'];
  $expires=gmdate('Y-m-d H:i:s',time()+self::SESSION_SECONDS);
  $id=DB::tx(static function()use($workspaceId,$masterId,$reason,$expires):int {
   DB::run('UPDATE workspace_support_sessions SET ended_at=? WHERE master_user_id=? AND ended_at IS NULL',[now(),$masterId]);
   $id=DB::insert('workspace_support_sessions',[
    'workspace_id'=>$workspaceId,'master_user_id'=>$masterId,'reason'=>$reason,
    'started_at'=>now(),'expires_at'=>$expires]);
   DB::insert('audit_log',['workspace_id'=>$workspaceId,'user_id'=>$masterId,
     'entity'=>'workspace_support','entity_id'=>$id,'action'=>'started',
     'before_json'=>null,'after_json'=>json(['reason'=>$reason,'expires_at'=>$expires]),
     'origin'=>'web','created_at'=>now()]);
   return $id;
  });
  session_regenerate_id(true);
  unset($_SESSION['preview_client_id']);$_SESSION['workspace_support_id']=$id;
  $_SESSION['csrf']=bin2hex(random_bytes(24));
  Auth::$user=null;Auth::$workspace=null;Auth::$previewClientId=null;
  return ['started'=>true,'workspace_id'=>$workspaceId,'expires_at'=>$expires,'url'=>base('index.php')];
 }
 public static function stop(): array {
  Auth::load();if(!self::isActor())fail('Área exclusiva do ADM Master.',403);
  $id=(int)($_SESSION['workspace_support_id']??0);
  $masterId=(int)Auth::$actor['id'];
  if($id>0)DB::tx(static function()use($id,$masterId):void {
   $record=DB::one('SELECT id,workspace_id,ended_at FROM workspace_support_sessions WHERE id=? AND master_user_id=? FOR UPDATE',
     [$id,$masterId]);
   if($record&&$record['ended_at']===null){
    DB::run('UPDATE workspace_support_sessions SET ended_at=? WHERE id=?',[now(),$id]);
    DB::insert('audit_log',['workspace_id'=>(int)$record['workspace_id'],
      'user_id'=>$masterId,'entity'=>'workspace_support','entity_id'=>$id,
      'action'=>'ended','before_json'=>null,'after_json'=>null,'origin'=>'web','created_at'=>now()]);
   }
  });
  unset($_SESSION['workspace_support_id'],$_SESSION['preview_client_id']);
  session_regenerate_id(true);$_SESSION['csrf']=bin2hex(random_bytes(24));
  Auth::$user=null;Auth::$workspace=null;Auth::$previewClientId=null;
  return ['stopped'=>true,'url'=>base('master.php')];
 }
 public static function invite(): array {
  Auth::load();Auth::admin();
  if(Auth::$origin!=='web'||self::isSupport()||
     (string)(Auth::$workspace['role']??'')!=='owner'||
     strtolower((string)Auth::$actor['email'])!==WorkspaceFoundation::OWNER_EMAIL)
   fail('Somente o proprietário original pode convidar o ADM Master.',403);
  self::singleWorkspace();
  Auth::rate('master-invite',3,86400);
  $token=bin2hex(random_bytes(32));
  $email=WorkspaceFoundation::MASTER_EMAIL;
  DB::tx(static function()use($token,$email):void {
   $identity=DB::one('SELECT * FROM platform_master_identities WHERE email=? FOR UPDATE',[$email]);
   if(!$identity||$identity['status']==='verified')fail('A identidade Master não está pendente.',409);
   $user=$identity['user_id']?DB::one('SELECT * FROM users WHERE id=? FOR UPDATE',[(int)$identity['user_id']]):DB::one('SELECT * FROM users WHERE LOWER(email)=? FOR UPDATE',[$email]);
   if($user&&($user['role']!=='admin'||(int)$user['active']!==0))fail('Conta Master existente exige verificação administrativa.',409);
   $id=$user?(int)$user['id']:DB::insert('users',[
    'name'=>'ADM Master TaskEsc','email'=>$email,
    'password_hash'=>password_hash(bin2hex(random_bytes(32)),PASSWORD_DEFAULT),
    'role'=>'admin','active'=>0,'created_at'=>now()]);
   if(DB::one('SELECT 1 FROM workspace_members WHERE user_id=? LIMIT 1',[$id]))
    fail('ADM Master não pode pertencer ao workspace.',409);
   DB::run("UPDATE platform_master_identities SET user_id=?,status='pending_verification',verified_at=NULL,updated_at=? WHERE email=?",
    [$id,now(),$email]);
   DB::run('DELETE FROM reset_tokens WHERE user_id=?',[$id]);
   DB::insert('reset_tokens',['token_hash'=>hash('sha256',$token),'user_id'=>$id,
     'expires_at'=>gmdate('Y-m-d H:i:s',time()+1800)]);
  });
  Notify::mail($email,'Ativação do ADM Master — TaskEsc',
   "Você recebeu um convite para ativar o ADM Master da plataforma TaskEsc.\n".
   "Defina sua senha pelo link de uso único (válido por 30 minutos):\n".
   base('master.php?activate='.$token)."\n\nSe não solicitou, ignore esta mensagem.");
  return ['sent'=>true,'email'=>$email,'expires_in_minutes'=>30];
 }
 public static function activate(array $input): array {
  Auth::rate('master-activation',10,3600);
  $raw=trim((string)($input['token']??''));
  if(!preg_match('/\A[a-f0-9]{64}\z/D',$raw))fail('Convite inválido ou expirado.',400);
  $password=(string)($input['password']??'');
  if(!hash_equals($password,(string)($input['confirm_password']??'')))fail('As senhas não conferem.',422);
  $hash=Auth::password($password);
  return DB::tx(static function()use($raw,$hash):array {
   $token=DB::one('SELECT t.token_hash,t.user_id,t.expires_at,u.email,u.role,u.active
     FROM reset_tokens t JOIN users u ON u.id=t.user_id WHERE t.token_hash=? FOR UPDATE',
     [hash('sha256',$raw)]);
   if(!$token||$token['expires_at']<=now()||
      strtolower((string)$token['email'])!==WorkspaceFoundation::MASTER_EMAIL||
      $token['role']!=='admin'||(int)$token['active']!==0)
    fail('Convite inválido ou expirado.',403);
   $identity=DB::one('SELECT user_id,status FROM platform_master_identities WHERE email=? FOR UPDATE',
     [WorkspaceFoundation::MASTER_EMAIL]);
   if(!$identity||$identity['status']!=='pending_verification'||
       (int)$identity['user_id']!==(int)$token['user_id'])fail('Convite já utilizado.',403);
   if(DB::one('SELECT 1 FROM workspace_members WHERE user_id=?',[(int)$token['user_id']]))
    fail('ADM Master não pode ser membro de empresa.',409);
   DB::update('users',(int)$token['user_id'],['password_hash'=>$hash,'active'=>1]);
   DB::run("UPDATE platform_master_identities SET status='verified',verified_at=?,updated_at=? WHERE email=?",
     [now(),now(),WorkspaceFoundation::MASTER_EMAIL]);
   DB::run('DELETE FROM reset_tokens WHERE user_id=?',[(int)$token['user_id']]);
   return ['activated'=>true,'login_url'=>base('index.php')];
  });
 }
}
