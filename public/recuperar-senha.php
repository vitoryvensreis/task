<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow, noarchive');
$error='';$success=false;
try{
 installed();
 if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
  checkCsrf();
  PasswordRecovery::request((string)($_POST['email']??''));
  $success=true;
 }
}catch(Throwable $e){$error=$e instanceof AppError?$e->getMessage():'Não foi possível solicitar a recuperação. Tente novamente.';}
?>
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Recuperar acesso · TaskEsc</title>
<style>
:root{color-scheme:dark}*{box-sizing:border-box}body{margin:0;min-height:100dvh;background:#080d12;color:#e8eef5;font:14px/1.6 system-ui,Arial,sans-serif;display:grid;place-items:center;padding:20px}
main{width:min(450px,100%);background:#111a24;border:1px solid #344250;border-radius:12px;padding:30px}h1{font-size:24px;margin:0 0 8px}p{color:#b5c3d0}label{display:grid;gap:8px;margin:22px 0;color:#d2dfed}input{width:100%;padding:13px;border-radius:8px;background:#0b121a;color:#fff;border:1px solid #45566b;font:inherit}
button,.link{display:block;width:100%;text-align:center;padding:12px;background:#345f82;color:#fff;border:0;border-radius:8px;font:inherit;font-weight:700;text-decoration:none;cursor:pointer;margin-top:18px}.link{background:transparent;color:#aed1ed}
.notice{background:#392721;color:#ffd6c5;padding:12px;border-radius:8px}.success{background:#163a32;color:#ddfff3}
</style></head><body><main>
<h1>Recuperar acesso</h1>
<?php if($success): ?><p class="notice success">Se o e-mail estiver cadastrado, você receberá um link para redefinir sua senha. Verifique sua caixa de entrada.</p>
<?php else: ?>
<p>Informe o e-mail da sua conta. Você receberá um link válido por 30 minutos, caso a conta esteja ativa.</p>
<?php if($error!==''): ?><p class="notice"><?=e($error)?></p><?php endif; ?>
<form method="post" action="<?=e(base('recuperar-senha.php'))?>">
<input type="hidden" name="csrf" value="<?=e(csrf())?>">
<label>E-mail cadastrado<input name="email" type="email" autocomplete="email" required maxlength="190"></label>
<button type="submit">Enviar link de recuperação</button>
</form>
<?php endif; ?>
<a class="link" href="<?=e(base('dashboard'))?>">Voltar ao login</a>
</main></body></html>
