<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>
<style>
    .finance-table-toolbar, .finance-table-footer { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 1rem; padding: 1rem 0; }
    .finance-table-toolbar .dataTables_filter { flex: 1 1 18rem; margin: 0; }
    .finance-table-toolbar .dataTables_filter label { display: block; width: 100%; }
    .finance-table-toolbar .dataTables_filter input { box-sizing: border-box; width: 100%; margin: 0; padding: .7rem .9rem; }
    .finance-table-toolbar .dataTables_length { flex: 0 0 auto; margin: 0; }
    .finance-table-footer .dataTables_info, .finance-table-footer .dataTables_paginate { margin: 0; padding: 0; }
</style>

<div class="mb-6">
    <p class="text-xs font-medium text-slate-500 mb-2"><?= in_array($activeSection, ['bills', 'payments']) ? 'Supplier Management' : 'Financial Management' ?></p>
    <h1 class="text-2xl font-bold tracking-tight text-slate-900"><?= esc($pageTitle) ?></h1>
</div>

<!-- ============================================================== -->
<!-- PAGE 1: Supplier Bills (Accounts Payable)                       -->
<!-- ============================================================== -->
<?php if ($activeSection === 'bills'): ?>
<div id="fin-content-bills" class="space-y-6">
    <div class="bg-white rounded-2xl border border-slate-200/80 p-6 sm:p-8 shadow-2xs">
        <div class="flex flex-wrap gap-3 items-center justify-between mb-4">
            <div>
                <h3 class="text-base font-bold text-slate-900">Supplier Bills (Payables)</h3>
                <p class="text-xs text-slate-500">Track and settle bills owed to vendors for inventory purchases or services.</p>
            </div>
            <button onclick="openNewBillModal()" class="px-3 py-1.5 bg-brand-600 text-white rounded-lg text-xs font-semibold shadow-sm hover:bg-brand-700">
                + New Bill
            </button>
        </div>

        <div class="overflow-x-auto">
            <table id="billsTable" class="w-full min-w-[640px] text-left text-xs stripe hover">
                <thead class="bg-slate-50 text-slate-600 font-semibold border-b border-slate-200">
                    <tr>
                        <th class="py-3 px-3">Bill Number</th>
                        <th class="py-3 px-3">Supplier Vendor</th>
                        <th class="py-3 px-3">Bill Date</th>
                        <th class="py-3 px-3">Due Date</th>
                        <th class="py-3 px-3">Total Amount</th>
                        <th class="py-3 px-3">Balance Due</th>
                        <th class="py-3 px-3">Payment Status</th>
                        <th class="py-3 px-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 text-slate-700">
                    <?php foreach ($bills as $b): ?>
                        <?php 
                        $bStatus = $b['status'] ?? 'unpaid';
                        $statusClass = match($bStatus) {
                            'paid' => 'bg-emerald-50 text-emerald-700 border-emerald-200',
                            'partially_paid', 'partial' => 'bg-amber-50 text-amber-700 border-amber-200',
                            default => 'bg-rose-50 text-rose-700 border-rose-200',
                        };
                        $bTotal = (float)($b['total'] ?? $b['total_amount'] ?? 0);
                        $bPaid  = (float)($b['paid_total'] ?? $b['paid_amount'] ?? 0);
                        $bal    = $bTotal - $bPaid;
                        $billNo = $b['bill_no'] ?? $b['bill_number'] ?? ('BILL-' . $b['id']);
                        ?>
                        <tr class="hover:bg-slate-50/80 transition-colors">
                            <td class="py-3 px-3 font-bold text-slate-900">
                                <?= esc($billNo) ?>
                            </td>
                            <td class="py-3 px-3 font-semibold text-slate-800">
                                <?= esc($b['supplier_name'] ?: 'Vendor #' . $b['supplier_id']) ?>
                            </td>
                            <td class="py-3 px-3 text-slate-500">
                                <?= esc($b['bill_date'] ?? $b['issue_date'] ?? '') ?>
                            </td>
                            <td class="py-3 px-3 text-slate-500">
                                <?= esc($b['due_date'] ?: '—') ?>
                            </td>
                            <td class="py-3 px-3 font-bold text-slate-900">
                                USD <?= number_format($bTotal, 2) ?>
                            </td>
                            <td class="py-3 px-3 font-bold <?= $bal > 0 ? 'text-rose-600' : 'text-emerald-600' ?>">
                                USD <?= number_format(max(0, $bal), 2) ?>
                            </td>
                            <td class="py-3 px-3">
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-semibold border <?= $statusClass ?> capitalize">
                                    <?= str_replace('_', ' ', $bStatus) ?>
                                </span>
                            </td>
                            <td class="py-3 px-3 text-right">
                                <?php if ($bStatus !== 'paid'): ?>
                                    <button onclick="openPayBillModal(<?= (int)$b['id'] ?>, '<?= esc($billNo, 'js') ?>', <?= max(0, $bal) ?>)"
                                            class="px-2.5 py-1 text-[11px] bg-brand-50 hover:bg-brand-100 text-brand-700 font-bold rounded border border-brand-200 transition-colors">
                                        Pay Bill
                                    </button>
                                <?php else: ?>
                                    <span class="text-[11px] text-emerald-600 font-semibold">Settled</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php endif; ?>

