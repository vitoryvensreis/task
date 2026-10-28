<?php
declare(strict_types=1);

/**
 * Stateless password reset: expiry and MAC include the current password hash,
 * so a successful password change invalidates every previously issued link.
 * An admin with 2FA still needs its second factor at next login.
 */
final class PasswordRecovery {
 private static function signature(string $data,string $hash): string {
  return hash_hmac('sha256','taskesc-password-reset|'.$data.'|'.$hash,(string)config('app_key'));
 }
 private static function queue(string $email,string $subject,string $body): void {
  DB::insert('platform_mail_queue',[
   'recipient'=>$email,'subject'=>$subject,'body'=>$body,
   'status'=>'pending','attempts'=>0,'next_attempt'=>now(),'created_at'=>now()
  ]);
 }
 public static function request(string $rawEmail): array {
  Auth::rate('forgot-password',5,3600);
  $email=strtolower(trim($rawEmail));
  if(!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($email)>190)
   fail('Informe um endereço de e-mail válido.',422);
  $generic=['received'=>true,'message'=>'Se esse e-mail estiver cadastrado, você receberá um link de recuperação.'];
  $user=DB::one('SELECT id,email,password_hash FROM users WHERE email=? AND active=1',[$email]);
  if(!$user)return $generic;
  Auth::rate('forgot-password-email-'.hash('sha256',$email),3,3600);
  $data=(int)$user['id'].'.'.(time()+1800).'.'.bin2hex(random_bytes(12));
  $token=$data.'.'.self::signature($data,(string)$user['password_hash']);
  self::queue($email,'TaskEsc — redefinição de senha',
   "Foi solicitada a redefinição da senha da sua conta TaskEsc.\\n\\n".
   "O link é válido por 30 minutos:\\n".
   base('redefinir-senha.php?token='.rawurlencode($token)).
   "\\n\\nSe você não solicitou, ignore esta mensagem. A autenticação de dois fatores continua obrigatória quando ativada.");
  return $generic;
 }
 public static function reset(array $p): array {
  Auth::rate('reset-password',10,900);
  $token=trim((string)($p['token']??''));
  if(!preg_match('/\\A([1-9][0-9]{0,9})\\.([0-9]{10})\\.([a-f0-9]{24})\\.([a-f0-9]{64})\\z/D',$token,$matches))
   fail('Link inválido ou expirado. Solicite uma nova recuperação.',422);
  $id=(int)$matches[1];$expires=(int)$matches[2];
  if($expires<time()||$expires>time()+1800)fail('Link inválido ou expirado. Solicite uma nova recuperação.',422);
  $password=(string)($p['password']??'');
  if($password!==(string)($p['confirm_password']??''))fail('A confirmação não corresponde à nova senha.',422);
  $hash=Auth::password($password);
  DB::tx(static function()use($id,$token,$matches,$hash):void {
   $user=DB::one('SELECT id,email,password_hash FROM users WHERE id=? AND active=1 FOR UPDATE',[$id]);
   if(!$user)fail('Link inválido ou expirado. Solicite uma nova recuperação.',422);
   $data=$matches[1].'.'.$matches[2].'.'.$matches[3];
   if(!hash_equals(self::signature($data,(string)$user['password_hash']),$matches[4]))
    fail('Link já utilizado ou inválido. Solicite uma nova recuperação.',422);
   DB::run('UPDATE users SET password_hash=? WHERE id=?',[$hash,$id]);
   // Revoga sessões antigas e tokens de API, inclusive os emitidos antes
   // desta funcionalidade ser instalada.
   DB::run('INSERT INTO settings (`key`,value) VALUES (?,?)
    ON DUPLICATE KEY UPDATE value=VALUES(value)',
    ['auth.password_reset.'.$id,(string)microtime(true)]);
   DB::run('UPDATE api_tokens SET revoked=1 WHERE user_id=?',[$id]);
   self::queue((string)$user['email'],'TaskEsc — senha alterada',
    "Sua senha do TaskEsc foi alterada. Se você não reconhece esta ação, procure o suporte da plataforma.");
  });
  if(session_status()===PHP_SESSION_ACTIVE){
   unset($_SESSION['user_id'],$_SESSION['pending_2fa_user_id'],$_SESSION['pending_2fa_until']);
   session_regenerate_id(true);
  }
  return ['saved'=>true];
 }
}
