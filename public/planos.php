<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
try {
 installed();
 $plans=PlatformPlans::publicList();
}catch(Throwable $e) {
 error_log('TaskEsc public plans: '.$e->getMessage());
 $plans=[];
}
$features=['tasks'=>'Gerenciamento de tarefas','automations'=>'Automações','contacts'=>'Agenda de contatos'];
?><!doctype html>
<html lang="pt-BR"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Planos de acesso — TaskEsc</title>
<meta name="description" content="Consulte os planos de acesso publicados pelo TaskEsc.">
<style>
:root{color-scheme:dark;font-family:Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
*{box-sizing:border-box}body{margin:0;background:#0a0c10;color:#fafafa}
main{max-width:1200px;margin:auto;padding:40px 20px 80px}
header{display:flex;justify-content:space-between;align-items:center;gap:18px}
a{color:#eceef1;text-decoration:none}header a{border:1px solid #414650;border-radius:10px;padding:12px 18px}
h1{font-size:clamp(36px,5vw,64px);letter-spacing:-.05em;line-height:1.07;margin:88px 0 16px}
.lead{color:#afb6c1;font-size:18px;max-width:660px;line-height:1.65}
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:16px;margin-top:42px}
.card{border:1px solid #323842;background:#15191f;border-radius:18px;padding:26px;min-width:0}
.name{font-size:22px;font-weight:750;margin:0 0 18px}.price{font-size:34px;font-weight:800;letter-spacing:-.035em}
.period{font-size:14px;color:#b5becb;font-weight:400}.feature{padding:11px 0;border-bottom:1px solid #292f38;color:#e8edf5}
.meta{color:#afb6c1;margin-top:20px}.empty{margin-top:45px;padding:40px;background:#15191f;border:1px solid #333943;border-radius:14px}
footer{padding-top:44px;color:#7e8997;font-size:13px}
</style><link rel="stylesheet" href="<?=e(base('assets/taskesc-theme.css'))?>?v=1"></head><body class="plans-page"><main>
<header><strong>TaskEsc <span style="color:#9fa9b6">/ Planos</span></strong><a href="<?=e(base('dashboard'))?>">Entrar na plataforma</a></header>
<h1>Planos para diferentes equipes.</h1>
<p class="lead">Escolha a estrutura que combina com a sua operação. Os módulos e os limites de equipe variam conforme o plano.</p>
<?php if($plans): ?><section class="grid" aria-label="Planos públicos">
<?php foreach($plans as $plan):
 $enabled=decode((string)$plan['features_json'],[]);
 $price=number_format((int)$plan['price_cents']/100,2,',','.');
?>
<article class="card">
 <h2 class="name"><?=e($plan['name'])?></h2>
 <div class="price">R$ <?=e($price)?> <span class="period">/ <?=$plan['billing_period']==='yearly'?'ano':'mês'?></span></div>
 <p class="meta"><?= $plan['team_limit']===null?'Equipe ilimitada':'Até '.(int)$plan['team_limit'].' usuário(s) de equipe' ?></p>
 <?php foreach($features as $key=>$label)if(!empty($enabled[$key])): ?>
  <div class="feature">✓ <?=e($label)?></div>
 <?php endif; ?>
</article>
<?php endforeach; ?>
</section><?php else: ?><div class="empty">Ainda não existem planos de acesso públicos disponíveis.</div><?php endif; ?>
<footer>Catálogo informativo. A contratação e a cobrança de assinaturas não estão habilitadas nesta etapa.</footer>
</main></body></html>
