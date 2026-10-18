'use strict';
(()=>{
 const base=(document.body.dataset.basePath||'').replace(/\/$/,'');
 const apiUrl=base+'/api.php?action=';
 const $=(selector,parent=document)=>parent.querySelector(selector);
 const el=(tag,className='',label='')=>{const n=document.createElement(tag);if(className)n.className=className;if(label)n.textContent=label;return n};
 const emojiList=['😀','😁','😂','😊','😍','🥰','😎','🤔','😅','😢','😮','🙏','👍','👎','👏','🙌','💪','🤝','👋','❤️','🔥','✨','✅','⚠️','🎉','📎','📸','🚀','💬','📅','🛠️','💡'];
 let user=null,csrf='',threads=[],selected=null,root=null,fab=null,opened=false,refreshing=false,sending=false;
 let polling=null,masterRealtime=null,masterSubscription=null,requestedOpen=false,lastSeenMessage=0,lastSignalAt=0;
 const threadKey=t=>t?String(t.workspace_id)+':'+String(t.tenant_user_id):'';
 const isMaster=()=>Boolean(user&&(user.role==='master'||user.is_support));
 const userId=()=>Number(user?.support_master_id||user?.id||0);
 const escTime=t=>{if(!t)return '';const d=new Date(t.replace(' ','T')+'Z');return Number.isNaN(d.valueOf())?'':d.toLocaleTimeString('pt-BR',{hour:'2-digit',minute:'2-digit'})};
 const authHeaders={'Accept':'application/json'};
 async function request(action,data=null,query=''){
  const method=data===null?'GET':'POST';
  const headers={...authHeaders,...(method==='POST'?{'X-CSRF-Token':csrf}:{})};
  const r=await fetch(apiUrl+encodeURIComponent(action)+query,{method,credentials:'same-origin',cache:'no-store',headers,...(method==='POST'?{body:data}:{})});
  const result=await r.json().catch(()=>({error:'Resposta inválida do suporte.'}));
  if(!r.ok||result.error)throw Error(result.error||'Não foi possível concluir a operação.');
  return result;
 }
 function feedback(text='',error=false){
  if(!root)return;
  const n=$('.ts-feedback',root);n.textContent=text;n.classList.toggle('error',error);
 }
 function selectedQuery(){
  const p=new URLSearchParams({workspace_id:String(selected.workspace_id),tenant_user_id:String(selected.tenant_user_id)});
  return '&'+p.toString();
 }
 function bubble(m){
  const mine=Number(m.sender_user_id)===userId();
  const wrap=el('article','ts-bubble'+(mine?' mine':''));
  wrap.append(el('span','ts-bubble-sender',mine?'Você':m.sender_role==='master'?'ADM Master':m.sender_name||'Administrador'));
  if(m.body)wrap.append(el('p','ts-bubble-text',m.body));
  if(m.image_url){
   const a=el('a','ts-image-link');a.href=m.image_url;a.target='_blank';a.rel='noopener noreferrer';
   const img=el('img');img.src=m.image_url;img.alt='Imagem anexada ao atendimento';img.loading='lazy';a.append(img);wrap.append(a);
  }
  wrap.append(el('time','ts-bubble-time',escTime(m.created_at)));
  return wrap;
 }
 function paintThreads(){
  if(!root)return;
  const q=$('.ts-search',root).value.trim().toLocaleLowerCase('pt-BR');
  const list=$('.ts-threads',root);list.replaceChildren();
  const filtered=threads.filter(t=>(t.tenant_name+' '+t.tenant_email+' '+t.workspace_name).toLocaleLowerCase('pt-BR').includes(q));
  if(!filtered.length){list.append(el('p','ts-muted',q?'Nenhum administrador encontrado.':'Nenhum atendimento disponível.'));return}
  for(const t of filtered){
   const item=el('button','ts-thread'+(threadKey(t)===threadKey(selected)?' active':''));item.type='button';
   const icon=el('div','ts-thread-avatar',(t.tenant_name||'A').trim().charAt(0).toUpperCase());
   const info=el('div','ts-thread-info'),name=el('strong','',isMaster()?t.tenant_name:'ADM Master');
   const time=el('time','',t.last_at?escTime(t.last_at):'');
   const top=el('div','ts-thread-top');top.append(name,time);info.append(top);
   info.append(el('span','ts-thread-preview',t.last_body||'Clique para iniciar uma conversa'));
   if(isMaster())info.append(el('small','ts-workspace-label',t.workspace_name));
   item.append(icon,info);
   item.onclick=()=>{selected=t;lastSeenMessage=0;root.classList.add('ts-conversation-active');paintThreads();paintHeading();loadMessages().catch(e=>feedback(e.message,true));};
   list.append(item);
  }
 }
 function paintHeading(){
  if(!root)return;
  const title=$('.ts-peer',root),subtitle=$('.ts-peer-sub',root),avatar=$('.ts-peer-avatar',root);
  title.textContent=selected?(isMaster()?selected.tenant_name:'Equipe de suporte TaskEsc'):'Selecione um atendimento';
  subtitle.textContent=selected?(isMaster()?selected.workspace_name+' · '+selected.tenant_email:'Conversa privada com o ADM Master'):'';
  avatar.textContent=selected?(isMaster()?selected.tenant_name.trim().charAt(0).toUpperCase():'T'):'?';
  $('.ts-composer',root).hidden=!selected;
 }
 async function loadThreads(){
  const updated=await request('support.list');
  threads=Array.isArray(updated)?updated:[];
  if(selected)selected=threads.find(t=>threadKey(t)===threadKey(selected))||threads[0]||null;
  else selected=threads[0]||null;
  paintThreads();paintHeading();
 }
 async function loadMessages(){
  if(!opened||!selected||refreshing)return;
  refreshing=true;
  try{
   const result=await request('support.messages',null,selectedQuery());
   const list=$('.ts-messages',root),items=result.messages||[];
   const lastId=Number(items.at(-1)?.id||0);
   if(list.dataset.thread!==threadKey(selected)||lastSeenMessage!==lastId){
    const atBottom=list.scrollHeight-list.scrollTop-list.clientHeight<130;
    const firstLoad=list.dataset.thread!==threadKey(selected);
    list.replaceChildren();
    if(!items.length)list.append(el('div','ts-chat-empty','Esta conversa é privada. Escreva uma mensagem para começar.'));
    for(const m of items)list.append(bubble(m));
    list.dataset.thread=threadKey(selected);
    lastSeenMessage=lastId;
    if(atBottom||firstLoad)list.scrollTop=list.scrollHeight;
   }
  }finally{refreshing=false}
 }
 async function sync(alsoMessages=true){
  if(!user||!root||!opened)return;
  try{await loadThreads();if(alsoMessages)await loadMessages();feedback()}
  catch(e){feedback(e.message,true)}
 }
 function updateConnection(){
  if(!root)return;
  const state=window.TaskEscRealtime?.status?.();
  const realtimeOk=Boolean(masterRealtime&&(masterRealtime.state==='connected'))||state==='connected';
  const tag=$('.ts-connection',root);tag.textContent=realtimeOk?'Atualizações em tempo real':'Sincronização ativa';
  tag.classList.toggle('live',realtimeOk);
 }
 function startPolling(){
  if(polling)clearInterval(polling);
  polling=setInterval(()=>{
   if(!opened||document.visibilityState==='hidden')return;
   updateConnection();
   if(Date.now()-lastSignalAt<1500)return;
   const rt=(masterRealtime?.state==='connected'||window.TaskEscRealtime?.status?.()==='connected');
   sync(!rt).catch(()=>{});
  },8000);
 }
 function openedUrl(){
  if(document.body.dataset.masterPage==='1')return base+'/master/suporte';
  return base+'/dashboard?suporte=1';
 }
 function openPanel(){
  if(!root||!user){requestedOpen=true;return}
  requestedOpen=false;opened=true;root.hidden=false;
  root.classList.remove('ts-conversation-active');
  fab.hidden=true;
  $('.ts-search',root).value='';
  updateConnection();startPolling();
  sync().then(()=>{if(selected)loadMessages().catch(()=>{});}).catch(()=>{});
  if(document.body.dataset.masterPage==='1'&&location.pathname==='/master'){
   history.replaceState(null,'',base+'/master/suporte');
  }
 }
 function closePanel(){
  opened=false;if(root){root.hidden=true;root.classList.remove('ts-conversation-active')}if(fab&&user&&!isMaster())fab.hidden=false;
  if(isMaster())window.dispatchEvent(new Event('taskesc:close-support'));
  if(polling)clearInterval(polling);polling=null;
  if(document.body.dataset.masterPage==='1'&&/\/master\/suporte\/?$/.test(location.pathname)){
   history.replaceState(null,'',base+'/master');
  }
 }
 function emojiMenu(){
  const box=el('div','ts-emoji-menu');box.hidden=true;
  for(const emoji of emojiList){
   const b=el('button','',emoji);b.type='button';b.setAttribute('aria-label','Inserir '+emoji);
   b.onclick=()=>{const area=$('.ts-text',root);const pos=area.selectionStart||0;area.setRangeText(emoji,pos,area.selectionEnd||pos,'end');area.focus();box.hidden=true};
   box.append(b);
  }
  return box;
 }
 async function requestPush(){
  if(!('serviceWorker' in navigator)||!('PushManager' in window)||!('Notification' in window)){
   feedback('Este navegador não oferece notificações push.',true);return;
  }
  const b=$('.ts-enable-push',root);b.disabled=true;
  try{
   const status=await request('push.status');
   if(!status.public_key)throw Error('Notificações não configuradas na plataforma.');
   const permission=await Notification.requestPermission();
   if(permission!=='granted')throw Error('Autorize as notificações do TaskEsc no navegador.');
   const reg=await navigator.serviceWorker.register(base+'/service-worker.js');
   const ready=await navigator.serviceWorker.ready;
   let subscription=await ready.pushManager.getSubscription();
   if(!subscription){
    let key=status.public_key.replace(/-/g,'+').replace(/_/g,'/');
    key+='='.repeat((4-key.length%4)%4);
    const bytes=Uint8Array.from(atob(key),c=>c.charCodeAt(0));
    subscription=await ready.pushManager.subscribe({userVisibleOnly:true,applicationServerKey:bytes});
   }
   const data=subscription.toJSON();
   await request('push.subscribe',new URLSearchParams({
    endpoint:subscription.endpoint,p256dh:data.keys?.p256dh||'',auth:data.keys?.auth||''
   }));
   b.textContent='Notificações ativadas';feedback('Você receberá alertas de novas mensagens no dispositivo.');
  }catch(e){feedback(e.message,true)}finally{b.disabled=false}
 }
 function createWidget(){
  fab=el('button','ts-support-fab','Suporte');fab.type='button';fab.setAttribute('aria-label','Abrir atendimento TaskEsc');
  const counter=el('span','ts-support-indicator');counter.hidden=true;fab.append(counter);
  root=el('section','ts-support-panel');root.hidden=true;root.setAttribute('aria-label','Central de suporte TaskEsc');
  const header=el('header','ts-header');
  const heading=el('div');heading.append(el('h2','','Central de atendimento'),el('small','','Suporte privado · TaskEsc'));
  const tools=el('div','ts-header-tools');
  const push=el('button','ts-enable-push','Ativar notificações');push.type='button';push.onclick=requestPush;
  const close=el('button','ts-close','×');close.type='button';close.setAttribute('aria-label','Fechar suporte');close.onclick=closePanel;
  tools.append(push,close);header.append(heading,tools);root.append(header);
  const content=el('div','ts-layout'),left=el('aside','ts-left'),right=el('section','ts-right');
  const leftHead=el('div','ts-left-head');leftHead.append(el('strong','','Conversas'),el('span','ts-connection','Sincronização ativa'));left.append(leftHead);
  const search=el('input','ts-search');search.type='search';search.placeholder='Buscar por nome ou e-mail';search.setAttribute('aria-label','Buscar administrador');
  search.oninput=paintThreads;left.append(search,el('div','ts-threads'));
  const peer=el('header','ts-peer-head'),avatar=el('div','ts-peer-avatar','?'),peerNames=el('div','ts-peer-names');
  const back=el('button','ts-mobile-back','←');back.type='button';back.setAttribute('aria-label','Voltar às conversas');back.onclick=()=>{root.classList.remove('ts-conversation-active');$('.ts-search',root)?.focus()};peer.append(back);
  peerNames.append(el('strong','ts-peer','Selecione um atendimento'),el('small','ts-peer-sub'));peer.append(avatar,peerNames);right.append(peer);
  const messages=el('div','ts-messages');messages.setAttribute('role','log');messages.setAttribute('aria-live','polite');right.append(messages);
  const composer=el('form','ts-composer');composer.hidden=true;
  const preview=el('div','ts-attachment-preview');preview.hidden=true;
  const text=el('textarea','ts-text');text.name='body';text.maxLength=4000;text.placeholder='Digite sua mensagem…';text.rows=2;
  const actions=el('div','ts-composer-actions');
  const emojiButton=el('button','ts-icon-button','☺');emojiButton.type='button';emojiButton.title='Emojis';
  const picker=emojiMenu();emojiButton.onclick=()=>{picker.hidden=!picker.hidden};
  const attachment=el('button','ts-icon-button','📎');attachment.type='button';attachment.title='Anexar imagem';
  const file=el('input','ts-file');file.type='file';file.name='image';file.accept='image/png,image/jpeg,image/webp,image/gif';file.hidden=true;
  attachment.onclick=()=>file.click();
  file.onchange=()=>{
   const f=file.files?.[0];preview.hidden=!f;preview.replaceChildren();
   if(!f)return;
   if(f.size>5*1024*1024){feedback('Imagem com mais de 5 MB.',true);file.value='';preview.hidden=true;return}
   const clear=el('button','','×');clear.type='button';clear.title='Remover anexo';clear.onclick=()=>{file.value='';preview.hidden=true;preview.replaceChildren()};
   preview.append(el('span','','📎 '+f.name),clear);
  };
  const send=el('button','ts-send','Enviar ↗');send.type='submit';
  actions.append(emojiButton,attachment,picker,file,el('span','ts-action-spacer'),send);
  const feedbackNode=el('div','ts-feedback');feedbackNode.setAttribute('role','status');
  composer.append(preview,text,actions,feedbackNode);right.append(composer);content.append(left,right);root.append(content);
  text.addEventListener('keydown',e=>{if(e.key==='Enter'&&!e.shiftKey){e.preventDefault();composer.requestSubmit()}});
  composer.onsubmit=async e=>{
   e.preventDefault();if(sending||!selected)return;
   const msg=text.value.trim(),f=file.files?.[0];
   if(!msg&&!f){feedback('Digite uma mensagem ou selecione uma imagem.',true);return}
   if(f&&f.size>5*1024*1024){feedback('A imagem deve ter até 5 MB.',true);return}
   sending=true;send.disabled=true;feedback('Enviando…');
   const payload=new FormData();
   payload.append('body',msg);payload.append('workspace_id',selected.workspace_id);
   payload.append('tenant_user_id',selected.tenant_user_id);
   const bytes=crypto.getRandomValues(new Uint8Array(16));payload.append('nonce',Array.from(bytes,n=>n.toString(16).padStart(2,'0')).join(''));
   if(f)payload.append('image',f);
   try{
    await request('support.send',payload);
    text.value='';file.value='';preview.replaceChildren();preview.hidden=true;
    await Promise.all([loadThreads(),loadMessages()]);
    $('.ts-messages',root).scrollTop=$('.ts-messages',root).scrollHeight;
    feedback('');
   }catch(err){feedback(err.message,true)}
   finally{sending=false;send.disabled=false}
  };
  fab.onclick=openPanel;
  if(isMaster()&&$('#master-support')){
   root.classList.add('ts-inline');$('#master-support').append(root);document.body.append(fab);fab.hidden=true;
  }else document.body.append(fab,root);
 }
 function handleRealtime(event){
  const p=event?.detail;
  if(p?.data?.event_type!=='support.message')return;
  lastSignalAt=Date.now();
  const source=p.data?.payload||{};
  if(root&&!opened){const indicator=$('.ts-support-indicator',fab);indicator.hidden=false;fab.classList.add('new-message')}
  if(!opened)return;
  if(!selected||Number(source.tenant_user_id)===Number(selected.tenant_user_id)){
   loadMessages().catch(()=>{});
  }
  loadThreads().catch(()=>{});
 }
 async function masterSocket(){
  if(!user||user.role!=='master'||typeof window.Centrifuge!=='function'||masterRealtime)return;
  try{
   const auth=async body=>{
    const response=await fetch(base+'/realtime.auth',{method:'POST',credentials:'same-origin',cache:'no-store',
     headers:{'Accept':'application/json','Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify(body)});
    if(!response.ok)throw Error('realtime unavailable');
    return response.json();
   };
   const init=await auth({});
   if(!init.enabled||!init.websocket||!init.token)return;
   const socket=new window.Centrifuge(init.websocket,{
    token:init.token,getToken:async()=>String((await auth({})).token),
    minReconnectDelay:500,maxReconnectDelay:15000,timeout:5000
   });
   const channel='user:'+String(user.id);
   masterSubscription=socket.newSubscription(channel,{getToken:async()=>String((await auth({channel})).token)});
   masterSubscription.on('publication',ctx=>handleRealtime({detail:{data:ctx.data,channel}}));
   masterSubscription.subscribe();socket.connect();masterRealtime=socket;updateConnection();
  }catch{masterRealtime=null}
 }
 async function initialize(){
  try{
   const response=await fetch(apiUrl+'auth.me',{credentials:'same-origin',cache:'no-store'});
   if(!response.ok)throw Error('not authenticated');
   const auth=await response.json();
   if(!auth.user||!['admin','master'].includes(auth.user.role)){
    user=null;closePanel();if(fab)fab.hidden=true;return;
   }
   user=auth.user;csrf=auth.csrf;
   if(!root)createWidget();
   fab.hidden=isMaster()||opened;
   if(user.role==='master')masterSocket().catch(()=>{});
   if(requestedOpen||location.search.includes('suporte=1')||/\/master\/suporte\/?$/.test(location.pathname))openPanel();
  }catch{user=null;closePanel();if(fab)fab.hidden=true}
 }
 window.addEventListener('taskesc:realtime-publication',handleRealtime);
 window.addEventListener('taskesc:open-support',()=>{requestedOpen=true;openPanel()});
 window.addEventListener('taskesc:leave-support',()=>{
  if(!isMaster()||!root)return;
  opened=false;root.hidden=true;root.classList.remove('ts-conversation-active');
  if(polling)clearInterval(polling);polling=null;
 });
 window.addEventListener('online',()=>{if(opened)sync().catch(()=>{})});
 document.addEventListener('visibilitychange',()=>{if(opened&&!document.hidden)sync().catch(()=>{})});
 document.addEventListener('taskesc:login',initialize);
 document.addEventListener('taskesc:logout',()=>{user=null;closePanel();if(fab)fab.hidden=true;if(masterRealtime){masterRealtime.disconnect();masterRealtime=null}});
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',initialize);
 else initialize();
})();