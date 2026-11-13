/** Consistent form details also apply to AJAX-created medicine and receipt rows. */
(() => {
    'use strict';
    function selectPanel(form, name) {
        form.querySelectorAll('[data-entry-panel]').forEach(panel=>{panel.hidden=panel.dataset.entryPanel!==name;});
        form.querySelectorAll('[data-entry-tab]').forEach(tab=>{
            const selected=tab.dataset.entryTab===name;
            tab.setAttribute('aria-selected',String(selected));tab.tabIndex=selected?0:-1;
        });
    }
    const enhance = () => {
        document.querySelectorAll('form').forEach(form => {
            if (form.querySelectorAll('input:not([type=hidden]),select,textarea').length < 3) return;
            form.classList.add('modern-entry-form');
            form.querySelectorAll('[required]').forEach(input => {
                const label = input.closest('label') || (input.id && document.querySelector('label[for="'+CSS.escape(input.id)+'"]'));
                if (!label || label.querySelector('.entry-required') || label.textContent.includes('*')) return;
                const marker=document.createElement('span');marker.className='entry-required';marker.textContent='*';marker.setAttribute('aria-hidden','true');
                const textNode=[...label.childNodes].find(node=>node.nodeType===Node.TEXT_NODE && node.textContent.trim());
                if (textNode) textNode.after(marker); else label.append(marker);
            });
        });
    };
    enhance();
    let scheduled=false;
    new MutationObserver(()=>{if(scheduled)return;scheduled=true;queueMicrotask(()=>{scheduled=false;enhance();});}).observe(document.getElementById('app-main'),{childList:true,subtree:true});
    document.addEventListener('click',event=>{
        const tab=event.target.closest('[data-entry-tab]');
        if(tab)selectPanel(tab.closest('form'),tab.dataset.entryTab);
        if(event.target.closest('[data-entry-cancel]') && !event.target.closest('form')?.querySelector('#pharmacySave:disabled')) event.target.closest('dialog')?.close();
    });
    document.addEventListener('reset',event=>{event.target.querySelectorAll('[aria-invalid]').forEach(input=>input.removeAttribute('aria-invalid'));selectPanel(event.target,'details');});
    const validatingForms = new WeakSet();
    document.addEventListener('invalid',event=>{
        const panel=event.target.closest('[data-entry-panel]');
        const form=event.target.form;
        // Keep the first invalid field visible when more than one tab has errors.
        if(form && !validatingForms.has(form)) {
            validatingForms.add(form);setTimeout(()=>validatingForms.delete(form),0);
            if(panel?.hidden)selectPanel(form,panel.dataset.entryPanel);
        }
        event.target.setAttribute('aria-invalid','true');
    },true);
    document.addEventListener('keydown',event=>{
        const tab=event.target.closest('[data-entry-tab]');
        if(!tab || !['ArrowLeft','ArrowRight','Home','End'].includes(event.key))return;
        const tabs=[...tab.parentElement.querySelectorAll('[data-entry-tab]')];
        const target=event.key==='Home'?tabs[0]:event.key==='End'?tabs.at(-1):tabs[(tabs.indexOf(tab)+1)%tabs.length];
        event.preventDefault();target.click();target.focus();event.stopPropagation();
    });
    document.addEventListener('input',event=>{if(event.target.matches('input,textarea,select') && event.target.validity.valid)event.target.removeAttribute('aria-invalid');});
    document.addEventListener('change',event=>{if(event.target.matches('input,textarea,select') && event.target.validity.valid)event.target.removeAttribute('aria-invalid');});
})();
