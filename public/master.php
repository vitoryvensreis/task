<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/bootstrap.php';
$basePath=(string)(parse_url((string)config('base_url',''),PHP_URL_PATH)??'');
$basePath='/'.trim($basePath,'/');if($basePath==='/')$basePath='';
$token=trim((string)($_GET['activate']??''));
$activation=preg_match('/\A[a-f0-9]{64}\z/D',$token)===1;
$assetVersion=static function(string $file):string {
 $path=ROOT.'/public/assets/'.$file;
 return is_file($path)?(string)filemtime($path):'1';
};
?><!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="referrer" content="no-referrer">
<meta name="theme-color" content="#080e14">
<title>TaskEsc · ADM Master</title>
<link rel="icon" href="<?=e($basePath)?>/assets/taskesc-logo.svg">
<link rel="stylesheet" href="<?=e($basePath)?>/assets/app.css?v=<?=e($assetVersion('app.css'))?>">
<link rel="stylesheet" href="<?=e($basePath)?>/assets/ux-v3.css?v=<?=e($assetVersion('ux-v3.css'))?>">
<link rel="stylesheet" href="<?=e($basePath)?>/assets/master-dashboard.css?v=<?=e($assetVersion('master-dashboard.css'))?>">
<link rel="stylesheet" href="<?=e($basePath)?>/assets/master-ux.css?v=<?=e($assetVersion('master-ux.css'))?>">
<link rel="stylesheet" href="<?=e($basePath)?>/assets/taskesc-theme.css?v=<?=e($assetVersion('taskesc-theme.css'))?>">
<link rel="stylesheet" href="<?=e($basePath)?>/assets/support-chat.css?v=<?=e($assetVersion('support-chat.css'))?>">
</head>
<?php
$requestPath=(string)(parse_url($_SERVER['REQUEST_URI']??'',PHP_URL_PATH)??'');
$routes=['clientes'=>'clients','planos'=>'plans','integracoes'=>'integrations','personalizacao'=>'personalization','configuracoes'=>'settings',
'whatsapp'=>'whatsapp','saude-logs'=>'health','banner-hero'=>'banner','suporte'=>'overview','workspaces'=>'clients'];
$relative=preg_replace('~^.*?/master(?:/|$)~','',$requestPath);
$routeKey=explode('/',trim((string)$relative,'/'))[0]??'';
$initialTab=$routes[$routeKey]??'overview';
?>
<body class="master-page" data-master-page="1" data-initial-tab="<?=e($initialTab)?>" data-base-path="<?=e($basePath)?>" data-activate="<?=e($activation?$token:'')?>">
<section id="master-auth-surface" class="master-access-login hidden">
 <div class="master-auth-card">
  <div class="kicker">TASKESC · ACESSO DA PLATAFORMA</div>
  <h1>ADM Master</h1>
  <section id="master-activate" class="<?=$activation?'':'hidden'?>">
   <p>Ativação segura por convite. Crie sua senha para acessar o painel.</p>
   <form id="master-activate-form">
    <label>Nova senha<input name="password" type="password" minlength="10" maxlength="200" autocomplete="new-password" required></label>
    <label>Confirmar senha<input name="confirm_password" type="password" minlength="10" maxlength="200" autocomplete="new-password" required></label>
    <button type="submit">Ativar ADM Master</button>
   </form>
   <p id="master-activate-message" aria-live="polite"></p>
  </section>
  <section id="master-login" class="hidden">
   <p>Entre com sua conta ADM Master no login do TaskEsc.</p>
   <a href="<?=e($basePath)?>/dashboard">Ir para o login</a>
  </section>
 </div>
