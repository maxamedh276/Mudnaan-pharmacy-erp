/** Customer advance list and creation through the existing receivables API. */
(() => {
    'use strict';
    $.fn.dataTable.ext.search.push((settings, _data, index) => {
        if (settings.nTable.id !== 'depositsTable') return true;
        const row = settings.aoData[index].nTr.dataset;
        const customer = document.getElementById('depositCustomerFilter').value;
        const status = document.getElementById('depositStatusFilter').value;
        return (!customer || row.customer === customer) && (!status || row.status === status);
    });
    const table = erp.initDataTable('#depositsTable', { order: [[2,'desc']], responsive:false, pageLength:25, language:{emptyTable:'No customer deposits yet.'} });
    ['depositCustomerFilter','depositStatusFilter'].forEach(id => document.getElementById(id).addEventListener('change', () => table.draw()));
    const dialog = document.getElementById('depositDialog');
    const form = document.getElementById('depositForm');
    if (!form) return;
    const saveButton = document.getElementById('saveDeposit');
    let saving = false;
    document.getElementById('addDeposit').addEventListener('click', () => { form.reset(); updateBankField(); dialog.classList.remove('hidden'); form.elements.customer_id.focus(); });
    dialog.querySelectorAll('[data-close-deposit]').forEach(button => button.addEventListener('click', () => { if (!saving) { dialog.classList.add('hidden'); document.getElementById('addDeposit').focus(); } }));
    function updateBankField() {
        const bankRequired = ['bank','cheque'].includes(form.elements.method.value);
        document.getElementById('depositBankField').classList.toggle('hidden', !bankRequired);
        form.elements.bank_account_id.required = bankRequired;
        if (!bankRequired) form.elements.bank_account_id.value = '';
    }
    dialog.addEventListener('keydown', event => {
        if (Swal.isVisible()) return;
        if (event.key === 'Escape' && !saving) { dialog.classList.add('hidden'); document.getElementById('addDeposit').focus(); }
        if (event.key === 'Tab') {
            const controls = [...dialog.querySelectorAll('button,input,select,textarea')].filter(element => !element.disabled && element.offsetParent !== null);
            const first = controls[0], last = controls[controls.length - 1];
            if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
            if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
        }
    });
    form.elements.method.addEventListener('change', updateBankField);
    form.addEventListener('submit', async event => {
        event.preventDefault(); if (saving || !form.reportValidity()) return;
        saving = true; saveButton.disabled = true;
        try {
            const amount = Number(form.elements.amount.value);
            if (!await erp.confirm(`Record USD ${amount.toFixed(2)} for ${form.elements.customer_id.selectedOptions[0].textContent}?`, { title:'Record customer deposit?',confirmButtonText:'Save deposit' })) return;
            saveButton.textContent = 'Saving…';
            await erp.api('customer-deposits', { method:'POST', body:JSON.stringify({customer_id:Number(form.elements.customer_id.value),amount,method:form.elements.method.value,bank_account_id:Number(form.elements.bank_account_id.value)||null,reference:form.elements.reference.value.trim()||null,notes:form.elements.notes.value.trim()||null}) });
            dialog.classList.add('hidden'); document.getElementById('addDeposit').focus(); erp.toast('Customer deposit recorded.');
            try {
                const response = await fetch(window.location.href, { credentials:'same-origin' });
                if (!response.ok || response.redirected) throw new Error();
                const page = new DOMParser().parseFromString(await response.text(),'text/html');
                const body = page.querySelector('#depositsTable tbody');
                if (!body) throw new Error();
                table.clear().rows.add([...body.rows]).draw();
            } catch (_) { erp.toast('Deposit saved. Refresh the page to see the updated list.', 'error'); }
        } catch (_) { /* API helper shows errors; retain all entered values. */ }
        finally { saving = false; saveButton.disabled = false; saveButton.textContent = 'Save deposit'; }
    });
})();
