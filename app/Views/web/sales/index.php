<?php $receiptBranding=(new \App\Services\ReceiptBrandingService())->get(); ?>
<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>

<!-- Page Header & Action Bar -->
<div class="mb-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
    <div>
        <div class="erp-page-title flex flex-wrap items-center gap-2">
            <h1 class="text-2xl sm:text-3xl font-bold text-slate-900 tracking-tight">
                Sales & Invoices
            </h1>
            <span class="px-2.5 py-0.5 rounded-full text-xs font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200">
                POS Transactions
            </span>
        </div>
        <p class="text-xs sm:text-sm text-slate-500 mt-1">
            Track daily point-of-sale orders, customer receipts, and credit sales balances.
        </p>
    </div>

    <div class="erp-page-actions flex flex-wrap items-center gap-2.5">
        <button type="button" onclick="exportSalesCsv()" 
                class="inline-flex items-center gap-1.5 px-3 py-2 bg-white border border-slate-200 hover:bg-slate-50 text-slate-700 text-xs font-semibold rounded-lg shadow-2xs transition-all">
            <i data-lucide="download" class="w-4 h-4 text-slate-500"></i>
            <span>Export CSV</span>
        </button>
        <a href="<?= site_url('pos') ?>" 
           class="inline-flex items-center gap-1.5 px-4 py-2 bg-brand-600 hover:bg-brand-700 active:scale-95 text-white text-xs font-semibold rounded-lg shadow-sm transition-all">
            <i data-lucide="shopping-bag" class="w-4 h-4"></i>
            <span>Launch POS Terminal</span>
        </a>
    </div>
</div>

<!-- 3 KPI Summary Cards -->
<div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
    <div class="bg-white p-5 rounded-xl border border-slate-200/80 shadow-2xs flex items-center justify-between">
        <div>
            <span class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Sales after returns</span>
            <div class="text-2xl font-bold text-slate-900 mt-1">USD <?= number_format($totalRevenue, 2) ?></div>
            <div class="text-[11px] text-slate-500 mt-0.5">Total across <?= count($sales) ?> sales receipts</div>
        </div>
        <div class="w-11 h-11 bg-slate-50 rounded-xl flex items-center justify-center text-slate-600 border border-slate-100">
            <i data-lucide="receipt" class="w-5 h-5"></i>
        </div>
    </div>

    <div class="bg-white p-5 rounded-xl border border-slate-200/80 shadow-2xs flex items-center justify-between">
        <div>
            <span class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Net settled</span>
            <div class="text-2xl font-bold text-emerald-600 mt-1">USD <?= number_format($totalPaid, 2) ?></div>
            <div class="text-[11px] text-slate-500 mt-0.5">Payments and waived discounts, after returns</div>
        </div>
        <div class="w-11 h-11 bg-emerald-50 rounded-xl flex items-center justify-center text-emerald-600 border border-emerald-100">
            <i data-lucide="check-circle-2" class="w-5 h-5"></i>
        </div>
    </div>

    <div class="bg-white p-5 rounded-xl border border-slate-200/80 shadow-2xs flex items-center justify-between">
        <div>
            <span class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Store Credit / Pay Later Due</span>
            <div class="text-2xl font-bold text-amber-600 mt-1">USD <?= number_format($unpaidCredit, 2) ?></div>
            <div class="text-[11px] text-slate-500 mt-0.5">Unsettled customer receivables</div>
        </div>
        <div class="w-11 h-11 bg-amber-50 rounded-xl flex items-center justify-center text-amber-600 border border-amber-100">
            <i data-lucide="clock" class="w-5 h-5"></i>
        </div>
    </div>
</div>

