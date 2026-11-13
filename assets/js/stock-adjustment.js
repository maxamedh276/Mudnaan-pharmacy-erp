/** Shared adjustment form: preview, confirmation, safe retries, and AJAX refresh. */
(() => {
    'use strict';
    const modal = document.getElementById('modal-adjust-stock');
    const form = document.getElementById('form-adjust-stock');
    const product = document.getElementById('adj-product-id');
    const stockMode = document.getElementById('adj-stock-mode');
    const direction = document.getElementById('adj-direction');
    const quantity = document.getElementById('adj-quantity');
    const note = document.getElementById('adj-note');
    const button = document.getElementById('btn-submit-adj');
    const errorLabel = document.getElementById('adjustmentError');
    const config = JSON.parse(document.getElementById('stockAdjustmentConfig').textContent);
    let submitting = false;
    let requestKey;
    let returnFocus;
    const formatQuantity = value => Number(value).toLocaleString(undefined, { maximumFractionDigits: 3 });
    const change = () => Number(quantity.value) * (direction.value === 'out' ? -1 : 1);
    function preview() {
        const selected = product.selectedOptions[0]?.dataset || {};
        const sealed = stockMode.value === 'sealed';
        const ready = product.value && (!selected.package || Boolean(stockMode.value));
        const current = Number(sealed ? selected.sealed : (selected.package ? selected.opened : selected.quantity)) || 0;
        const unit = sealed ? selected.package : (selected.unit || 'units');
        quantity.min = sealed ? '1' : '0.001'; quantity.step = sealed ? '1' : '0.001';
        document.getElementById('adjustmentCurrent').textContent = ready ? formatQuantity(current) + ' ' + unit : '—';
        document.getElementById('adjustmentAfter').textContent = ready ? formatQuantity(current + change()) + ' ' + unit : '—';
        const invalid = product.value && current + change() < 0;
        errorLabel.textContent = invalid ? 'This decrease would make stock negative.' : '';
        errorLabel.classList.toggle('hidden', !invalid);
        quantity.setCustomValidity(invalid ? errorLabel.textContent : '');
    }
    function configurePool() {
        const selected = product.selectedOptions[0]?.dataset || {};
        stockMode.replaceChildren(new Option((selected.package ? 'Opened ' : '') + (selected.unit || 'Main stock'),'loose'));
        if (selected.package) { stockMode.insertBefore(new Option('Choose sealed or opened stock…',''),stockMode.firstChild); stockMode.add(new Option('Sealed ' + selected.package,'sealed')); stockMode.value=''; }
        preview();
    }
    product.addEventListener('change',configurePool);
    stockMode.addEventListener('change',preview);
    window.openAdjustModal = (productId = null) => {
        if (submitting) return;
        returnFocus = document.activeElement;
        form.reset();
        requestKey = crypto.randomUUID();
        if (productId) product.value = String(productId);
        modal.classList.remove('hidden');
        configurePool();
        product.focus();
    };
    window.closeModal = () => {
        if (submitting) return;
        modal.classList.add('hidden');
        returnFocus?.focus();
    };
    [product, direction, quantity].forEach(element => element.addEventListener('input', preview));
    modal.addEventListener('keydown', event => {
        if (Swal.isVisible()) return;
        if (event.key === 'Escape') { event.preventDefault(); window.closeModal(); }
        if (event.key === 'Tab') {
            const controls = [...modal.querySelectorAll('button, input, select, textarea')].filter(element => !element.disabled);
            const first = controls[0], last = controls[controls.length - 1];
            if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
            if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
        }
    });
    async function refreshWorkspace() {
        const response = await fetch(window.location.href, { credentials: 'same-origin' });
        if (!response.ok || response.redirected) throw new Error('Refresh failed');
        const page = new DOMParser().parseFromString(await response.text(), 'text/html');
        const tableId = document.getElementById('stockTable') ? 'stockTable' : 'movementsTable';
        const replacement = page.getElementById(tableId);
        if (!replacement) throw new Error('Refresh failed');
        const table = $('#' + tableId).DataTable();
        const search = table.search(), length = table.page.len();
        table.destroy();
        document.getElementById(tableId).replaceWith(replacement);
        erp.initDataTable('#' + tableId, { responsive: false, order: [[tableId === 'stockTable' ? 0 : 0, tableId === 'stockTable' ? 'asc' : 'desc']], pageLength: length }).search(search).draw();
        const movementCount = document.getElementById('movementCount');
        if (movementCount) movementCount.textContent = page.getElementById('movementCount').textContent;
        const summary = document.getElementById('stockSummary');
        if (summary) summary.replaceChildren(...page.getElementById('stockSummary').childNodes);
        product.replaceChildren(...page.getElementById('adj-product-id').childNodes);
        lucide.createIcons();
    }
    form.addEventListener('submit', async event => {
        event.preventDefault();
        if (submitting || !form.reportValidity()) return;
        const payload = { product_id: Number(product.value), qty_change: change(), stock_mode: stockMode.value, note: note.value.trim(), request_key: requestKey };
        if (!payload.note) { note.setCustomValidity('Enter an adjustment reason.'); note.reportValidity(); return; }
        submitting = true;
        button.disabled = true;
        try {
            const confirmed = await erp.confirm(`${product.selectedOptions[0].textContent.trim()}: ${payload.qty_change > 0 ? '+' : ''}${formatQuantity(payload.qty_change)} ${stockMode.value === 'sealed' ? product.selectedOptions[0].dataset.package : product.selectedOptions[0].dataset.unit}. Reason: ${payload.note}`, { title: 'Apply stock adjustment?', confirmButtonText: 'Apply adjustment', destructive: payload.qty_change < 0 });
            if (!confirmed) return;
            button.textContent = 'Saving…';
            await erp.web(config.saveUrl, { method: 'POST', body: JSON.stringify(payload) });
            modal.classList.add('hidden');
            returnFocus?.focus();
            erp.toast('Stock adjustment saved.');
            try { await refreshWorkspace(); }
            catch (_) { erp.toast('Adjustment saved, but the page could not refresh. Refresh the page before making another adjustment.', 'error'); }
        } catch (error) {
            errorLabel.textContent = error.message;
            errorLabel.classList.remove('hidden');
            // Keep the same request key and inputs: a network retry cannot post twice.
        } finally {
            submitting = false;
            button.disabled = false;
            button.textContent = 'Apply adjustment';
        }
    });
    note.addEventListener('input', () => note.setCustomValidity(''));
})();