</section>
<div id="master-shell" class="app master-app hidden">
 <aside class="sidebar" aria-label="Navegação do ADM Master">
  <div class="brand">
   <img class="brand-logo" src="<?=e($basePath)?>/assets/taskesc-logo.svg" alt="TaskEsc">
   <span>TaskEsc</span>
  </div>
  <nav class="master-nav" aria-label="Administração da plataforma">
   <div class="master-search"><label for="master-nav-search">ACESSO RÁPIDO</label><div class="master-search-field"><span aria-hidden="true">⌕</span><input id="master-nav-search" type="search" placeholder="Buscar seção…" autocomplete="off" aria-label="Buscar no ADM Master"><kbd>/</kbd></div></div>
   <div class="master-nav-group"><span class="master-nav-label">VISÃO GERAL</span>
    <button class="nav-btn" data-master-tab="overview" type="button"><span class="nav-icon">▦</span><span>Dashboard</span></button>
   </div>
   <div class="master-nav-group"><span class="master-nav-label">GESTÃO COMERCIAL</span>
    <button class="nav-btn" data-master-tab="clients" type="button"><span class="nav-icon">♙</span><span>Clientes e equipes</span></button>
    <button class="nav-btn" data-master-tab="plans" type="button"><span class="nav-icon">▤</span><span>Planos e assinaturas</span></button>
   </div>
   <div class="master-nav-group"><span class="master-nav-label">OPERAÇÃO</span>
    <button class="nav-btn" data-master-tab="integrations" type="button"><span class="nav-icon">◈</span><span>Integrações e Asaas</span></button>
    <button class="nav-btn" data-master-tab="whatsapp" type="button"><span class="nav-icon">◉</span><span>WhatsApp</span></button>
    <button class="nav-btn" data-master-tab="health" type="button"><span class="nav-icon">◷</span><span>Saúde e logs</span></button>
   </div>
   <div class="master-nav-group"><span class="master-nav-label">APARÊNCIA E CONTA</span>
    <button class="nav-btn" data-master-tab="personalization" type="button"><span class="nav-icon">✧</span><span>Identidade visual</span></button>
    <button class="nav-btn" data-master-tab="banner" type="button"><span class="nav-icon">▣</span><span>Banner Hero</span></button>
    <button class="nav-btn" data-master-tab="settings" type="button"><span class="nav-icon">⚙</span><span>Minha conta</span></button>
   </div>
   <div class="master-nav-empty" id="master-nav-empty" hidden>Nenhuma seção encontrada.</div>
  </nav>
  <div class="sidebar-bottom">
   <div class="master-sync"><span class="master-sync-dot"></span> Administração Master</div>
   <button id="master-logout" class="ghost full" type="button">Sair</button>
  </div>
 </aside>
 <div id="master-nav-backdrop" class="master-backdrop"></div>
 <main class="main">
  <header class="topbar">
   <button id="master-menu" class="icon-btn master-menu-btn" type="button" aria-label="Abrir menu">☰</button>
   <div class="page-heading">
    <div id="master-page-kicker" class="kicker">VISÃO GERAL · PLATAFORMA</div>
    <h2 id="master-panel-title">Dashboard</h2>
   </div>
   <div class="top-actions master-topbar-actions">
    <button id="master-refresh" class="secondary" type="button" title="Atualizar indicadores">↻ Atualizar</button>
    <div class="user-chip"><span class="master-account-avatar">M</span><span>ADM Master</span></div>
   </div>
  </header>
  <div id="master-panel" class="content hidden">
   <div id="master-panel-message" class="master-panel-message" role="status" aria-live="polite"></div>
   <section id="master-dashboard" class="master-view hidden" aria-label="Indicadores da plataforma"></section>
   <section id="master-administrators" class="master-view hidden" aria-label="Clientes e administradores"></section>
   <section id="master-plans" class="master-view hidden" aria-label="Planos de acesso"></section>
   <section id="master-workspaces" class="master-view hidden" aria-label="Workspaces"></section>
   <section id="master-settings" class="master-view hidden" aria-label="Configurações da plataforma"></section>
   <section id="master-integrations" class="master-view hidden" aria-label="Integrações globais"></section>
   <section id="master-whatsapp" class="master-view hidden" aria-label="WhatsApp da plataforma"></section>
   <section id="master-personalization" class="master-view hidden" aria-label="Personalização"></section>
   <section id="master-banner" class="master-view hidden" aria-label="Banner Hero"></section>
   <section id="master-health" class="master-view hidden" aria-label="Saúde e logs da plataforma"></section>
  </div>
 </main>
</div>
<script src="<?=e($basePath)?>/assets/vendor/centrifuge-5.7.4.js" defer></script>
<script src="<?=e($basePath)?>/assets/master-tabs.js?v=<?=e($assetVersion('master-tabs.js'))?>" defer></script>
<script src="<?=e($basePath)?>/assets/master-settings.js?v=<?=e($assetVersion('master-settings.js'))?>" defer></script>
<script src="<?=e($basePath)?>/assets/master-operations.js?v=<?=e($assetVersion('master-operations.js'))?>" defer></script>
<script src="<?=e($basePath)?>/assets/master-console.js?v=<?=e($assetVersion('master-console.js'))?>" defer></script>
<script src="<?=e($basePath)?>/assets/master.js?v=<?=e($assetVersion('master.js'))?>" defer></script>
<script src="<?=e($basePath)?>/assets/support-chat.js?v=<?=e($assetVersion('support-chat.js'))?>" defer></script>
</body></html>