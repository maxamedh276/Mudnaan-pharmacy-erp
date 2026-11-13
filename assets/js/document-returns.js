/** Server-calculated return previews; one request ID survives failed submissions and retries. */
(() => {
    'use strict';
    const config = JSON.parse(document.getElementById('returnConfig').textContent);
    const dialog = document.getElementById('returnDialog');
    const form = document.getElementById('returnForm');
    const sourceSelect = document.getElementById('returnSource');
    const body = document.getElementById('returnItems');
    const status = document.getElementById('returnStatus');
    const save = document.getElementById('saveReturn');
    const account = document.getElementById('returnAccount');
    let source = null, quote = null, requestId = null, busy = false, revision = 0, timer, pending;
    let previousDisabled = new Map();
    const money = value => Number(value).toFixed(2);
    const escape = value => String(value).replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
    const uuid = () => crypto.randomUUID ? crypto.randomUUID() : '10000000-1000-4000-8000-100000000000'.replace(/[018]/g, char => (char ^ crypto.getRandomValues(new Uint8Array(1))[0] & 15 >> char / 4).toString(16));
    const selectedItems = () => Array.from(body.querySelectorAll('[data-return-line]')).filter(row => row.querySelector('input[type=checkbox]').checked).map(row => ({id: Number(row.dataset.returnLine), quantity: row.querySelector('input[type=number]').value}));
    function totals(value = null) {
        quote = value;
        document.getElementById('returnTotal').textContent = money(value?.total || 0);
        document.getElementById('returnDebt').textContent = money(value?.debt_reduction || 0);
        document.getElementById('returnRefund').textContent = money(value?.refund || 0);
        document.getElementById('returnDiscount').textContent = value?.discount_reversal > 0 ? `Previously waived discount reversed: USD ${money(value.discount_reversal)}. This amount is not refunded as cash.` : '';
        account.required = Number(value?.refund || 0) > 0;
        save.disabled = busy || !value;
    }
    function invalidate() {
        revision++;
        clearTimeout(timer);
        pending?.abort();
        totals();
    }
    function lock(value) {
        busy = value;
        if (value) previousDisabled = new Map(Array.from(form.querySelectorAll('input,select,textarea,button'), control => [control, control.disabled]));
        previousDisabled.forEach((disabled, control) => control.disabled = value || disabled);
        save.disabled = value || !quote;
        save.textContent = value ? 'Posting…' : 'Post return';
    }
    async function loadSource() {
        invalidate();
        const version = revision;
        source = null;
        body.replaceChildren();
        if (!sourceSelect.value) { status.textContent = 'Select an order to see eligible items.'; return; }
        status.textContent = 'Loading order…';
        pending = new AbortController();
        try {
            const result = await erp.web(`${config.baseUrl}/source/${sourceSelect.value}`, {signal: pending.signal});
            if (version !== revision || !dialog.open) return;
            source = result.data;
            body.innerHTML = source.lines.map(line => `<tr data-return-line="${line.id}" class="hover:bg-slate-50"><td class="p-3"><input type="checkbox" aria-label="Return ${escape(line.description)}" ${line.remaining <= 0 ? 'disabled' : ''}></td><td class="p-3"><span class="font-medium">${escape(line.description)}</span>${line.cost_estimated ? '<span class="block text-xs text-slate-400">Legacy cost allocation</span>' : ''}</td><td class="p-3 text-right">${line.quantity}</td><td class="p-3 text-right">${line.returned}</td><td class="p-3 text-right">${line.remaining}</td><td class="p-3"><input type="number" inputmode="decimal" min="0.001" max="${line.remaining}" step="0.001" value="${line.remaining}" disabled aria-label="Return quantity for ${escape(line.description)}" class="w-28 border border-slate-200 rounded-lg p-2"></td></tr>`).join('');
            status.textContent = `${source.reference} · ${source.party} · Outstanding: USD ${money(source.due_cents / 100)}`;
        } catch (error) { if (error.name !== 'AbortError' && version === revision) status.textContent = 'Order could not be loaded. Select it again to retry.'; }
    }
    function preview() {
        invalidate();
        if (!source || !selectedItems().length) return;
        const version = revision;
        status.textContent = 'Calculating return…';
        timer = setTimeout(async () => {
            pending = new AbortController();
            try {
                const result = await erp.web(`${config.baseUrl}/quote/${source.id}`, {method:'POST',body:JSON.stringify({items:selectedItems()}),signal:pending.signal});
                if (version !== revision || !dialog.open) return;
                totals(result.data);
                status.textContent = `${source.reference} · ${source.party} · Preview ready`;
            } catch (error) { if (error.name !== 'AbortError' && version === revision) status.textContent = 'Check the selected quantities. No return has been posted.'; }
        }, 250);
    }
    document.getElementById('addReturn').addEventListener('click', () => {
        form.reset(); invalidate(); requestId = uuid(); source = null; body.replaceChildren();
        status.textContent = 'Select an order to see eligible items.';
        dialog.showModal();
        window.jQuery?.(sourceSelect).trigger('change.select2');
    });
    sourceSelect.addEventListener('change', loadSource);
    body.addEventListener('change', event => {
        if (event.target.type === 'checkbox') event.target.closest('tr').querySelector('input[type=number]').disabled = !event.target.checked;
        preview();
    });
    body.addEventListener('input', event => { if (event.target.type === 'number') preview(); });
    function close() { if (!busy) { invalidate(); dialog.close(); } }
    form.querySelectorAll('[data-close-return]').forEach(button => button.addEventListener('click', close));
    dialog.addEventListener('cancel', event => { if (busy) event.preventDefault(); else invalidate(); });
    form.addEventListener('submit', async event => {
        event.preventDefault();
        if (busy || !source || !quote || !form.reportValidity()) return;
        const version = revision;
        const payload = {uuid:requestId,quote_token:quote.quote_token,items:selectedItems(),account_id:account.value || null,reason:document.getElementById('returnReason').value};
        lock(true);
        try {
            const confirmation = await Swal.fire({target:dialog,title:'Post this return?',text:`Return USD ${money(quote.total)}, reduce debt by USD ${money(quote.debt_reduction)} and refund USD ${money(quote.refund)}. The posted credit note is permanent.`,icon:'warning',showCancelButton:true,confirmButtonText:'Post return',cancelButtonText:'Keep editing',focusCancel:true,returnFocus:true});
            if (!confirmation.isConfirmed || version !== revision) return;
            const result = await erp.web(`${config.baseUrl}/save/${source.id}`, {method:'POST',body:JSON.stringify(payload)});
            dialog.close();
            // Show the posted result without inviting a second submission if reloading fails.
            requestId = null;
            erp.toast(`${result.data.return_no} posted successfully.`, 'success');
            location.reload();
        } catch (_) { status.textContent = 'Posting failed. You can retry with the same details safely, or refresh the order.'; }
        finally { lock(false); if (!requestId) save.disabled = true; }
    });
    const detailDialog = document.getElementById('returnDetails');
    document.getElementById('closeReturnDetails').addEventListener('click', () => detailDialog.close());
    document.getElementById('returnsTable').addEventListener('click', async event => {
        const button = event.target.closest('[data-return-details]');
        if (!button || button.disabled) return;
        button.disabled = true;
        try {
            const {data:r} = await erp.web(`${config.baseUrl}/details/${button.dataset.returnDetails}`);
            document.getElementById('returnDetailsTitle').textContent = r.return_no;
            document.getElementById('returnDetailsBody').innerHTML = `<p class="text-sm text-slate-500 mb-3">${escape(r.source_no)} · ${escape(r.party_name)} · ${escape(r.returned_at)}</p><p class="text-sm mb-5 whitespace-pre-wrap">${escape(r.reason)}</p><div class="overflow-x-auto"><table class="w-full text-sm text-left"><thead class="bg-slate-50"><tr><th class="p-3">Item</th><th class="p-3 text-right">Quantity</th><th class="p-3 text-right">Returned value</th></tr></thead><tbody>${r.items.map(i => `<tr class="border-b border-slate-100"><td class="p-3">${escape(i.description)}</td><td class="p-3 text-right">${Number(i.quantity)}</td><td class="p-3 text-right">${money(i.total)}</td></tr>`).join('')}</tbody></table></div><p class="text-sm mt-5">Total: <strong>USD ${money(r.total)}</strong> · Debt reduced: USD ${money(r.debt_reduction)} · Refund: USD ${money(r.refund)}${Number(r.discount_reversal) ? ` · Waived discount reversed: USD ${money(r.discount_reversal)}` : ''}</p>`;
            detailDialog.showModal();
        } catch (_) {} finally { button.disabled = false; }
    });
    erp.initDataTable?.('#returnsTable', {order:[[0,'desc']],columnDefs:[{targets:-1,orderable:false}]});
})();
