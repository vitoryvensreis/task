<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
$basePath=(string)(parse_url((string)config('base_url',''),PHP_URL_PATH)??'');
$basePath='/'.trim($basePath,'/');if($basePath==='/')$basePath='';
$token=trim((string)($_GET['activate']??''));
$activation=preg_match('/\A[a-f0-9]{64}\z/D',$token)===1;
$masterJsVersion=is_file(ROOT.'/public/assets/master.js')?filemtime(ROOT.'/public/assets/master.js'):1;
$supportJsVersion=is_file(ROOT.'/public/assets/support-chat.js')?filemtime(ROOT.'/public/assets/support-chat.js'):1;
$supportCssVersion=is_file(ROOT.'/public/assets/support-chat.css')?filemtime(ROOT.'/public/assets/support-chat.css'):1;
?><!doctype html>
<html lang="pt-BR"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="referrer" content="no-referrer">
<title>ADM Master — TaskEsc</title>
<link rel="stylesheet" href="<?=e($basePath)?>/assets/app.css">
<style>
.master-shell{max-width:850px;margin:7vh auto;padding:20px}.master-card{background:#16191e;color:#f6f7f8;border:1px solid #3a3d40;border-radius:18px;padding:28px;margin:18px 0}.master-card p,.master-card small{color:#aab0b8}.master-card input{display:block;width:100%;padding:12px;margin:9px 0 16px;background:#101215;color:white;border:1px solid #59616a;border-radius:9px;box-sizing:border-box}.master-card button{background:#f5f5f5;color:#111;border:0;border-radius:9px;padding:12px 18px;font-weight:700;cursor:pointer}.master-card button:disabled{opacity:.55;cursor:wait}.master-card a{color:#dce4ee}.master-card .error{color:#ffb9b9}.master-card .success{color:#a6e8c8}.master-card label{display:block;margin:14px 0}.master-flag{display:inline-block;background:#242933;padding:6px 12px;border-radius:20px;font-size:12px;letter-spacing:.08em}body{background:#0b0d10;color:#f6f7f8}
.master-tabs{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:22px}.master-tabs button{padding:10px 15px;background:#232936;border:1px solid #454d60;color:#edf1f5}.master-tabs button.active{background:#f1f5f9;color:#171b20}.master-shell{max-width:1080px}.master-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:12px}.master-mini{background:#0c1016;border:1px solid #313946;padding:18px;border-radius:12px}.master-mini p{margin-bottom:8px}.master-mini h3{margin-top:4px}.master-note{border-left:3px solid #9ca3af;padding:10px 14px;background:#121822}.master-reset-form{padding-top:14px;border-top:1px solid #333b48}.master-reset-form .danger{background:#a63b3b;color:#fff}
</style><link rel="stylesheet" href="<?=e($basePath)?>/assets/support-chat.css?v=<?=e((string)$supportCssVersion)?>"></head>
<body data-base-path="<?=e($basePath)?>" data-activate="<?=e($activation?$token:'')?>">
<main class="master-shell">
<div class="master-flag">TASKESC · ADMINISTRAÇÃO DA PLATAFORMA</div>
<h1>ADM Master</h1>
<p>Acesse os workspaces somente após abrir uma sessão de suporte autorizada e registrada.</p>
<section id="master-activate" class="master-card <?=$activation?'':'hidden'?>">
<h2>Ativar conta Master</h2><p>Convite de uso único. Defina sua senha para acessar a plataforma.</p>
<form id="master-activate-form">
<label>Nova senha<input name="password" type="password" minlength="10" maxlength="200" autocomplete="new-password" required></label>
<label>Confirmar senha<input name="confirm_password" type="password" minlength="10" maxlength="200" autocomplete="new-password" required></label>
<button>Ativar ADM Master</button>
</form><p id="master-activate-message" aria-live="polite"></p>
</section>
<section id="master-login" class="master-card hidden"><h2>Entre com sua conta Master</h2><p>Use o login normal do TaskEsc com o e-mail verificado.</p><a href="<?=e($basePath)?>/dashboard">Ir para o login</a></section>
<section id="master-panel" class="master-card hidden">
<div class="master-tabs" role="tablist" aria-label="Administração Master"><button type="button" data-master-tab="overview">Visão geral</button><button type="button" data-master-tab="clients">Clientes (ADMs)</button><button type="button" data-master-tab="plans">Planos de acesso</button><button type="button" data-master-tab="support">Atendimentos</button><button type="button" data-master-tab="workspaces">Workspaces</button></div>
<h2 id="master-panel-title">Visão geral</h2><p>Esta tela mostra apenas dados cadastrais dos workspaces. Nenhum dado operacional é exibido antes de iniciar o suporte.</p>
<div id="master-workspaces"></div><div id="master-administrators" class="hidden"></div><div id="master-plans" class="hidden"></div><p id="master-panel-message" aria-live="polite"></p>
<button type="button" id="master-logout">Sair da conta Master</button>
</section>
</main>
<script src="<?=e($basePath)?>/assets/vendor/centrifuge-5.7.4.js" defer></script>
<script src="<?=e($basePath)?>/assets/master.js?v=<?=e((string)$masterJsVersion)?>" defer></script>
<script src="<?=e($basePath)?>/assets/support-chat.js?v=<?=e((string)$supportJsVersion)?>" defer></script></body></html>