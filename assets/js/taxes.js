(() => {
    'use strict';
    const config = JSON.parse(document.getElementById('taxConfig').textContent);
    const dialog = document.getElementById('taxDialog'), form = document.getElementById('taxForm');
    const add = document.getElementById('addTax'), save = document.getElementById('saveTax'), close = document.getElementById('closeTax');
    const table = erp.initDataTable('#taxTable', {order: [], columnDefs: [{orderable: false, targets: [4]}]});
    let busy = false, uuid;
    function render(taxes) {
        const rows = taxes.map(tax => {
            const row = document.createElement('tr');
            [tax.name, `${Number(tax.percentage).toFixed(2)}%`, Number(tax.is_active) ? 'Active' : 'Inactive', tax.created_at].forEach((value, index) => {
                const cell = document.createElement('td');cell.className = 'p-3 border-t border-slate-100';
                const text = document.createElement('span');text.textContent = value;
                if (index === 2) text.className = Number(tax.is_active) ? 'bg-emerald-50 text-emerald-700 px-3 py-1 rounded-full text-xs' : 'bg-slate-100 text-slate-500 px-3 py-1 rounded-full text-xs';
                cell.append(text);row.append(cell);
            });
            const action = document.createElement('td');action.className = 'p-3 border-t border-slate-100';
            if (!Number(tax.is_active)) {
                const button = document.createElement('button');button.type = 'button';button.textContent = 'Activate';button.dataset.taxId = tax.id;button.dataset.taxName = tax.name;
                button.className = 'border border-slate-200 rounded-lg px-3 py-2 text-xs font-semibold';action.append(button);
            }
            row.append(action);return row;
        });
        if (table) { table.clear();table.rows.add(rows);table.draw(false); }
        else document.querySelector('#taxTable tbody').replaceChildren(...rows);
    }
    function loading(value) {
        busy = value;add.disabled = save.disabled = close.disabled = value;
        form.setAttribute('aria-busy', String(value));save.textContent = value ? 'Saving…' : 'Save tax';
    }
    add.addEventListener('click', () => { if (busy) return;form.reset();uuid = crypto.randomUUID();dialog.showModal(); });
    close.addEventListener('click', () => { if (!busy) dialog.close(); });
    dialog.addEventListener('cancel', event => { if (busy) event.preventDefault(); });
    form.addEventListener('submit', async event => {
        event.preventDefault();if (busy || !form.reportValidity()) return;
        loading(true);
        try {
            const result = await erp.web(config.saveUrl, {method: 'POST', body: JSON.stringify({...Object.fromEntries(new FormData(form)), uuid, action: 'add'})});
            render(result.data);dialog.close();erp.toast('Tax added and activated.');
        } catch (_) { /* Keep the form and request ID for a safe retry. */ }
        finally { loading(false); }
    });
    document.getElementById('taxTable').addEventListener('click', async event => {
        const button = event.target.closest('[data-tax-id]');if (!button || busy) return;
        loading(true);
        try {
            if (!await erp.confirm(`Activate ${button.dataset.taxName}? The current tax will become inactive.`, {title: 'Change active tax'})) return;
            const result = await erp.web(config.saveUrl, {method: 'POST', body: JSON.stringify({action: 'activate', id: button.dataset.taxId})});
            render(result.data);erp.toast('Active tax updated.');
        } catch (_) { /* The shared request helper reports the failure. */ }
        finally { loading(false); }
    });
    render(config.taxes);
})();
