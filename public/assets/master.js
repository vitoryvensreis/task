'use strict';
(async function(){
 const $=s=>document.querySelector(s),base=document.body.dataset.basePath||'';
 const endpoint=base+'/api.php?action=';
 const show=(s,yes)=>$(s)?.classList.toggle('hidden',!yes);
 const message=(s,text,bad=false)=>{const n=$(s);if(n){n.textContent=text;n.className=bad?'error':'success'}};
 const el=(tag,text='',cls='')=>{const n=document.createElement(tag);n.textContent=text;if(cls)n.className=cls;return n};
 async function request(action,body=null,csrf=''){
  const r=await fetch(endpoint+encodeURIComponent(action),{method:body===null?'GET':'POST',credentials:'same-origin',
   headers:{'Accept':'application/json',...(body!==null?{'Content-Type':'application/json'}:{}),...(csrf?{'X-CSRF-Token':csrf}:{})},
   ...(body!==null?{body:JSON.stringify(body)}:{})});
  const data=await r.json().catch(()=>({error:'Resposta inválida'}));
  if(!r.ok||data.error)throw Error(data.error||'Operação indisponível');return data;
 }
 const activation=document.body.dataset.activate;
 if(activation){
  show('#master-auth-surface',true);
  show('#master-activate',true);
  $('#master-activate-form').addEventListener('submit',async e=>{
   e.preventDefault();const b=e.submitter;b.disabled=true;
   try{const data=Object.fromEntries(new FormData(e.target));data.token=activation;
    await request('master.activate',data);message('#master-activate-message','Conta ativada! Entre pelo login do TaskEsc.');
    e.target.reset();history.replaceState(null,'',base+'/master');show('#master-login',true);
   }catch(err){message('#master-activate-message',err.message,true);}finally{b.disabled=false}
  });
  return;
 }
 let csrf;
 try{
  const auth=await request('auth.me');csrf=auth.csrf;
  if(!auth.user?.is_master||!(auth.user.role==='master'||!!auth.user.is_support)){show('#master-auth-surface',true);show('#master-login',true);return}
  const data=await request('master.workspaces');
  show('#master-shell',true);show('#master-panel',true);
  const workspaces=$('#master-workspaces'),admins=$('#master-administrators'),planPanel=$('#master-plans'),dashboard=$('#master-dashboard'),settings=$('#master-settings'),support=$('#master-support'),shell=$('#master-shell');
  const tabs=[...document.querySelectorAll('[data-master-tab]')];
  const tabRoutes={overview:'master',clients:'master/clientes',plans:'master/planos',workspaces:'master/workspaces',support:'master/suporte',settings:'master/configuracoes'};
  const currentTab=()=>Object.entries(tabRoutes).find(([,p])=>location.pathname.replace(/\/+$/,'').endsWith('/'+p))?.[0]||'overview';

  if(location.pathname.endsWith('/master.php'))history.replaceState(null,'',base+'/master'+location.search+location.hash);
  const clear=()=>{dashboard.replaceChildren();workspaces.replaceChildren();admins.replaceChildren();planPanel.replaceChildren();settings.replaceChildren();message('#master-panel-message','')};
  function supportCard(w){
   const wrap=el('article','','master-mini');
   wrap.append(el('h3',w.name),el('p',w.slug+' · '+w.status));
   const form=document.createElement('form');
   const label=el('label','Motivo do suporte (obrigatório)');
   const input=document.createElement('input');input.name='reason';input.required=true;input.minLength=8;input.maxLength=190;
   input.placeholder='Ex.: atendimento solicitado pelo cliente';label.append(input);form.append(label);
   const b=el('button','Entrar em modo suporte');b.type='submit';form.append(b);
   form.addEventListener('submit',async e=>{
    e.preventDefault();b.disabled=true;
    try{const resp=await request('master.start',{workspace_id:w.id,reason:input.value.trim()},csrf);location.href=resp.url||base+'/dashboard'}
    catch(err){message('#master-panel-message',err.message,true);b.disabled=false}
   });wrap.append(form);return wrap;
  }
  // Dashboard-only helper (tag, className, text); legacy el() is (tag, text, className).
  const mk=(tag,cls='',text='')=>el(tag,text,cls);
  const number=(x)=>new Intl.NumberFormat('pt-BR',{maximumFractionDigits:0}).format(Number(x)||0);
  function statCard(title,value,hint,disabled=false){
   const card=mk('article','master-kpi');
   card.append(mk('div','master-kpi-label',title),mk('div','master-kpi-number'+(disabled?' disabled':''),value),mk('div','master-kpi-hint',hint));
   return card;
  }
  function sectionHead(title,description,button){
   const h=mk('div','master-panel-header'),left=mk('div');
   left.append(mk('h3','',title),mk('small','',description));h.append(left);
   if(button){const b=mk('button','master-panel-link',button.label);b.type='button';b.onclick=()=>draw(button.tab).catch(err=>message('#master-panel-message',err.message,true));h.append(b)}
   return h;
  }
  function registrations(rows){
   const chart=mk('div','master-bars');chart.setAttribute('role','img');
   chart.setAttribute('aria-label','Novos usuários associados a workspaces nos últimos seis meses');
   const top=Math.max(1,...rows.map(r=>Number(r.users)||0));
   for(const r of rows){
    const col=mk('div','master-bar-column'),space=mk('div','master-bar-space'),bar=mk('div','master-bar');
    const value=Math.max(0,Number(r.users)||0);
    bar.style.height=value===0?'3px':Math.max(5,Math.round((value/top)*94))+'%';
    bar.title=(r.month||'')+': '+value+' novo(s) usuário(s)';
    bar.append(mk('span','master-bar-value',number(value)));
    space.append(bar);col.append(space,mk('span','',(r.month||'').slice(5)+'/'+(r.month||'').slice(2,4)));chart.append(col);
   }
   return chart;
  }
  function splitRow(label,value,top){
   const row=mk('div','master-split-row');
   row.append(mk('span','',label));
   const rail=mk('div','master-progress');const fill=mk('i');fill.style.width=Math.max(0,Math.min(100,top>0?(value/top)*100:0))+'%';rail.append(fill);
   row.append(rail,mk('span','master-split-total',number(value)));return row;
  }
  async function renderMasterDashboard(){
   const stats=await request('master.dashboard');
   dashboard.append(mk('p','master-description','Acompanhe os indicadores gerais da plataforma. Dados de tarefas e financeiro das empresas só aparecem em uma sessão de suporte autorizada.'));
   if(!stats.metrics?.billing_enabled){
    const note=mk('div','master-billing-note');
    note.append(mk('span','master-icon','ⓘ'));
    const copy=mk('div');copy.append(mk('b','','Vendas e receita de assinaturas ainda não disponíveis'),mk('span','','A cobrança SaaS não foi ativada. Os valores financeiros dos clientes não são receita da plataforma e não entram neste painel.'));note.append(copy);dashboard.append(note);
   }
   const m=stats.metrics||{};
   const grid=mk('div','master-stats');
   [
    ['VENDAS / RECEITA SAAS','Aguardando cobrança','Nenhuma transação SaaS registrada',true],
    ['USUÁRIOS ATIVOS',number(m.active_users),number(m.active_admins)+' administrador(es) · contas habilitadas',false],
    ['WORKSPACES',number(m.workspaces),'Ambiente multiempresa em homologação',false],
    ['PLANOS ATRIBUÍDOS',number(m.assigned_plans),number(m.public_plans)+' plano(s) público(s)',false],
    ['ATENDIMENTOS',number(m.support_threads),number(m.unread_support)+' notificação(ões) não lida(s)',false]
   ].forEach(([title,val,hint,disabled])=>grid.append(statCard(title,val,hint,disabled)));
   dashboard.append(grid);
   const analytics=mk('div','master-dashboard-grid');
   const activity=mk('section','panel');
   activity.append(sectionHead('Crescimento de usuários','Novos usuários vinculados aos workspaces nos últimos 6 meses'));
   activity.append(registrations(stats.monthly_registrations||[]));analytics.append(activity);
   const distribution=mk('section','panel');
   distribution.append(sectionHead('Usuários por perfil','Contas ativas vinculadas ao workspace'));
   const list=mk('div','master-split-list');
   const titles={owner:'Proprietário',admin:'Administradores',staff:'Equipe',client:'Clientes'};
   const roles=stats.users_by_role||[];
   const top=Math.max(1,...roles.map(r=>Number(r.total)||0));
   if(!roles.length)list.append(mk('div','master-empty','Nenhum usuário encontrado.'));
   else for(const r of roles)list.append(splitRow(titles[r.role]||r.role,Number(r.total)||0,top));
   distribution.append(list);analytics.append(distribution);
   dashboard.append(analytics);
   const lower=mk('div','master-dashboard-grid master-lower');
   const work=mk('section','panel');work.append(sectionHead('Workspaces cadastrados','Plano atribuído e quantidade de contas ativas',{label:'Gerenciar',tab:'workspaces'}));
   const wr=stats.recent_workspaces||[];
   if(wr.length){
    const wrap=mk('div','master-table-wrap'),table=mk('table','master-table'),head=mk('thead'),theadrow=mk('tr');
    for(const col of ['Workspace','Plano','Usuários','Estado'])theadrow.append(mk('th','',col));
    head.append(theadrow);const body=mk('tbody');
    for(const w of wr){const tr=mk('tr');tr.append(mk('td','',w.name||'—'),mk('td','',w.plan_name||'Sem plano'),mk('td','',number(w.active_users)));
      const state=mk('td'),badge=mk('span','master-status-chip',w.status==='staged'?'Em homologação':w.status||'—');state.append(badge);tr.append(state);body.append(tr)}
    table.append(head,body);wrap.append(table);work.append(wrap);
   }else work.append(mk('div','master-empty','Nenhum workspace cadastrado.'));
   lower.append(work);
   const plans=mk('section','panel');plans.append(sectionHead('Distribuição de planos','Planos cadastrados e quantidade de workspaces vinculados',{label:'Ver planos',tab:'plans'}));
   const dist=mk('div','master-split-list'),planRows=stats.workspaces_by_plan||[];
   if(planRows.length){const topPlan=Math.max(1,...planRows.map(p=>Number(p.total)||0));for(const p of planRows)dist.append(splitRow(p.name||'Sem nome',Number(p.total)||0,topPlan));}
   else dist.append(mk('div','master-empty','Nenhum plano encontrado.'));
   plans.append(dist);lower.append(plans);dashboard.append(lower);
   dashboard.append(mk('p','master-description','Atualizado em '+new Date((stats.as_of||'').replace(' ','T')+'Z').toLocaleString('pt-BR')+' · Somente dados cadastrais agregados.'));
  }
  async function draw(tab){
   if(tabRoutes[tab]&&!location.pathname.replace(/\/+$/,'').endsWith('/'+tabRoutes[tab]))
    history.replaceState(null,'',base+'/'+tabRoutes[tab]);
   clear();tabs.forEach(b=>{const active=b.dataset.masterTab===tab;b.classList.toggle('active',active);if(b.classList.contains('nav-btn')){b.setAttribute('aria-current',active?'page':'false')}});shell.classList.remove('menu-open');
   show('#master-dashboard',tab==='overview');show('#master-workspaces',tab==='workspaces');show('#master-administrators',tab==='clients');show('#master-plans',tab==='plans');show('#master-settings',tab==='settings');show('#master-support',tab==='support');
   if(tab!=='support')window.dispatchEvent(new Event('taskesc:leave-support'));
   $('#master-panel-title').textContent=({overview:'Dashboard',clients:'Clientes (ADMs)',workspaces:'Workspaces',plans:'Planos de acesso',support:'Atendimentos',settings:'Configurações'})[tab];
   $('#master-page-kicker').textContent=({overview:'VISÃO GERAL · PLATAFORMA',clients:'GESTÃO DE CONTAS',workspaces:'EMPRESAS E ACESSOS',plans:'ASSINATURAS · PLANOS',support:'SUPORTE · ATENDIMENTO',settings:'ADMINISTRAÇÃO · SISTEMA'})[tab];
   if(tab==='support'){window.dispatchEvent(new Event('taskesc:open-support'));return;}
   if(tab==='settings'){await window.TaskEscMasterSettings.render(settings,csrf,base);return;}
   if(tab==='overview'){await renderMasterDashboard();return;}
   if(tab==='plans'){
    const list=await request('master.plans');
    planPanel.append(el('p','Crie planos com módulos e limites de equipe. A disponibilidade pública apenas mostra o catálogo; nenhuma cobrança ou novo workspace será ativado automaticamente.','master-note'));
    const shell=el('div','','master-grid');planPanel.append(shell);
    const editor=el('form','','master-mini');editor.append(el('h3','Criar ou editar plano'));
    const field=(label,input)=>{const wrap=el('label',label);wrap.append(input);editor.append(wrap);return input};
    const inp=(type='text',required=true)=>{const n=document.createElement('input');n.type=type;n.required=required;return n};
    const name=field('Nome do plano',inp());name.maxLength=120;name.placeholder='Ex.: Starter';
    const code=field('Identificador único',inp());code.pattern='[a-z0-9][a-z0-9-]{2,99}';code.placeholder='starter';
    const price=field('Valor mensal/anual (R$)',inp('number'));price.step='0.01';price.min='0';price.value='0';
    const cycle=el('select');cycle.innerHTML='<option value="monthly">Mensal</option><option value="yearly">Anual</option>';field('Periodicidade',cycle);
    const limit=field('Máximo de usuários da equipe (sem contar proprietário)',inp('number',false));limit.min='1';limit.value='2';
    const unlimited=inp('checkbox',false);const unlimitedLabel=el('label','Equipe ilimitada');unlimitedLabel.append(unlimited);editor.append(unlimitedLabel);
    const checks={};
    const row=el('div','','master-mini');row.append(el('h4','Módulos incluídos'));
    for(const [id,label] of [['tasks','Gerenciamento de tasks'],['automations','Automações'],['contacts','Agenda de contatos']]){
     const cb=inp('checkbox',false);cb.checked=true;const l=el('label',label);l.prepend(cb);row.append(l);checks[id]=cb;
    }editor.append(row);
    const visible=inp('checkbox',false);const visLabel=el('label','Exibir este plano no catálogo público');visLabel.prepend(visible);editor.append(visLabel);
    const active=inp('checkbox',false);active.checked=true;const activeLabel=el('label','Plano ativo');activeLabel.prepend(active);editor.append(activeLabel);
    const save=el('button','Salvar plano');save.type='submit';editor.append(save);
    let editing=0;unlimited.onchange=()=>{limit.disabled=unlimited.checked;limit.required=!unlimited.checked};
    editor.addEventListener('submit',async e=>{
     e.preventDefault();save.disabled=true;
     try{await request('master.plan_save',{
      id:editing,name:name.value.trim(),code:code.value.trim(),price_cents:Math.round(Number(price.value)*100),
      billing_period:cycle.value,team_limit:Number(limit.value||0),unlimited_team:unlimited.checked?1:0,
      public_visible:visible.checked?1:0,active:active.checked?1:0,
      features:Object.fromEntries(Object.entries(checks).map(([id,cb])=>[id,cb.checked]))
     },csrf);message('#master-panel-message','Plano salvo com sucesso.');await draw('plans')}
     catch(e){message('#master-panel-message',e.message,true);save.disabled=false}
    });
    shell.append(editor);
    const saved=el('div','','master-mini');saved.append(el('h3','Planos cadastrados'));
    for(const p of list){
     const card=el('div','','master-mini');card.append(el('h4',p.name),el('p',p.code+' · R$ '+(Number(p.price_cents)/100).toFixed(2)+' · '+(p.team_limit===null?'Equipe ilimitada':p.team_limit+' usuários')+' · '+(p.public_visible?'Público':'Privado')+' · '+p.assigned_workspaces+' workspace(s)'));
     const edit=el('button','Editar plano');edit.type='button';edit.addEventListener('click',()=>{
       editing=Number(p.id);name.value=p.name;code.value=p.code;price.value=(Number(p.price_cents)/100).toFixed(2);cycle.value=p.billing_period;
       unlimited.checked=p.team_limit===null;limit.disabled=unlimited.checked;limit.required=!unlimited.checked;limit.value=p.team_limit??2;
       visible.checked=Number(p.public_visible)===1;active.checked=Number(p.active)===1;
       const feat=typeof p.features_json==='string'?JSON.parse(p.features_json):p.features_json;
       for(const [id,cb] of Object.entries(checks))cb.checked=Boolean(feat?.[id]);
       editor.scrollIntoView({behavior:'smooth',block:'start'});
     });card.append(edit);saved.append(card);
    }
    shell.append(saved);
    const assignment=el('form','','master-mini');assignment.append(el('h3','Vincular plano a workspace'),el('p','O workspace já existente permanece o único permitido.'));
    const wsSelect=el('select');
    for(const w of data.workspaces){const option=el('option',w.name);option.value=w.id;wsSelect.append(option)}
    const planSelect=el('select');
    for(const p of list.filter(v=>Number(v.active)===1)){const option=el('option',p.name);option.value=p.id;planSelect.append(option)}
    const wl=el('label','Workspace');wl.append(wsSelect);const pl=el('label','Plano');pl.append(planSelect);
    const assign=el('button','Aplicar plano');assign.type='submit';assignment.append(wl,pl,assign);
    assignment.onsubmit=async e=>{e.preventDefault();assign.disabled=true;try{await request('master.plan_assign',{workspace_id:Number(wsSelect.value),plan_id:Number(planSelect.value)},csrf);message('#master-panel-message','Plano atribuído ao workspace.');await draw('plans')}catch(err){message('#master-panel-message',err.message,true);assign.disabled=false}};
    planPanel.append(assignment);return;
   }
   if(tab==='clients'){
    const rows=await request('master.administrators');
    admins.append(el('p','Administradores proprietários ou autorizados dos workspaces. O acesso aos dados operacionais continua restrito ao suporte.'));
    if(!rows.length){admins.append(el('p','Nenhum administrador encontrado.'));return}
    const grid=el('div','','master-grid');admins.append(grid);
    for(const u of rows){
      const card=el('article','','master-mini');
      card.append(el('h3',u.name),el('p',u.email),el('small',u.workspace_name+' · '+(u.workspace_role==='owner'?'Proprietário':'Administrador')+' · '+(u.twofa_enabled?'2FA ativo':'2FA desativado')));
      const form=el('form','','master-reset-form');
      const title=el('h4','Redefinir autenticação em duas etapas');form.append(title);
      const reason=document.createElement('input');reason.placeholder='Justificativa (mín. 12 caracteres)';reason.required=true;reason.minLength=12;reason.maxLength=190;
      const pw=document.createElement('input');pw.type='password';pw.autocomplete='current-password';pw.placeholder='Sua senha ADM Master';pw.required=true;
      const confirm=el('button','Redefinir 2FA','danger');confirm.type='submit';
      form.append(reason,pw,confirm);
      form.addEventListener('submit',async e=>{
       e.preventDefault();
       if(!window.confirm('Desativar o 2FA desta conta? A ação será auditada e o usuário deverá configurá-lo novamente.'))return;
       confirm.disabled=true;
       try{await request('master.reset_2fa',{user_id:u.id,reason:reason.value,master_password:pw.value},csrf);message('#master-panel-message','2FA redefinido e registrado em auditoria.');await draw('clients')}
       catch(err){message('#master-panel-message',err.message,true);confirm.disabled=false}
      });
      card.append(form);grid.append(card);
    }
    return;
   }
   const grid=el('div','','master-grid');for(const w of data.workspaces)grid.append(supportCard(w));workspaces.append(grid);
   if(data.support_active){const link=el('a','Abrir sessão de suporte ativa');link.href=base+'/dashboard';workspaces.append(link)}
  }
  for(const t of tabs)t.addEventListener('click',()=>draw(t.dataset.masterTab).catch(e=>message('#master-panel-message',e.message,true)));
  $('#master-refresh').onclick=()=>draw(currentTab()).catch(e=>message('#master-panel-message',e.message,true));
  $('#master-menu').onclick=()=>shell.classList.toggle('menu-open');
  $('#master-nav-backdrop').onclick=()=>shell.classList.remove('menu-open');
  await draw(currentTab());
  window.addEventListener('popstate',()=>draw(currentTab()).catch(e=>message('#master-panel-message',e.message,true)));
  window.addEventListener('taskesc:close-support',()=>{if(currentTab()==='support')draw('overview').catch(e=>message('#master-panel-message',e.message,true))});
  if(currentTab()==='support')window.dispatchEvent(new Event('taskesc:open-support'));
  $('#master-logout').addEventListener('click',async()=>{try{await request('auth.logout',{})}finally{location.href=base+'/dashboard'}});
 }catch(err){show('#master-auth-surface',true);show('#master-login',true);message('#master-panel-message',err.message,true)}
})();
