<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow, noarchive');
$error='';$success=false;
$token=(string)($_POST['token']??$_GET['token']??'');
try{
 installed();
 if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
  checkCsrf();
  PasswordRecovery::reset($_POST);
  $success=true;$token='';
 }
}catch(Throwable $e){$error=$e instanceof AppError?$e->getMessage():'Não foi possível redefinir a senha.';}
$valid=(bool)preg_match('/\\A[0-9]{1,10}\\.[0-9]{10}\\.[a-f0-9]{24}\\.[a-f0-9]{64}\\z/D',$token);
?>
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Definir nova senha · TaskEsc</title>
<style>
:root{color-scheme:dark}*{box-sizing:border-box}body{margin:0;min-height:100dvh;background:#080d12;color:#e8eef5;font:14px/1.6 system-ui,Arial,sans-serif;display:grid;place-items:center;padding:20px}
main{width:min(450px,100%);background:#111a24;border:1px solid #344250;border-radius:12px;padding:30px}h1{font-size:24px;margin:0 0 8px}p{color:#b5c3d0}label{display:grid;gap:8px;margin:18px 0;color:#d2dfed}input{width:100%;padding:13px;border-radius:8px;background:#0b121a;color:#fff;border:1px solid #45566b;font:inherit}
button,.link{display:block;width:100%;text-align:center;padding:12px;background:#345f82;color:#fff;border:0;border-radius:8px;font:inherit;font-weight:700;text-decoration:none;cursor:pointer;margin-top:18px}.link{background:transparent;color:#aed1ed}
.notice{background:#392721;color:#ffd6c5;padding:12px;border-radius:8px}.success{background:#163a32;color:#ddfff3}
</style></head><body><main>
<h1>Definir nova senha</h1>
<?php if($success): ?><p class="notice success">Senha atualizada. Entre novamente no TaskEsc. Se sua conta usa 2FA, o código continua obrigatório.</p>
<?php elseif(!$valid): ?><p class="notice">Link inválido ou expirado. Solicite uma nova recuperação.</p>
<?php else: ?>
<p>Escolha uma senha com pelo menos 10 caracteres.</p>
<?php if($error!==''): ?><p class="notice"><?=e($error)?></p><?php endif; ?>
<form method="post" action="<?=e(base('redefinir-senha.php'))?>">
<input type="hidden" name="csrf" value="<?=e(csrf())?>">
<input type="hidden" name="token" value="<?=e($token)?>">
<label>Nova senha<input type="password" name="password" minlength="10" maxlength="200" required autocomplete="new-password"></label>
<label>Confirme a senha<input type="password" name="confirm_password" minlength="10" maxlength="200" required autocomplete="new-password"></label>
<button type="submit">Atualizar minha senha</button></form>
<?php endif; ?>
<a class="link" href="<?=e(base('recuperar-senha.php'))?>">Solicitar outro link</a>
</main></body></html>
