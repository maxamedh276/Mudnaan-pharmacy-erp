(() => {
    'use strict';
    const root=document.getElementById('pharmacyWorkspace'), dialog=document.getElementById('patientCareDialog');
    if(!root||!dialog)return;
    const body=document.getElementById('patientCareBody'), form=document.getElementById('patientCareForm');
    const base=root.dataset.detailUrl.replace(/\/prescriptions$/,'');
    let patientId, controller, requestKey=crypto.randomUUID();
    document.getElementById('patientCareClose').addEventListener('click',()=>dialog.close());
    dialog.addEventListener('close',()=>controller?.abort());
    const block=(title,text)=>{const section=document.createElement('section');section.className='border rounded-lg p-3';const heading=document.createElement('h3');heading.className='font-semibold';heading.textContent=title;const p=document.createElement('p');p.className='text-sm whitespace-pre-wrap break-words';p.textContent=text;section.append(heading,p);return section;};
    async function load(id){
        controller?.abort();controller=new AbortController();body.textContent='Loading patient history…';
        if(form)form.hidden=true;
        try{
            const response=await fetch(`${base}/patients/${id}/history`,{credentials:'same-origin',headers:{Accept:'application/json'},signal:controller.signal});
            const result=await response.json();if(!response.ok||!result.success)throw new Error(result.message||'Unable to load patient history.');
            const data=result.data;body.replaceChildren();
            body.append(block(data.patient.name,`Allergies (recorded): ${data.patient.allergies||'Not recorded'}\nPhone: ${data.patient.phone||'—'}\nDate of birth: ${data.patient.date_of_birth||'—'}`));
            const title=document.createElement('h3');title.textContent='Care notes';title.className='font-bold';body.append(title);
            for(const n of data.notes)body.append(block(`${n.kind} · ${n.created_at} · ${n.staff_name||''}`,n.note));
            if(!data.notes.length)body.append(block('No care notes','No notes have been recorded for this patient.'));
            const rx=document.createElement('h3');rx.textContent='Prescriptions';rx.className='font-bold';body.append(rx);
            for(const r of data.prescriptions)body.append(block(`#${r.id} · ${r.status}`,`${r.prescriber} · ${r.issued_on} · Ref: ${r.reference||'—'}`));
            const fills=document.createElement('h3');fills.textContent='Recorded prescription dispensing';fills.className='font-bold';body.append(fills);
            for(const f of data.dispensings)body.append(block(`${f.medicine_name} · ${f.invoice_no}`,`${f.quantity} ${f.unit_label||'unit'} · ${f.sold_at} · Rx #${f.prescription_id}`));
            if(form){form.hidden=false;form.elements.patient_id.value=id;}
        }catch(error){if(error.name==='AbortError')return;body.textContent=error.message;const retry=document.createElement('button');retry.type='button';retry.textContent='Retry';retry.className='underline block';retry.addEventListener('click',()=>load(id));body.append(retry);}
    }
    root.addEventListener('click',event=>{const button=event.target.closest('[data-patient-history]');if(!button)return;patientId=button.dataset.patientHistory;requestKey=crypto.randomUUID();form?.reset();if(form)form.querySelector('[data-care-feedback]').textContent='';dialog.showModal();load(patientId);});
    form?.addEventListener('submit',async event=>{
        event.preventDefault();const button=form.querySelector('button[type="submit"]');if(button.disabled||!form.reportValidity())return;button.disabled=true;
        const feedback=form.querySelector('[data-care-feedback]');feedback.textContent='';
        try{const data=Object.fromEntries(new FormData(form));data.request_key=requestKey;
            const response=await fetch(`${base}/care-note/save`,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','Accept':'application/json','X-Pharmacy-CSRF':root.dataset.token},body:JSON.stringify(data)});
            const result=await response.json();if(!response.ok||!result.success)throw new Error(result.message||'Unable to save care note.');
            requestKey=crypto.randomUUID();form.elements.note.value='';window.erp?.toast(result.message);await load(patientId);
        }catch(error){feedback.textContent=error.message;window.erp?.toast(error.message,'error');}finally{button.disabled=false;}
    });
})();
