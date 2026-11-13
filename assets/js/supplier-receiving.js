/** Receiving keeps stock and accounting rules in the existing receipt service. */
'use strict';
let receivableDt = null;
let receiptsDt = null;

$(document).ready(function() {
    receivableDt = erp.initDataTable('#receivableTable', {
        order: [[2, 'desc']],
        pageLength: 10
    });

    receiptsDt = erp.initDataTable('#receiptsTable', {
        order: [[3, 'desc']],
        pageLength: 10
    });

    // Set default datetime to now
    const now = new Date();
    now.setMinutes(now.getMinutes() - now.getTimezoneOffset());
    document.getElementById('receive-date').value = now.toISOString().slice(0, 16);

    // Auto open modal if PO id is in URL (?po=123)
    const urlParams = new URLSearchParams(window.location.search);
    const poParam = urlParams.get('po');
    if (poParam) {
        openReceiveModal(parseInt(poParam), 'PO-' + poParam);
    }
});

function openModal(id) {
    document.getElementById(id).classList.remove('hidden');
}

function closeModal(id) {
    document.getElementById(id).classList.add('hidden');
}

function escapeReceivingText(value) {
    const element = document.createElement('span');
    element.textContent = String(value ?? '');
    return element.innerHTML;
}
// SPA AJAX: Open Goods Receiving Modal
async function openReceiveModal(poId, poRef) {
    document.getElementById('receive-po-id').value = poId;
    document.getElementById('receive-modal-title').textContent = `Receive Shipment — ${poRef}`;
    document.getElementById('form-receive').reset();
    document.getElementById('receive-po-id').value = poId;
    document.getElementById('btn-submit-receive').disabled = true;
    openModal('modal-receive');

    const tbody = document.getElementById('receive-items-tbody');
    tbody.innerHTML = `<tr><td colspan="5" class="py-6 text-center text-slate-400">Loading order line items...</td></tr>`;

    try {
        const data = await erp.api(`purchases/${poId}`);
        document.getElementById('receive-expected-date').value = data.expected_received_at || 'Not scheduled';
        const receivedDate = document.getElementById('receive-date');
        const now = new Date();
        now.setMinutes(now.getMinutes() - now.getTimezoneOffset());
        receivedDate.value = data.expected_received_at?.replace(' ', 'T').slice(0, 16) || now.toISOString().slice(0, 16);
        document.getElementById('btn-submit-receive').disabled = false;
        const items = data.items || [];

        if (items.length === 0) {
            tbody.innerHTML = `<tr><td colspan="5" class="py-6 text-center text-slate-400">No items found on this order.</td></tr>`;
            return;
        }

        tbody.innerHTML = items.map(item => {
            const ordered = parseFloat(item.quantity);
            const recvd = parseFloat(item.quantity_received || 0);
            const remaining = Math.max(0, ordered - recvd);

            return `
                <tr class="hover:bg-slate-50/60 receive-row" data-line-id="${item.id}">
                    <td class="py-2.5 px-3 font-semibold text-slate-900">${escapeReceivingText(item.product_name)} (${escapeReceivingText(item.unit_label || "base unit")})
                    <div class="grid gap-2 mt-2"><label class="font-normal">Batch / lot<input data-batch-field="batch_no" maxlength="80" class="block border rounded p-1 w-full"></label><label class="font-normal">Expiry date<input data-batch-field="expiry_date" type="date" class="block border rounded p-1 w-full"></label><label class="font-normal">Manufactured (optional)<input data-batch-field="mfg_date" type="date" class="block border rounded p-1 w-full"></label></div></td>
                    <td class="py-2.5 px-3 text-center text-slate-600 font-medium">${ordered.toFixed(1)}</td>
                    <td class="py-2.5 px-3 text-center text-emerald-600 font-medium">${recvd.toFixed(1)}</td>
                    <td class="py-2.5 px-3 text-center font-bold ${remaining > 0 ? 'text-amber-600' : 'text-slate-400'}">${remaining.toFixed(1)}</td>
                    <td class="py-2.5 px-3 text-center">
                        <input type="number" step="${item.stock_mode === 'sealed' ? '1' : '0.001'}" min="0" max="${remaining}"
                               value="${remaining}"
                               class="recv-qty-input w-24 px-2 py-1 text-xs border border-slate-300 rounded-lg text-center font-bold focus:outline-none focus:border-emerald-600 ${remaining == 0 ? 'bg-slate-100 text-slate-400' : 'bg-white text-emerald-700'}"
                               ${remaining == 0 ? 'disabled' : ''}>
                    </td>
                </tr>
            `;
        }).join('');
    } catch (err) {
        tbody.innerHTML = `<tr><td colspan="5" class="py-6 text-center text-rose-500">Failed to load purchase items.</td></tr>`;
    }
}

// SPA AJAX: Submit Receiving Goods
async function submitReceiveGoods(e) {
    e.preventDefault();
    const poId = document.getElementById('receive-po-id').value;
    const items = [];

    document.querySelectorAll('#receive-items-tbody tr.receive-row').forEach(row => {
        const lineId = row.getAttribute('data-line-id');
        const qtyInput = row.querySelector('.recv-qty-input');
        const qty = parseFloat(qtyInput?.value || 0);

        if (lineId && qty > 0) {
            const batch = Object.fromEntries([...row.querySelectorAll('[data-batch-field]')].map(input => [input.dataset.batchField, input.value]));
            items.push({
                purchase_item_id: parseInt(lineId),
                quantity: qty,
                ...batch
            });
        }
    });

    if (items.length === 0) {
        erp.toast('Please specify a quantity greater than 0 to receive.', 'error');
        return;
    }

    if (!await erp.confirm('Receive these goods and update stock and supplier balances?', { title: 'Confirm goods received?', confirmButtonText: 'Receive goods' })) return;
    const btn = document.getElementById('btn-submit-receive');
    btn.disabled = true;
    btn.innerHTML = `<i data-lucide="loader" class="w-4 h-4 animate-spin"></i> Checking in...`;
    lucide.createIcons();

    const payload = {
        received_at: document.getElementById('receive-date').value ? (document.getElementById('receive-date').value.replace('T', ' ') + ':00') : undefined,
        notes: document.getElementById('receive-notes').value.trim() || undefined,
        items: items
    };

    try {
        await erp.api(`purchases/${poId}/receipts`, {
            method: 'POST',
            body: JSON.stringify(payload)
        });
        erp.toast('Shipment received and stock updated successfully!');
        closeModal('modal-receive');
        setTimeout(() => {
            window.location.href = JSON.parse(document.getElementById('receivingConfig').textContent).receivingUrl;
        }, 600);
    } catch (err) {
        // Handled by erp.api
    } finally {
        btn.disabled = false;
        btn.innerHTML = `<i data-lucide="check-circle" class="w-4 h-4"></i> Confirm Goods In`;
        lucide.createIcons();
    }
}
