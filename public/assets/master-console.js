'use strict';
window.MasterConsole=(()=>{
 const el=(tag,cls='',txt='')=>{const n=document.createElement(tag);if(cls)n.className=cls;if(txt!==null&&txt!==undefined)n.textContent=String(txt);return n};
 const fmt=n=>new Intl.NumberFormat('pt-BR').format(Number(n)||0);
 const money=n=>new Intl.NumberFormat('pt-BR',{style:'currency',currency:'BRL'}).format((Number(n)||0)/100);
 const cycles={daily:'Diário',weekly:'Semanal',biweekly:'Quinzenal',monthly:'Mensal',bimonthly:'Bimestral',quarterly:'Trimestral',semiannually:'Semestral',yearly:'Anual'};
 async function api(base,action,csrf,data=null,query=null){
  const url=base+'/api.php?action='+encodeURIComponent(action)+(query?'&'+new URLSearchParams(query):'');
  const r=await fetch(url,{method:data===null?'GET':'POST',credentials:'same-origin',cache:'no-store',
   headers:{'Accept':'application/json',...(data!==null?{'Content-Type':'application/json','X-CSRF-Token':csrf}:{})},
   ...(data!==null?{body:JSON.stringify(data)}:{})});
  const result=await r.json().catch(()=>({error:'Resposta inválida do servidor'}));
  if(!r.ok||result.error)throw Error(result.error||'Falha na operação');return result;
 }
 function button(label,handler,klass='secondary'){
  const b=el('button',klass,label);b.type='button';b.onclick=handler;return b;
 }
 const field=(form,label,key,value='',type='text')=>{
  const wrap=el('label','mc-field'),caption=el('span','',label),input=el('input','input');input.name=key;input.value=value??'';input.type=type;
  wrap.append(caption,input);form.append(wrap);return input;
 };
 const formBody=(title,subtitle)=>{
  const body=el('div','mc-dialog-body');body.append(el('h2','',title),el('p','master-description',subtitle));return body;
 };
 function modal(title,subtitle,render){
  const overlay=el('div','mc-overlay'),dialog=el('section','mc-dialog');
  dialog.setAttribute('role','dialog');dialog.setAttribute('aria-modal','true');dialog.setAttribute('aria-label',title);
  const head=el('div','mc-dialog-head'),label=el('strong','',title);
  const close=button('×',()=>overlay.remove(),'mc-close');close.setAttribute('aria-label','Fechar janela');head.append(label,close);
  dialog.append(head);const body=formBody(title,subtitle);dialog.append(body);overlay.append(dialog);document.body.append(overlay);
  overlay.onclick=e=>{if(e.target===overlay)overlay.remove()};
  const key=e=>{if(e.key==='Escape'){overlay.remove();document.removeEventListener('keydown',key)}};
  document.addEventListener('keydown',key);render(body,()=>{overlay.remove();document.removeEventListener('keydown',key)});
  close.focus();return overlay;
 }
 function note(parent,title,body){
  const n=el('div','mc-note');n.append(el('b','',title),el('span','',body));parent.append(n);return n;
 }
 function status(host,value,error=false){
  const target=host.querySelector('.mc-feedback');if(target){target.textContent=value;target.classList.toggle('error',error)}
 }
 async function customers(host,csrf,base){
  host.replaceChildren();
  const head=el('div','mc-toolbar'),input=el('input','input');
  input.type='search';input.placeholder='Buscar por e-mail, nome ou empresa';input.setAttribute('aria-label','Buscar clientes');
  head.append(input,button('↻ Atualizar',()=>load(),'secondary'));host.append(head);
  const result=el('div');host.append(result);
  let timer;
  input.oninput=()=>{clearTimeout(timer);timer=setTimeout(load,300)};
  async function load(){
   result.replaceChildren(el('p','master-description','Carregando clientes…'));
   try{
    const [data,admins,plans]=await Promise.all([
     api(base,'master.customers',csrf,null,{q:input.value.trim()}),
     api(base,'master.administrators',csrf),api(base,'master.plans',csrf)]);
    result.replaceChildren();
    note(result,'Faturamento SaaS ainda não integrado',
      'O status de pagamentos e o histórico de faturas serão exibidos aqui quando a sincronização Asaas estiver homologada. Não confundir com os recebimentos dos clientes do workspace.');
    const table=el('div','mc-customer-list');
    for(const w of data.customers||[]){
     const row=el('article','mc-customer-row');
     const ident=el('div','mc-customer-ident');
     const circle=el('div','mc-avatar',String(w.name||'E').slice(0,1).toUpperCase());
     const person=el('div');person.append(el('strong','',w.name),el('span','',w.owner_name+' · '+w.owner_email));
     ident.append(circle,person);row.append(ident);
     const plan=el('div','mc-cell');plan.append(el('small','','Plano'),el('strong','',w.plan_name||'Sem plano'));row.append(plan);
     const team=el('div','mc-cell');team.append(el('small','','Equipe'),el('strong','',fmt(w.team_users)+' membros'));row.append(team);
     const bill=el('div','mc-cell');bill.append(el('small','','Assinatura'),el('span','mc-chip','Não integrada'));row.append(bill);
     const actions=el('div','mc-row-actions');
     const detail=button('Gerenciar',()=>showCustomer(w,admins,plans,load,base,csrf),'primary');
     const access=button('↗',()=>startSupport(w,base,csrf),'secondary');access.title='Entrar no workspace (suporte)';
     actions.append(detail,access);row.append(actions);table.append(row);
    }
    if(!data.customers?.length)result.append(el('div','master-empty','Nenhum cliente encontrado para essa busca.'));
    result.append(table);
   }catch(e){result.replaceChildren(el('p','mc-feedback error',e.message))}
  }
  await load();
 }
 function startSupport(w,base,csrf){
  modal('Acessar workspace','Toda sessão de suporte é auditada e expira automaticamente após duas horas.',(body,close)=>{
   const form=el('form','mc-form');field(form,'Workspace','company',w.name).disabled=true;
   const reason=field(form,'Motivo obrigatório (mínimo 8 caracteres)','reason','');
   reason.required=true;reason.minLength=8;reason.maxLength=190;reason.placeholder='Ex.: atendimento solicitado pelo cliente';
   const foot=el('div','mc-actions'),send=el('button','primary','Entrar no workspace');send.type='submit';
   foot.append(send);form.append(foot,el('p','mc-feedback'));body.append(form);
   form.onsubmit=async e=>{
    e.preventDefault();send.disabled=true;
    try{const x=await api(base,'master.start',csrf,{workspace_id:Number(w.id),reason:reason.value.trim()});close();location.href=x.url||base+'/dashboard'}
    catch(err){status(form,err.message,true);send.disabled=false}
   };
  });
 }
 function showCustomer(w,admins,plans,refresh,base,csrf){
  modal('Gerenciar '+w.name,'Gestão de plano, equipe, acessos e histórico de suporte da empresa.',(body,close)=>{
   const stats=el('div','mc-profile-stats');
   for(const [title,value] of [['Plano atual',w.plan_name||'Nenhum'],['Usuários ativos',fmt(w.active_users)],['Equipe',fmt(w.team_users)],['Suporte realizado',fmt(w.support_sessions)+' sessões']]){
    const cell=el('div');cell.append(el('small','',title),el('strong','',value));stats.append(cell)
   }body.append(stats);
   const actions=el('div','mc-actions');
   actions.append(button('↗ Entrar no workspace',()=>{close();startSupport(w,base,csrf)},'primary'));
   body.append(actions);
   const planForm=el('form','mc-form');planForm.append(el('h3','','Acesso e plano'));
   const select=el('select','select');select.name='plan_id';
   for(const p of plans.filter(x=>Number(x.active)===1)){
    const o=el('option','',p.name+' · '+money(p.price_cents)+'/'+(cycles[p.billing_period]||p.billing_period).toLowerCase());
    o.value=p.id;o.selected=Number(p.id)===Number(w.plan_id);select.append(o);
   }
   const label=el('label','mc-field');label.append(el('span','','Plano do workspace'),select);planForm.append(label);
   const save=el('button','primary','Aplicar plano');save.type='submit';
   planForm.append(save,el('p','mc-feedback'));body.append(planForm);
   planForm.onsubmit=async e=>{e.preventDefault();save.disabled=true;try{await api(base,'master.plan_assign',csrf,{workspace_id:Number(w.id),plan_id:Number(select.value)});status(planForm,'Plano atualizado.');refresh()}
    catch(err){status(planForm,err.message,true)}finally{save.disabled=false}};
   const users=el('div','mc-team');users.append(el('h3','','Administradores da empresa'));
   for(const u of admins.filter(x=>Number(x.workspace_id)===Number(w.id))){
    const row=el('div','mc-team-row'),who=el('div');
    who.append(el('strong','',u.name),el('span','',u.email+' · '+(u.twofa_enabled?'2FA ativo':'2FA desativado')));
    row.append(who,button('Redefinir 2FA',()=>resetTwofa(u,base,csrf),'secondary'));users.append(row);
   }body.append(users);
   const billing=el('div','mc-billing');billing.append(el('h3','','Assinatura e histórico financeiro'));
   note(billing,'Faturas não disponíveis','Nenhuma cobrança SaaS foi sincronizada. Quando o Asaas estiver ligado, aparecerão status, vencimentos, pagamentos, tentativas e notas.');
   body.append(billing);
  });
 }
 function resetTwofa(u,base,csrf){
  modal('Redefinir 2FA · '+u.name,'Operação sensível: requer senha Master e justificativa, com auditoria.',(body,close)=>{
   const form=el('form','mc-form'),why=field(form,'Justificativa','reason',''),pw=field(form,'Senha do ADM Master','master_password','','password');
   why.minLength=12;why.maxLength=190;why.required=true;pw.required=true;
   const submit=el('button','primary','Confirmar redefinição');submit.type='submit';
   form.append(submit,el('p','mc-feedback'));body.append(form);
   form.onsubmit=async e=>{e.preventDefault();if(!confirm('Confirma a redefinição do 2FA de '+u.name+'?'))return;submit.disabled=true;
    try{await api(base,'master.reset_2fa',csrf,{user_id:Number(u.id),reason:why.value,master_password:pw.value});close()}
    catch(err){status(form,err.message,true);submit.disabled=false}};
  });
 }
 async function plans(host,csrf,base){
  host.replaceChildren(el('p','master-description','Organize seus produtos SaaS. A publicação de planos não ativa cobranças nem novos workspaces automaticamente.'));
  const top=el('div','mc-plan-toolbar'),area=el('div','mc-plan-grid');
  top.append(button('+ Novo plano',()=>editPlan(null,draw,base,csrf),'primary'));
  host.append(top,area);
  async function draw(){
   const rows=await api(base,'master.plans',csrf);
   area.replaceChildren();
   for(const p of rows){
    const item=el('article','mc-plan-card'+(Number(p.active)?'':' disabled')),head=el('div','mc-plan-head');
    head.append(el('span','mc-chip',Number(p.public_visible)?'Público':'Privado'),el('span','mc-muted',cycles[p.billing_period]||p.billing_period));item.append(head);
    item.append(el('h3','',p.name),el('div','mc-plan-price',money(p.price_cents)));
    item.append(el('p','mc-muted','por período · '+(cycles[p.billing_period]||p.billing_period)));
    if(p.billing_period==='daily')item.append(el('p','mc-warning','Diário: requer motor de cobrança próprio; não é recorrência nativa do Asaas.'));
    const feats=typeof p.features_json==='string'?JSON.parse(p.features_json):p.features_json||{};
    const ul=el('div','mc-plan-features');
    for(const [k,title] of [['tasks','Gerenciamento de tarefas'],['automations','Automações'],['contacts','Agenda de contatos']]){
      if(feats[k])ul.append(el('span','','✓ '+title));
    }
    ul.append(el('span','','✓ '+(p.team_limit===null?'Equipe ilimitada':'Até '+p.team_limit+' membros')));
    item.append(ul,el('small','mc-muted',fmt(p.assigned_workspaces)+' workspace(s) vinculado(s)'));
    item.append(button('Editar plano',()=>editPlan(p,draw,base,csrf),'primary'));
    area.append(item);
   }
   if(!rows.length)area.append(el('div','master-empty','Nenhum plano cadastrado.'))
  }
  await draw();
 }
 function editPlan(plan,refresh,base,csrf){
  modal(plan?'Editar plano · '+plan.name:'Criar plano','Personalize valor, recorrência, limites e recursos.',(body,close)=>{
   const form=el('form','mc-form mc-plan-form');
   const name=field(form,'Nome do plano','name',plan?.name||''),code=field(form,'Identificador único','code',plan?.code||'');
   name.required=true;code.required=true;code.pattern='[a-z0-9][a-z0-9-]{2,99}';
   const price=field(form,'Valor por período (R$)','price',(Number(plan?.price_cents||0)/100).toFixed(2),'number');
   price.min='0';price.step='0.01';price.required=true;
   const cycleLabel=el('label','mc-field'),cycle=el('select','select');cycle.name='billing_period';
   for(const [id,label] of Object.entries(cycles)){
    const o=el('option','',label);o.value=id;o.selected=(plan?.billing_period||'monthly')===id;cycle.append(o);
   }cycleLabel.append(el('span','','Recorrência'),cycle);form.append(cycleLabel);
   const limit=field(form,'Limite de usuários da equipe','team_limit',plan?.team_limit??2,'number');limit.min='1';limit.required=true;
   const feats=typeof plan?.features_json==='string'?JSON.parse(plan.features_json):plan?.features_json||{tasks:true,automations:true,contacts:true};
   const options=el('div','mc-plan-options');
   const checkbox=(label,key,checked)=>{
    const l=el('label','mc-check'),cb=el('input');cb.type='checkbox';cb.name=key;cb.checked=checked;l.append(cb,el('span','',label));options.append(l);return cb;
   };
   const unlimited=checkbox('Equipe ilimitada','unlimited',plan?.team_limit===null);unlimited.onchange=()=>limit.disabled=unlimited.checked;limit.disabled=unlimited.checked;
   for(const [id,label] of [['tasks','Tasks'],['automations','Automações'],['contacts','Contatos']])checkbox(label,id,Boolean(feats[id]));
   checkbox('Disponível publicamente','public_visible',Boolean(Number(plan?.public_visible||0)));
   checkbox('Plano ativo','active',plan?Boolean(Number(plan.active)):true);
   form.append(options);
   const save=el('button','primary','Salvar plano');save.type='submit';form.append(save,el('p','mc-feedback'));body.append(form);
   form.onsubmit=async e=>{
    e.preventDefault();save.disabled=true;
    const data=Object.fromEntries(new FormData(form));const f={};
    for(const k of ['tasks','automations','contacts'])f[k]=!!data[k];
    try{
     await api(base,'master.plan_save',csrf,{
      id:Number(plan?.id||0),name:name.value.trim(),code:code.value.trim(),
      price_cents:Math.round(Number(price.value)*100),billing_period:cycle.value,
      team_limit:Number(limit.value||2),unlimited_team:unlimited.checked?1:0,
      public_visible:data.public_visible?1:0,active:data.active?1:0,features:f});
     close();await refresh();
    }catch(err){status(form,err.message,true);save.disabled=false}
   };
  });
 }
 async function account(host,csrf,base){
  host.replaceChildren(el('p','master-description','Perfil pessoal e segurança da administração da plataforma. Os novos Masters não participam automaticamente de nenhum workspace.'));
  const data=await api(base,'master.account',csrf),grid=el('div','mc-account-grid');host.append(grid);
  const card=(title,subtitle)=>{
   const v=el('section','panel ms-panel');v.append(el('h3','',title),el('p','master-description',subtitle));grid.append(v);return v;
  };
  const profile=card('Minha conta','Dados exclusivos do seu login Master.');
  const pf=el('form','mc-form'),name=field(pf,'Nome','name',data.me.name),email=field(pf,'E-mail','email',data.me.email);email.disabled=true;
  const btn=el('button','primary','Salvar nome');btn.type='submit';pf.append(btn,el('p','mc-feedback'));profile.append(pf);
  pf.onsubmit=async e=>{e.preventDefault();try{await api(base,'master.profile_save',csrf,{name:name.value});status(pf,'Perfil atualizado.')}catch(err){status(pf,err.message,true)}};
  const pw=card('Alterar senha','Use uma senha forte e exclusiva do ADM Master.');
  const pwf=el('form','mc-form');
  field(pwf,'Senha atual','current_password','','password');field(pwf,'Nova senha','new_password','','password');field(pwf,'Confirmar nova senha','confirm_password','','password');
  const pbtn=el('button','primary','Alterar senha');pbtn.type='submit';pwf.append(pbtn,el('p','mc-feedback'));pw.append(pwf);
  pwf.onsubmit=async e=>{e.preventDefault();try{await api(base,'master.password_save',csrf,Object.fromEntries(new FormData(pwf)));pwf.reset();status(pwf,'Senha alterada.')}catch(err){status(pwf,err.message,true)}};
  const two=card('Autenticação em duas etapas','Proteja seu acesso Master usando um aplicativo autenticador.');
  note(two,data.me.twofa_enabled?'2FA ativo':'2FA desativado',data.me.twofa_enabled?'Seu acesso solicita código do autenticador no login.':'Ative a proteção antes de acessar dados e credenciais sensíveis.');
  const tbtn=button('Configurar 2FA',async()=>{
   tbtn.disabled=true;
   try{const secret=await api(base,'master.twofa_setup',csrf,{});tbtn.disabled=false;
    modal('Ativar 2FA','Cadastre a chave no aplicativo autenticador. Guarde os códigos de recuperação ao confirmar.',(body,close)=>{
     const s=el('pre','mc-secret',secret.secret||'');body.append(s);
     const form=el('form','mc-form'),code=field(form,'Código de 6 dígitos','code','');code.required=true;code.maxLength=6;
     const submit=el('button','primary','Ativar autenticação');submit.type='submit';form.append(submit,el('p','mc-feedback'));body.append(form);
     form.onsubmit=async e=>{e.preventDefault();try{const r=await api(base,'master.twofa_enable',csrf,{code:code.value});form.replaceChildren(el('strong','','Guarde estes códigos de recuperação em local seguro:'),el('pre','mc-secret',(r.recovery_codes||[]).join('\n')),button('Concluir',()=>{close();account(host,csrf,base)}))}catch(err){status(form,err.message,true)}};
    });
   }catch(err){tbtn.disabled=false;note(two,'Erro',err.message)}
  },'primary');
  if(!data.me.twofa_enabled)two.append(tbtn);
  const list=card('Administradores Master','Contas com acesso à plataforma, separadas dos ADMs das empresas.');
  for(const u of data.masters){const row=el('div','mc-team-row'),info=el('div');
   info.append(el('strong','',u.name||u.email),el('span','',u.email+' · '+(u.status==='verified'?'Verificado':'Convite pendente')+' · '+(u.twofa_enabled?'2FA ativo':'2FA não ativo')));
   row.append(info);list.append(row)}
  const invite=card('Convidar ADM Master','Convite individual por e-mail, válido por 30 minutos. Exige sua senha atual.');
  const f=el('form','mc-form');
  field(f,'Nome','name');field(f,'E-mail','email','','email');field(f,'Sua senha Master','master_password','','password');
  const send=el('button','primary','Enviar convite');send.type='submit';f.append(send,el('p','mc-feedback'));invite.append(f);
  f.onsubmit=async e=>{e.preventDefault();send.disabled=true;
   try{const result=await api(base,'master.invite_additional',csrf,Object.fromEntries(new FormData(f)));status(f,'Convite enviado para '+result.email);f.reset()}
   catch(err){status(f,err.message,true)}finally{send.disabled=false}};
   grid.remove();
   window.TaskEscMasterTabs.mount(host,[
    {key:'perfil',label:'Minha conta',node:profile},
    {key:'senha',label:'Senha',node:pw},
    {key:'2fa',label:'Autenticação 2FA',node:two},
    {key:'masters',label:'Administradores Master',node:list},
    {key:'convites',label:'Convidar Master',node:invite}
   ],'conta');
 }
 function banner(host){
  host.replaceChildren();
  const card=el('section','panel mc-soon');card.append(el('div','mc-soon-icon','▧'),el('h2','','Banner Hero'),el('p','','Em breve'));
  host.append(card);
 }
 return {customers,plans,account,banner};
})();