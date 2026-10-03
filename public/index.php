<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
$basePath=(string)(parse_url((string)config('base_url',''),PHP_URL_PATH)??'');$basePath='/'.trim($basePath,'/');if($basePath==='/')$basePath='';
$brandName='TaskEsc';$brandLogo=($basePath?:'').'/assets/taskesc-logo.svg';
if(config()&&is_file(ROOT.'/storage/installed.lock')){try{$savedName=trim(setting('brand_name','TaskEsc'));if($savedName!=='')$brandName=$savedName;$savedLogo=trim(setting('logo_url',''));if($savedLogo!=='')$brandLogo=$savedLogo;}catch(Throwable){}}
$pwa180=ROOT.'/public/uploads/branding/pwa-180.png';$pwa192=ROOT.'/public/uploads/branding/pwa-192.png';
$pwa180Url=is_file($pwa180)?(($basePath?:'').'/uploads/branding/pwa-180.png?v='.filemtime($pwa180)):(($basePath?:'').'/assets/taskesc-logo.svg');
$pwa192Url=is_file($pwa192)?(($basePath?:'').'/uploads/branding/pwa-192.png?v='.filemtime($pwa192)):(($basePath?:'').'/assets/taskesc-logo.svg');
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="theme-color" content="#101820"><title><?=e($brandName)?></title>
  <meta name="application-name" content="TaskEsc">
  <meta name="mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
  <meta name="apple-mobile-web-app-title" content="TaskEsc">
  <link rel="manifest" href="<?=e($basePath)?>/manifest.php?v=<?=e((string)(is_file($pwa192)?filemtime($pwa192):1))?>">
  <link rel="icon" href="<?=e($pwa192Url)?>">
  <link rel="apple-touch-icon" href="<?=e($pwa180Url)?>">
  <link rel="stylesheet" href="<?=e($basePath)?>/assets/app.css?v=3.4.0">
  <link rel="stylesheet" href="<?=e($basePath)?>/assets/ui-fixes.css?v=3.10.3">
  <link rel="stylesheet" href="<?=e($basePath)?>/assets/ux-v3.css?v=3.14.0">
  <script src="<?=e($basePath)?>/assets/taskesc-icons.js?v=3.4.0" defer></script>
</head>
<body data-base-path="<?=e($basePath)?>">
  <div id="toast" class="toast"></div><div id="page-loader" class="page-loader hidden" aria-live="polite"><div class="page-loader-card"><span class="loader-ring"></span><b>Carregando</b></div></div>
  <section id="login" class="login-screen hidden">
    <div class="login-card"><img class="brand-logo brand-logo-login" id="login-logo" src="<?=e($brandLogo)?>" alt="Logo do sistema"><h1 id="login-brand-name"><?=e($brandName)?></h1><p>Jobs, tarefas, arquivos e agendas em um só fluxo.</p>
      <form id="login-form"><label>E-mail<input class="input login-input" type="email" name="email" autocomplete="username" required></label><label>Senha<input class="input login-input" type="password" name="password" autocomplete="current-password" required></label><button class="primary full">Entrar</button><div id="login-error" class="form-error"></div></form>
      <form id="twofa-form" class="hidden"><div class="login-twofa-copy"><b>Verificação em duas etapas</b><span>Digite o código de 6 dígitos do seu autenticador ou um código de recuperação.</span></div><label>Código<input class="input login-input" name="code" inputmode="numeric" autocomplete="one-time-code" required></label><button class="primary full">Verificar</button><button type="button" class="ghost full" id="twofa-back">Voltar</button><div id="twofa-error" class="form-error"></div></form>
    </div>
  </section>
  <div id="app" class="app hidden">
    <aside class="sidebar"><div class="brand"><img class="brand-logo" id="sidebar-logo" src="<?=e($brandLogo)?>" alt="Logo do sistema"><span id="brand-name"><?=e($brandName)?></span></div><nav id="nav"></nav><div class="sidebar-bottom"><div id="sync-indicator" class="sync-indicator"><i></i> Dados atualizados</div><button id="logout" class="ghost full">Sair</button></div></aside>
    <div id="nav-backdrop" class="nav-backdrop"></div><main class="main"><header class="topbar"><button id="menu" class="icon-btn mobile-only" type="button" aria-label="Abrir menu"><span class="icon-slot"><i data-lucide="menu" class="ui-icon" aria-hidden="true"></i><span class="icon-fallback"></span></span></button><div class="page-heading"><div id="page-kicker" class="kicker">VISÃO GERAL</div><h2 id="page-title">Dashboard</h2></div><div class="top-actions"><button id="preview-exit" class="secondary hidden">Sair da visão do cliente</button><button id="new-demand" class="primary"><i data-lucide="plus" class="ui-icon" aria-hidden="true"></i><span class="button-label">Nova task</span></button><div class="notification-shell"><button id="notifications-btn" class="icon-btn notification-btn" type="button" aria-label="Notificações" title="Notificações" aria-haspopup="dialog" aria-expanded="false"><span class="icon-slot"><i data-lucide="bell" class="ui-icon" aria-hidden="true"></i><span class="icon-fallback"></span></span><span id="notification-badge" class="notification-badge hidden">0</span></button><div id="notification-popover" class="notification-popover hidden" role="dialog" aria-label="Notificações"></div></div><div id="user-chip" class="user-chip"></div></div></header><div id="content" class="content"></div></main>
  </div>
  <div id="modal-root"></div>
  <script src="<?=e($basePath)?>/assets/app.js?v=3.14.0" defer></script>
  <script src="<?=e($basePath)?>/assets/ux-v3.js?v=3.14.0" defer></script>
</body>
</html>
