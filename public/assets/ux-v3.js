(() => {
'use strict';
const TYPES=new Set(['date','datetime-local','month','time']);
const mobile=()=>matchMedia('(max-width:760px)').matches;
const all=(s,r=document)=>Array.from(r.querySelectorAll(s));
const icon='<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"></rect><path d="M16 3v4M8 3v4M3 10h18"></path></svg>';

function labelOf(i){
  return i.getAttribute('title')||i.getAttribute('aria-label')||({date:'Data','datetime-local':'Data e horário',month:'Mês',time:'Horário'}[i.type]||'Data');
}
function displayValue(i){
  const v=String(i.value||'').trim();
  if(!v)return 'Selecionar '+labelOf(i).toLowerCase();
  if(i.type==='date'){const [y,m,d]=v.split('-');return d+'/'+m+'/'+y}
  if(i.type==='datetime-local'){const [a,b='']=v.split('T'),[y,m,d]=a.split('-');return d+'/'+m+'/'+y+' · '+b.slice(0,5)}
  if(i.type==='month'){const [y,m]=v.split('-');return m+'/'+y}
  if(i.type==='time')return v.slice(0,5);
  return v;
}
function picker(i){
  try{if(typeof i.showPicker==='function'){i.showPicker();return}}catch(_){}
  try{i.focus({preventScroll:true})}catch(_){i.focus()}
  try{i.click()}catch(_){}
}
function dateInput(i){
  if(!(i instanceof HTMLInputElement)||!TYPES.has(i.type)||i.dataset.uxDateEnhanced)return;
  i.dataset.uxDateEnhanced='1';
  const w=document.createElement('span'),b=document.createElement('button');
  w.className='ux-date-control';b.type='button';b.className='ux-date-trigger';
  b.innerHTML='<span class="ux-date-trigger-label"></span><span class="ux-date-trigger-icon">'+icon+'</span>';
  b.setAttribute('aria-label',labelOf(i)+': abrir seletor');
  i.parentNode.insertBefore(w,i);w.append(i,b);i.classList.add('ux-date-native');i.tabIndex=-1;i.setAttribute('aria-hidden','true');
  const paint=()=>{const shown=displayValue(i);b.querySelector('.ux-date-trigger-label').textContent=shown;b.setAttribute('aria-label',labelOf(i)+': '+shown+'. Abrir seletor');b.classList.toggle('is-empty',!i.value);w.classList.toggle('has-error',i.matches(':invalid'));b.disabled=i.disabled};
  i._uxDatePaint=paint;b.addEventListener('click',()=>picker(i));i.addEventListener('input',paint);i.addEventListener('change',paint);i.addEventListener('invalid',()=>{w.classList.add('has-error');b.focus()});paint();
}
function dates(){all('input[type="date"],input[type="datetime-local"],input[type="month"],input[type="time"]').forEach(dateInput)}

function table(t){
  if(!(t instanceof HTMLTableElement)||t.dataset.uxTableEnhanced)return;
  const heads=all('thead th',t).map(x=>x.textContent.replace(/\s+/g,' ').trim());if(!heads.length)return;
  t.dataset.uxTableEnhanced='1';t.classList.add('ux-card-table');
  all('tbody tr',t).forEach(r=>{const cells=Array.from(r.children).filter(x=>x.tagName==='TD');if(cells.length===1&&cells[0].hasAttribute('colspan'))return;cells.forEach((td,n)=>{let l=heads[n]||'';if(!l&&td.querySelector('button,a,.row-actions'))l='Ações';td.dataset.uxLabel=l})});
}
function tables(){all('#content table.table,#modal-root table.table').forEach(table)}

function filterInfo(g){const c=all('select,input',g).filter(x=>!x.closest('.search-box'));const n=c.filter(x=>x.type==='checkbox'?x.checked:String(x.value||'').trim()).length;return n?(n+' ativo'+(n>1?'s':'')):'Refinar resultados'}
function filterGroup(g){
  if(!(g instanceof HTMLElement))return;if(g.dataset.uxFilterEnhanced){if(typeof g._uxFilterSync==='function')g._uxFilterSync();return}g.dataset.uxFilterEnhanced='1';
  const b=document.createElement('button');b.type='button';b.className='ux-filter-toggle';b.innerHTML='<span>Filtros</span><small>Refinar resultados</small>';g.parentNode.insertBefore(b,g);
  let open=false;const sync=()=>{const m=mobile();g.classList.toggle('ux-filter-collapsed',m&&!open);b.hidden=!m;b.querySelector('small').textContent=open?'Ocultar opções':filterInfo(g);b.setAttribute('aria-expanded',String(!m||open))};
  g._uxFilterSync=sync;b.addEventListener('click',()=>{open=!open;sync()});g.addEventListener('change',sync);sync();
}
function filters(){all('.advanced-filter-grid,.toolbar.kanban-filters').forEach(filterGroup)}

let agendaRoute=false,agendaDone=false;
function isAgenda(){return /\/agenda\/?$/i.test(location.pathname)}
function agenda(){
  const here=isAgenda();if(!here){agendaRoute=false;agendaDone=false;return}if(!agendaRoute){agendaRoute=true;agendaDone=false}
  if(mobile()&&!agendaDone){const day=document.querySelector('.agenda-view[data-view="day"]');if(day){agendaDone=true;if(!day.classList.contains('active')){day.click();return}}}
  const box=document.querySelector('.calendar-sources');if(!box||box.dataset.uxAgendaEnhanced)return;box.dataset.uxAgendaEnhanced='1';
  const head=box.querySelector('.panel-head');if(!head)return;const b=document.createElement('button');b.type='button';b.className='ux-agenda-source-toggle';b.textContent='Mostrar agendas';b.setAttribute('aria-expanded','false');
  const wrap=document.createElement('div');wrap.className='row-actions ux-agenda-source-actions';const sync=head.querySelector('#sync-google');if(sync)wrap.append(sync);wrap.append(b);head.append(wrap);
  b.addEventListener('click',()=>{const open=box.classList.toggle('is-open');b.textContent=open?'Ocultar agendas':'Mostrar agendas';b.setAttribute('aria-expanded',String(open))});
}
function run(){dates();tables();filters();agenda();document.documentElement.classList.add('ux-v311')}
let queued=false;function schedule(){if(queued)return;queued=true;requestAnimationFrame(()=>{queued=false;run()})}
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',run,{once:true});else run();
new MutationObserver(m=>{if(m.some(x=>x.addedNodes.length))schedule()}).observe(document.documentElement,{childList:true,subtree:true});
document.addEventListener('change',schedule,true);addEventListener('popstate',schedule);addEventListener('resize',schedule,{passive:true});
})();