<!-- ============================================================== -->
<!-- PAGE 2: Supplier Payments History                               -->
<!-- ============================================================== -->
<?php if ($activeSection === 'payments'): ?>
<div id="fin-content-payments" class="space-y-6">
    <div class="bg-white rounded-2xl border border-slate-200/80 p-6 sm:p-8 shadow-2xs">
        <div class="mb-4">
            <h3 class="text-base font-bold text-slate-900">Supplier Payments History</h3>
            <p class="text-xs text-slate-500">Record of disbursements and payments made to suppliers against open bills.</p>
        </div>

        <div class="overflow-x-auto">
            <table id="paymentsTable" class="w-full min-w-[640px] text-left text-xs stripe hover">
                <thead class="bg-slate-50 text-slate-600 font-semibold border-b border-slate-200">
                    <tr>
                        <th class="py-3 px-3">Date Paid</th>
                        <th class="py-3 px-3">Supplier</th>
                        <th class="py-3 px-3">Applied To Bill</th>
                        <th class="py-3 px-3">Payment Account</th>
                        <th class="py-3 px-3">Reference #</th>
                        <th class="py-3 px-3">Amount Paid</th>
                        <th class="py-3 px-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 text-slate-700">
                    <?php foreach ($supplierPayments as $sp): ?>
                        <tr class="hover:bg-slate-50/80 transition-colors">
                            <td class="py-3 px-3 text-slate-500 whitespace-nowrap">
                                <?= esc(!empty($sp['paid_at']) ? date('M d, Y', strtotime($sp['paid_at'])) : '—') ?>
                            </td>
                            <td class="py-3 px-3 font-semibold text-slate-900">
                                <?= esc($sp['supplier_name'] ?: 'Vendor #' . $sp['supplier_id']) ?>
                            </td>
                            <td class="py-3 px-3 font-medium text-slate-700">
                                <?= esc($sp['bill_number'] ?: ($sp['bill_id'] ? 'Bill #' . $sp['bill_id'] : 'On account')) ?>
                            </td>
                            <td class="py-3 px-3 capitalize">
                                <span class="px-2 py-0.5 rounded bg-slate-100 text-slate-700 border border-slate-200 font-medium">
                                    <?= esc($sp['payment_account_name'] ?? str_replace('_', ' ', $sp['method'])) ?>
                                </span>
                            </td>
                            <td class="py-3 px-3 font-mono text-[11px] text-slate-500">
                                <?= esc($sp['reference'] ?: '—') ?>
                            </td>
                            <td class="py-3 px-3 font-bold text-emerald-700">
                                USD <?= number_format((float)$sp['amount'], 2) ?>
                            </td>
                            <td class="py-3 px-3 text-right">
                                <button onclick="voidSupplierPayment(<?= (int)$sp['id'] ?>)"
                                        class="px-2 py-0.5 text-[11px] text-rose-600 hover:bg-rose-50 rounded border border-rose-200 font-medium transition-colors">
                                    Void
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php endif; ?>

