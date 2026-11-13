(() => {
    const form=document.getElementById('appearanceSettings');let saving=false;
    const tabs=[...document.querySelectorAll('[data-settings-tab]')];
    function showSection(key){
        if(!tabs.some(tab=>tab.dataset.settingsTab===key))key='identity';
        tabs.forEach(tab=>{const active=tab.dataset.settingsTab===key;tab.setAttribute('aria-pressed',String(active));tab.classList.toggle('bg-brand-50',active);tab.classList.toggle('text-brand-700',active);tab.classList.toggle('text-slate-500',!active);});
        document.querySelectorAll('[data-settings-panel]').forEach(panel=>panel.classList.toggle('hidden',panel.dataset.settingsPanel!==key));
        document.getElementById('saveAppearance').classList.toggle('hidden',key==='printers');
    }
    tabs.forEach(tab=>tab.addEventListener('click',()=>{history.replaceState(null,'','#'+tab.dataset.settingsTab);showSection(tab.dataset.settingsTab);}));
    showSection(location.hash.slice(1));
    // Reveal an invalid field's section before the browser moves focus to it.
    form.addEventListener('invalid',event=>{const panel=event.target.closest('[data-settings-panel]');if(panel)showSection(panel.dataset.settingsPanel);},true);
    function updatePreview(){
        const f=form.elements;
        document.querySelectorAll('[data-preview]').forEach(element=>{element.textContent=f.namedItem(element.dataset.preview)?.value||'';});
        document.querySelectorAll('[data-color-value]').forEach(element=>{element.textContent=f.namedItem(element.dataset.colorValue).value.toUpperCase();});
        document.getElementById('appearanceCanvas').style.backgroundColor=f.background_color.value;
        document.getElementById('appearanceAccent').style.color=f.accent_color.value;
        document.getElementById('appearanceButton').style.backgroundColor=f.brand_color.value;
        const receipt=document.getElementById('receiptLivePreview');
        receipt.style.maxWidth=f.thermal_paper_width.value==='58'?'230px':'320px';
        receipt.style.lineHeight=f.receipt_style.value==='compact'?'1.3':'1.7';
    }
    form.addEventListener('input',updatePreview);form.addEventListener('change',updatePreview);updatePreview();
    form.addEventListener('submit',async event=>{event.preventDefault();if(saving||!form.reportValidity())return;saving=true;const button=document.getElementById('saveAppearance');button.disabled=true;try{await erp.web(form.dataset.url,{method:'POST',body:JSON.stringify(Object.fromEntries(new FormData(form)))});erp.toast('Settings saved.');location.reload();}catch(_){}finally{saving=false;button.disabled=false;}});
    document.getElementById('previewThermal').addEventListener('click',()=>{const f=form.elements;document.getElementById('thermalPreviewText').textContent=[f.business_name.value,f.business_address.value,f.business_phone.value,f.receipt_header.value,'-'.repeat(f.thermal_paper_width.value==='58'?32:48),'EXAMPLE RECEIPT','Example order   1 × 10.00','TOTAL: USD 10.00','Paid: USD 10.00',f.receipt_footer.value].filter(Boolean).join(f.receipt_style.value==='compact'?'\n':'\n\n');document.getElementById('thermalPreview').showModal();});
    const printers=document.getElementById('printerManagement'),printerForm=document.getElementById('addReceiptPrinter');
    function printerFields(){const network=printerForm.elements.type.value==='network';printers.querySelectorAll('[data-printer-network]').forEach(field=>field.classList.toggle('hidden',!network));printers.querySelector('[data-printer-windows]').classList.toggle('hidden',network);printerForm.elements.name.required=!network;printerForm.elements.name.disabled=network;printerForm.elements.ip.required=network;printerForm.elements.ip.disabled=!network;printerForm.elements.port.disabled=!network;}
    printerForm.elements.type.addEventListener('change',printerFields);printerFields();
    printerForm.addEventListener('submit',async event=>{event.preventDefault();if(!printerForm.reportValidity())return;const button=printerForm.querySelector('button');if(button.disabled)return;button.disabled=true;try{await erp.web(printers.dataset.url,{method:'POST',body:JSON.stringify({...Object.fromEntries(new FormData(printerForm)),action:'add'})});erp.toast('Printer added and activated.');location.reload();}catch(_){}finally{button.disabled=false;}});
    printers.addEventListener('click', async event => {
        const button = event.target.closest('[data-printer-action]');
        if (!button || button.disabled) return;
        const action = button.dataset.printerAction;
        const messages = {
            activate: 'Activate this printer and disable the previous printer?',
            disable: 'Disable this printer?',
            delete: 'Delete this printer from receipt settings? If it is active, receipt printing will be disabled.',
        };
        button.disabled = true;
        try {
            const options = action === 'delete'
                ? {title: 'Delete receipt printer', confirmButtonText: 'Delete printer', destructive: true}
                : {title: 'Change active printer'};
            if (!await erp.confirm(messages[action], options)) return;
            await erp.web(printers.dataset.url, {method: 'POST', body: JSON.stringify({id: button.dataset.printerId, action})});
            if (action === 'delete') erp.toast('Receipt printer deleted.');
            location.reload();
        } catch (_) {
            // The shared web request helper displays the error.
        } finally {
            button.disabled = false;
        }
    });
})();
