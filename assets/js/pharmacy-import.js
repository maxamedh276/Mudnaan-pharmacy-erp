(() => {
    'use strict';
    const root=document.getElementById('pharmacyImport');if(!root)return;
    const file=document.getElementById('pharmacyImportFile'),preview=document.getElementById('pharmacyImportPreview'),commit=document.getElementById('pharmacyImportCommit'),status=document.getElementById('pharmacyImportStatus'),results=document.getElementById('pharmacyImportResults');
    let csv='',valid=false;
    file.addEventListener('change',()=>{csv='';valid=false;commit.disabled=true;results.hidden=true;status.textContent='';});
    async function request(action){const response=await fetch(`${root.dataset.base}/${action}/save`,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','Accept':'application/json','X-Pharmacy-CSRF':root.dataset.token},body:JSON.stringify({csv})});const result=await response.json();if(!response.ok||!result.success)throw new Error(result.message||'Import request failed.');return result;}
    preview.addEventListener('click',async()=>{
        if(preview.disabled)return;preview.disabled=true;commit.disabled=true;valid=false;status.textContent='Reading and checking the file…';
        try{const selected=file.files[0];if(!selected||selected.size>2*1024*1024)throw new Error('Choose a UTF-8 CSV up to 2 MB.');csv=await selected.text();const result=await request('import-preview');const data=result.data;results.replaceChildren();results.hidden=false;
            status.textContent=data.already_imported?`Already imported as #${data.import_id}. No duplicates will be created.`:`${data.valid_count} valid medicines · ${data.errors.length} row errors. ${data.errors.length?'Correct the file and preview again.':'Ready to import.'}`;
            for(const error of data.errors){const p=document.createElement('p');p.className='text-sm text-red-700 border-b py-2';p.textContent=`Row ${error.row} · ${error.medicine}: ${error.message}`;results.append(p);}
            for(const record of data.records.slice(0,40)){const card=document.createElement('div');card.className='border-b py-3 text-sm';const title=document.createElement('strong');title.textContent=record.name;const p=document.createElement('p');p.className='text-slate-500';p.textContent=`${record.dosage_form} · ${record.strength} · ${record.base_unit} · ${record.units.length} units · opening ${record.opening_quantity} ${record.base_unit}`;card.append(title,p);results.append(card);}
            if(data.records.length>40){const p=document.createElement('p');p.textContent=`Showing the first 40 of ${data.records.length} validated records.`;results.append(p);}valid=!data.errors.length&&!data.already_imported;commit.disabled=!valid;
        }catch(error){status.textContent=error.message;window.erp?.toast(error.message,'error');}finally{preview.disabled=false;}
    });
    commit.addEventListener('click',async()=>{
        if(!valid||commit.disabled)return;const answer=await Swal.fire({title:'Import these medicines?',text:'All validated rows and opening stock will be saved together. Existing medicines will not be overwritten.',icon:'question',showCancelButton:true,confirmButtonText:'Import medicines'});if(!answer.isConfirmed)return;
        commit.disabled=true;preview.disabled=true;file.disabled=true;status.textContent='Importing…';
        try{const result=await request('import-commit');valid=false;status.textContent=`Import #${result.id} completed. Medicines, units and opening batches are now available.`;window.erp?.toast(result.message);}
        catch(error){status.textContent=error.message;commit.disabled=!valid;window.erp?.toast(error.message,'error');}finally{preview.disabled=false;file.disabled=false;}
    });
})();
