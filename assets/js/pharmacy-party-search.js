/** Server-backed selectors remain usable when the initial dropdown limit is exceeded. */
(() => {
    'use strict';
    document.querySelectorAll('[data-pharmacy-party-search]').forEach(input=>{
        const select=document.getElementById(input.dataset.target);if(!select)return;
        const feedback=document.createElement('small');feedback.setAttribute('role','status');feedback.className='block text-slate-500';input.after(feedback);
        let timer,controller;
        const initial=[...select.options].map(option=>option.cloneNode(true));
        input.closest('form')?.addEventListener('reset',()=>{clearTimeout(timer);controller?.abort();feedback.textContent='';select.replaceChildren(...initial.map(option=>option.cloneNode(true)));});
        input.addEventListener('input',()=>{clearTimeout(timer);controller?.abort();timer=setTimeout(async()=>{
            controller=new AbortController();feedback.textContent='Searching…';
            try{const response=await fetch(`${input.dataset.pharmacyPartySearch}?q=${encodeURIComponent(input.value.trim())}`,{credentials:'same-origin',headers:{Accept:'application/json'},signal:controller.signal});const result=await response.json();if(!response.ok||!result.success)throw new Error(result.message||'Search failed.');
                const current=select.selectedOptions[0];const selected=select.value;const keep=current&&selected&&!result.data.some(p=>String(p.id)===selected);
                const placeholder=select.options[0]?.textContent||'Select';select.replaceChildren(new Option(placeholder,''));if(keep)select.add(new Option(current.textContent,selected));
                for(const party of result.data)select.add(new Option(`${party.name}${party.phone?' · '+party.phone:' #'+party.id}`,party.id));select.value=selected;
                feedback.textContent=result.data.length?`${result.data.length} matches${keep?' · current selection retained':''}`:`No matches${keep?' · current selection retained':''}`;
            }catch(error){if(error.name!=='AbortError'){feedback.textContent=error.message;window.erp?.toast(error.message,'error');}}
        },250);});
    });
})();
