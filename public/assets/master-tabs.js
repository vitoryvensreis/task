'use strict';
/** Compact accessible inner navigation. Existing forms retain their real DOM handlers. */
window.TaskEscMasterTabs=(()=>{
 let seq=0;
 function mount(host,items,group='master'){
  if(!host||!Array.isArray(items)||!items.length)return;
  const tabs=document.createElement('div');tabs.className='master-inner-tabs';tabs.setAttribute('role','tablist');
  tabs.setAttribute('aria-label','Seções da página');
  const stage=document.createElement('div');stage.className='master-inner-content';
  const uid='master-inner-'+(++seq)+'-';
  const controls=[];
  let selected=items.findIndex(({key})=>location.hash==='#'+group+'-'+key);
  if(selected<0)selected=0;
  const activate=(index,focus=false,persist=false)=>{
   selected=index;
   controls.forEach((button,i)=>{
    const active=i===index;
    button.classList.toggle('active',active);
    button.setAttribute('aria-selected',active?'true':'false');
    button.tabIndex=active?0:-1;
    items[i].node.hidden=!active;
    if(active&&focus)button.focus();
   });
   if(persist){
    const url=new URL(location.href);
    url.hash=group+'-'+items[index].key;
    history.replaceState(history.state,'',url.pathname+url.search+url.hash);
   }
  };
  items.forEach((item,i)=>{
   const btn=document.createElement('button');btn.type='button';
   btn.className='master-inner-tab';btn.id=uid+'tab-'+i;
   btn.setAttribute('role','tab');btn.setAttribute('aria-controls',uid+'panel-'+i);
   const title=document.createElement('span');title.textContent=item.label;
   btn.append(title);
   if(item.hint){
    const hint=document.createElement('small');hint.textContent=item.hint;btn.append(hint);
   }
   btn.onclick=()=>activate(i,false,true);
   btn.onkeydown=e=>{
    if(!['ArrowRight','ArrowLeft','Home','End'].includes(e.key))return;
    e.preventDefault();
    let next=i;
    if(e.key==='ArrowRight')next=(i+1)%items.length;
    if(e.key==='ArrowLeft')next=(i+items.length-1)%items.length;
    if(e.key==='Home')next=0;
    if(e.key==='End')next=items.length-1;
    activate(next,true,true);
   };
   tabs.append(btn);controls.push(btn);
   item.node.id=uid+'panel-'+i;item.node.setAttribute('role','tabpanel');
   item.node.setAttribute('aria-labelledby',btn.id);
   item.node.classList.add('master-inner-pane');
   stage.append(item.node);
  });
  const wrapper=document.createElement('section');
  wrapper.className='master-inner-shell';
  wrapper.append(tabs,stage);
  host.append(wrapper);
  activate(selected);
  return {activate:(key)=>{const i=items.findIndex(x=>x.key===key);if(i>=0)activate(i,false,true)}};
 }
 return {mount};
})();