<!-- ============================================================== -->
<!-- PAGE 3: Customer Invoices (Accounts Receivable)                 -->
<!-- ============================================================== -->
<?php if ($activeSection === 'invoices'): ?>
<div id="fin-content-invoices" class="space-y-6">
    <div class="bg-white rounded-2xl border border-slate-200/80 p-6 sm:p-8 shadow-2xs">
        <div class="flex flex-wrap gap-3 items-center justify-between mb-4">
            <div>
                <h3 class="text-base font-bold text-slate-900">Customer Invoices (Receivables)</h3>
                <p class="text-xs text-slate-500">Official invoices issued to clients for wholesale, credit sales, or services.</p>
            </div>
            <button onclick="openNewInvoiceModal()" class="px-3 py-1.5 bg-brand-600 text-white rounded-lg text-xs font-semibold shadow-sm hover:bg-brand-700">
                + Issue Invoice
            </button>
        </div>

        <div class="overflow-x-auto">
            <table id="invoicesTable" class="w-full min-w-[640px] text-left text-xs stripe hover">
                <thead class="bg-slate-50 text-slate-600 font-semibold border-b border-slate-200">
                    <tr>
                        <th class="py-3 px-3">Invoice #</th>
                        <th class="py-3 px-3">Customer Client</th>
                        <th class="py-3 px-3">Issue Date</th>
                        <th class="py-3 px-3">Due Date</th>
                        <th class="py-3 px-3">Total Billed</th>
                        <th class="py-3 px-3">Balance Due</th>
                        <th class="py-3 px-3">Status</th>
                        <th class="py-3 px-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 text-slate-700">
                    <?php foreach ($invoices as $inv): ?>
                        <?php 
                        $iStatus = $inv['status'] ?? 'unpaid';
                        $statusClass = match($iStatus) {
                            'paid' => 'bg-emerald-50 text-emerald-700 border-emerald-200',
                            'partial' => 'bg-amber-50 text-amber-700 border-amber-200',
                            default => 'bg-rose-50 text-rose-700 border-rose-200',
                        };
                        $iTotal = (float)($inv['total'] ?? $inv['total_amount'] ?? 0);
                        $iPaid  = (float)($inv['paid_total'] ?? $inv['paid_amount'] ?? 0);
                        $iBal   = $iTotal - $iPaid;
                        $invNo  = $inv['invoice_no'] ?? $inv['invoice_number'] ?? ('INV-' . $inv['id']);
                        ?>
                        <tr class="hover:bg-slate-50/80 transition-colors">
                            <td class="py-3 px-3 font-bold text-slate-900">
                                <?= esc($invNo) ?>
                            </td>
                            <td class="py-3 px-3 font-semibold text-slate-800">
                                <?= esc($inv['customer_name'] ?: 'Customer #' . $inv['customer_id']) ?>
                            </td>
                            <td class="py-3 px-3 text-slate-500">
                                <?= esc($inv['invoice_date'] ?? $inv['issue_date'] ?? '') ?>
                            </td>
                            <td class="py-3 px-3 text-slate-500">
                                <?= esc($inv['due_date'] ?: '—') ?>
                            </td>
                            <td class="py-3 px-3 font-bold text-slate-900">
                                USD <?= number_format($iTotal, 2) ?>
                            </td>
                            <td class="py-3 px-3 font-bold <?= $iBal > 0 ? 'text-rose-600' : 'text-emerald-600' ?>">
                                USD <?= number_format(max(0, $iBal), 2) ?>
                            </td>
                            <td class="py-3 px-3">
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-semibold border <?= $statusClass ?> capitalize">
                                    <?= esc($iStatus) ?>
                                </span>
                            </td>
                            <td class="py-3 px-3 text-right">
                                <?php if (in_array($iStatus, ['unpaid', 'partial'], true) && $iBal > 0 && \App\Libraries\AuthContext::can('invoices.manage')): ?>
                                    <button onclick="openReceivePaymentModal(<?= (int)$inv['id'] ?>, '<?= esc($invNo, 'js') ?>', <?= max(0, $iBal) ?>)"
                                            class="px-2.5 py-1 text-[11px] bg-emerald-50 hover:bg-emerald-100 text-emerald-700 font-bold rounded border border-emerald-200 transition-colors">
                                        Collect Payment
                                    </button>
                                <?php elseif ($iStatus === 'paid'): ?>
                                    <span class="text-[11px] text-emerald-600 font-semibold">Fully Paid</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php endif; ?>

