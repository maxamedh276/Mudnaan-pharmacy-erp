/** Purchase order filters, scheduled delivery, and pre-receipt editing. */
'use strict';
const purchaseConfig = JSON.parse(document.getElementById('purchaseConfig').textContent);
const availableProducts = purchaseConfig.products;
const purchaseSaveUrl = purchaseConfig.saveUrl;
// Icons are decorative; a delayed CDN must never stop an order form opening.
function renderPurchaseIcons(root = document) {
    window.lucide?.createIcons({ root });
}
// Escape dynamic values used in existing HTML templates.
function escapePurchaseText(value) {
    const element = document.createElement('span');
    element.textContent = String(value ?? '');
    return element.innerHTML.replaceAll('"', '&quot;').replaceAll("'", '&#39;');
}
function localDateTimeNow() {
    const now = new Date();
    now.setMinutes(now.getMinutes() - now.getTimezoneOffset());
    return now.toISOString().slice(0, 16);
}
let purchasesDt = null;

$(document).ready(function() {
    purchasesDt = erp.initDataTable('#purchasesTable', {
        order: [[2, 'desc']],
        pageLength: 10, dom: 'lrtip'
    });
    const linkedPurchaseId = new URLSearchParams(window.location.search).get('po');
    if (/^[1-9]\d*$/.test(linkedPurchaseId || '')) viewPODetails(Number(linkedPurchaseId));
});

// Filters apply to this table only and preserve the current page without navigation.
$.fn.dataTable.ext.search.push((settings, _data, index) => {
    if (settings.nTable.id !== 'purchasesTable') return true;
    const row = settings.aoData[index].nTr.dataset;
    const status = document.getElementById('purchaseStatus').value;
    const supplier = document.getElementById('purchaseSupplier').value;
    const from = document.getElementById('purchaseFrom').value;
    const to = document.getElementById('purchaseTo').value;
    return (!status || row.status === status) && (!supplier || row.supplier === supplier)
        && (!from || row.date >= from) && (!to || row.date <= to);
});
document.getElementById('purchaseSearch').addEventListener('input', event => purchasesDt?.search(event.target.value).draw());
['purchaseStatus', 'purchaseSupplier', 'purchaseFrom', 'purchaseTo'].forEach(id => document.getElementById(id).addEventListener('change', () => purchasesDt?.draw()));
function clearPurchaseFilters() {
    ['purchaseSearch', 'purchaseStatus', 'purchaseSupplier', 'purchaseFrom', 'purchaseTo'].forEach(id => document.getElementById(id).value = '');
    purchasesDt?.search('').draw();
}
async function refreshPurchaseOrders() {
    // Reuse server-rendered rows after mutations; retain filters and avoid a full-page reload.
    const response = await fetch(window.location.href, { credentials: 'same-origin' });
    if (!response.ok) throw new Error('Unable to refresh orders.');
    const page = new DOMParser().parseFromString(await response.text(), 'text/html');
    const table = page.getElementById('purchasesTable');
    if (!table) throw new Error('Unable to refresh orders. Please sign in again.');
    const search = document.getElementById('purchaseSearch').value;
    purchasesDt.destroy();
    document.getElementById('purchasesTable').replaceWith(table);
    document.getElementById('purchaseSummary').replaceChildren(...page.getElementById('purchaseSummary').childNodes);
    purchasesDt = erp.initDataTable('#purchasesTable', { order: [[2, 'desc']], pageLength: 10, dom: 'lrtip' });
    purchasesDt.search(search).draw();
    renderPurchaseIcons();
}

function openModal(id) {
    document.getElementById(id).classList.remove('hidden');
}

function closeModal(id) {
    document.getElementById(id).classList.add('hidden');
}

function openCreatePOModal() {
    document.getElementById('form-create-po').reset();
    document.getElementById('purchaseOrderId').value = '';
    document.getElementById('purchaseEditorTitle').textContent = 'Create Purchase Order';
    document.getElementById('btn-save-po').textContent = 'Create Order';
    document.getElementById('purchaseExpectedDate').value = localDateTimeNow();
    document.getElementById('po-items-tbody').innerHTML = '';
    openModal('modal-create-po');
    addPOLineItem(); // Start with 1 empty row; icon loading is optional.
}

