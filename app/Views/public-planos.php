<?php
declare(strict_types=1);
// Renderizada pelo front controller public/index.php.
try {
 installed();
 $plans=PlatformPlans::publicList();
}catch(Throwable $e) {
 error_log('TaskEsc public plans: '.$e->getMessage());
 $plans=[];
}
$features=['tasks'=>'Gerenciamento de tarefas','automations'=>'Automações','contacts'=>'Agenda de contatos'];
$cycleLabels=['weekly'=>'semana','biweekly'=>'quinzena','monthly'=>'mês','bimonthly'=>'bimestre','quarterly'=>'trimestre','semiannually'=>'semestre','yearly'=>'ano'];
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
.sales{margin-top:55px;border:1px solid #36414d;background:#111821;padding:28px;border-radius:20px}
.sales h2{font-size:28px;letter-spacing:-.03em;margin:0 0 8px}
.sales .intro{color:#aeb9c8;font-size:14px;line-height:1.6;max-width:720px}
.sales form{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px;margin-top:26px}
.sales label{font-size:13px;color:#dbe5ee;display:flex;flex-direction:column;gap:7px}
.sales label input,.sales label select{width:100%;padding:12px 14px;color:#fff;background:#0c131a;border:1px solid #465366;border-radius:9px;font:inherit}
.sales label.full,.sales .consent,.sales .actions{grid-column:1/-1}
.sales .consent{flex-direction:row;align-items:flex-start;color:#b4c0cd}
.sales .consent input{width:auto;margin-top:4px}
.sales a{text-decoration:underline;text-underline-offset:3px;color:#a8d1ed}
.sales .actions{display:flex;flex-direction:column;gap:10px}
.sales button,.card .request{display:inline-flex;justify-content:center;align-items:center;border:0;border-radius:9px;background:#345f82;color:#fff;padding:13px 18px;font-weight:700;cursor:pointer}
.sales button{align-self:start}
.sales button:disabled{opacity:.55;cursor:wait}
.sales .feedback{min-height:22px;color:#cbd8e8}
.sales .feedback.error{color:#ffb3ac}
.sales .honeypot{position:absolute!important;left:-9999px!important;opacity:0!important}
@media(max-width:650px){.sales form{grid-template-columns:1fr}.sales{padding:20px}.sales label{grid-column:1/-1}}

</style><link rel="stylesheet" href="<?=e(base('assets/taskesc-theme.css'))?>?v=<?= (int)filemtime(ROOT.'/public/assets/taskesc-theme.css') ?>"></head><body class="plans-page"><main>
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
 <div class="price">R$ <?=e($price)?> <span class="period">/ <?=e($cycleLabels[$plan['billing_period']]??$plan['billing_period'])?></span></div>
 <p class="meta"><?= $plan['team_limit']===null?'Equipe ilimitada':((int)$plan['team_limit']===0?'Somente proprietário ADM':'Proprietário ADM + até '.(int)$plan['team_limit'].' pessoa(s) da equipe') ?></p>
 <p class="meta">Clientes ilimitados. Proprietário ADM não ocupa vaga.</p>
 <?php if($plan['team_limit']!==null&&(int)$plan['allow_extra_seats']===0): ?>
 <p class="meta">Plano fechado: não permite contratar usuários adicionais.</p>
 <?php endif; ?>
 <?php if($plan['team_limit']!==null&&(int)$plan['allow_extra_seats']===1&&(int)$plan['extra_user_price_cents']>0): ?>
 <p class="meta">Vaga adicional ADM/equipe: R$ <?=e(number_format((int)$plan['extra_user_price_cents']/100,2,',','.'))?> / <?=e($cycleLabels[$plan['billing_period']]??$plan['billing_period'])?></p>
 <?php endif; ?>
 <a class="request" href="<?=e(base('contratar?plan_id='.(int)$plan['id']))?>">Contratar plano</a>
 <?php foreach($features as $key=>$label)if(!empty($enabled[$key])): ?>
  <div class="feature">✓ <?=e($label)?></div>
 <?php endif; ?>
</article>
<?php endforeach; ?>
</section><?php else: ?><div class="empty">Ainda não existem planos de acesso públicos disponíveis.</div><?php endif; ?>
<footer>TaskEsc é um produto da Escotilha Produtora. <a href="<?=e(base('privacidade'))?>">Privacidade</a> · <a href="<?=e(base('termos-de-uso'))?>">Termos de Uso</a>. Após criar a conta e confirmar seu e-mail, você pode concluir o pagamento no Asaas.</footer>

</main></body></html>
