(() => {
  'use strict';
  const board=document.getElementById('demo-kanban');
  if(!board)return;
  const root=board.closest('.preview');
  const columns=[...board.querySelectorAll('.column[data-lane]')];
  const order=['todo','doing','done'];
  const cards=[...board.querySelectorAll('[data-demo-task]')];
  const initial=new Map(cards.map(card=>[card.dataset.demoTask,card.closest('[data-lane]').dataset.lane]));
  const counter=()=>columns.forEach(c=>{c.querySelector('[data-count]').textContent=c.querySelectorAll('[data-demo-task]').length.toString().padStart(2,'0')});
  const status=document.getElementById('demo-status');
  const reduced=window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  let pauseUntil=0,animating=false,index=0,dragged=null,observerVisible=true,timer=0;
  const lane=card=>card.closest('[data-lane]')?.dataset.lane;
  const name=code=>({todo:'A fazer',doing:'Em andamento',done:'Concluído'})[code]||'Etapa';
  function move(card,destination,automatic=false){
    const original=lane(card),target=columns.find(c=>c.dataset.lane===destination);
    if(!target||original===destination||animating)return;
    animating=true;
    const before=card.getBoundingClientRect();
    target.querySelector('[data-stack]').append(card);
    const after=card.getBoundingClientRect();
    card.dataset.moved='1';
    if(!reduced&&card.animate){
      const dx=before.left-after.left,dy=before.top-after.top;
      const animation=card.animate([{transform:'translate('+dx+'px,'+dy+'px) scale(1.03)',opacity:.88},{transform:'translate(0,0) scale(1)',opacity:1}],{duration:660,easing:'cubic-bezier(.2,.85,.2,1)'});
      animation.finished.catch(()=>{}).finally(()=>{animating=false;delete card.dataset.moved});
    }else{animating=false;delete card.dataset.moved}
    counter();
    if(status)status.textContent=(automatic?'Automação: ':'Você moveu: ')+card.querySelector('strong').textContent+' → '+name(destination);
    card.setAttribute('aria-label','Mover '+card.querySelector('strong').textContent+' para a próxima etapa');
    if(!automatic)pauseUntil=Date.now()+7000;
  }
  const next=card=>order[(order.indexOf(lane(card))+1)%order.length];
  cards.forEach(card=>{
    card.addEventListener('click',()=>move(card,next(card)));
    card.addEventListener('keydown',e=>{
      if(!['Enter',' ','ArrowRight','ArrowLeft'].includes(e.key))return;
      e.preventDefault();
      const current=order.indexOf(lane(card));
      move(card,e.key==='ArrowLeft'?order[(current+order.length-1)%order.length]:order[(current+1)%order.length]);
    });
    card.addEventListener('dragstart',e=>{dragged=card;pauseUntil=Date.now()+10000;card.classList.add('demo-dragging');e.dataTransfer.effectAllowed='move';e.dataTransfer.setData('text/plain',card.dataset.demoTask)});
    card.addEventListener('dragend',()=>{card.classList.remove('demo-dragging');dragged=null;columns.forEach(c=>c.classList.remove('demo-over'))});
    card.addEventListener('pointermove',e=>{
      if(e.pointerType!=='mouse'||reduced)return;
      const r=card.getBoundingClientRect();
      card.style.setProperty('--demo-rotate-y',((e.clientX-r.left)/r.width-.5)*7+'deg');
      card.style.setProperty('--demo-rotate-x',(.5-(e.clientY-r.top)/r.height)*5+'deg');
    },{passive:true});
    card.addEventListener('pointerleave',()=>{card.style.removeProperty('--demo-rotate-y');card.style.removeProperty('--demo-rotate-x')});
  });
  columns.forEach(column=>{
    column.addEventListener('dragover',e=>{if(!dragged)return;e.preventDefault();e.dataTransfer.dropEffect='move';column.classList.add('demo-over')});
    column.addEventListener('dragleave',e=>{if(!column.contains(e.relatedTarget))column.classList.remove('demo-over')});
    column.addEventListener('drop',e=>{e.preventDefault();column.classList.remove('demo-over');if(dragged)move(dragged,column.dataset.lane)});
  });
  root.addEventListener('pointerenter',()=>{pauseUntil=Date.now()+3000});
  root.addEventListener('pointerleave',()=>{pauseUntil=Date.now()+1200});
  root.addEventListener('focusin',()=>{pauseUntil=Date.now()+6000});
  root.addEventListener('focusout',()=>{pauseUntil=Date.now()+1200});
  document.getElementById('demo-reset')?.addEventListener('click',()=>{
    pauseUntil=Date.now()+5500;
    for(const card of cards){const to=initial.get(card.dataset.demoTask);const target=columns.find(c=>c.dataset.lane===to);target?.querySelector('[data-stack]')?.append(card)}
    counter();if(status)status.textContent='Quadro reiniciado. Experimente arrastar um card para outra coluna.';
  });
  const tick=()=>{
    if(reduced||document.hidden||!observerVisible||Date.now()<pauseUntil||animating||dragged)return;
    const card=cards[index%cards.length];index++;
    move(card,next(card),true);
  };
  if('IntersectionObserver' in window){
    new IntersectionObserver(entries=>{observerVisible=!!entries[0]?.isIntersecting},{threshold:.15}).observe(root);
  }
  counter();
  timer=window.setInterval(tick,3900);
  window.addEventListener('pagehide',()=>window.clearInterval(timer),{once:true});
})();