async function editPurchaseOrder(purchaseId) {
    try {
        const purchase = await erp.api(`purchases/${purchaseId}`);
        if (purchase.status !== 'pending' || Number(purchase.quantity_received) > 0 || purchase.receipts?.length) {
            erp.toast('Only orders without received goods can be edited.', 'error');
            return;
        }
        openCreatePOModal();
        closeModal('modal-view-po');
        document.getElementById('purchaseOrderId').value = purchase.id;
        document.getElementById('purchaseEditorTitle').textContent = 'Edit Purchase Order';
        document.getElementById('btn-save-po').textContent = 'Save Changes';
        document.getElementById('po-supplier-id').value = purchase.supplier_id;
        document.getElementById('po-reference').value = purchase.reference_no || '';
        document.getElementById('po-notes').value = purchase.notes || '';
        document.getElementById('purchaseExpectedDate').value = purchase.expected_received_at?.replace(' ', 'T').slice(0, 16) || localDateTimeNow();
        document.getElementById('po-items-tbody').replaceChildren();
        purchase.items.forEach(addPOLineItem);
    } catch (_) { /* Shared API helper reports loading errors. */ }
}

function addPOLineItem(item = null) {
    const tbody = document.getElementById('po-items-tbody');
    const rowIndex = tbody.children.length;

    const tr = document.createElement('tr');
    tr.className = 'hover:bg-slate-50/60 line-item-row';
    tr.innerHTML = `
        <td class="py-2.5 px-3">
            <select required onchange="handleProductSelect(this, ${rowIndex})" class="po-prod-select w-full px-2.5 py-1.5 text-xs border border-slate-300 rounded-lg bg-white focus:outline-none focus:border-brand-600">
                <option value="">Select a product...</option>
                ${availableProducts.map(p => `
                    <option value="${p.id}" data-cost="${p.cost_price || 0}" data-unit="${escapePurchaseText(p.unit || 'pcs')}">
                        ${escapePurchaseText(p.name)} (SKU: ${escapePurchaseText(p.sku || '—')})
                    </option>
                `).join('')}
            </select>
        </td>
        <td class="py-2.5 px-3"><span class="po-unit-label"></span><select aria-label="Purchase unit" class="po-stock-mode w-full p-2 border rounded-lg" onchange="handlePurchaseUnit(this)"><option value="loose">Base unit</option></select>
        </td>
        <td class="py-2.5 px-3">
            <input type="number" step="0.001" min="0.001" required value="1" oninput="calculatePOTotals()"
                   class="po-prod-qty w-full px-2.5 py-1.5 text-xs border border-slate-300 rounded-lg text-center font-semibold focus:outline-none focus:border-brand-600">
        </td>
        <td class="py-2.5 px-3">
            <input type="number" step="0.01" min="0" required value="0.00" oninput="calculatePOTotals()"
                   class="po-prod-cost w-full px-2.5 py-1.5 text-xs border border-slate-300 rounded-lg text-right font-semibold focus:outline-none focus:border-brand-600">
        </td>
        <td class="py-2.5 px-3 text-right font-bold text-slate-800 po-line-total">
            $0.00
        </td>
        <td class="py-2.5 px-2 text-center">
            <button type="button" onclick="removePOLineItem(this)" class="p-1 text-slate-400 hover:text-rose-600 rounded">
                <i data-lucide="trash-2" class="w-4 h-4"></i>
            </button>
        </td>
    `;
    if (item) {
        const select = tr.querySelector('.po-prod-select');
        if (![...select.options].some(option => Number(option.value) === Number(item.product_id))) {
            select.add(new Option(item.product_name, item.product_id));
        }
        select.value = item.product_id;
        tr.querySelector('.po-prod-qty').value = item.quantity;
        tr.querySelector('.po-prod-cost').value = item.unit_cost;
    }
    tbody.appendChild(tr);
    configurePurchaseUnit(tr, item?.stock_mode || 'loose', item);
    renderPurchaseIcons(tr);
    calculatePOTotals();
}

