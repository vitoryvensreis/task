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
 async function ai(host,csrf,base){
  host.replaceChildren();header(host,'Monitoramento de IA','Consumo de Gemini e Groq, uso de tokens e falhas de comunicação. Exclusivo do ADM Master.');
  const filter=el('div','master-ops-toolbar'),period=el('select','select'),provider=el('select','select'),refresh=el('button','secondary','↻ Atualizar');
  for(const days of [1,7,14,30]){const option=el('option','',days===1?'Hoje':'Últimos '+days+' dias');option.value=days;option.selected=days===7;period.append(option)}
  for(const [value,label] of [['all','Todos os provedores'],['gemini','Gemini'],['groq','Groq']]){const option=el('option','',label);option.value=value;provider.append(option)}
  const label=(name,input)=>{const node=el('label','ms-field');node.append(el('span','',name),input);return node};
  filter.append(label('Período',period),label('Provedor',provider),refresh);host.append(filter);
  const content=el('div');host.append(content);
  const load=async()=>{
   content.replaceChildren(el('p','master-description','Consultando métricas…'));
   const d=await request(base,'master.ai_usage',{days:period.value,provider:provider.value});
   content.replaceChildren();
   const stats=el('div','master-stats');
   card(stats,'CHAMADAS',num(d.requests),'HTTP aos provedores');
   card(stats,'TOKENS',num(d.total_tokens),'Informados pelos modelos');
   card(stats,'FALHAS',num(Number(d.http_errors||0)+Number(d.transport_errors||0)),'HTTP e conexão');
   card(stats,'LATÊNCIA MÉDIA',d.avg_latency_ms!==null?(Number(d.avg_latency_ms)/1000).toFixed(2)+' s':'—',num(d.latency_samples)+' amostras');
   content.append(stats);
   const grid=el('div','master-dashboard-grid');
   const daily=panel(grid,'Solicitações por dia','Volume de chamadas e falhas HTTP por dia.');
   const values=d.daily||[],max=Math.max(1,...values.map(x=>Number(x.requests)||0));
   const bars=el('div','master-bars master-ops-bars');
   bars.style.gridTemplateColumns='repeat('+Math.max(values.length,1)+',minmax(0,1fr))';
   for(const x of values){
    const col=el('div','master-bar-column'),space=el('div','master-bar-space');
    const bar=el('div','master-bar');bar.style.height=Math.max(3,Math.round((Number(x.requests)||0)/max*93))+'%';
    bar.title=x.date+': '+num(x.requests)+' chamada(s), '+num(x.errors)+' falha(s)';
    space.append(bar);col.append(space,el('span','',String(x.date).slice(5)));bars.append(col);
   }
   daily.append(bars);
   const source=panel(grid,'Origem da interpretação','Roteamento de solicitações de IA.');
   const routes=d.routes||{},routeBox=el('div','master-ops-rows');
   for(const [name,label] of [['fast_path','PHP direto'],['cache_hit','Cache reutilizado'],['model_route','Modelo de IA']]){
    const row=el('div','master-ops-row');row.append(el('span','',label),el('strong','',num(routes[name])));routeBox.append(row);
   }
   source.append(routeBox);content.append(grid);
   const providers=panel(content,'Uso por provedor','Contagem agregada, sem conteúdo de conversas.');
   table(providers,['Provedor','Chamadas','Sucessos','Falhas','Tokens'],
    Object.entries(d.providers||{}).map(([name,r])=>[name,num(r.requests),num(r.http_ok),num(r.errors),num(r.tokens)]));
   content.append(el('p','master-description',d.coverage||''));
  };
  period.onchange=()=>load().catch(e=>{content.replaceChildren(el('p','master-description',e.message))});
  provider.onchange=period.onchange;refresh.onclick=period.onchange;
  await load();
 }
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
   content.append(summary);
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
   content.append(tabs,results);tabs.querySelector('button')?.click();state.textContent='Atualizado em '+date(h.checked_at);
  };
  reload.onclick=()=>load().catch(e=>state.textContent=e.message);
  await load();
 }
 return {ai,health};
})();