<?php if ($activeSection === 'receipts'): ?>
<?= $this->include('web/finance/customer_receipts') ?>
<?php endif; ?>

<!-- ============================================================== -->
<!-- PAGE 5: Chart of Accounts (PRO)                                 -->
<!-- ============================================================== -->
<?php if ($activeSection === 'coa'): ?>
<?= $this->include('web/finance/accounts') ?>
<?php endif; ?>

<!-- ============================================================== -->
<!-- TAB: POS Payment Methods (Chart of Accounts & POS Link)       -->
<!-- ============================================================== -->
<?php if ($activeSection === 'pos-methods'): ?>
<?= $this->include('web/finance/payment_methods') ?>
<?php endif; ?>

<!-- ============================================================== -->
<!-- PAGE 6: Journal Entries (PRO)                                   -->
<!-- ============================================================== -->
<?php if ($activeSection === 'journals'): ?>
<div id="fin-content-journals" class="space-y-6">
    <div class="bg-white rounded-2xl border border-slate-200/80 p-6 sm:p-8 shadow-2xs">
        <div class="flex flex-wrap gap-3 items-center justify-between mb-4">
            <div>
                <h3 class="text-base font-bold text-slate-900">Journal Vouchers (Double-Entry)</h3>
                <p class="text-xs text-slate-500">Every monetary transaction posted as balanced debit and credit ledger rows.</p>
            </div>
            <?php if (\App\Libraries\AuthContext::can('accounting.manage')): ?><a href="<?= site_url('finance/journals/new') ?>" class="px-3 py-1.5 bg-brand-600 text-white rounded-lg text-xs font-semibold shadow-sm hover:bg-brand-700">
                + Add New Journal
            </a><?php endif; ?>
        </div>

        <div class="overflow-x-auto">
            <table id="journalsTable" class="w-full min-w-[640px] text-left text-xs stripe hover">
                <thead class="bg-slate-50 text-slate-600 font-semibold border-b border-slate-200">
                    <tr>
                        <th class="py-3 px-3">Entry #</th>
                        <th class="py-3 px-3">Entry Date</th>
                        <th class="py-3 px-3">Source Type</th>
                        <th class="py-3 px-3">Description</th>
                        <th class="py-3 px-3 text-right">Debit / Credit Total</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 text-slate-700">
                    <?php foreach ($journalEntries as $je): ?>
                        <tr class="hover:bg-slate-50/80 transition-colors">
                            <td class="py-3 px-3 font-mono font-bold text-slate-900"><?= esc($je['entry_no'] ?? ('JV-' . $je['id'])) ?></td>
                            <td class="py-3 px-3 text-slate-500"><?= esc($je['entry_date']) ?></td>
                            <td class="py-3 px-3">
                                <span class="px-2 py-0.5 rounded bg-blue-50 text-brand-700 border border-blue-200 font-semibold capitalize">
                                    <?= esc($je['source_type']) ?>
                                </span>
                            </td>
                            <td class="py-3 px-3 text-slate-600 max-w-sm truncate"><?= esc($je['description'] ?: 'Automated journal entry') ?></td>
                            <td class="py-3 px-3 text-right font-bold text-slate-900">
                                USD <?= number_format((float)($je['total_debit'] ?? $je['total_amount'] ?? 0), 2) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php endif; ?>

