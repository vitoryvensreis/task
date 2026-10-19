<?php
declare(strict_types=1);
$basePath=(string)(parse_url((string)config('base_url',''),PHP_URL_PATH)??'');
$basePath='/'.trim($basePath,'/');if($basePath==='/')$basePath='';
$root=$basePath?:'';
try{$publicPlans=PlatformPlans::publicList();}catch(Throwable $e){error_log('TaskEsc landing plans unavailable');$publicPlans=[];}
$periodNames=['daily'=>'dia','weekly'=>'semana','biweekly'=>'quinzena','monthly'=>'mês','bimonthly'=>'bimestre','quarterly'=>'trimestre','semiannually'=>'semestre','yearly'=>'ano'];
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#0b0d12">
<meta name="description" content="TaskEsc: projetos, tarefas, Kanban, automações e equipes em um único lugar. Organize sua operação com controle e clareza.">
<meta property="og:title" content="TaskEsc | Uma operação organizada, uma equipe mais produtiva">
<meta property="og:description" content="Organize demandas, projetos e entregas com Kanban e automações inteligentes baseadas em regras.">
<meta property="og:type" content="website">
<title>TaskEsc — Gestão de tarefas e equipes sem complicação</title>
<link rel="icon" href="<?=e($root)?>/assets/taskesc-logo.svg">
<link rel="stylesheet" href="<?=e($root)?>/assets/landing.css?v=1.1">
</head>
<body>
<div class="site-bg" aria-hidden="true"></div>
<header class="site-header"><a class="logo" href="<?=e($root)?>/" aria-label="TaskEsc, início"><span class="logo-mark">T</span><span>TaskEsc<span class="logo-dot">.</span></span></a><nav aria-label="Menu principal"><a href="#recursos">Recursos</a><a href="#como-funciona">Como funciona</a><a href="#planos">Planos</a></nav><div class="header-actions"><a class="login-link" href="<?=e($root)?>/dashboard">Entrar</a><a class="btn btn-small" href="#planos">Conhecer planos <span aria-hidden="true">↗</span></a></div></header>
<main>
<section class="hero wrap"><div class="hero-copy">
<div class="eyebrow"><span class="live-dot"></span> UM ESPAÇO PARA TODA A SUA OPERAÇÃO</div>
<h1>Menos tarefas perdidas.<br><span>Mais trabalho entregue.</span></h1>
<p>Centralize demandas, projetos, equipes e aprovações. Ganhe clareza sobre o que precisa ser feito e automatize o que não precisa consumir seu tempo.</p>
<div class="hero-actions"><a class="btn btn-large" href="#planos">Explorar planos <span aria-hidden="true">→</span></a><a class="btn btn-quiet" href="#como-funciona">Entender como funciona <span aria-hidden="true">↘</span></a></div>
<div class="hero-proof"><span class="proof-icon">✓</span> Kanban personalizável <span class="proof-sep"></span><span class="proof-icon">✓</span> Automações por regras <span class="proof-sep"></span><span class="proof-icon">✓</span> Gestão por empresa</div>
</div>
<div class="hero-art" aria-label="Prévia ilustrativa de um quadro Kanban do TaskEsc">
<div class="glow"></div><div class="preview">
<div class="preview-top"><span class="preview-logo"><span>◈</span> taskesc / operação</span><span class="preview-user">● Ambiente de demonstração</span></div>
<div class="preview-head"><div><small>PROJETOS / DEMANDAS</small><h2>Visão da equipe</h2></div><span class="preview-pill">Quadro Kanban</span></div>
<div class="demo-toolbar"><span class="demo-live"><i></i> Prévia interativa</span><span>Arraste, toque ou mova os cards</span><button id="demo-reset" type="button" aria-label="Reiniciar Kanban de demonstração" title="Reiniciar demonstração">↺ Reiniciar</button></div>
<div class="kanban" id="demo-kanban" aria-label="Demonstração interativa de quadro Kanban TaskEsc">
 <div class="column" data-lane="todo" aria-label="A fazer"><div class="column-name"><i class="indicator neutral"></i><span>A fazer</span><b data-count>0</b></div><div class="demo-card-stack" data-stack>
  <article class="ticket" tabindex="0" role="button" draggable="true" data-demo-task="campaign" aria-label="Mover Planejar campanha para a próxima etapa"><span class="chip">CONTEÚDO</span><strong>Planejar campanha</strong><p>Briefing e referências</p><span class="ticket-foot">◷ Hoje <span class="demo-avatar">VS</span></span></article>
  <article class="ticket" tabindex="0" role="button" draggable="true" data-demo-task="review" aria-label="Mover Revisar entregas para a próxima etapa"><span class="chip violet">PROJETO</span><strong>Revisar entregas</strong><p>Organização dos arquivos</p><span class="ticket-foot">◷ Amanhã <span class="demo-avatar">GB</span></span></article>
 </div></div>
 <div class="column" data-lane="doing" aria-label="Em andamento"><div class="column-name"><i class="indicator orange"></i><span>Em andamento</span><b data-count>0</b></div><div class="demo-card-stack" data-stack>
  <article class="ticket accent" tabindex="0" role="button" draggable="true" data-demo-task="design" aria-label="Mover Produção de materiais para a próxima etapa"><span class="chip orange">PRIORIDADE ALTA</span><strong>Produção de materiais</strong><p>Design e edição final</p><div class="progress"><span></span></div><span class="ticket-foot">↗ Em execução <span class="demo-avatar">DP</span></span></article>
  <article class="ticket" tabindex="0" role="button" draggable="true" data-demo-task="website" aria-label="Mover Ajustar interface para a próxima etapa"><span class="chip violet">DESENVOLVIMENTO</span><strong>Ajustar interface</strong><p>Componentes responsivos</p><span class="ticket-foot">↗ Produção <span class="demo-avatar">VR</span></span></article>
 </div></div>
 <div class="column" data-lane="done" aria-label="Concluído"><div class="column-name"><i class="indicator green"></i><span>Concluído</span><b data-count>0</b></div><div class="demo-card-stack" data-stack>
  <article class="ticket" tabindex="0" role="button" draggable="true" data-demo-task="landing" aria-label="Mover Landing page para a próxima etapa"><span class="chip green">ENTREGUE</span><strong>Landing page</strong><p>Pronta para publicar</p><span class="ticket-foot">✓ Finalizado <span class="demo-avatar">TM</span></span></article>
 </div></div>