<!-- Sales Orders Log Table Card -->
<div class="bg-white rounded-xl border border-slate-200/80 shadow-2xs overflow-hidden">
    <div class="p-4 sm:p-5 border-b border-slate-100 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
        <div>
            <h2 class="text-sm font-bold text-slate-900">Sales Transactions Register</h2>
            <p class="text-xs text-slate-500">Every sales invoice, payment status, and thermal receipt</p>
        </div>
    </div>

    <div class="p-4 sm:p-6 overflow-x-auto">
        <table id="salesTable" class="w-full text-left text-xs border-collapse">
            <thead>
                <tr class="border-b border-slate-200 text-slate-500 uppercase tracking-wider text-[11px]">
                    <th class="py-3 px-3">Invoice No</th>
                    <th class="py-3 px-3">Date & Time</th>
                    <th class="py-3 px-3">Customer</th>
                    <th class="py-3 px-3">Cashier</th>
                    <th class="py-3 px-3 text-right">Subtotal</th>
                    <th class="py-3 px-3 text-right">Discount</th>
                    <th class="py-3 px-3 text-right">Net Total</th>
                    <th class="py-3 px-3 text-right">Paid / settled</th>
                    <th class="py-3 px-3 text-center">Status</th>
                    <th class="py-3 px-3 text-center">Action</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 text-slate-700">
                <?php foreach ($sales as $sale): ?>
                    <tr class="hover:bg-slate-50/60 transition-colors">
                        <td class="py-3 px-3 font-semibold text-slate-900">
                            <span class="font-mono text-xs"><?= esc($sale['invoice_no'] ?: '#' . $sale['id']) ?></span>
                        </td>
                        <td class="py-3 px-3 text-slate-500 whitespace-nowrap">
                            <?= date('M d, Y h:i A', strtotime($sale['sold_at'] ?? 'now')) ?>
                        </td>
                        <td class="py-3 px-3">
                            <span class="font-medium text-slate-800">
                                <?= esc($sale['customer_name'] ?? 'Walk-in Customer') ?>
                            </span>
                        </td>
                        <td class="py-3 px-3 text-slate-500">
                            <?= esc($sale['cashier_name'] ?? 'Staff') ?>
                        </td>
                        <td class="py-3 px-3 text-right font-mono">
                            $<?= number_format((float) $sale['subtotal'], 2) ?>
                        </td>
                        <td class="py-3 px-3 text-right font-mono text-slate-400">
                            $<?= number_format((float) $sale['discount'], 2) ?>
                        </td>
                        <td class="py-3 px-3 text-right font-mono font-bold text-slate-900">
                            $<?= number_format((float) $sale['net_total'], 2) ?>
                            <?php if ($sale['returned_total'] > 0): ?><span class="block text-[10px] text-amber-700">Returned $<?= number_format($sale['returned_total'], 2) ?></span><?php endif; ?>
                        </td>
                        <td class="py-3 px-3 text-right font-mono font-medium text-emerald-600">
                            $<?= number_format((float) $sale['paid_total'], 2) ?>
                        </td>
                        <td class="py-3 px-3 text-center">
                            <?php
                                $status = strtolower($sale['status'] ?? 'completed');
                                $badgeClass = 'bg-emerald-50 text-emerald-700 border-emerald-200';
                                if ($status === 'partial') {
                                    $badgeClass = 'bg-amber-50 text-amber-700 border-amber-200';
                                } elseif ($status === 'unpaid' || $status === 'credit') {
                                    $badgeClass = 'bg-rose-50 text-rose-700 border-rose-200';
                                }
                            ?>
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-semibold border <?= $badgeClass ?> capitalize">
                                <?= esc($status) ?>
                            </span>
                        </td>
                        <td class="py-3 px-3 text-center">
                            <button type="button" data-pharmacy-receipt="<?= (int) $sale['id'] ?>"
                                    class="inline-flex items-center gap-1 px-2.5 py-1 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-medium rounded transition-colors">
                                <i data-lucide="printer" class="w-3.5 h-3.5"></i>
                                <span>Receipt</span>
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Thermal Receipt Modal -->
<div id="receiptModal" class="fixed inset-0 z-50 hidden overflow-y-auto bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-4">
    <div class="relative bg-white rounded-2xl shadow-2xl max-w-sm w-full p-6 text-slate-800">
        <!-- Close button (screen only) -->
        <button type="button" onclick="closeReceiptModal()" 
                class="print:hidden absolute top-4 right-4 text-slate-400 hover:text-slate-600 p-1 rounded-lg hover:bg-slate-100">
            <i data-lucide="x" class="w-5 h-5"></i>
        </button>

        <!-- Printable Receipt Body -->
        <div id="receiptPrintArea" class="text-center font-mono text-xs">
            <div class="flex flex-col items-center mb-3">
                <div class="w-10 h-10 bg-brand-600 rounded-lg flex items-center justify-center text-white mb-2 shadow-xs">
                    <i data-lucide="coffee" class="w-6 h-6"></i>
                </div>
                <h3 class="text-base font-bold text-slate-900 tracking-tight"><?= esc($receiptBranding['store_name']) ?></h3>
                <p class="text-[11px] text-slate-500"><?= esc($receiptBranding['address']) ?></p>
                <p class="text-[10px] text-slate-400">Tel: <?= esc($receiptBranding['phone']) ?></p>
                    <?php if($receiptBranding['header']!==''): ?><p class="text-[11px] text-slate-500 mt-1"><?= esc($receiptBranding['header']) ?></p><?php endif; ?>
            </div>

            <div class="border-t border-b border-dashed border-slate-300 py-2.5 my-3 text-left space-y-1 text-[11px]">
                <div class="flex justify-between">
                    <span class="text-slate-500">Invoice:</span>
                    <span id="rcptInvoiceNo" class="font-bold text-slate-800">#0000</span>
                </div>
                <div class="flex justify-between">
                    <span class="text-slate-500">Date:</span>
                    <span id="rcptDate" class="text-slate-700">Nov 03, 2026 07:00</span>
                </div>
                <div class="flex justify-between">
                    <span class="text-slate-500">Customer:</span>
                    <span id="rcptCustomer" class="font-semibold text-slate-800">Walk-in</span>
                </div>
                <div class="flex justify-between">
                    <span class="text-slate-500">Cashier:</span>
                    <span id="rcptCashier" class="text-slate-700">Staff</span>
                </div>
            </div>

            <!-- Items -->
            <table class="w-full text-left my-3 text-[11px]">
                <thead>
                    <tr class="border-b border-slate-300 text-slate-500">
                        <th class="py-1">Item</th>
                        <th class="py-1 text-center">Qty</th>
                        <th class="py-1 text-right">Price</th>
                        <th class="py-1 text-right">Total</th>
                    </tr>
                </thead>
                <tbody id="rcptItemsBody" class="divide-y divide-slate-100">
                    <!-- Populated dynamically -->
                </tbody>
            </table>

            <!-- Totals -->
            <div class="border-t border-dashed border-slate-300 pt-2 space-y-1 text-[11px] text-right">
                <div class="flex justify-between text-slate-500">
                    <span>Subtotal:</span>
                    <span id="rcptSubtotal">$0.00</span>
                </div>
                <div class="flex justify-between text-slate-500">
                    <span>Discount:</span>
                    <span id="rcptDiscount">$0.00</span>
                </div>
                <div class="flex justify-between text-slate-500">
                    <span>Tax:</span>
                    <span id="rcptTax">$0.00</span>
                </div>
                <div class="flex justify-between text-slate-500"><span>Shipping:</span><span id="rcptShipping">$0.00</span></div>
                    <div class="flex justify-between text-sm font-bold text-slate-900 border-t border-slate-200 pt-1.5 mt-1">
                    <span>GRAND TOTAL:</span>
                    <span id="rcptGrandTotal">$0.00</span>
                </div>
                <div id="rcptReturnRow" class="hidden text-amber-700"><div class="flex justify-between"><span>Returns:</span><span id="rcptReturned"></span></div><div class="flex justify-between font-bold"><span>Net total:</span><span id="rcptNet"></span></div><div class="flex justify-between"><span>Refunded:</span><span id="rcptRefunded"></span></div></div>
            </div>

            <!-- Payments -->
            <div class="border-t border-dashed border-slate-300 pt-2 mt-2 text-left text-[11px] space-y-1">
                <div class="font-bold text-slate-700 mb-0.5">Payment Details:</div>
                <div id="rcptPaymentsList">
                    <!-- Payment lines -->
                </div>
                <div class="flex justify-between font-bold text-slate-800 pt-1">
                    <span>Amount Paid:</span>
                    <span id="rcptPaidTotal">$0.00</span>
                </div>
                <div id="rcptDueRow" class="flex justify-between font-semibold text-rose-600 hidden">
                    <span>Balance Due (Credit):</span>
                    <span id="rcptDueTotal">$0.00</span>
                </div>
            </div>

            <div class="mt-4 pt-3 border-t border-slate-200 text-center text-[10px] text-slate-500">
                <p class="font-medium whitespace-pre-line"><?= esc($receiptBranding['footer']) ?></p>
                <p class="text-slate-400 mt-0.5">Powered by <?= esc($receiptBranding['system_name']) ?></p>
            </div>
        </div>

        <!-- Modal Action Footer (Screen only) -->
        <div class="print:hidden mt-6 flex flex-col gap-2">
            <button type="button" onclick="printDirectThermalSale()" id="salesDirectPrintBtn"
                    class="w-full inline-flex items-center justify-center gap-2 py-2.5 bg-brand-600 hover:bg-brand-700 text-white rounded-xl text-xs font-bold shadow-sm transition-all">
                <i data-lucide="printer" class="w-4 h-4"></i>
                <span>Print</span>
            </button>
            <div class="flex gap-2">
                <button type="button" onclick="closeReceiptModal()" 
                        class="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl text-xs font-medium transition-colors">
                    Close
                </button>
            </div>
        </div>
    </div>