function removePOLineItem(btn) {
    const tbody = document.getElementById('po-items-tbody');
    if (tbody.children.length > 1) {
        btn.closest('tr').remove();
        calculatePOTotals();
    } else {
        erp.toast('At least one item line is required for a purchase order.', 'error');
    }
}

function handleProductSelect(select, index) {
    const selectedOpt = select.options[select.selectedIndex];
    const cost = selectedOpt.getAttribute('data-cost') || '0.00';
    const tr = select.closest('tr');
    configurePurchaseUnit(tr, 'loose');
    tr.querySelector('.po-prod-cost').value = parseFloat(cost).toFixed(2);
    calculatePOTotals();
}

function configurePurchaseUnit(row, mode, original = null) {
    const product = availableProducts.find(p => Number(p.id) === Number(row.querySelector('.po-prod-select').value));
    const select = row.querySelector('.po-stock-mode');
    select.replaceChildren(new Option(product?.unit || 'Base unit', 'loose'));
    if (product?.package_unit) select.add(new Option(`${product.package_unit} (${Number(product.package_size)} ${product.unit})`, 'sealed'));
    if (product?.medicine_units?.length) {
        select.replaceChildren();
        for (const unit of product.medicine_units) select.add(new Option(`${unit.name} (${Number(unit.factor)} ${product.unit})`, `unit:${unit.id}`));
        const match = product.medicine_units.find(unit => original ? unit.name === original.unit_label && Number(unit.factor) === Number(original.stock_factor) : Number(unit.factor) === 1);
        mode = match ? `unit:${match.id}` : select.options[0].value;
    }
    select.value = mode;
    select.hidden = !product?.package_unit && !product?.medicine_units?.length;
    select.dataset.nativeSelect = 'true';
    row.querySelector('.po-unit-label').textContent = product?.package_unit ? '' : (product?.unit || '—');
    setPurchaseQuantityUnit(row, mode);
}
// HTML number steps start at min: sealed quantities need a whole-number base.
function setPurchaseQuantityUnit(row, mode) {
    const quantity = row.querySelector('.po-prod-qty');
    quantity.min = mode === 'sealed' ? '1' : '0.001';
    quantity.step = mode === 'sealed' ? '1' : '0.001';
}
function handlePurchaseUnit(select) {
    const row = select.closest('tr');
    const product = availableProducts.find(p => Number(p.id) === Number(row.querySelector('.po-prod-select').value));
    setPurchaseQuantityUnit(row, select.value);
    row.querySelector('.po-prod-qty').value = 1;
    const medicineUnit = product?.medicine_units?.find(unit => select.value === `unit:${unit.id}`);
    if (medicineUnit) { const quantity = row.querySelector('.po-prod-qty'); quantity.step = Number(medicineUnit.fractional) ? '0.001' : '1'; quantity.min = quantity.step; }
    row.querySelector('.po-prod-cost').value = (Number(product?.cost_price || 0) * (medicineUnit ? Number(medicineUnit.factor) : select.value === 'sealed' ? Number(product.package_size) : 1)).toFixed(2);
    calculatePOTotals();
}
function calculatePOTotals() {
    let grandTotal = 0;
    let totalItems = 0;

    document.querySelectorAll('#po-items-tbody tr').forEach(row => {
        const qty = parseFloat(row.querySelector('.po-prod-qty')?.value || 0);
        const cost = parseFloat(row.querySelector('.po-prod-cost')?.value || 0);
        const lineTotal = qty * cost;
        row.querySelector('.po-line-total').textContent = '$' + lineTotal.toFixed(2);
        grandTotal += lineTotal;
        if (qty > 0) totalItems += qty;
    });

    document.getElementById('po-calc-items').textContent = totalItems.toFixed(1);
    document.getElementById('po-calc-total').textContent = 'USD ' + grandTotal.toFixed(2);
}

