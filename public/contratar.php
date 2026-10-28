<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow, noarchive');
$token=trim((string)($_GET['token']??''));
if(!preg_match('/\A[a-f0-9]{64}\z/D',$token))$token='';
$planId=filter_var($_GET['plan_id']??0,FILTER_VALIDATE_INT)?:0;
$plans=[];
try{installed();$plans=PlatformPlans::publicList();}catch(Throwable){}
$logged=false;
try{$logged=(bool)Auth::load(false);}catch(Throwable){}
?><!doctype html><html lang="pt-BR"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Contratar TaskEsc</title>
<style>
:root{color-scheme:dark;font-family:Inter,system-ui,Arial,sans-serif}*{box-sizing:border-box}
body{margin:0;background:#080d13;color:#eef4fa}main{max-width:680px;margin:5vh auto;padding:22px}
h1{font-size:clamp(30px,5vw,42px);letter-spacing:-.04em;margin:10px 0}h2{margin:4px 0 14px;font-size:21px}
a{color:#a4d3fb}p{line-height:1.6;color:#b8c7d5}
.panel{background:#111a24;border:1px solid #304154;border-radius:16px;padding:26px;margin:18px 0}
.form{display:grid;gap:14px}.form label{display:grid;gap:6px;font-size:13px;color:#d6e5f0}
.form input,.form select{width:100%;background:#0c141d;border:1px solid #4b6075;color:#fff;border-radius:9px;padding:13px;font:inherit}
button,.button{display:inline-block;background:#336a99;color:#fff;border:0;border-radius:9px;padding:13px 20px;font:inherit;font-weight:700;cursor:pointer;text-decoration:none}
button:disabled{opacity:.5}.button.secondary,button.secondary{background:#27394a}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}.wide{grid-column:1/-1}
.notice{padding:13px;border-radius:9px;background:#1c3141;color:#d9eefb}
.notice.error{background:#522c2d;color:#ffdedb}
.hidden{display:none!important}.muted{font-size:13px;color:#a4b4c3}
@media(max-width:580px){.grid{grid-template-columns:1fr}.panel{padding:20px}}
</style></head><body><main>
<a href="<?=e(base('planos.php'))?>">← Ver planos</a><h1>Seu TaskEsc começa aqui.</h1>
<p>Escolha o plano, crie a conta de proprietário ADM e confirme seu e-mail. Seu ambiente será liberado após o pagamento.</p>
<?php if($token!==''): ?>
<section class="panel" id="verification"><h2>Confirme e finalize sua contratação</h2>
<p id="checkout-status">Consultando seu pedido…</p>
<button type="button" id="confirm-email">Confirmar meu e-mail</button>
<form id="payment-form" class="form hidden" style="margin-top:20px">
<label>CPF ou CNPJ do pagador<input name="cpf_cnpj" required minlength="11" maxlength="20" autocomplete="off" placeholder="Somente para cobrança"></label>
<button type="submit">Gerar primeira cobrança no Asaas</button></form>
<a id="payment-link" class="button hidden" target="_blank" rel="noopener noreferrer" style="margin-top:16px">Abrir cobrança do Asaas</a>
<a id="login-after-paid" class="button hidden" href="<?=e(base('dashboard'))?>" style="margin-top:16px">Entrar no meu TaskEsc</a>
<p class="muted">Não feche esta página antes de guardar seu link de confirmação. O pagamento pode levar alguns instantes para ser identificado.</p>
</section>
<?php elseif($logged): ?>
<section class="panel"><h2>Você já está conectado</h2>
<p>Use a área <strong>Configurações → Assinaturas</strong> para alterar o plano ou adicionar membros. A cobrança da diferença será confirmada antes de aplicar a mudança.</p>
<a class="button" href="<?=e(base('configuracoes?assinaturas=1&plano='.(int)$planId))?>">Ir para minhas assinaturas</a></section>
<?php else: ?>
<section class="panel"><h2>Criar conta e contratar</h2>
<form class="form" id="signup">
<label>Plano<select name="plan_id" id="plan_id" required><?php foreach($plans as $plan): ?>
<option value="<?= (int)$plan['id'] ?>" <?= (int)$plan['id']===$planId?'selected':'' ?>><?=e($plan['name'])?> — R$ <?=e(number_format((int)$plan['price_cents']/100,2,',','.'))?> / <?=e($plan['billing_period'])?></option>
<?php endforeach; ?></select></label>
<label>Vagas extras para equipe<select name="extra_seats" id="extra_seats">
<?php for($n=0;$n<=20;$n++): ?><option value="<?=$n?>"><?=$n?> vaga(s)</option><?php endfor; ?>
</select></label>
<p class="notice" id="quote"></p>
<div class="grid"><label>Seu nome<input name="name" required maxlength="150" autocomplete="name"></label>
<label>Nome da empresa<input name="company" required maxlength="190" autocomplete="organization"></label>
<label class="wide">E-mail de acesso<input name="email" type="email" required maxlength="190" autocomplete="email"></label>
<label class="wide">Senha (mínimo 10 caracteres)<input name="password" type="password" required minlength="10" maxlength="200" autocomplete="new-password"></label></div>
<label style="display:flex;flex-direction:row;align-items:start;gap:10px"><input type="checkbox" name="privacy_consent" value="1" required style="width:auto;margin-top:4px"> Li a <a href="<?=e(base('privacidade'))?>" target="_blank" rel="noopener">Política de Privacidade</a> e aceito os <a href="<?=e(base('termos-de-uso'))?>" target="_blank" rel="noopener">Termos de Uso</a>.</label>
<button type="submit">Criar conta e continuar para pagamento</button></form>
<p class="muted">Após o cadastro, enviaremos um link para verificar seu e-mail. Nenhuma cobrança será criada antes dessa confirmação.</p></section>
<section class="panel"><h2>Já tem uma conta?</h2>
<form id="existing-login" class="form">
<label>E-mail<input name="email" type="email" autocomplete="username" required></label>
<label>Senha<input name="password" type="password" autocomplete="current-password" required></label>
<button type="submit" class="secondary">Entrar e gerenciar assinatura</button></form>
<form class="form hidden" id="login-2fa"><label>Código de verificação em duas etapas<input name="code" inputmode="numeric" autocomplete="one-time-code" required></label><button type="submit">Validar código</button></form>
<p><a href="<?=e(base('recuperar-senha.php'))?>">Esqueci minha senha</a></p></section>
<?php endif; ?>
<p class="notice hidden" id="message" role="status" aria-live="polite"></p>
</main>
<script>
(()=>{'use strict';
const token=<?=json($token)?>,csrf=<?=json(csrf())?>;
const plans=<?=json(array_map(static fn($p)=>[
 'id'=>(int)$p['id'],'price'=>(int)$p['price_cents'],
 'extra'=>(int)$p['extra_user_price_cents'],'allowed'=>(int)$p['allow_extra_seats']],$plans))?>;
const $=id=>document.getElementById(id);
const money=c=>new Intl.NumberFormat('pt-BR',{style:'currency',currency:'BRL'}).format(Number(c)/100);
const message=(text,error=false)=>{const el=$('message');el.classList.remove('hidden');el.classList.toggle('error',error);el.textContent=text;};
async function request(action,p={},method='POST'){
const url='<?=e(base('api.php'))?>?action='+encodeURIComponent(action);
const response=await fetch(url,{method,credentials:'same-origin',headers:{'Accept':'application/json','Content-Type':'application/json','X-CSRF-Token':csrf},...(method==='POST'?{body:JSON.stringify(p)}:{})});
const data=await response.json().catch(()=>({}));
if(!response.ok)throw Error(data.error||data.message||'Não foi possível concluir a operação.');
return data;
}
const signup=$('signup');
if(signup){
 const plan=$('plan_id'),extras=$('extra_seats');
 function quote(){
  const p=plans.find(x=>x.id===Number(plan.value));if(!p)return;
  const available=!!p.allowed&&p.extra>0;
  extras.disabled=!available;if(!available)extras.value='0';
  $('quote').textContent=money(p.price+Number(extras.value)*p.extra)+' por período. '+(available?'Vagas extras permitidas.':'Este plano não permite contratar usuários extras.');
 }
 plan.addEventListener('change',quote);extras.addEventListener('change',quote);quote();
 signup.addEventListener('submit',async event=>{
  event.preventDefault();const btn=signup.querySelector('[type=submit]');btn.disabled=true;
  try{const payload=Object.fromEntries(new FormData(signup));const r=await request('checkout.register',payload);signup.reset();message(r.message||'Confira seu e-mail.');}
  catch(e){message(e.message,true);}finally{btn.disabled=false;}
 });
 const login=$('existing-login');
 const destination='<?=e(base('configuracoes?assinaturas=1&plano='.(int)$planId))?>';
 const onLogin=r=>{if(r.twofa_required){login.classList.add('hidden');$('login-2fa').classList.remove('hidden');message('Informe o código de autenticação em duas etapas.');}else location.href=destination};
 login.addEventListener('submit',async e=>{
  e.preventDefault();try{onLogin(await request('auth.login',Object.fromEntries(new FormData(login))))}catch(err){message(err.message,true);}
 });
 $('login-2fa').addEventListener('submit',async e=>{
  e.preventDefault();try{onLogin(await request('auth.2fa',Object.fromEntries(new FormData($('login-2fa')))))}catch(err){message(err.message,true);}
 });
}
if(token){
 const status=$('checkout-status'),confirm=$('confirm-email'),form=$('payment-form'),link=$('payment-link'),done=$('login-after-paid');
 async function refresh(){
  try{
   const r=await request('checkout.status',{token});
   status.textContent='Plano: '+r.plan+' · '+money(r.amount_cents)+' por período · '+({
    awaiting_email:'Confirme seu e-mail',verified:'E-mail confirmado',creating:'Solicitando assinatura',
    awaiting_payment:'Aguardando pagamento',active:'Pagamento confirmado — acesso liberado',needs_review:'Em análise pelo suporte'
   }[r.status]||r.status);
   confirm.classList.toggle('hidden',r.status!=='awaiting_email');
   form.classList.toggle('hidden',r.status!=='verified');
   done.classList.toggle('hidden',r.status!=='active');
   if(r.invoice_url){link.href=r.invoice_url;link.classList.remove('hidden');}
  }catch(e){message(e.message,true)}
 }
 confirm.onclick=async()=>{confirm.disabled=true;try{await request('checkout.verify',{token});await refresh();}catch(e){message(e.message,true);confirm.disabled=false}};
 form.onsubmit=async e=>{
  e.preventDefault();const btn=form.querySelector('button');btn.disabled=true;
  try{await request('checkout.pay',{token,cpf_cnpj:form.querySelector('[name=cpf_cnpj]').value});await refresh();}
  catch(err){message(err.message,true);}finally{btn.disabled=false}
 };
 refresh();
 const poll=()=>{if(!document.hidden)refresh();};
 setInterval(poll,20000);
}
})();
</script></body></html>