</div>

<style>
@media print {
    body * {
        visibility: hidden;
    }
    #receiptPrintArea, #receiptPrintArea * {
        visibility: visible;
    }
    #receiptPrintArea {
        position: absolute;
        left: 0;
        top: 0;
        width: 80mm;
        padding: 10px;
        margin: 0;
        background: white;
    }
}
</style>

<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
    $(document).ready(function() {
        erp.initDataTable('#salesTable', {
            order: [[0, 'desc']],
            pageLength: 25
        });
    });

    let currentViewSaleId = null;

    async function viewReceipt(saleId) {
        currentViewSaleId = saleId;
        try {
            const res = await erp.api('/sales/' + saleId);
            const sale = res.data;
            if (!sale) return;

            document.getElementById('rcptInvoiceNo').textContent = sale.invoice_no || ('#' + sale.id);
            document.getElementById('rcptDate').textContent = new Date(sale.sold_at || Date.now()).toLocaleString();
            document.getElementById('rcptCustomer').textContent = sale.customer_name || 'Walk-in Customer';
            document.getElementById('rcptCashier').textContent = sale.cashier_name || 'Staff';
            
            document.getElementById('rcptSubtotal').textContent = '$' + parseFloat(sale.subtotal || 0).toFixed(2);
            document.getElementById('rcptDiscount').textContent = '$' + parseFloat(sale.discount || 0).toFixed(2);
            document.getElementById('rcptTax').textContent = '$' + parseFloat(sale.tax_total || 0).toFixed(2);
            document.getElementById('rcptShipping').textContent = '$' + parseFloat(sale.shipping || 0).toFixed(2);
            document.getElementById('rcptGrandTotal').textContent = '$' + parseFloat(sale.grand_total || 0).toFixed(2);
            document.getElementById('rcptReturnRow').classList.toggle('hidden', !Number(sale.returned_total));
            document.getElementById('rcptReturned').textContent = '-$' + Number(sale.returned_total || 0).toFixed(2);
            document.getElementById('rcptNet').textContent = '$' + Number(sale.net_total ?? sale.grand_total).toFixed(2);
            document.getElementById('rcptRefunded').textContent = '$' + Number(sale.refunded_total || 0).toFixed(2);
            document.getElementById('rcptPaidTotal').textContent = '$' + parseFloat(sale.paid_total || 0).toFixed(2);

            const due = parseFloat(sale.net_total ?? sale.grand_total ?? 0) - parseFloat(sale.paid_total || 0);
            const dueRow = document.getElementById('rcptDueRow');
            if (due > 0.005) {
                dueRow.classList.remove('hidden');
                document.getElementById('rcptDueTotal').textContent = '$' + due.toFixed(2);
            } else {
                dueRow.classList.add('hidden');
            }

            // Line items
            const itemsBody = document.getElementById('rcptItemsBody');
            itemsBody.innerHTML = '';
            (sale.items || []).forEach(item => {
                const tr = document.createElement('tr');
                tr.innerHTML = `
                    <td class="py-1 text-slate-800">${item.description}</td>
                    <td class="py-1 text-center font-mono">${item.quantity}</td>
                    <td class="py-1 text-right font-mono">$${parseFloat(item.unit_price).toFixed(2)}</td>
                    <td class="py-1 text-right font-mono font-medium">$${parseFloat(item.line_total).toFixed(2)}</td>
                `;
                itemsBody.appendChild(tr);
            });

            // Payments
            const payList = document.getElementById('rcptPaymentsList');
            payList.innerHTML = '';
            if (sale.payments && sale.payments.length > 0) {
                sale.payments.forEach(p => {
                    const row = document.createElement('div');
                    row.className = 'flex justify-between text-slate-600';
                    row.innerHTML = `
                        <span>${p.reference || p.method}:</span>
                        <span class="font-mono">$${parseFloat(p.amount).toFixed(2)}</span>
                    `;
                    payList.appendChild(row);
                });
            } else if (due > 0) {
                payList.innerHTML = '<div class="text-amber-600 italic">Pay Later (Account Credit)</div>';
            }

            document.getElementById('receiptModal').classList.remove('hidden');
            lucide.createIcons();
        } catch (err) {
            erp.toast(err.message || 'Failed to load receipt details', 'error');
        }
    }

    async function printDirectThermalSale() {
        if (!currentViewSaleId) return;
        const btn = document.getElementById('salesDirectPrintBtn');
        if (btn?.disabled) return;
        const oldHtml = btn ? btn.innerHTML : '';
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = `<span class="inline-block animate-spin mr-1">↻</span> Sending to ESC/POS Printer...`;
        }

        try {
            const result = await window.erpPrinting.sale(currentViewSaleId);
            const res = {data: result};
            if (res.data && res.data.success) {
                erp.toast('✓ ' + res.data.message);
            } else {
                erp.toast((res.data && res.data.error) ? res.data.error : 'Thermal printer not responding.', 'error');
            }
        } catch (err) {
            erp.toast(err.message || 'Thermal printer communication error', 'error');
        } finally {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = oldHtml;
            }
        }
    }

    function closeReceiptModal() {
        document.getElementById('receiptModal').classList.add('hidden');
    }

    function exportSalesCsv() {
        window.location.href = '<?= site_url('api/v1/sales') ?>?perPage=1000';
    }
</script>
<?= $this->endSection() ?>