</div>
<div class="preview-note" role="status" aria-live="polite"><span class="sparkle">✦</span><div><b>Fluxos automáticos</b><small id="demo-status">Mova um card ou assista à demonstração das etapas.</small></div><span class="note-arrow">↗</span></div>
</div>
</div></section>
<section class="value-strip" aria-label="Benefícios"><div class="wrap value-items"><div><b>01</b><span>Centralize todas as demandas</span></div><div><b>02</b><span>Visualize prazos e responsáveis</span></div><div><b>03</b><span>Automatize tarefas repetitivas</span></div></div></section>
<section id="recursos" class="section wrap"><div class="section-lead"><span class="eyebrow muted">TUDO EM SEU LUGAR</span><h2>Organização que acompanha<br><em>o ritmo da sua equipe.</em></h2><p>Não é só um quadro de tarefas. É um fluxo de trabalho em que cada pessoa sabe o que fazer a seguir.</p></div><div class="features">
<article class="feature"><span class="feature-icon">▤</span><h3>Kanban e tarefas</h3><p>Arraste demandas entre etapas, defina responsáveis, prioridades e prazos com clareza.</p><span class="feature-no">01 / ORGANIZAÇÃO</span></article>
<article class="feature"><span class="feature-icon">✳</span><h3>Automações práticas</h3><p>Configure gatilhos, condições e ações para transformar processos manuais em fluxos repetíveis, sem depender de IA.</p><span class="feature-no">02 / PRODUTIVIDADE</span></article>
<article class="feature"><span class="feature-icon">◎</span><h3>Projetos e clientes</h3><p>Conecte demandas aos projetos, acompanhe aprovações e concentre o contexto em um lugar.</p><span class="feature-no">03 / CONTROLE</span></article>
<article class="feature"><span class="feature-icon">⌁</span><h3>Equipe e permissões</h3><p>Cada empresa tem seu ambiente, usuários e níveis de acesso para colaborar com segurança.</p><span class="feature-no">04 / COLABORAÇÃO</span></article>
<article class="feature"><span class="feature-icon">◇</span><h3>Arquivos e histórico</h3><p>Briefings, anexos, checklists e comentários acompanham o trabalho do início à entrega.</p><span class="feature-no">05 / CONTEXTO</span></article>
<article class="feature"><span class="feature-icon">↗</span><h3>Indicadores e financeiro</h3><p>Veja o andamento da operação e acompanhe os dados financeiros ligados às demandas.</p><span class="feature-no">06 / VISIBILIDADE</span></article>
</div></section>
<section id="como-funciona" class="section how"><div class="wrap how-inner"><div><span class="eyebrow muted">SIMPLES DE COMEÇAR</span><h2>Seu processo.<br><em>Seu fluxo.</em></h2><p>O TaskEsc se adapta à rotina da empresa sem exigir que você reconstrua sua operação do zero.</p><a class="text-link" href="#planos">Encontrar meu plano <span>→</span></a></div><div class="steps"><article><span>01</span><div><h3>Organize os projetos</h3><p>Cadastre clientes, estruture demandas e defina os responsáveis.</p></div></article><article><span>02</span><div><h3>Visualize o progresso</h3><p>Acompanhe o trabalho em etapas com prazos e prioridades.</p></div></article><article><span>03</span><div><h3>Automatize a rotina</h3><p>Use regras do tipo gatilho → condição → ação para reduzir o trabalho manual.</p></div></article></div></div></section>
<section id="planos" class="section wrap plans-section"><div class="section-lead"><span class="eyebrow muted">PLANOS TASKESC</span><h2>O plano certo para<br><em>cada etapa.</em></h2><p>Escolha os recursos e o tamanho de equipe que combinam com a sua operação.</p></div>
<?php if($publicPlans): ?><div class="plans-grid"><?php foreach($publicPlans as $plan): $features=decode((string)$plan['features_json'],[]);$price=number_format((int)$plan['price_cents']/100,2,',','.');$cycle=$periodNames[$plan['billing_period']]??$plan['billing_period']; ?><article class="plan"><span class="plan-tag">PLANO TASKESC</span><h3><?=e($plan['name'])?></h3><div class="price">R$ <?=e($price)?><span>/ <?=$cycle?></span></div><p><?= $plan['team_limit']===null?'Equipe ilimitada':'Até '.(int)$plan['team_limit'].' integrantes na equipe' ?></p><ul><?php if(!empty($features['tasks'])): ?><li>✓ Gestão de tarefas e projetos</li><?php endif; ?><?php if(!empty($features['automations'])): ?><li>✓ Automações de fluxo</li><?php endif; ?><?php if(!empty($features['contacts'])): ?><li>✓ Gestão de contatos</li><?php endif; ?></ul><a class="btn btn-plan" href="<?=e($root)?>/planos.php">Consultar contratação <span>↗</span></a></article><?php endforeach; ?></div>
<?php else: ?><div class="plans-empty"><div><h3>Encontre uma solução para sua equipe.</h3><p>Estamos preparando o catálogo de planos. Conheça os recursos e entre em contato para saber sobre disponibilidade.</p></div><a class="btn" href="<?=e($root)?>/planos.php">Consultar planos ↗</a></div><?php endif; ?></section>
<section class="last-cta"><div class="wrap"><div class="eyebrow">TRANSFORME SUA ROTINA</div><h2>Seu próximo projeto merece<br>um processo melhor.</h2><p>Um lugar para organizar o trabalho e deixar sua equipe focar no que realmente importa.</p><a class="btn btn-large" href="#planos">Conhecer planos <span>→</span></a></div></section>
</main>
<footer class="footer"><div class="wrap footer-row"><a class="logo" href="<?=e($root)?>/"><span class="logo-mark">T</span><span>TaskEsc<span class="logo-dot">.</span></span></a><p>Projetos claros. Equipes alinhadas.</p><div><a href="#recursos">Recursos</a><a href="#planos">Planos</a><a href="<?=e($root)?>/dashboard">Acesso à plataforma</a></div></div></footer>
<script src="<?=e($root)?>/assets/landing-kanban.js?v=1.1" defer></script>
</body></html>