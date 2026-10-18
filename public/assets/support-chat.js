'use strict';
(()=>{
 const base=(document.body.dataset.basePath||'').replace(/\/$/,'');
 const endpoint=base+'/api.php?action=';
 let csrf='',user=null,threads=[],selected=null,open=false,timer=null,busy=false,root=null,fab=null;
 const $=(s,p=document)=>p.querySelector(s);
 const el=(tag,cls='',label='')=>{const n=document.createElement(tag);if(cls)n.className=cls;if(label)n.textContent=label;return n};
 async function request(action,data=null,query=''){
  const post=data!==null,headers={'Accept':'application/json'};
  if(post)headers['X-CSRF-Token']=csrf;
  const response=await fetch(endpoint+encodeURIComponent(action)+query,{method:post?'POST':'GET',
   credentials:'same-origin',headers,...(post?{body:data}:{})});
  const result=await response.json().catch(()=>({error:'Resposta inválida do suporte.'}));
  if(!response.ok||result.error)throw Error(result.error||'Operação indisponível.');
  return result;
 }
 function selectedQuery(){
  if(!selected)return '';
  const p=new URLSearchParams({workspace_id:selected.workspace_id,tenant_user_id:selected.tenant_user_id});
  return '&'+p.toString();
 }
 function feedback(msg='',bad=false){
  const node=$('.ts-support-feedback',root);
  if(node){node.textContent=msg;node.style.color=bad?'#ffb6b6':'#b5e7c5'}
 }
 function displayMessage(m){
  const mine=Number(m.sender_user_id)===Number(user.id);
  const bubble=el('div','ts-support-bubble'+(mine?' mine':''));
  bubble.append(el('div','who',m.sender_role==='master'?'ADM Master':m.sender_name||'Administrador'));
  const text=el('div','body',m.body||'');bubble.append(text);
  if(m.image_url){
   const img=el('img');img.src=m.image_url;img.alt='Imagem enviada no suporte';
   img.loading='lazy';img.addEventListener('click',()=>window.open(m.image_url,'_blank','noopener,noreferrer'));
   bubble.append(img);
  }
  const at=el('time','',m.created_at||'');bubble.append(at);
  return bubble;
 }
 async function refreshMessages(){
  if(!open||!selected||busy)return;
  const data=await request('support.messages',null,selectedQuery());
  const view=$('.ts-support-messages',root);
  const wasNearBottom=view.scrollHeight-view.scrollTop-view.clientHeight<100;
  const currentKey=view.dataset.last||'',newKey=(data.messages||[]).map(m=>m.id).join(',');
  if(newKey!==currentKey){
   view.replaceChildren();
   if(!data.messages.length)view.append(el('p','', 'Envie uma mensagem para iniciar o atendimento.'));
   for(const msg of data.messages)view.append(displayMessage(msg));
   view.dataset.last=newKey;
   if(wasNearBottom||currentKey==='')view.scrollTop=view.scrollHeight;
  }
 }
 async function refreshThreads(){
  threads=await request('support.list');
  const select=$('.ts-support-select select',root);
  select.replaceChildren();
  if(!threads.length){const o=el('option','','Nenhuma conversa disponível');o.value='';select.append(o);selected=null;return}
  const previous=selected?.tenant_user_id;
  for(const t of threads){
   const option=el('option','',''+(user.role==='master'?'('+t.workspace_name+') ':'')+t.tenant_name);
   option.value=t.tenant_user_id;select.append(option);
  }
  selected=threads.find(t=>Number(t.tenant_user_id)===Number(previous))||threads[0];
  select.value=String(selected.tenant_user_id);
  select.onchange=()=>{selected=threads.find(t=>Number(t.tenant_user_id)===Number(select.value));$('.ts-support-messages',root).dataset.last='';refreshMessages().catch(e=>feedback(e.message,true))};
 }
 async function openPanel(){
  if(!root)return;open=true;root.hidden=false;fab.setAttribute('aria-expanded','true');
  feedback();try{await refreshThreads();await refreshMessages()}catch(e){feedback(e.message,true)}
  if(timer)clearInterval(timer);
  timer=setInterval(()=>refreshMessages().catch(e=>feedback(e.message,true)),10000);
 }
 function closePanel(){open=false;if(root)root.hidden=true;if(fab)fab.setAttribute('aria-expanded','false');if(timer)clearInterval(timer);timer=null}
 function createWidget(){
  fab=el('button','ts-support-fab','Suporte TaskEsc');fab.type='button';fab.setAttribute('aria-expanded','false');
  root=el('aside','ts-support-panel');root.hidden=true;root.setAttribute('aria-label','Chat de suporte com o ADM Master');
  const header=el('div','ts-support-header');header.append(el('h3','','Atendimento e suporte'));
  const close=el('button','','×');close.type='button';close.setAttribute('aria-label','Fechar atendimento');header.append(close);
  close.onclick=closePanel;root.append(header);
  const choose=el('div','ts-support-select');
  choose.append(el('label','','Conversa'));choose.append(el('select'));root.append(choose);
  root.append(el('div','ts-support-messages'));
  const form=el('form','ts-support-form'),area=el('textarea');area.name='body';area.maxLength=4000;area.placeholder='Como podemos ajudar?';area.setAttribute('aria-label','Mensagem ao suporte');form.append(area);
  const actions=el('div','ts-support-actions'),file=el('input');file.type='file';file.name='image';file.accept='image/png,image/jpeg,image/webp,image/gif';
  const send=el('button','','Enviar');send.type='submit';actions.append(file,send);form.append(actions,el('div','ts-support-feedback'));root.append(form);
  form.onsubmit=async event=>{
   event.preventDefault();if(busy||!selected)return;
   if(!area.value.trim()&&!file.files.length){feedback('Escreva uma mensagem ou anexe uma imagem.',true);return}
   busy=true;send.disabled=true;feedback('Enviando…');
   try{
    const body=new FormData();body.append('body',area.value.trim());
    body.append('workspace_id',selected.workspace_id);body.append('tenant_user_id',selected.tenant_user_id);
    const bytes=crypto.getRandomValues(new Uint8Array(16));body.append('nonce',[...bytes].map(n=>n.toString(16).padStart(2,'0')).join(''));
    if(file.files[0])body.append('image',file.files[0]);
    await request('support.send',body);area.value='';file.value='';
    feedback('Mensagem enviada.');$('.ts-support-messages',root).dataset.last='';
   }catch(e){feedback(e.message,true)}
   finally{busy=false;send.disabled=false;refreshMessages().catch(()=>{})}
  };
  fab.onclick=()=>open?closePanel():openPanel();
  document.body.append(fab,root);
 }
 async function initialize(){
  let data;try{
   const response=await fetch(endpoint+'auth.me',{credentials:'same-origin'});
   if(!response.ok)throw Error('Not authenticated');
   data=await response.json();
  }catch{if(fab)fab.hidden=true;closePanel();return}
  user=data.user;csrf=data.csrf||'';
  const eligible=user?.role==='master'||user?.role==='admin';
  if(!eligible){if(fab)fab.hidden=true;closePanel();return}
  if(!fab)createWidget();
  fab.hidden=false;
 }
 document.addEventListener('taskesc:login',initialize);
 document.addEventListener('taskesc:logout',()=>{if(fab)fab.hidden=true;closePanel()});
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',initialize);
 else initialize();
})();
