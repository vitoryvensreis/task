'use strict';
window.MasterConsole=(()=>{
 const el=(tag,cls='',txt='')=>{const n=document.createElement(tag);if(cls)n.className=cls;if(txt!==null&&txt!==undefined)n.textContent=String(txt);return n};
 const fmt=n=>new Intl.NumberFormat('pt-BR').format(Number(n)||0);
 const money=n=>new Intl.NumberFormat('pt-BR',{style:'currency',currency:'BRL'}).format((Number(n)||0)/100);
 const currencyField=(form,label,key,amount)=>{const input=field(form,label,key,money(amount),'text');input.inputMode='numeric';input.autocomplete='off';input.addEventListener('input',()=>{const digits=input.value.replace(/\D/g,'').slice(0,11);input.value=money(Number(digits||0));input.setSelectionRange(input.value.length,input.value.length)});return input};
 const cents=input=>Number(input.value.replace(/\D/g,'')||0);
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
    note(result,'Cobranças Asaas da plataforma',
      'Cada assinatura é vinculada a uma empresa e conciliada por webhook. Os recebimentos SaaS ficam separados do financeiro operacional.');
    const table=el('div','mc-customer-list');
    for(const w of data.customers||[]){
     const staged=!!w.provisioning;
     const row=el('article','mc-customer-row'+(staged?' mc-customer-pending':''));
     const ident=el('div','mc-customer-ident');
     const circle=el('div','mc-avatar',String(w.name||'E').slice(0,1).toUpperCase());
     const person=el('div');person.append(el('strong','',w.name),
      el('span','',w.owner_name+' · '+w.owner_email));
     if(staged)person.append(el('span','mc-pending-state','● Pré-cadastro · Aguardando ativação'));
     if(!staged)person.append(el('span','mc-company-usage',
      fmt(w.clients_count)+' clientes · '+fmt(w.projects_count)+' projetos · '+
      fmt(w.demands_count)+' tarefas · '+fmt(w.automations_count)+' automações'));
     ident.append(circle,person);row.append(ident);
     const plan=el('div','mc-cell');plan.append(el('small','','Plano'),el('strong','',w.plan_name||'Sem plano'));row.append(plan);
     const team=el('div','mc-cell');team.append(el('small','','Equipe'),el('strong','',fmt(w.team_users)+' membros'));row.append(team);
     const bill=el('div','mc-cell');bill.append(el('small','',staged?'Ambiente':'Assinatura'),
      el('span','mc-chip',staged?'Não ativado':'Consultar'));row.append(bill);
     const actions=el('div','mc-row-actions');
     if(staged){
      const detail=button('Ver preparação',()=>showPendingCustomer(w),'secondary');
      detail.title='Ambiente em preparação. Login independente será liberado após isolamento.';
      actions.append(detail);
     }else{
      const detail=button('Gerenciar',()=>showCustomer(w,admins,plans,load,base,csrf),'primary');
      const access=button('↗',()=>startSupport(w,base,csrf),'secondary');access.title='Entrar no workspace (suporte)';
      actions.append(detail,access);
     }
     row.append(actions);table.append(row);
    }
    if(!data.customers?.length)result.append(el('div','master-empty','Nenhum cliente encontrado para essa busca.'));
    result.append(table);
   }catch(e){result.replaceChildren(el('p','mc-feedback error',e.message))}
  }
  await load();
 }
 function showPendingCustomer(w){
  modal('Workspace Gabriella · preparação',
   'Pré-cadastro persistido no TaskEsc. O segundo workspace operacional ainda não foi ativado.',
   (body)=>{
    const stats=el('div','mc-profile-stats');
    for(const [k,v] of [['Proprietária',w.owner_name],['Clientes','0'],['Tasks','0'],['Dados financeiros','0']]){
     const c=el('div');c.append(el('small','',k),el('strong','',v));stats.append(c);
    }
    body.append(stats);
    const cfg=w.initial_config||{};
    const modules=Object.entries(cfg.modules||{}).filter(([,v])=>v).map(([k])=>({tasks:'Tarefas',automations:'Automações',contacts:'Contatos'})[k]||k);
    const board=(cfg.kanban_columns||[]).join('  →  ');
    note(body,'Configuração inicial planejada',
      'Módulos: '+(modules.join(', ')||'A definir')+'. Kanban: '+(board||'A definir')+'. Plano: escolha pendente no ADM Master.');
    note(body,'Acesso não ativado',
      'Gabriella permanece no workspace original até a ativação com isolamento multiempresa. Nenhum cliente, arquivo, tarefa ou lançamento foi copiado.');
   });
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
  modal('Gerenciar '+w.name,'Dados da empresa, equipe, acesso e assinatura.',(body,close)=>{
   async function render(){
    body.replaceChildren(el('p','master-description','Carregando informações do workspace…'));
    let d;
    try{d=await api(base,'master.workspace_members',csrf,null,{workspace_id:Number(w.id)});}
    catch(error){body.replaceChildren(el('p','mc-feedback error',error.message));return;}
    body.replaceChildren();
    const company=el('section','mc-detail-pane');
    const header=el('div','mc-profile-stats');
    const quota=d.team_limit===null?'Ilimitada':fmt(d.team_used)+' / '+fmt(d.team_limit);
    for(const [title,value] of [
      ['Usuários ativos',fmt(w.active_users)],
      ['Equipe / limite',quota],
      ['Clientes',fmt(w.clients_count)],
      ['Tarefas',fmt(w.demands_count)],
      ['Projetos',fmt(w.projects_count)],
      ['Automações',fmt(w.automations_count)]
    ]){
     const cell=el('div');cell.append(el('small','',title),el('strong','',value));header.append(cell);
    }
    company.append(header);
    const edit=el('form','mc-form');edit.append(el('h3','','Dados cadastrais'));
    const name=field(edit,'Nome da empresa','name',d.workspace.name);
    const email=field(edit,'E-mail comercial','company_email',d.workspace.company_email,'email');
    const phone=field(edit,'Telefone comercial','company_phone',d.workspace.company_phone,'tel');
    name.required=true;name.maxLength=190;
    email.maxLength=190;phone.maxLength=25;
    note(edit,'Identidade do proprietário',
      d.workspace.owner_name+' · '+d.workspace.owner_email+' · Slug: '+d.workspace.slug+
      '. O cadastro comercial não altera o login do proprietário nem o endereço do workspace.');
    const save=el('button','primary','Salvar dados da empresa');save.type='submit';
    edit.append(save,el('p','mc-feedback'));company.append(edit);
    edit.onsubmit=async event=>{
     event.preventDefault();save.disabled=true;
     try{
      const result=await api(base,'master.workspace_profile_save',csrf,{
       workspace_id:Number(w.id),name:name.value.trim(),
       company_email:email.value.trim(),company_phone:phone.value.trim()
      });
      w.name=result.name;
      status(edit,'Dados atualizados com sucesso.');
      await refresh();
     }catch(error){status(edit,error.message,true)}
     finally{save.disabled=false}
    };
    const access=el('section','mc-detail-pane');
    access.append(el('h3','','Acesso de suporte'),
      el('p','master-description','Acesso temporário ao ambiente com justificativa e histórico de auditoria.'));
    access.append(button('↗ Entrar no workspace',()=>{close();startSupport(w,base,csrf)},'primary'));
    const plan=el('section','mc-detail-pane');
    const form=el('form','mc-form');form.append(el('h3','','Plano e limites'));
    const planLabel=el('label','mc-field'),select=el('select','select');
    for(const p of plans.filter(x=>Number(x.active)===1)){
     const op=el('option','',p.name+' · '+money(p.price_cents)+' / '+(cycles[p.billing_period]||p.billing_period));
     op.value=p.id;op.selected=Number(p.id)===Number(w.plan_id);select.append(op);
    }
    planLabel.append(el('span','','Plano atribuído'),select);form.append(planLabel);
    const limits=el('div','mc-profile-stats');
    const used=el('div');used.append(el('small','','Usuários adicionais ativos'),el('strong','',fmt(d.team_used)));
    const allowed=el('div');allowed.append(el('small','','Limite atual'),el('strong','',d.team_limit===null?'Ilimitado':fmt(d.team_limit)));
    limits.append(used,allowed);form.append(limits);
    note(form,'Limites de acesso','Clientes ilimitados. Proprietário ADM não ocupa vaga; ADMs adicionais e equipe usam as vagas contratadas.');
    const submit=el('button','primary','Aplicar plano');submit.type='submit';
    form.append(submit,el('p','mc-feedback'));plan.append(form);
    form.onsubmit=async event=>{
     event.preventDefault();submit.disabled=true;
     try{await api(base,'master.plan_assign',csrf,{workspace_id:Number(w.id),plan_id:Number(select.value)});
      status(form,'Plano aplicado.');await refresh();await render();
     }catch(error){status(form,error.message,true)}
     finally{submit.disabled=false}
    };
    const overrideForm=el('form','mc-form mc-limit-form');
    overrideForm.append(el('h3','','Limite exclusivo do workspace'));
    const override=field(overrideForm,'Máximo de usuários adicionais','team_limit_override',
      d.team_limit_override===null?'':String(d.team_limit_override),'number');
    override.min='1';override.max='100000';override.step='1';
    override.placeholder='Vazio: seguir o plano';
    note(overrideForm,'Personalização do limite',
     'Defina um número para esta empresa ou deixe o campo vazio para usar o limite do plano. O proprietário não entra na contagem.');
    const saveLimit=el('button','secondary','Salvar limite exclusivo');
    saveLimit.type='submit';overrideForm.append(saveLimit,el('p','mc-feedback'));
    overrideForm.onsubmit=async event=>{
     event.preventDefault();saveLimit.disabled=true;
     try{
      await api(base,'master.workspace_limit_save',csrf,
       {workspace_id:Number(w.id),team_limit_override:override.value.trim()});
      await refresh();await render();
     }catch(error){status(overrideForm,error.message,true)}
     finally{saveLimit.disabled=false}
    };
    if(Number(d.extra_user_price_cents)>0){
     const extras=el('form','mc-form mc-seats-form');
     extras.append(el('h3','','Adquirir vagas extras de ADM ou equipe'));
     const qty=field(extras,'Quantidade adicional contratada','extra_seats',String(d.extra_seats||0),'number');
     qty.min='0';qty.max='100000';qty.step='1';qty.required=true;
     const selected=plans.find(p=>Number(p.id)===Number(w.plan_id));
     const preview=el('p','master-description');
     const update=()=>{const q=Math.max(0,Number(qty.value)||0);preview.textContent='Plano '+money(selected?.price_cents||0)+' + '+q+' × '+money(d.extra_user_price_cents)+' = '+money(Number(selected?.price_cents||0)+q*Number(d.extra_user_price_cents))+' / '+(cycles[selected?.billing_period]||'período')+'. Total de vagas de equipe: '+(Number(d.plan_team_limit||0)+q)+'.'};
     qty.addEventListener('input',update);update();
     const confirm=field(extras,'Digite ATUALIZAR para confirmar o novo valor','confirmation','');
     confirm.required=true;confirm.autocomplete='off';
     note(extras,'Assinatura recorrente','O Asaas altera somente cobranças futuras. Cobranças já emitidas não serão modificadas.');
     const buy=el('button','primary','Atualizar vagas e recorrência');buy.type='submit';
     extras.append(preview,buy,el('p','mc-feedback'));
     extras.onsubmit=async event=>{
      event.preventDefault();if(!window.confirm('Confirma '+preview.textContent+'?'))return;
      buy.disabled=true;
      try{await api(base,'master.asaas_seats',csrf,{workspace_id:Number(w.id),extra_seats:Number(qty.value),confirmation:confirm.value});await refresh();await render();}
      catch(err){status(extras,err.message,true);buy.disabled=false}
     };
     plan.append(extras);
    }else plan.append(overrideForm);
    const team=el('section','mc-detail-pane');
    team.append(el('h3','','Administradores e equipe'));
    note(team,'Um proprietário por workspace',
      'O proprietário não pode ser suspenso por este painel. Os membros e funções personalizadas são gerenciados pelo ADM da empresa.');
    for(const member of d.members||[]){
     const row=el('div','mc-team-row'),who=el('div');
     const isOwner=Number(member.id)===Number(w.owner_user_id);
     const role=isOwner?'Proprietário':member.role==='admin'?'ADM':member.custom_role_name||member.role;
     const active=Number(member.active)===1&&member.status==='active';
     who.append(el('strong','',member.name),
      el('span','',member.email+' · '+role+' · '+(active?'Ativo':'Inativo')+
        (member.twofa_enabled?' · 2FA ativo':'')));
     row.append(who);
     const controls=el('div','mc-actions');
     if(member.role==='admin'||isOwner){
      controls.append(button('Redefinir 2FA',()=>resetTwofa(member,base,csrf),'secondary'));
     }
     if(member.role==='admin'&&!isOwner){
      controls.append(button(active?'Suspender ADM':'Reativar ADM',
       ()=>changeAdminAccess(member,w,active,render,base,csrf),'secondary'));
     }
     row.append(controls);team.append(row);
    }
    const integrationsPane=el('section','mc-detail-pane');
    integrationsPane.append(el('h3','','Integrações desta empresa'));
    const integrations=d.integrations||{};
    const integrationStats=el('div','mc-profile-stats');
    for(const [label,value] of [
     ['Google Drive',integrations.drive_ready?'Conectado':'Pendente'],
               ['Arquivos vinculados',fmt(integrations.file_count||0)],
     ['Automações',fmt(integrations.automation_count||0)],
     ['Notificações',fmt(integrations.notification_count||0)]
    ]){
     const cell=el('div');cell.append(el('small','',label),el('strong','',value));
     integrationStats.append(cell);
    }
    integrationsPane.append(integrationStats);
    const googleAccounts=(integrations.google_accounts||[]).map(a=>
      (a.email||'Conta Google')+' · '+(a.status==='connected'?'Conectada':a.status));
    note(integrationsPane,'Contas Google deste workspace',
      googleAccounts.length?googleAccounts.join(' | '):'Nenhuma conta Google conectada nesta empresa.');
    note(integrationsPane,'WhatsApp e operações externas',
      'WPPConnect e algumas rotinas ainda usam configuração global do ADM Master. A operação independente por empresa só poderá ser habilitada depois do isolamento completo e da homologação.');
    const billing=el('section','mc-detail-pane');
    billing.append(el('h3','','Assinatura e pagamentos'));
    const b=d.billing||{},sub=(b.subscriptions||[])[0],payRows=b.payments||[];
    note(billing,b.enabled?'Asaas habilitado':'Asaas indisponível',
      b.enabled?'Assinaturas da plataforma, independentes do financeiro desta empresa.':'Configure o Asaas em Integrações para gerar cobranças reais ou de teste.');
    const meta=el('div','mc-profile-stats');
    const billingPlan=el('div');billingPlan.append(el('small','','Plano cadastrado'),el('strong','',d.plan_name||'Sem plano'));
    const billingState=el('div');billingState.append(el('small','','Situação financeira'),el('strong','',sub?.billing_status||'Sem cobrança'));
    meta.append(billingPlan,billingState);billing.append(meta);
    if(sub){
     const info=el('p','master-description','Assinatura '+(sub.asaas_subscription_id||'aguardando confirmação')+' · '+money(sub.amount_cents)+' / '+(cycles[sub.cycle]||sub.cycle)+' · '+sub.request_status);
     billing.append(info);
     const reload=button('↻ Consultar cobranças no Asaas',async()=>{
      reload.disabled=true;try{await api(base,'master.asaas_refresh',csrf,{workspace_id:Number(w.id)});await render();}
      catch(e){status(billing,e.message,true);reload.disabled=false}
     });
     billing.append(reload,el('p','mc-feedback'));
     if(sub.asaas_subscription_id&&['created','inactive'].includes(sub.request_status)){
      const paused=sub.request_status==='inactive';
      const verb=paused?'RETOMAR':'PAUSAR',caption=paused?'Retomar cobrança recorrente':'Pausar recorrência';
      const control=button(caption,async()=>{
       const confirmation=window.prompt('Confirme digitando '+verb+'. '+(paused?'O Asaas criará a próxima cobrança com vencimento em 2 dias.':'Nenhuma nova cobrança será gerada enquanto a assinatura estiver pausada.'));
       if(confirmation!==verb)return;
       control.disabled=true;
       try{await api(base,'master.asaas_toggle',csrf,{workspace_id:Number(w.id),operation:paused?'resume':'pause',confirmation});await render()}
       catch(e){status(billing,e.message,true);control.disabled=false}
      },'secondary');
      billing.append(control);
     }

    }else if(b.enabled){
     const currentPlan=plans.find(p=>Number(p.id)===Number(w.plan_id));
     if(currentPlan&&currentPlan.code!=='legacy-unlimited'&&Number(currentPlan.price_cents)>=100){
      const payForm=el('form','mc-form');
      payForm.append(el('h4','','Criar assinatura recorrente Asaas'));
      const payer=field(payForm,'Nome completo ou razão social','payer_name',d.workspace.name);payer.required=true;
      const mail=field(payForm,'E-mail do pagador','payer_email',d.workspace.company_email||d.workspace.owner_email,'email');mail.required=true;
      const doc=field(payForm,'CPF ou CNPJ do pagador','cpf_cnpj','');doc.required=true;
      const confirm=field(payForm,'Digite COBRAR para confirmar a assinatura','confirmation','');confirm.required=true;confirm.autocomplete='off';
      const total=Number(currentPlan.price_cents)+Number(d.extra_seats||0)*Number(currentPlan.extra_user_price_cents||0);
      note(payForm,'Valor e recorrência','Será gerada assinatura de '+money(total)+' a cada '+(cycles[currentPlan.billing_period]||currentPlan.billing_period)+', com '+Number(d.extra_seats||0)+' vagas extras. Primeiro vencimento em dois dias.');
      const create=el('button','primary','Gerar assinatura Asaas');create.type='submit';payForm.append(create,el('p','mc-feedback'));
      payForm.onsubmit=async event=>{
       event.preventDefault();
       if(!window.confirm('Confirma a geração de uma assinatura de '+money(total)+' com cobranças recorrentes no Asaas?'))return;
       create.disabled=true;
       try{await api(base,'master.asaas_start',csrf,{
        workspace_id:Number(w.id),payer_name:payer.value.trim(),payer_email:mail.value.trim(),
        cpf_cnpj:doc.value.trim(),confirmation:confirm.value.trim()
       });await render();}
       catch(e){status(payForm,e.message,true);create.disabled=false}
      };
      billing.append(payForm);
     }else note(billing,'Plano legado preservado','O plano ilimitado legado não gera cobrança. Atribua um plano pago no ADM Master somente quando houver contratação.');
    }
    if(payRows.length){
     const list=el('div','mc-form');list.append(el('h4','','Cobranças recebidas por webhook ou conciliação'));
     for(const payment of payRows){
      const row=el('div','mc-team-row'),info=el('div');
      info.append(el('strong','',money(payment.amount_cents)+' · '+payment.status),
       el('span','','Vencimento: '+(payment.due_date||'—')+' · '+payment.asaas_payment_id));
      row.append(info);
      if(payment.invoice_url&&/^https:\/\//.test(payment.invoice_url)){
       const url=el('a','secondary','Abrir fatura');url.href=payment.invoice_url;url.target='_blank';url.rel='noopener noreferrer';
       row.append(url);
      }
      list.append(row);
     }
     billing.append(list);
    }
    body.append(access);
    window.TaskEscMasterTabs.mount(body,[
     {key:'empresa',label:'Empresa',node:company},
     {key:'plano',label:'Plano e limites',node:plan},
     {key:'equipe',label:'Administradores',node:team},
     {key:'suporte',label:'Acesso ao workspace',node:access},
     {key:'integracoes',label:'Integrações',node:integrationsPane},
     {key:'assinatura',label:'Assinatura',node:billing}
    ],'workspace-'+w.id);
   }
   render().catch(error=>body.replaceChildren(el('p','mc-feedback error',error.message)));
  });
 }
 function changeAdminAccess(member,w,currentlyActive,reload,base,csrf){
  modal((currentlyActive?'Suspender':'Reativar')+' ADM · '+member.name,
   'Ação administrativa com confirmação da senha Master e registro do motivo.',(body,close)=>{
    const form=el('form','mc-form');
    const why=field(form,'Motivo da alteração (mínimo 12 caracteres)','reason','');
    why.required=true;why.minLength=12;why.maxLength=190;
    const pw=field(form,'Senha atual do ADM Master','master_password','','password');pw.required=true;
    const submit=el('button','primary',currentlyActive?'Confirmar suspensão':'Confirmar reativação');
    submit.type='submit';form.append(submit,el('p','mc-feedback'));body.append(form);
    form.onsubmit=async event=>{
     event.preventDefault();submit.disabled=true;
     try{
      await api(base,'master.admin_access',csrf,{
       workspace_id:Number(w.id),user_id:Number(member.id),
       active:!currentlyActive,reason:why.value.trim(),master_password:pw.value
      });
      close();await reload();
     }catch(error){status(form,error.message,true)}
     finally{submit.disabled=false}
    };
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
    ul.append(el('span','','✓ '+(p.team_limit===null?'Equipe ilimitada':'Até '+p.team_limit+' membros de equipe')));
     ul.append(el('span','','✓ Clientes ilimitados'));
     if(p.team_limit!==null&&Number(p.extra_user_price_cents)>0)
      ul.append(el('span','','+ '+money(p.extra_user_price_cents)+' por vaga extra / '+(cycles[p.billing_period]||p.billing_period)));
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
   const name=field(form,'Nome do plano','name',plan?.name||'');
   name.required=true;
   if(plan?.code)note(form,'ID interno automático','Gerado pelo servidor: '+plan.code);
   const price=currencyField(form,'Valor por período (R$)','price',Number(plan?.price_cents||0));
   const extraPrice=currencyField(form,'Valor por usuário extra / período (R$)','extra_user_price',Number(plan?.extra_user_price_cents||0));
   note(form,'Quem ocupa vaga','Clientes ilimitados. O proprietário ADM não ocupa vaga; outros ADMs e membros da equipe ocupam vagas. O valor extra segue a periodicidade do plano.');
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
      id:Number(plan?.id||0),name:name.value.trim(),
      price_cents:cents(price),extra_user_price_cents:cents(extraPrice),billing_period:cycle.value,
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
  const avatarBlock=el('form','ts-avatar-form mc-form');
  avatarBlock.enctype='multipart/form-data';
  const avatarPreview=el('div','ts-avatar-preview'),avatarImage=el('img');
  avatarImage.alt='Foto de perfil';avatarImage.hidden=!data.me.avatar_url;
  if(data.me.avatar_url)avatarImage.src=data.me.avatar_url;
  avatarPreview.append(avatarImage);
  const avatarLabel=el('label','ts-avatar-label','Foto de perfil (JPEG, PNG ou WebP, até 10 MB)');
  const avatarInput=el('input','input');avatarInput.type='file';avatarInput.name='avatar';
  avatarInput.accept='image/jpeg,image/png,image/webp';avatarInput.required=true;
  avatarLabel.append(avatarInput);
  const avatarBtn=el('button','secondary','Salvar foto');avatarBtn.type='submit';
  const avatarInfo=el('p','mc-feedback');
  avatarBlock.append(avatarPreview,avatarLabel,avatarBtn,avatarInfo);
  avatarBlock.onsubmit=async event=>{
   event.preventDefault();
   if(avatarInput.files?.[0]?.size>10*1024*1024){avatarInfo.textContent='A foto deve ter até 10 MB.';return;}
   avatarBtn.disabled=true;
   try{
    const response=await fetch(base+'/api.php?action=account.avatar_upload',{
     method:'POST',body:new FormData(avatarBlock),credentials:'same-origin',
     headers:{'X-CSRF-Token':csrf}});
    const json=await response.json().catch(()=>({error:'Resposta inválida'}));
    if(!response.ok||json.error)throw Error(json.error||'Falha no envio');
    avatarImage.hidden=false;avatarImage.src=json.avatar_url;avatarInput.value='';
    avatarInfo.textContent='Foto atualizada.';
    window.dispatchEvent(new CustomEvent('taskesc:avatar-updated',{detail:json.avatar_url}));
   }catch(error){avatarInfo.textContent=error.message}finally{avatarBtn.disabled=false}
  };
  profile.append(avatarBlock);
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