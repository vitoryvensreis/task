'use strict';
(async function(){
 const $=s=>document.querySelector(s),base=document.body.dataset.basePath||'';
 const endpoint=base+'/api.php?action=';
 const show=(s,yes)=>$(s)?.classList.toggle('hidden',!yes);
 const message=(s,text,bad=false)=>{const n=$(s);if(n){n.textContent=text;n.className=bad?'error':'success'}};
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
  if(!auth.user?.is_master||auth.user.role!=='master'&&auth.user.is_support!==1){show('#master-login',true);return}
  const data=await request('master.workspaces');
  show('#master-panel',true);
  const root=$('#master-workspaces');
  if(data.support_active){const b=document.createElement('p');b.textContent='Sessão de suporte ativa. Você pode retornar ao painel operacional.';root.append(b);
    const link=document.createElement('a');link.href=base+'/index.php';link.textContent='Abrir workspace em suporte';root.append(link)}
  for(const w of data.workspaces){
   const wrap=document.createElement('article');wrap.className='master-card';
   const h=document.createElement('h3');h.textContent=w.name;wrap.append(h);
   const p=document.createElement('p');p.textContent=w.slug+' · '+w.status;wrap.append(p);
   const form=document.createElement('form');
   const label=document.createElement('label');label.textContent='Motivo do atendimento (obrigatório)';
   const input=document.createElement('input');input.name='reason';input.required=true;input.minLength=8;input.maxLength=190;
   input.placeholder='Ex.: correção solicitada pelo cliente';label.append(input);form.append(label);
   const b=document.createElement('button');b.textContent='Entrar em modo suporte';form.append(b);
   form.addEventListener('submit',async e=>{e.preventDefault();b.disabled=true;
    try{const r=await request('master.start',{workspace_id:w.id,reason:input.value.trim()},csrf);location.href=r.url||base+'/index.php'}
    catch(err){message('#master-panel-message',err.message,true);b.disabled=false}
   });wrap.append(form);root.append(wrap);
  }
  $('#master-logout').addEventListener('click',async()=>{try{await request('auth.logout',{})}finally{location.href=base+'/index.php'}});
 }catch(err){show('#master-login',true);message('#master-panel-message',err.message,true)}
})();