<!-- ============================================================== -->
<!-- PAGE 7: Banks & Periods (PRO)                                   -->
<!-- ============================================================== -->
<?php if ($activeSection === 'periods'): ?>
<?= $this->include('web/finance/periods') ?>
<?php endif; ?>

<!-- ============================================================== -->
<!-- MODALS (Creation & Payment Dialogs)                            -->
<!-- ============================================================== -->

<?php if (in_array($activeSection, ['bills', 'payments'], true)): ?>
<!-- 1. Create Supplier Bill Modal -->
<div id="newBillModal" class="fixed inset-0 z-50 bg-slate-900/50 backdrop-blur-sm hidden flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl shadow-2xl border border-slate-200 w-full max-w-lg overflow-hidden animate-in fade-in zoom-in duration-200">
        <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
            <h3 class="text-sm font-bold text-slate-900">Record New Supplier Bill</h3>
            <button onclick="closeNewBillModal()" class="text-slate-400 hover:text-slate-700">
                <i data-lucide="x" class="w-4 h-4"></i>
            </button>
        </div>

        <form id="newBillForm" onsubmit="submitNewBill(event)" class="p-6 space-y-4 text-xs">
            <div>
                <label class="block font-semibold text-slate-700 mb-1">Select Supplier *</label>
                <select id="bill_supplier_id" required class="w-full px-3 py-2 border border-slate-200 rounded-lg focus:outline-none focus:border-brand-600 focus:ring-1 focus:ring-brand-600">
                    <option value="">Choose supplier vendor...</option>
                    <?php foreach ($suppliers as $s): ?>
                        <option value="<?= (int)$s['id'] ?>"><?= esc($s['name']) ?> (<?= esc($s['phone']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block font-semibold text-slate-700 mb-1">Bill / Invoice # *</label>
                    <input type="text" id="bill_number" required placeholder="e.g. BILL-9041"
                           class="w-full px-3 py-2 border border-slate-200 rounded-lg focus:outline-none focus:border-brand-600 focus:ring-1 focus:ring-brand-600">
                </div>
                <div>
                    <label class="block font-semibold text-slate-700 mb-1">Bill Date *</label>
                    <input type="date" id="bill_date" required value="<?= date('Y-m-d') ?>"
                           class="w-full px-3 py-2 border border-slate-200 rounded-lg focus:outline-none focus:border-brand-600 focus:ring-1 focus:ring-brand-600">
                </div>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block font-semibold text-slate-700 mb-1">Due Date</label>
                    <input type="date" id="bill_due_date" value="<?= date('Y-m-d', strtotime('+30 days')) ?>"
                           class="w-full px-3 py-2 border border-slate-200 rounded-lg focus:outline-none focus:border-brand-600 focus:ring-1 focus:ring-brand-600">
                </div>
                <div>
                    <label class="block font-semibold text-slate-700 mb-1">Total Bill Amount ($) *</label>
                    <input type="number" step="0.01" id="bill_total" required placeholder="0.00"
                           class="w-full px-3 py-2 border border-slate-200 rounded-lg focus:outline-none focus:border-brand-600 focus:ring-1 focus:ring-brand-600">
                </div>
            </div>

            <div class="pt-3 flex justify-end gap-2 border-t border-slate-100">
                <button type="button" onclick="closeNewBillModal()" class="px-3.5 py-1.5 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-lg font-medium">Cancel</button>
                <button type="submit" id="save-bill-btn" class="px-4 py-1.5 bg-brand-600 hover:bg-brand-700 text-white rounded-lg font-semibold shadow-sm">Save Bill</button>
            </div>
        </form>
    </div>
</div>

<!-- 2. Record Payment on Bill Modal -->
<div id="payBillModal" class="fixed inset-0 z-50 bg-slate-900/50 backdrop-blur-sm hidden flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl shadow-2xl border border-slate-200 w-full max-w-md overflow-hidden animate-in fade-in zoom-in duration-200">
        <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
            <h3 class="text-sm font-bold text-slate-900">Record Bill Payment</h3>
            <button onclick="closePayBillModal()" class="text-slate-400 hover:text-slate-700">
                <i data-lucide="x" class="w-4 h-4"></i>
            </button>
        </div>

        <form id="payBillForm" onsubmit="submitPayBill(event)" class="p-6 space-y-4 text-xs">
            <input type="hidden" id="pay_bill_id">

            <div>
                <span class="text-slate-500">Bill Number:</span>
                <span id="pay_bill_label" class="font-bold text-slate-900 ml-1"></span>
            </div>

            <div>
                <label class="block font-semibold text-slate-700 mb-1">Payment Amount ($) *</label>
                <input type="number" min="0.01" step="0.01" id="pay_amount" required
                       class="w-full px-3 py-2 border border-slate-200 rounded-lg focus:outline-none focus:border-brand-600 focus:ring-1 focus:ring-brand-600 text-sm font-bold">
            </div>

            <div>
                <label for="pay_account" class="block font-semibold text-slate-700 mb-1">Payment account</label>
                <select id="pay_account" required class="w-full px-3 py-2 border border-slate-200 rounded-lg focus:outline-none focus:border-brand-600 focus:ring-1 focus:ring-brand-600">
                    <?= $this->include('web/finance/settlement_account_options') ?>
                </select>
            </div>

            <div>
                <label class="block font-semibold text-slate-700 mb-1">Payment Reference / Txn ID</label>
                <input type="text" id="pay_reference" placeholder="e.g. TXN-89042"
                       class="w-full px-3 py-2 border border-slate-200 rounded-lg focus:outline-none focus:border-brand-600 focus:ring-1 focus:ring-brand-600">
            </div>

            <div class="pt-3 flex justify-end gap-2 border-t border-slate-100">
                <button type="button" onclick="closePayBillModal()" class="px-3.5 py-1.5 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-lg font-medium">Cancel</button>
                <button type="submit" id="submit-pay-btn" class="px-4 py-1.5 bg-brand-600 hover:bg-brand-700 text-white rounded-lg font-semibold shadow-sm">Confirm Disbursement</button>
            </div>
        </form>
    </div>
</div>

<?php endif; ?>
<?php if ($activeSection === 'invoices'): ?>
<?= $this->include('web/finance/customer_receipts') ?>
<?php endif; ?>
<?php if (in_array($activeSection, ['invoices', 'receipts'], true)): ?>
<!-- 3. Issue Customer Invoice Modal -->
<div id="newInvoiceModal" class="fixed inset-0 z-50 bg-slate-900/50 backdrop-blur-sm hidden flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl shadow-2xl border border-slate-200 w-full max-w-lg overflow-hidden animate-in fade-in zoom-in duration-200">
        <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
            <h3 class="text-sm font-bold text-slate-900">Issue Customer Invoice</h3>
            <button onclick="closeNewInvoiceModal()" class="text-slate-400 hover:text-slate-700">
                <i data-lucide="x" class="w-4 h-4"></i>
            </button>
        </div>

        <form id="newInvoiceForm" onsubmit="submitNewInvoice(event)" class="p-6 space-y-4 text-xs">
            <div>
                <label class="block font-semibold text-slate-700 mb-1">Select Customer *</label>
                <select id="inv_customer_id" required class="w-full px-3 py-2 border border-slate-200 rounded-lg focus:outline-none focus:border-brand-600 focus:ring-1 focus:ring-brand-600">
                    <option value="">Choose customer client...</option>
                    <?php foreach ($customers as $c): ?>
                        <option value="<?= (int)$c['id'] ?>"><?= esc($c['name']) ?> (<?= esc($c['phone']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block font-semibold text-slate-700 mb-1">Invoice Number *</label>
                    <input type="text" id="inv_number" required placeholder="e.g. INV-2026-001"
                           class="w-full px-3 py-2 border border-slate-200 rounded-lg focus:outline-none focus:border-brand-600 focus:ring-1 focus:ring-brand-600">
                </div>
                <div>
                    <label class="block font-semibold text-slate-700 mb-1">Issue Date *</label>
                    <input type="date" id="inv_date" required value="<?= date('Y-m-d') ?>"
                           class="w-full px-3 py-2 border border-slate-200 rounded-lg focus:outline-none focus:border-brand-600 focus:ring-1 focus:ring-brand-600">
                </div>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block font-semibold text-slate-700 mb-1">Payment Due Date</label>
                    <input type="date" id="inv_due_date" value="<?= date('Y-m-d', strtotime('+14 days')) ?>"
                           class="w-full px-3 py-2 border border-slate-200 rounded-lg focus:outline-none focus:border-brand-600 focus:ring-1 focus:ring-brand-600">
                </div>
                <div>
                    <label class="block font-semibold text-slate-700 mb-1">Total Amount ($) *</label>
                    <input type="number" step="0.01" id="inv_total" required placeholder="0.00"
                           class="w-full px-3 py-2 border border-slate-200 rounded-lg focus:outline-none focus:border-brand-600 focus:ring-1 focus:ring-brand-600">
                </div>
            </div>

            <div class="pt-3 flex justify-end gap-2 border-t border-slate-100">
                <button type="button" onclick="closeNewInvoiceModal()" class="px-3.5 py-1.5 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-lg font-medium">Cancel</button>
                <button type="submit" id="save-inv-btn" class="px-4 py-1.5 bg-brand-600 hover:bg-brand-700 text-white rounded-lg font-semibold shadow-sm">Issue Invoice</button>
            </div>
        </form>
    </div>
</div>

<?php endif; ?>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
    $(document).ready(function() {
        document.querySelectorAll('table[id]:not(#posMethodsTable)').forEach(table => {
            erp.initDataTable('#' + table.id, {
                pageLength: 10,
                responsive: false,
                dom: '<"finance-table-toolbar"fl>rt<"finance-table-footer"ip>',
            });
            document.querySelector('#' + table.id + '_filter input')?.setAttribute('aria-label', 'Search ' + (table.id === 'journalsTable' ? 'journal entries' : 'records'));
        });
    });

    // Modal Helpers
    function openNewBillModal() {
        document.getElementById('newBillForm').reset();
        document.getElementById('newBillModal').classList.remove('hidden');
    }
    function closeNewBillModal() {
        document.getElementById('newBillModal').classList.add('hidden');
    }

    async function submitNewBill(e) {
        e.preventDefault();
        const btn = document.getElementById('save-bill-btn');
        btn.disabled = true;

        const payload = {
            supplier_id: parseInt(document.getElementById('bill_supplier_id').value, 10),
            bill_no: document.getElementById('bill_number').value.trim(),
            bill_date: document.getElementById('bill_date').value,
            due_date: document.getElementById('bill_due_date').value,
            total_amount: parseFloat(document.getElementById('bill_total').value),
            items: [
                {
                    description: 'Supplier Bill Items',
                    quantity: 1,
                    unit_cost: parseFloat(document.getElementById('bill_total').value)
                }
            ]
        };

        try {
            await erp.api('supplier-bills', {
                method: 'POST',
                body: JSON.stringify(payload)
            });
            erp.toast('Supplier bill created successfully!');
            closeNewBillModal();
            setTimeout(() => window.location.reload(), 600);
        } catch (err) {
            // Handled
        } finally {
            btn.disabled = false;
        }
    }

    let payBillRequestId;
    function openPayBillModal(billId, billNum, balance) {
        if (document.getElementById('submit-pay-btn').disabled) return;
        document.getElementById('payBillForm').reset();
        $('#pay_account').trigger('change');
        payBillRequestId = crypto.randomUUID();
        document.getElementById('pay_bill_id').value = billId;
        document.getElementById('pay_bill_label').textContent = billNum;
        document.getElementById('pay_amount').value = balance.toFixed(2);
        document.getElementById('payBillModal').classList.remove('hidden');
    }
    function closePayBillModal() {
        if (document.getElementById('submit-pay-btn').disabled) return;
        document.getElementById('payBillModal').classList.add('hidden');
    }

    async function submitPayBill(e) {
        e.preventDefault();
        const btn = document.getElementById('submit-pay-btn');
        if (btn.disabled) return;
        btn.disabled = true;

        const payload = {
            uuid: payBillRequestId,
            bill_id: parseInt(document.getElementById('pay_bill_id').value, 10),
            amount: parseFloat(document.getElementById('pay_amount').value),
            account_id: Number(document.getElementById('pay_account').value),
            reference: document.getElementById('pay_reference').value.trim()
        };

        try {
            const result = await erp.web(<?= json_encode(site_url('finance/supplier-payments/save')) ?>, {
                method: 'POST',
                body: JSON.stringify(payload)
            });
            erp.toast(result.data.status === 'cancelled' ? 'This payment was already voided.' : 'Payment recorded successfully!');
            document.getElementById('payBillModal').classList.add('hidden');
            setTimeout(() => window.location.reload(), 600);
        } catch (err) {
            btn.disabled = false;
        }
    }

    async function voidSupplierPayment(paymentId) {
        if (!await erp.confirm('Void this supplier payment?', { title: 'Void payment', confirmButtonText: 'Void payment', destructive: true })) return;
        try {
            await erp.api('supplier-payments/' + paymentId + '/void', { method: 'POST' });
            erp.toast('Payment voided.');
            setTimeout(() => window.location.reload(), 600);
        } catch (err) {}
    }

    function openNewInvoiceModal() {
        document.getElementById('newInvoiceForm').reset();
        document.getElementById('newInvoiceModal').classList.remove('hidden');
    }
    function closeNewInvoiceModal() {
        document.getElementById('newInvoiceModal').classList.add('hidden');
    }

    async function submitNewInvoice(e) {
        e.preventDefault();
        const btn = document.getElementById('save-inv-btn');
        btn.disabled = true;

        const payload = {
            customer_id: parseInt(document.getElementById('inv_customer_id').value, 10),
            invoice_number: document.getElementById('inv_number').value.trim(),
            issue_date: document.getElementById('inv_date').value,
            due_date: document.getElementById('inv_due_date').value,
            total_amount: parseFloat(document.getElementById('inv_total').value),
            items: [
                {
                    description: 'Invoice service / goods',
                    quantity: 1,
                    unit_price: parseFloat(document.getElementById('inv_total').value)
                }
            ]
        };

        try {
            await erp.api('customer-invoices', {
                method: 'POST',
                body: JSON.stringify(payload)
            });
            erp.toast('Customer invoice issued!');
            closeNewInvoiceModal();
            setTimeout(() => window.location.reload(), 600);
        } catch (err) {
            // Handled
        } finally {
            btn.disabled = false;
        }
    }

</script>
<?php if (in_array($activeSection, ['invoices', 'receipts'], true)): ?>
<script src="<?= base_url('assets/js/receipt-allocation.js') ?>"></script>
<script src="<?= base_url('assets/js/customer-receipts.js').'?v='.filemtime(FCPATH.'assets/js/customer-receipts.js') ?>"></script>
<?php endif; ?>
<?php if ($activeSection === 'pos-methods'): ?>
<script src="<?= base_url('assets/js/vendor/Sortable.min.js') ?>"></script>
<script src="<?= base_url('assets/js/finance-payment-methods.js') ?>"></script>
<?php endif; ?>
<?= $this->endSection() ?>
