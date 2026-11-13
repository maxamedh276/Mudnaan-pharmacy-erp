/** Payment-method CRUD, filtering, and persistent accessible row ordering. */
(() => {
    'use strict';
    const workspace = document.getElementById('paymentMethodsWorkspace');
    if (!workspace) return;
    const baseUrl = workspace.dataset.baseUrl.replace(/\/$/, '');
    const canManage = workspace.dataset.canManage === 'true';
    const tableBody = document.getElementById('paymentMethodRows');
    const searchField = document.getElementById('paymentMethodSearch');
    const orderHint = document.getElementById('paymentOrderHint');
    const countLabel = document.getElementById('paymentMethodCount');
    const methodDialog = document.getElementById('paymentMethodDialog');
    const methodForm = document.getElementById('paymentMethodForm');
    const paymentTypeLabels = { cash: 'Cash', mobile_money: 'Mobile money', bank: 'Bank / card' };
    let paymentMethods = JSON.parse(document.getElementById('paymentMethodsInitialData').textContent);
    let isSaving = false;
    let sortableRows;

    function createCell(text, className = '') {
        const cell = document.createElement('td');
        cell.className = 'px-3 py-4 ' + className;
        cell.textContent = String(text ?? '');
        return cell;
    }

    function createAction(label, action, methodId, className) {
        const button = document.createElement('button');
        button.type = 'button';
        button.textContent = label;
        button.dataset.methodAction = action;
        button.dataset.methodId = methodId;
        button.className = className;
        button.disabled = isSaving;
        return button;
    }

    function renderPaymentMethods(focusMethodId) {
        const searchTerm = searchField.value.trim().toLocaleLowerCase();
        const matchingMethods = paymentMethods.filter(method => [method.name, method.account_code, method.account_name, paymentTypeLabels[method.type]].some(value => String(value || '').toLocaleLowerCase().includes(searchTerm)));
        tableBody.replaceChildren();
        matchingMethods.forEach(method => {
            const row = document.createElement('tr');
            row.dataset.methodId = method.id;
            row.className = 'payment-method-row hover:bg-slate-50';
            const orderCell = createCell('', 'text-slate-400');
            const handle = document.createElement('button');
            handle.type = 'button';
            handle.className = 'payment-drag-handle inline-flex items-center gap-2 px-2 py-2 rounded hover:bg-slate-100 focus-visible:ring-2 focus-visible:ring-brand-600 cursor-grab disabled:cursor-default disabled:opacity-40';
            handle.setAttribute('aria-label', 'Reorder ' + method.name + '. Use Arrow Up or Arrow Down.');
            handle.disabled = !canManage || Boolean(searchTerm) || isSaving;
            const gripIcon = document.createElement('i');
            gripIcon.dataset.lucide = 'grip-vertical';
            gripIcon.className = 'w-4 h-4';
            const positionLabel = document.createElement('span');
            positionLabel.textContent = paymentMethods.indexOf(method) + 1;
            handle.append(gripIcon, positionLabel);
            orderCell.append(handle);
            row.append(orderCell, createCell(method.name, 'font-semibold text-slate-900'), createCell(paymentTypeLabels[method.type] || method.type, 'text-slate-500'), createCell([method.account_code, method.account_name].filter(Boolean).join(' · ') || 'No linked account', 'text-slate-600'));
            const enabled = Number(method.is_active) === 1;
            const statusCell = createCell('');
            const statusButton = createAction(enabled ? 'Enabled' : 'Hidden', 'toggle', method.id, 'px-2.5 py-1.5 rounded-full text-[11px] font-semibold ' + (enabled ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500'));
            statusButton.disabled = !canManage || isSaving;
            statusButton.setAttribute('aria-label', (enabled ? 'Disable ' : 'Enable ') + method.name);
            statusCell.append(statusButton);
            const actionCell = createCell('', 'text-right whitespace-nowrap');
            if (canManage) {
                actionCell.append(createAction('Edit', 'edit', method.id, 'px-2 py-2 text-brand-600 hover:underline'), createAction('Delete', 'delete', method.id, 'px-2 py-2 text-rose-600 hover:underline'));
            }
            row.append(statusCell, actionCell);
            tableBody.append(row);
        });
        if (matchingMethods.length === 0) {
            const emptyRow = document.createElement('tr');
            const emptyCell = createCell(searchTerm ? 'No matching payment methods.' : 'No payment methods yet. Add a payment method to get started.', 'py-10 text-center text-slate-400');
            emptyCell.colSpan = 6;
            emptyRow.append(emptyCell);
            tableBody.append(emptyRow);
        }
        countLabel.textContent = `${matchingMethods.length} of ${paymentMethods.length} payment methods`;
        orderHint.textContent = isSaving ? 'Saving changes…' : searchTerm ? 'Clear search to reorder payment methods.' : canManage ? 'Drag the handle to set the POS order. You can also focus a handle and press Arrow Up or Arrow Down.' : 'Payment methods appear in POS order.';
        sortableRows?.option('disabled', !canManage || Boolean(searchTerm) || isSaving);
        lucide.createIcons({ root: tableBody });
        if (focusMethodId) tableBody.querySelector(`[data-method-id="${focusMethodId}"] .payment-drag-handle`)?.focus();
    }

    async function refreshPaymentMethods() {
        const result = await erp.web(baseUrl + '/data');
        paymentMethods = result.data;
        renderPaymentMethods();
    }

    function setSaving(saving) {
        isSaving = saving;
        searchField.disabled = saving;
        document.getElementById('addPaymentMethod')?.toggleAttribute('disabled', saving);
        renderPaymentMethods();
    }

    async function savePaymentOrder(orderedIds, focusMethodId) {
        if (isSaving) return;
        const previousMethods = paymentMethods.slice();
        const methodsById = new Map(paymentMethods.map(method => [Number(method.id), method]));
        paymentMethods = orderedIds.map(methodId => methodsById.get(methodId));
        setSaving(true);
        try {
            await erp.web(baseUrl + '/reorder', { method: 'POST', body: JSON.stringify({ ordered_ids: orderedIds }) });
            paymentMethods.forEach((method, index) => method.sort_order = index + 1);
            erp.toast('Payment order saved.');
        } catch (error) {
            paymentMethods = previousMethods;
            // A stale list may contain deleted/new rows. Reload server truth after rollback.
            try { await refreshPaymentMethods(); } catch (_) { /* The request helper already displayed the error. */ }
        } finally {
            setSaving(false);
            renderPaymentMethods(focusMethodId);
        }
    }

    function openMethodDialog(method) {
        methodForm.reset();
        document.getElementById('paymentMethodId').value = method?.id || '';
        document.getElementById('paymentMethodLabel').value = method?.name || '';
        document.getElementById('paymentMethodAccount').value = method?.account_id || '';
        document.getElementById('paymentMethodType').value = method?.type || 'mobile_money';
        document.getElementById('paymentMethodDialogTitle').textContent = method ? 'Edit payment method' : 'Add payment method';
        methodDialog.showModal();
    }

    searchField.addEventListener('input', () => renderPaymentMethods());
    document.getElementById('addPaymentMethod')?.addEventListener('click', () => openMethodDialog());
    methodDialog.querySelectorAll('[data-close-payment-dialog]').forEach(button => button.addEventListener('click', () => methodDialog.close()));
    methodDialog.addEventListener('cancel', event => { if (isSaving) event.preventDefault(); });
    methodForm.addEventListener('submit', async event => {
        event.preventDefault();
        if (isSaving) return;
        const saveButton = document.getElementById('savePaymentMethod');
        saveButton.disabled = true;
        saveButton.textContent = 'Saving…';
        setSaving(true);
        try {
            await erp.web(baseUrl + '/save', { method: 'POST', body: JSON.stringify({
                id: Number(document.getElementById('paymentMethodId').value) || null,
                name: document.getElementById('paymentMethodLabel').value.trim(),
                account_id: Number(document.getElementById('paymentMethodAccount').value),
                type: document.getElementById('paymentMethodType').value,
            }) });
            methodDialog.close();
            erp.toast('Payment method saved.');
            await refreshPaymentMethods();
        } catch (_) { /* Keep failed input in place; shared web helper reports errors. */ }
        finally {
            setSaving(false);
            saveButton.disabled = false;
            saveButton.textContent = 'Save method';
        }
    });

    tableBody.addEventListener('click', async event => {
        const actionButton = event.target.closest('[data-method-action]');
        if (!actionButton || isSaving) return;
        const method = paymentMethods.find(item => Number(item.id) === Number(actionButton.dataset.methodId));
        if (!method) return;
        const action = actionButton.dataset.methodAction;
        if (action === 'edit') { openMethodDialog(method); return; }
        if (action === 'delete' && !await erp.confirm(`Remove “${method.name}” from POS payment methods?`, { title: 'Delete payment method', confirmButtonText: 'Delete method', destructive: true })) return;
        setSaving(true);
        try {
            await erp.web(`${baseUrl}/${action}/${method.id}`, { method: 'POST', body: '{}' });
            erp.toast(action === 'delete' ? 'Payment method deleted.' : 'Payment method status updated.');
            await refreshPaymentMethods();
        } catch (_) { /* Shared request helper reports errors. */ }
        finally { setSaving(false); }
    });

    // Keyboard ordering uses the same server validation as pointer/touch dragging.
    tableBody.addEventListener('keydown', event => {
        const handle = event.target.closest('.payment-drag-handle');
        if (!handle || handle.disabled || !['ArrowUp', 'ArrowDown'].includes(event.key)) return;
        event.preventDefault();
        const methodId = Number(handle.closest('tr').dataset.methodId);
        const orderedIds = paymentMethods.map(method => Number(method.id));
        const currentIndex = orderedIds.indexOf(methodId);
        const targetIndex = currentIndex + (event.key === 'ArrowUp' ? -1 : 1);
        if (targetIndex < 0 || targetIndex >= orderedIds.length) return;
        [orderedIds[currentIndex], orderedIds[targetIndex]] = [orderedIds[targetIndex], orderedIds[currentIndex]];
        savePaymentOrder(orderedIds, methodId);
    });

    renderPaymentMethods();
    if (canManage) {
        sortableRows = Sortable.create(tableBody, {
            handle: '.payment-drag-handle', draggable: '.payment-method-row',
            animation: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 0 : 120,
            ghostClass: 'bg-blue-50',
            onEnd: event => {
                if (event.oldIndex === event.newIndex) return;
                const orderedIds = Array.from(tableBody.querySelectorAll('tr[data-method-id]')).map(row => Number(row.dataset.methodId));
                savePaymentOrder(orderedIds, Number(event.item.dataset.methodId));
            },
        });
    }
})();
