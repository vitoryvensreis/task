(()=>{'use strict';
 const form=document.getElementById('sales-form');
 if(!form)return;
 document.querySelectorAll('[data-plan-select]').forEach(link=>{
  link.addEventListener('click',()=>{
   const select=form.querySelector('[name=plan_id]');
   if(select)select.value=link.dataset.planSelect;
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