// SPA AJAX: Submit Purchase Order
async function submitCreatePO(e) {
    e.preventDefault();
    const supplierId = document.getElementById('po-supplier-id').value;
    if (!supplierId) {
        erp.toast('Please select a supplier.', 'error');
        return;
    }

    const items = [];
    document.querySelectorAll('#po-items-tbody tr').forEach(row => {
        const prodId = row.querySelector('.po-prod-select')?.value;
        const qty = parseFloat(row.querySelector('.po-prod-qty')?.value || 0);
        const cost = parseFloat(row.querySelector('.po-prod-cost')?.value || 0);

        if (prodId && qty > 0) {
            items.push({
                stock_mode: row.querySelector('.po-stock-mode').value.startsWith('unit:') ? 'loose' : row.querySelector('.po-stock-mode').value,
                medicine_unit_id: row.querySelector('.po-stock-mode').value.startsWith('unit:') ? Number(row.querySelector('.po-stock-mode').value.slice(5)) : undefined,
                product_id: parseInt(prodId),
                quantity: qty,
                unit_cost: cost
            });
        }
    });

    if (items.length === 0) {
        erp.toast('Please add at least one product with valid quantity.', 'error');
        return;
    }

    if (!await erp.confirm('Save this purchase order and its expected receipt time?', { title: 'Save purchase order?', confirmButtonText: 'Save order' })) return;
    const btn = document.getElementById('btn-save-po');
    btn.disabled = true;
    btn.innerHTML = `<i data-lucide="loader" class="w-4 h-4 animate-spin"></i> Creating...`;
    renderPurchaseIcons();

    const payload = {
        id: Number(document.getElementById('purchaseOrderId').value) || undefined,
        expected_received_at: document.getElementById('purchaseExpectedDate').value,
        supplier_id: parseInt(supplierId),
        reference_no: document.getElementById('po-reference').value.trim() || undefined,
        notes: document.getElementById('po-notes').value.trim() || undefined,
        items: items
    };

    try {
        await erp.web(purchaseSaveUrl, {
            method: 'POST',
            body: JSON.stringify(payload)
        });
        erp.toast('Purchase order saved.');
        closeModal('modal-create-po');
        await refreshPurchaseOrders();
    } catch (err) {
        if (!err.reported) erp.toast(err.message || 'Unable to save the order.', 'error');
    } finally {
        btn.disabled = false;
        btn.textContent = document.getElementById('purchaseOrderId').value ? 'Save Changes' : 'Create Order';
    }
}

