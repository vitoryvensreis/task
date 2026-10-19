<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow, noarchive');
$error='';$saved=false;
$token=(string)($_POST['token']??$_GET['token']??'');
try{
 installed();
 if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
  checkCsrf();
  WorkspaceTeams::accept($_POST);
  $saved=true;$token='';
 }
}catch(Throwable $e){
 $error=$e instanceof AppError?$e->getMessage():'Não foi possível concluir o convite. Tente novamente.';
}
$base=(string)(parse_url((string)config('base_url',''),PHP_URL_PATH)??'');
$base='/'.trim($base,'/');if($base==='/')$base='';
$valid=(bool)preg_match('/\A[a-f0-9]{64}\z/D',$token);
$e=static fn(string $v):string=>htmlspecialchars($v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
?>
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Convite da equipe · TaskEsc</title>
<style>
:root{color-scheme:dark}*{box-sizing:border-box}
body{margin:0;min-height:100dvh;background:#080d12;color:#e8eef5;font:14px/1.6 system-ui,-apple-system,Segoe UI,sans-serif;display:grid;place-items:center;padding:20px}
main{width:min(440px,100%);background:#101923;border:1px solid #30404d;border-radius:6px;padding:26px}
h1{margin:0 0 9px;font-size:22px}p{color:#a5b6c5}
label{display:grid;gap:6px;margin:17px 0 0;color:#c2cfdb;font-size:12px}
input{width:100%;height:44px;color:#e8eef5;background:#0b121a;border:1px solid #40515f;border-radius:5px;padding:10px;font:inherit}
button,a.link{display:block;text-align:center;margin-top:19px;width:100%;padding:11px 14px;background:#287e7b;color:white;border:0;border-radius:5px;text-decoration:none;font:inherit;font-weight:700;cursor:pointer}
.notice{border-left:3px solid #e08968;padding:9px 12px;background:#32241f;color:#f4d0bc}
.success{border-left-color:#49bc95;background:#173a32;color:#d2f9e9}
small{color:#8fa3b3}
</style><link rel="stylesheet" href="<?= $e($base.'/assets/taskesc-theme.css') ?>?v=<?= (int)filemtime(__DIR__.'/assets/taskesc-theme.css') ?>"></head><body class="invite-page"><main>
<h1>Convite para equipe</h1>
<?php if($saved): ?>
<p class="notice success">Conta ativada! Entre no TaskEsc usando o e-mail convidado e a senha escolhida.</p>
<a class="link" href="<?= $e($base.'/dashboard') ?>">Acessar TaskEsc</a>
<?php elseif(!$valid): ?>
<p class="notice">O endereço do convite é inválido. Solicite um novo convite ao administrador da sua empresa.</p>
<?php else: ?>
<p>Defina sua senha para ativar o acesso à equipe no TaskEsc. O convite é individual e expira após 48 horas.</p>
<?php if($error!==''): ?><p class="notice"><?= $e($error) ?></p><?php endif; ?>
<form method="post" action="<?= $e($base.'/convite.php') ?>">
<input type="hidden" name="csrf" value="<?= $e(csrf()) ?>">
<input type="hidden" name="token" value="<?= $e($token) ?>">
<label>Crie sua senha (mínimo 10 caracteres)<input type="password" name="password" minlength="10" maxlength="200" autocomplete="new-password" required></label>
<label>Confirme sua senha<input type="password" name="confirm_password" minlength="10" maxlength="200" autocomplete="new-password" required></label>
<button type="submit">Ativar meu acesso</button>
</form>
<?php endif; ?>
<small>Seu acesso será vinculado somente ao workspace da empresa que enviou o convite.</small>
</main></body></html>