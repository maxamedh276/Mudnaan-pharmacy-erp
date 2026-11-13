(() => {
    'use strict';const root=document.getElementById('pharmacyCashShifts');if(!root)return;
    root.querySelectorAll('form[data-shift-action]').forEach(form=>{const key=crypto.randomUUID();form.addEventListener('submit',async event=>{
        event.preventDefault();const button=form.querySelector('button');if(button.disabled||!form.reportValidity())return;
        if(form.dataset.shiftAction==='cash-shift-close'){const answer=await Swal.fire({title:'Close this cash shift?',text:'The server will reconcile your count against recorded cash movements. This closing record is permanent.',icon:'question',showCancelButton:true,confirmButtonText:'Close shift'});if(!answer.isConfirmed)return;}
        button.disabled=true;const feedback=form.querySelector('[data-feedback]');feedback.textContent='';
        try{const input=Object.fromEntries(new FormData(form));input.request_key=key;const response=await fetch(`${root.dataset.base}/${form.dataset.shiftAction}/save`,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','Accept':'application/json','X-Pharmacy-CSRF':root.dataset.token},body:JSON.stringify(input)});const result=await response.json();if(!response.ok||!result.success)throw new Error(result.message||'Unable to save cash shift.');window.erp?.toast(result.message);location.reload();}catch(error){feedback.textContent=error.message;window.erp?.toast(error.message,'error');}finally{button.disabled=false;}
    });});
})();
