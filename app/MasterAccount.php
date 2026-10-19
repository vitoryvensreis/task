<?php
declare(strict_types=1);
/** Account management for verified platform Masters; no tenant impersonation. */
final class MasterAccount {
 private static function guard(): void {
  Auth::load();
  if(!MasterSupport::isActor()||Auth::$origin!=='web'||MasterSupport::isSupport())
   fail('Acesse sua conta pela administração Master, fora do modo suporte.',403);
 }
 public static function status(): array {
  self::guard();
  $masters=DB::all("SELECT i.email,i.status,i.verified_at,u.id,u.name,u.active,u.twofa_enabled
   FROM platform_master_identities i LEFT JOIN users u ON u.id=i.user_id
   ORDER BY i.verified_at IS NULL,i.email");
  return ['me'=>['id'=>(int)Auth::$actor['id'],'name'=>Auth::$actor['name'],
    'email'=>Auth::$actor['email'],'avatar_url'=>UserAvatars::url((int)Auth::$actor['id']),'twofa_enabled'=>(bool)Auth::$actor['twofa_enabled']],
    'masters'=>$masters];
 }
 public static function profile(array $p): array {
  self::guard();$name=required($p,'name',150);
  DB::run('UPDATE users SET name=? WHERE id=?',[$name,(int)Auth::$actor['id']]);
  audit('user',(int)Auth::$actor['id'],'master.profile.updated');
  return ['saved'=>true];
 }
 public static function password(array $p): array {
  self::guard();return Auth::passwordChange($p);
 }
 public static function twofaSetup(): array {
  self::guard();return Auth::twoFactorSetup();
 }
 public static function twofaEnable(array $p): array {
  self::guard();return Auth::twoFactorEnable($p);
 }
 public static function twofaDisable(array $p): array {
  self::guard();return Auth::twoFactorDisable($p);
 }
 public static function invite(array $p): array {
  self::guard();Auth::rate('master-invite-additional',4,86400);
  $email=strtolower(trim((string)($p['email']??'')));
  if(strlen($email)>190||!filter_var($email,FILTER_VALIDATE_EMAIL))fail('E-mail inválido.',422);
  $name=required($p,'name',150);
  $password=(string)($p['master_password']??'');
  $own=DB::one('SELECT password_hash FROM users WHERE id=?',[(int)Auth::$actor['id']]);
  if(!$own||!password_verify($password,(string)$own['password_hash']))fail('Senha atual do ADM Master incorreta.',403);
  $token=bin2hex(random_bytes(32));
  DB::tx(static function()use($email,$name,$token):void {
   if(DB::one('SELECT 1 FROM platform_master_identities WHERE email=? FOR UPDATE',[$email]))
    fail('Já existe convite ou ADM Master cadastrado para este e-mail.',409);
   if(DB::one('SELECT 1 FROM users WHERE LOWER(email)=?',[$email]))
    fail('Este e-mail já pertence a um usuário. Utilize outro e-mail exclusivo para o Master.',409);
   $id=DB::insert('users',['name'=>$name,'email'=>$email,
    'password_hash'=>password_hash(bin2hex(random_bytes(32)),PASSWORD_DEFAULT),
    'role'=>'admin','active'=>0,'created_at'=>now()]);
   DB::run("INSERT INTO platform_master_identities (email,user_id,status,created_at,updated_at)
    VALUES (?,?,'pending_verification',?,?)",[$email,$id,now(),now()]);
   DB::insert('reset_tokens',['token_hash'=>hash('sha256',$token),
     'user_id'=>$id,'expires_at'=>gmdate('Y-m-d H:i:s',time()+1800)]);
   audit('user',$id,'master.invited',null,['email'=>$email]);
  });
  Notify::mail($email,'Convite ADM Master — TaskEsc',
   "Você foi convidado para administrar a plataforma TaskEsc.\n".
   "Defina sua senha usando o link individual (válido por 30 minutos):\n".
   base('master?activate='.$token)."\n\nSe não solicitou, ignore.");
  return ['sent'=>true,'email'=>$email,'expires_in_minutes'=>30];
 }
}
