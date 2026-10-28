(()=>{'use strict';
 const form=document.getElementById('sales-form');
 if(!form)return;
 const select=form.querySelector('[name=plan_id]'),qty=form.querySelector('[name=extra_seats]');
 const pricing=(()=>{try{return JSON.parse(document.getElementById('sales-pricing')?.textContent||'[]')}catch{return []}})();
 const preview=document.getElementById('sales-total');
 const fmt=c=>new Intl.NumberFormat('pt-BR',{style:'currency',currency:'BRL'}).format(c/100);
 const renderPrice=()=>{
  const plan=pricing.find(x=>Number(x.id)===Number(select.value));if(!plan)return;
  const supports=Number(plan.seat)>0;
  qty.disabled=!supports;qty.max=supports?'100':'0';if(!supports)qty.value='0';
  const n=Math.max(0,Math.min(100,Number(qty.value)||0));
  preview.textContent='Estimativa: '+fmt(Number(plan.base)+n*Number(plan.seat))+' por '+plan.period+
   ' ('+fmt(plan.base)+' + '+n+' vaga(s) × '+fmt(plan.seat)+'). Clientes ilimitados. A contratação será confirmada pela equipe.';
 };
 select.addEventListener('change',renderPrice);qty.addEventListener('input',renderPrice);
 renderPrice();

 document.querySelectorAll('[data-plan-select]').forEach(link=>{
  link.addEventListener('click',()=>{
   const select=form.querySelector('[name=plan_id]');
   if(select)select.value=link.dataset.planSelect;
   renderPrice();
  });
 });
 form.addEventListener('submit',async event=>{
  event.preventDefault();
  const button=form.querySelector('[type=submit]');
  const feedback=document.getElementById('sales-feedback');
  if(!form.reportValidity())return;
  button.disabled=true;
  feedback.classList.remove('error');
  feedback.textContent='Enviando solicitação…';
  const fields=Object.fromEntries(new FormData(form).entries());
  try{
   const response=await fetch('api.php?action=sales.request',{
    method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json'},
    body:JSON.stringify(fields),credentials:'same-origin'
   });
   const json=await response.json().catch(()=>({}));
   if(!response.ok||json.error)throw Error(json.error||'Não foi possível enviar a solicitação.');
   feedback.textContent=json.message||'Solicitação recebida.';
   form.reset();
  }catch(error){
   feedback.classList.add('error');
   feedback.textContent=error.message||'Não foi possível enviar. Tente novamente.';
  }finally{button.disabled=false;}
 });
})();
