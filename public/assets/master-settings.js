'use strict';
window.TaskEscMasterSettings=(()=>{
 const el=(tag,cls='',value='')=>{const n=document.createElement(tag);if(cls)n.className=cls;if(value)n.textContent=String(value);return n};
 async function send(base,csrf,action,body){
  const isFile=body instanceof FormData;
  const response=await fetch(base+'/api.php?action='+action,{method:'POST',credentials:'same-origin',cache:'no-store',
   headers:{'Accept':'application/json','X-CSRF-Token':csrf,...(isFile?{}:{'Content-Type':'application/json'})},
   body:isFile?body:JSON.stringify(body)});
  const d=await response.json().catch(()=>({error:'Resposta inválida.'}));
  if(!response.ok||d.error)throw Error(d.error||'Não foi possível salvar.');return d;
 }
 function card(host,title,subtitle){
  const section=el('section','panel ms-panel'),head=el('div','panel-head'),titleBox=el('div');
  titleBox.append(el('h3','',title),el('p','panel-sub',subtitle));head.append(titleBox);section.append(head);host.append(section);return section;
 }
 function field(form,label,name,value='',type='text',placeholder=''){
  const wrapper=el('label','ms-field'),lbl=el('span','',label),input=el('input','input');
  input.name=name;input.type=type;input.value=String(value??'');input.placeholder=placeholder;
  wrapper.append(lbl,input);form.append(wrapper);return input;
 }
 function fileField(form,label,name){
  const f=field(form,label,name,'','file');
  f.accept=name==='logo'?'image/png,image/jpeg,image/webp,image/gif':'image/png';
  return f;
 }
 function status(form,message='',bad=false){const s=form.querySelector('.ms-msg');if(s){s.textContent=message;s.classList.toggle('error',bad)}}
 function submit(form,action,section,base,csrf,after){
  const foot=el('div','ms-footer');const msg=el('div','ms-msg'),button=el('button','primary','Salvar alterações');
  button.type='submit';foot.append(msg,button);form.append(foot);
  form.onsubmit=async event=>{
   event.preventDefault();button.disabled=true;status(form,'Salvando…');
   try{
    const p=Object.fromEntries(new FormData(form));p.section=section;
    const result=await send(base,csrf,action,p);status(form,'Salvo com sucesso.');
    if(after)after(result);
   }catch(e){status(form,e.message,true)}
   finally{button.disabled=false}
  };
 }
 function note(host,title,description){
  const box=el('div','ms-info');box.append(el('strong','',title),el('span','',description));host.append(box);return box;
 }
 async function render(host,csrf,base,mode='integrations'){
  host.replaceChildren(el('p','master-description','Carregando configurações globais…'));
  const response=await fetch(base+'/api.php?action=master.settings',{credentials:'same-origin',cache:'no-store'});
  const d=await response.json().catch(()=>({error:'Resposta inválida do servidor.'}));
  if(!response.ok||d.error)throw Error(d.error||'Configurações indisponíveis.');
  const b=d.branding||{},smtp=d.smtp||{},w=d.wppconnect||{},icons=b.icons||{};
  host.replaceChildren(el('p','master-description','Gerencie as configurações da plataforma. As credenciais globais não ficam visíveis aos administradores dos workspaces.'));
  const grid=el('div','ms-grid');host.append(grid);
  const brand=card(grid,'Identidade visual','Logo, nome e configurações gerais da plataforma.');
  const fBrand=el('form','ms-form'),preview=el('div','ms-logo-row'),imgBox=el('div','ms-logo-preview');
  if(b.logo_url){const img=el('img');img.src=b.logo_url;img.alt='Logo atual';imgBox.append(img)}else imgBox.textContent='TASKESC';
  const hint=el('div');hint.append(el('strong','','Logo principal'),el('p','panel-sub','Envie a logo e os ícones no cartão ao lado.'));
  preview.append(imgBox,hint);fBrand.append(preview);
  const fbg=el('div','ms-fields');field(fbg,'Nome da plataforma','brand_name',b.brand_name||'TaskEsc');
  field(fbg,'Cor HEX','brand_color',b.brand_color||'#ffffff');
  field(fbg,'Limite de upload (MB)','upload_mb',b.upload_mb??0,'number');
  field(fbg,'Prazo mínimo (horas úteis)','min_delivery_business_hours',b.min_delivery_business_hours||24,'number');
  fBrand.append(fbg);submit(fBrand,'master.settings_save','branding',base,csrf);brand.append(fBrand);
  const assets=card(grid,'Logo e ícones do aplicativo','Arquivos para identidade visual e instalação do PWA.');
  const previewIcons=el('div','ms-icon-preview');
  for(const [key,label] of [['180','180 × 180'],['192','192 × 192'],['512','512 × 512'],['maskable','Maskable']]){
   const item=el('div','ms-icon-item'),box=el('div');
   if(icons[key]){const image=el('img');image.src=icons[key];image.alt='Ícone atual '+label;box.append(image)}
   else box.append(el('span','','Sem ícone'));
   item.append(box,el('small','',label));previewIcons.append(item);
  }assets.append(previewIcons);
  const files=el('form','ms-form');files.enctype='multipart/form-data';
  fileField(files,'Logo principal (até 5 MB)','logo');
  fileField(files,'Ícone 180 PNG','icon_180');fileField(files,'Ícone 192 PNG','icon_192');
  fileField(files,'Ícone 512 PNG','icon_512');fileField(files,'Ícone maskable PNG','icon_maskable_512');
  const filesFooter=el('div','ms-footer'),filesButton=el('button','primary','Enviar imagens');
  filesButton.type='submit';filesFooter.append(el('div','ms-msg'),filesButton);files.append(filesFooter);assets.append(files);
  files.onsubmit=async event=>{
   event.preventDefault();filesButton.disabled=true;status(files,'Enviando imagens…');
   try{
    const p=new FormData(files);
    if(![...p.values()].some(v=>v instanceof File&&v.size>0))throw Error('Selecione pelo menos uma imagem.');
    await send(base,csrf,'master.brand_upload',p);await render(host,csrf,base,mode);
   }catch(e){status(files,e.message,true);filesButton.disabled=false}
  };
  const email=card(grid,'E-mail e SMTP','Servidor de envio e identidade do remetente.');
  const fe=el('form','ms-form'),fg=el('div','ms-fields');
  field(fg,'Host SMTP','host',smtp.host||'');field(fg,'Porta','port',smtp.port||587,'number');
  const enc=el('label','ms-field');enc.append(el('span','','Segurança'));
  const select=el('select','select');select.name='encryption';
  for(const v of ['tls','ssl']){const opt=el('option','',v.toUpperCase());opt.value=v;opt.selected=(smtp.encryption||'tls')===v;select.append(opt)}enc.append(select);fg.append(enc);
  field(fg,'Usuário SMTP','username',smtp.username||'');
  field(fg,'Remetente','from',smtp.from||'','email');
  field(fg,'Nome do remetente','from_name',smtp.from_name||'TaskEsc');
  field(fg,'Senha SMTP','password','','password',d.smtp_password_set?'Configurada — deixe vazio para manter':'Não configurada');
  fe.append(fg);submit(fe,'master.settings_save','email',base,csrf);email.append(fe);
  const wpp=card(grid,'WhatsApp único da plataforma','Um número compartilhado controlado pelo ADM Master.');
  note(wpp,w.configured?'Credenciais configuradas':'Configuração pendente','Indicador de configuração; não é uma confirmação de sessão conectada.');
  const fw=el('form','ms-form');
  field(fw,'URL interna (localhost)','internal_url',w.internal_url||'http://127.0.0.1:21465');
  field(fw,'URL pública HTTPS','public_url',w.public_url||'');
  field(fw,'Sessão','session',w.session||'taskesc');
  field(fw,'Segredo WPPConnect','secret','','password',w.secret_set?'Configurado — deixe vazio para manter':'Informe o segredo');
  submit(fw,'master.settings_save','whatsapp',base,csrf);wpp.append(fw);
  const google=card(grid,'Integração Google OAuth','Credenciais globais do Google Drive.');
  const fgAuth=el('form','ms-form');field(fgAuth,'Google Client ID','google_client_id',d.google_client_id||'');
  field(fgAuth,'Google Client Secret','google_client_secret','','password',d.google_secret_set?'Configurado — deixe vazio para manter':'Não configurado');
  submit(fgAuth,'master.settings_save','google',base,csrf);google.append(fgAuth);
  const asaas=d.asaas||{};
  const asaasCard=card(grid,'Asaas · Pagamentos SaaS','Conexão de cobrança recorrente da plataforma; gerenciada somente pelo ADM Master.');
  note(asaasCard,asaas.integration_enabled?'Cobranças habilitadas':'Cobranças desabilitadas',
    'Nenhuma assinatura é criada automaticamente. Pagadores e valores são confirmados na ficha de cada empresa. O token do webhook deve ser cadastrado também no Asaas.');
  const fa=el('form','ms-form');
  const envLabel=el('label','ms-field');envLabel.append(el('span','','Ambiente Asaas'));
  const envSelect=el('select','select');envSelect.name='environment';
  for(const [value,label] of [['sandbox','Sandbox · Testes'],['production','Produção · Cobranças reais']]){
   const opt=el('option','',label);opt.value=value;opt.selected=asaas.environment===value;envSelect.append(opt);
  }
  envLabel.append(envSelect);fa.append(envLabel);
  field(fa,'Identificação da conta','account_label',asaas.account_label||'','text','Ex.: TaskEsc plataforma');
  field(fa,'Chave API Asaas','api_key','','password',asaas.api_key_set?'Cadastrada — deixe vazio para manter':'Cole sua chave privada');
  const webhookToken=field(fa,'Token de autenticação do webhook','webhook_auth_token','','password',
    asaas.webhook_token_set?'Cadastrado — deixe vazio para manter':'Token de 32 a 255 caracteres, distinto da API Key');
  webhookToken.minLength=32;webhookToken.maxLength=255;
  const urlField=field(fa,'URL de recebimento de webhooks','webhook_url',asaas.webhook_url||'');
  urlField.readOnly=true;urlField.title='Cadastre este endereço no painel Asaas com os eventos PAYMENT_* e SUBSCRIPTION_*';
  const active=el('label','ms-field');const activeToggle=el('input');activeToggle.type='checkbox';
  activeToggle.name='enable_billing';activeToggle.value='1';activeToggle.checked=!!asaas.integration_enabled;
  active.append(activeToggle,el('span','','Habilitar cobranças (confirmação explícita)'));fa.append(active);
  submit(fa,'master.asaas_save','asaas',base,csrf,()=>render(host,csrf,base,mode));
  const asaasTools=el('div','ms-footer'),check=el('button','secondary','Verificar conexão Asaas');
  check.type='button';check.onclick=async()=>{check.disabled=true;
   try{const r=await send(base,csrf,'master.asaas_test',{});
    alert('Asaas conectado ('+r.environment+'). Situação da conta: '+r.general_status);
   }catch(e){alert('Falha de conexão: '+e.message)}finally{check.disabled=false}
  };asaasTools.append(check);
  asaasCard.append(fa,asaasTools);
  note(asaasCard,'Recebimentos protegidos','Ativação exige chave válida, token de webhook e conta de produção aprovada. Cobranças reais só são emitidas com confirmação no ADM Master.');
  const drive=card(grid,'Google Drive','Arquivos do sistema são enviados apenas pelas contas Google autorizadas de cada workspace.');
  note(drive,'Acesso isolado por empresa','Cada administrador conecta as próprias contas Google no workspace. O ADM Master gerencia somente as credenciais globais OAuth aqui.');
  const wppTools=el('div','mc-integration-tools');
  wpp.append(wppTools);
  const state=el('div','mc-feedback');wppTools.append(state);
  const statusBtn=el('button','secondary','Verificar sessão');statusBtn.type='button';
  const startBtn=el('button','secondary','Iniciar / Reconectar');startBtn.type='button';
  const qrBtn=el('button','secondary','Exibir QR Code');qrBtn.type='button';
  const closeBtn=el('button','secondary','Fechar sessão');closeBtn.type='button';
  const disconnectBtn=el('button','secondary','Desconectar número');disconnectBtn.type='button';
  const tools=el('div','mc-actions');tools.append(statusBtn,startBtn,qrBtn,closeBtn,disconnectBtn);wppTools.append(tools);
  const qrHolder=el('div','mc-qr');wppTools.append(qrHolder);
  const wppRequest=async(action,p=null)=>{
   const isPost=p!==null;
   const response=await fetch(base+'/api.php?action='+action,{
    method:isPost?'POST':'GET',credentials:'same-origin',cache:'no-store',
    headers:{Accept:'application/json',...(isPost?{'Content-Type':'application/json','X-CSRF-Token':csrf}:{})},
    ...(isPost?{body:JSON.stringify(p)}:{})});
   const out=await response.json().catch(()=>({error:'Resposta inválida'}));
   if(!response.ok||out.error)throw Error(out.error||'Erro na integração');return out;
  };
  const runWpp=async fn=>{state.textContent='Consultando conexão…';try{await fn()}catch(error){state.textContent=error.message}};
  statusBtn.onclick=()=>runWpp(async()=>{const d=await wppRequest('master.wpp_status');
   state.textContent=d.connected?'WhatsApp conectado'+(d.phone?' · +'+d.phone:'')+'.':d.message||'WhatsApp desconectado.';});
  startBtn.onclick=()=>runWpp(async()=>{await wppRequest('master.wpp_start',{});state.textContent='Sessão iniciada. Clique em Exibir QR Code se necessário.'});
  qrBtn.onclick=()=>runWpp(async()=>{const d=await wppRequest('master.wpp_qr');qrHolder.replaceChildren();
   const url=d.qrcode;
   if(typeof url==='string'&&url.startsWith('data:image/png;base64,')){
    const image=el('img');image.src=url;image.alt='QR Code WhatsApp';qrHolder.append(image);
   }else state.textContent='Sessão: '+String(d.status||'Aguardando QR Code');
  });
  const confirmMaster=async(action,label)=>{
   const password=window.prompt('Digite a senha do ADM Master para '+label+':');
   if(password===null||password==='')return;
   if(!window.confirm('Confirma '+label+' da sessão WhatsApp compartilhada?'))return;
   await runWpp(async()=>{
     await wppRequest(action,{master_password:password});
     qrHolder.replaceChildren();state.textContent='Operação confirmada: '+label+'.';
   });
  };
  closeBtn.onclick=()=>confirmMaster('master.wpp_close','fechar (mantendo a autenticação salva)');
  disconnectBtn.onclick=()=>confirmMaster('master.wpp_disconnect','desconectar (apaga dados de autenticação)');
  const testForm=el('form','ms-form mc-wpp-test');
  field(testForm,'Enviar teste para telefone (com DDI)','phone','','tel','5585999999999');
  field(testForm,'Mensagem de teste','message','TaskEsc · teste de integração WhatsApp');
  const testButton=el('button','secondary','Enviar teste WhatsApp');testButton.type='submit';
  testForm.append(testButton);wppTools.append(testForm);
  testForm.onsubmit=event=>{event.preventDefault();runWpp(async()=>{
   await wppRequest('master.wpp_test',Object.fromEntries(new FormData(testForm)));
   state.textContent='Mensagem de teste encaminhada pelo provedor.';})};
  const security=card(grid,'Segurança da plataforma','Acesso e segregação dos dados.');
  note(security,'Acesso exclusivo','Somente a conta ADM Master autenticada pode alterar estas configurações. Ações são auditadas.');
  note(security,'Proteção dos workspaces','Criação de novos workspaces segue bloqueada até a homologação multiempresa.');
  const tabItems=mode==='whatsapp'? [{key:'whatsapp',label:'Sessão compartilhada',node:wpp}]:mode==='personalization'?
    [{key:'identidade',label:'Identidade visual',node:brand},
     {key:'icones',label:'Ícones e PWA',node:assets}]:
    [{key:'email',label:'E-mail / SMTP',node:email},
     {key:'asaas',label:'Asaas',node:asaasCard},
     {key:'google',label:'Google OAuth',node:google},
     {key:'drive',label:'Google Drive',node:drive}];
  grid.remove();
  window.TaskEscMasterTabs.mount(host,tabItems,mode==='personalization'?'aparencia':mode==='whatsapp'?'wpp':'integracoes');

 }
 return {render};
})();