// SPA AJAX: View PO Details Modal
async function viewPODetails(poId) {
    openModal('modal-view-po');
    const itemsTbody = document.getElementById('view-po-items-tbody');
    const receiptsDiv = document.getElementById('view-po-receipts');
    itemsTbody.innerHTML = `<tr><td colspan="5" class="py-4 text-center text-slate-400">Loading order items...</td></tr>`;
    receiptsDiv.innerHTML = `<p class="text-slate-400">Loading receipts...</p>`;

    try {
        const data = await erp.api(`purchases/${poId}`);
        document.getElementById('view-po-ref').textContent = `Order: ${data.reference_no || ('PO-' + data.id)}`;
        document.getElementById('view-po-meta').textContent = `Placed on ${data.purchased_at || '—'} · Expected receipt: ${data.expected_received_at || 'Not scheduled'}`;
        document.getElementById('view-po-supplier').textContent = data.supplier_name || 'Vendor #' + data.supplier_id;
        document.getElementById('view-po-user').textContent = data.recorded_by || 'Admin';
        document.getElementById('view-po-total').textContent = 'USD ' + parseFloat(data.total || 0).toFixed(2);
        document.getElementById('view-po-status').innerHTML = `
            <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase ${data.status === 'received' ? 'bg-emerald-50 text-emerald-700' : (data.status === 'pending' ? 'bg-amber-50 text-amber-700' : 'bg-blue-50 text-blue-700')}">
                ${data.status}
            </span>
        `;

        // Line items
        const items = data.items || [];
        itemsTbody.innerHTML = items.map(item => `
            <tr class="hover:bg-slate-50/60">
                <td class="py-2.5 px-3 font-semibold text-slate-900">${escapePurchaseText(item.product_name)} (${escapePurchaseText(item.unit_label || "base unit")})</td>
                <td class="py-2.5 px-3 text-center font-bold text-slate-800">${parseFloat(item.quantity).toFixed(1)}</td>
                <td class="py-2.5 px-3 text-center font-bold ${item.quantity_received >= item.quantity ? 'text-emerald-600' : 'text-blue-600'}">
                    ${parseFloat(item.quantity_received || 0).toFixed(1)}
                </td>
                <td class="py-2.5 px-3 text-right font-medium">$${parseFloat(item.cost_price || item.unit_cost || 0).toFixed(2)}</td>
                <td class="py-2.5 px-3 text-right font-bold text-slate-900">$${Number(item.line_total).toFixed(2)}</td>
            </tr>
        `).join('');

        // Receipts
        const receipts = await erp.api(`purchases/${poId}/receipts`);
        if (receipts.length === 0) {
            receiptsDiv.innerHTML = `<p class="text-slate-400 italic">No goods-in receipts recorded yet for this purchase order.</p>`;
        } else {
            receiptsDiv.innerHTML = `
                <div class="space-y-2">
                    ${receipts.map(r => `
                        <div class="p-3 bg-slate-50 rounded-lg border border-slate-200 flex items-center justify-between text-xs">
                            <div>
                                <span class="font-bold text-slate-800 font-mono">${escapePurchaseText(r.receipt_no || 'GRN-' + r.id)}</span>
                                <span class="text-slate-400 ml-2">${escapePurchaseText(r.received_at || r.created_at)}</span>
                            </div>
                            <span class="px-2 py-0.5 rounded text-[10px] font-semibold bg-emerald-50 text-emerald-700">Checked-in</span>
                        </div>
                    `).join('')}
                </div>
            `;
        }

        // Footer action buttons
        const footer = document.getElementById('view-po-footer-actions');
        let actionsHtml = `<div></div>`;
        if (data.status === 'pending' || data.status === 'partially_received') {
            actionsHtml = `
                <div class="flex items-center gap-2">
                    ${data.status === 'pending' ? `
                        <button type="button" onclick="editPurchaseOrder(${data.id})" class="px-3 py-1.5 text-xs text-brand-600 border rounded-lg">Edit Order</button>
                        <button type="button" onclick="voidPO(${data.id})" class="px-3 py-1.5 text-xs text-rose-600 hover:bg-rose-50 border border-rose-200 rounded-lg transition-colors">
                            Void Order
                        </button>
                    ` : ''}
                    <a href="${purchaseConfig.receivingUrl}?po=${data.id}" class="px-3.5 py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-semibold rounded-lg shadow-sm transition-all flex items-center gap-1.5">
                        <i data-lucide="package-check" class="w-4 h-4"></i> Receive Goods Now
                    </a>
                </div>
            `;
        }
        footer.innerHTML = actionsHtml;
        renderPurchaseIcons(footer);

    } catch (err) {
        itemsTbody.innerHTML = `<tr><td colspan="5" class="py-4 text-center text-rose-500">Failed to load purchase order details.</td></tr>`;
    }
}

// SPA AJAX: Void Purchase Order
async function voidPO(poId) {
    const confirmation = await Swal.fire({
        title: 'Void purchase order?', text: 'This order will be cancelled.', icon: 'warning',
        input: 'textarea', inputLabel: 'Reason (optional)', inputAttributes: { maxlength: '1000' },
        showCancelButton: true, confirmButtonText: 'Void order', confirmButtonColor: '#e11d48'
    });
    if (!confirmation.isConfirmed) return;
    const reason = confirmation.value;

    try {
        await erp.api(`purchases/${poId}/void`, {
            method: 'POST',
            body: JSON.stringify({ reason: reason || 'Cancelled by user' })
        });
        erp.toast('Purchase order voided successfully.');
        closeModal('modal-view-po');
        await refreshPurchaseOrders();
    } catch (err) {
        // Handled by erp.api
    }
}
