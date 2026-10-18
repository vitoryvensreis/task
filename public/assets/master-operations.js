'use strict';
window.TaskEscMasterOperations=(()=>{
 const el=(tag,cls='',value='')=>{const n=document.createElement(tag);if(cls)n.className=cls;n.textContent=String(value??'');return n};
 const request=async(base,action,p={},csrf='',method='GET')=>{
  const query=method==='GET'?'&'+new URLSearchParams(p):'';
  const response=await fetch(base+'/api.php?action='+encodeURIComponent(action)+query,{
   method,credentials:'same-origin',cache:'no-store',
   headers:{'Accept':'application/json',...(method==='POST'?{'X-CSRF-Token':csrf,'Content-Type':'application/json'}:{})},
   ...(method==='POST'?{body:JSON.stringify(p)}:{})});
  const out=await response.json().catch(()=>({error:'Resposta inválida do servidor.'}));
  if(!response.ok||out.error)throw Error(out.error||'Não foi possível carregar dados.');return out;
 };
 const num=n=>new Intl.NumberFormat('pt-BR').format(Number(n)||0);
 const header=(host,title,subtitle)=>{
  const div=el('div','master-operation-head');div.append(el('h3','',title),el('p','master-description',subtitle));host.append(div);
 };
 const card=(host,title,value,detail)=>{
  const item=el('article','master-kpi');
  item.append(el('div','master-kpi-label',title),el('div','master-kpi-number',value),el('div','master-kpi-hint',detail));
  host.append(item);
 };
 const panel=(host,title,description)=>{
  const node=el('section','panel master-ops-panel'),head=el('div','master-panel-header'),col=el('div');
  col.append(el('h3','',title),el('small','',description));head.append(col);node.append(head);host.append(node);return node;
 };
 function table(host,headers,rows){
  if(!rows.length){host.append(el('div','master-empty','Nenhum registro neste período.'));return}
  const box=el('div','master-table-wrap'),t=el('table','master-table'),head=el('thead'),tr=el('tr');
  headers.forEach(h=>tr.append(el('th','',h)));head.append(tr);t.append(head);
  const body=el('tbody');
  rows.forEach(cells=>{const row=el('tr');cells.forEach(value=>row.append(el('td','',value)));body.append(row)});
  t.append(body);box.append(t);host.append(box);
 }
 const date=s=>s?String(s).slice(0,16):'—';
 async function health(host,csrf,base){
  host.replaceChildren();header(host,'Saúde & Logs','Diagnóstico das integrações, tarefas do servidor e auditoria. Acesso exclusivo do ADM Master.');
  const toolbar=el('div','master-ops-toolbar'),reload=el('button','secondary','↻ Atualizar'),state=el('span','master-operation-state');
  toolbar.append(reload,state);host.append(toolbar);
  const content=el('div');host.append(content);
  const load=async()=>{
   state.textContent='Carregando…';
   const [h,logs]=await Promise.all([
    request(base,'master.health'),request(base,'master.logs',{limit:75})]);
   content.replaceChildren();
   const summary=el('div','master-stats'),items=h.items||[];
   card(summary,'ESTADO GERAL',String(h.status||'—').toUpperCase(),'Atualizado em '+date(h.checked_at));
   card(summary,'SERVIÇOS',num(items.length),'Itens monitorados');
   card(summary,'ATENÇÃO',num(items.filter(x=>x.status==='warn').length),'Avisos');
   card(summary,'FALHAS',num(items.filter(x=>x.status==='error').length),'Serviços com erro');
   const overview=panel(content,'Visão geral','Estado de funcionamento dos serviços monitorados.');overview.append(summary);
   const diagnostics=panel(content,'Diagnóstico da plataforma','Situação atual dos serviços monitorados.');
   const rows=el('div','master-ops-rows');
   for(const item of items){
    const row=el('div','master-ops-row'),text=el('div'),status=el('span','master-status-chip',item.status||'—');
    text.append(el('strong','',item.label||''),el('small','',item.detail||''));row.append(text,status);rows.append(row);
   }diagnostics.append(rows);
   const tabs=el('div','master-ops-tabs'),results=el('div');
   const sources=[
    ['errors','Erros',logs.errors||[],['Data','Origem','Mensagem','Situação'],r=>[date(r.created_at),r.context||'—',r.message||'—',r.resolved_at?'Resolvido':'Aberto']],
    ['audit','Auditoria',logs.audit||[],['Data','Usuário','Ação','Entidade'],r=>[date(r.created_at),r.user_name||'—',r.action||'—',r.entity||'—']],
    ['mail','E-mails',logs.mail||[],['Data','Destinatário','Assunto','Estado'],r=>[date(r.created_at),r.recipient||'—',r.subject||'—',r.status||'—']],
    ['push','Push',logs.push||[],['Data','Usuário','Título','Estado'],r=>[date(r.created_at),r.user_name||'—',r.title||'—',r.status||'—']]
   ];
   for(const [id,label,rowsData,headers,convert] of sources){
    const b=el('button','secondary',label+' · '+rowsData.length);b.type='button';
    b.onclick=()=>{
     tabs.querySelectorAll('button').forEach(button=>button.classList.toggle('active',button===b));
     results.replaceChildren();table(results,headers,rowsData.map(convert));
     if(id==='errors'){
      const unresolved=rowsData.filter(r=>!r.resolved_at).slice(0,20);
      const controls=el('div','master-ops-errors');
      for(const error of unresolved){
       const btn=el('button','secondary','Resolver #'+error.id);btn.type='button';
       btn.onclick=async()=>{if(!confirm('Marcar erro como resolvido?'))return;btn.disabled=true;try{await request(base,'master.error_resolve',{id:Number(error.id)},csrf,'POST');await load()}catch(e){btn.disabled=false;state.textContent=e.message}};
       controls.append(btn);
      }
      results.append(controls);
     }
    };tabs.append(b);
   }
   const registry=panel(content,'Registros do sistema','Eventos, falhas e históricos internos da plataforma.');
   registry.append(tabs,results);tabs.querySelector('button')?.click();
   const issues=(h.items||[]).filter(x=>x.status==='error'||x.status==='warn');
   if(issues.length){
    const warning=el('div','mc-note');
    warning.append(el('b','','Por que o estado geral indica atenção ou erro?'),
      el('span','',issues.map(x=>x.label+': '+x.detail).join(' · ')));
    overview.prepend(warning);
   }
   const audits=panel(content,'Auditoria por cliente e ação','Registros de administração e operações de todos os workspaces, sem expor conteúdo de tarefas ou mensagens.');
   const controls=el('div','master-ops-toolbar'),ws=el('select','select'),actionInput=el('input','input');
   actionInput.type='search';actionInput.placeholder='Filtrar por ação (ex.: plan.saved)';
   ws.setAttribute('aria-label','Filtrar por workspace');
   controls.append(ws,actionInput);
   const run=el('button','secondary','Pesquisar logs');run.type='button';controls.append(run);
   audits.append(controls);const auditTable=el('div');audits.append(auditTable);
   const populate=async()=>{
    auditTable.replaceChildren(el('p','master-description','Pesquisando auditoria…'));
    try{
     const d=await request(base,'master.audit',{
      workspace_id:ws.value||0,action:actionInput.value.trim()
     });
     if(!ws.options.length){
      const all=el('option','','Todos os clientes');all.value='0';ws.append(all);
      for(const w of d.workspaces||[]){const op=el('option','',w.name);op.value=w.id;ws.append(op)}
     }
     auditTable.replaceChildren();
     table(auditTable,['Data','Workspace','Usuário','Ação','Entidade'],
      (d.entries||[]).map(x=>[date(x.created_at),x.workspace_name||'Plataforma',
       x.user_name||'Sistema',x.action||'—',(x.entity||'—')+' #'+(x.entity_id||0)]));
    }catch(error){auditTable.replaceChildren(el('div','master-description',error.message))}
   };
   run.onclick=populate;actionInput.onkeydown=e=>{if(e.key==='Enter'){e.preventDefault();populate()}};
   await populate();
   window.TaskEscMasterTabs.mount(content,[
    {key:'resumo',label:'Resumo',node:overview},
    {key:'servicos',label:'Serviços',node:diagnostics},
    {key:'registros',label:'Registros',node:registry},
    {key:'auditoria',label:'Auditoria por cliente',node:audits}
   ],'saude');
   state.textContent='Atualizado em '+date(h.checked_at);
  };
  reload.onclick=()=>load().catch(e=>state.textContent=e.message);
  await load();
 }
 return {health};
})();