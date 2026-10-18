<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
$basePath=(string)(parse_url((string)config('base_url',''),PHP_URL_PATH)??'');
$basePath='/'.trim($basePath,'/');if($basePath==='/')$basePath='';
$token=trim((string)($_GET['activate']??''));
$activation=preg_match('/\A[a-f0-9]{64}\z/D',$token)===1;
?><!doctype html>
<html lang="pt-BR"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="referrer" content="no-referrer">
<title>ADM Master — TaskEsc</title>
<link rel="stylesheet" href="<?=e($basePath)?>/assets/app.css">
<style>
.master-shell{max-width:850px;margin:7vh auto;padding:20px}.master-card{background:#16191e;color:#f6f7f8;border:1px solid #3a3d40;border-radius:18px;padding:28px;margin:18px 0}.master-card p,.master-card small{color:#aab0b8}.master-card input{display:block;width:100%;padding:12px;margin:9px 0 16px;background:#101215;color:white;border:1px solid #59616a;border-radius:9px;box-sizing:border-box}.master-card button{background:#f5f5f5;color:#111;border:0;border-radius:9px;padding:12px 18px;font-weight:700;cursor:pointer}.master-card button:disabled{opacity:.55;cursor:wait}.master-card a{color:#dce4ee}.master-card .error{color:#ffb9b9}.master-card .success{color:#a6e8c8}.master-card label{display:block;margin:14px 0}.master-flag{display:inline-block;background:#242933;padding:6px 12px;border-radius:20px;font-size:12px;letter-spacing:.08em}body{background:#0b0d10;color:#f6f7f8}
</style></head>
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
<section id="master-login" class="master-card hidden"><h2>Entre com sua conta Master</h2><p>Use o login normal do TaskEsc com o e-mail verificado.</p><a href="<?=e($basePath)?>/index.php">Ir para o login</a></section>
<section id="master-panel" class="master-card hidden">
<h2>Workspaces</h2><p>Esta tela mostra apenas dados cadastrais dos workspaces. Nenhum dado operacional é exibido antes de iniciar o suporte.</p>
<div id="master-workspaces"></div><p id="master-panel-message" aria-live="polite"></p>
<button type="button" id="master-logout">Sair da conta Master</button>
</section>
</main>
<script src="<?=e($basePath)?>/assets/master.js" defer></script></body></html>