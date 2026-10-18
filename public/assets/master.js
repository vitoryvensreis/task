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
  show('#master-activate',true);
  $('#master-activate-form').addEventListener('submit',async e=>{
   e.preventDefault();const b=e.submitter;b.disabled=true;
   try{const data=Object.fromEntries(new FormData(e.target));data.token=activation;
    await request('master.activate',data);message('#master-activate-message','Conta ativada! Entre pelo login do TaskEsc.');
    e.target.reset();history.replaceState(null,'',base+'/master.php');show('#master-login',true);
   }catch(err){message('#master-activate-message',err.message,true);}finally{b.disabled=false}
  });
  return;
 }
 let csrf;
 try{
  const auth=await request('auth.me');csrf=auth.csrf;
  if(!auth.user?.is_master||!(auth.user.role==='master'||!!auth.user.is_support)){show('#master-login',true);return}
  const data=await request('master.workspaces');
  show('#master-panel',true);
  const workspaces=$('#master-workspaces'),admins=$('#master-administrators');
  const tabs=[...document.querySelectorAll('[data-master-tab]')];
  const clear=()=>{workspaces.replaceChildren();admins.replaceChildren();message('#master-panel-message','')};
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
    try{const resp=await request('master.start',{workspace_id:w.id,reason:input.value.trim()},csrf);location.href=resp.url||base+'/index.php'}
    catch(err){message('#master-panel-message',err.message,true);b.disabled=false}
   });wrap.append(form);return wrap;
  }
  async function draw(tab){
   clear();tabs.forEach(b=>b.classList.toggle('active',b.dataset.masterTab===tab));
   show('#master-workspaces',tab!=='clients');show('#master-administrators',tab==='clients');
   $('#master-panel-title').textContent=({overview:'Visão geral da plataforma',clients:'Clientes e administradores',workspaces:'Gerenciamento de workspaces'})[tab];
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
   if(tab==='overview'){
    const grid=el('div','','master-grid');
    const status=el('div','','master-mini');status.append(el('h3',String(data.workspaces.length)),el('p','Workspace cadastrado'));
    const isolation=el('div','','master-mini');isolation.append(el('h3','Protegido'),el('p','Multiempresa ainda bloqueada até homologação'));
    grid.append(status,isolation);workspaces.append(grid);
    workspaces.append(el('p','O ADM Master não tem acesso automático aos dados de outras empresas. Use uma sessão de suporte com motivo registrado.','master-note'));
    if(data.support_active){const link=el('a','Retornar ao suporte ativo');link.href=base+'/index.php';workspaces.append(link)}
    return;
   }
   const grid=el('div','','master-grid');for(const w of data.workspaces)grid.append(supportCard(w));workspaces.append(grid);
   if(data.support_active){const link=el('a','Abrir sessão de suporte ativa');link.href=base+'/index.php';workspaces.append(link)}
  }
  for(const t of tabs)t.addEventListener('click',()=>draw(t.dataset.masterTab).catch(e=>message('#master-panel-message',e.message,true)));
  await draw('overview');
  $('#master-logout').addEventListener('click',async()=>{try{await request('auth.logout',{})}finally{location.href=base+'/index.php'}});
 }catch(err){show('#master-login',true);message('#master-panel-message',err.message,true)}
})();
