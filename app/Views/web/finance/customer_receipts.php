<?php $canCollect = \App\Libraries\AuthContext::can('invoices.manage'); ?>
<div id="customerReceiptManager" data-auto-print="<?= !empty($receiptAutoPrintEnabled)?'1':'0' ?>" data-scope="<?= esc((\App\Libraries\AuthContext::$tenant['code'] ?? '') . ':' . (\App\Libraries\AuthContext::$user->branch_id ?? 1), 'attr') ?>" data-save-url="<?= site_url('finance/receipts/save') ?>" data-order-url="<?= site_url('finance/receipts/orders') ?>" data-details-url="<?= site_url('finance/receipts/details') ?>" data-void-url="<?= site_url('finance/receipts') ?>">
    <?php if ($activeSection === 'receipts'): ?>
    <div id="receiptLists" class="space-y-6">
        <section class="bg-white rounded-2xl border border-slate-200 p-6">
            <div class="flex items-center justify-between gap-4 mb-4">
                <h2 class="font-bold text-slate-900">Receipts</h2>
                <?php if ($canCollect): ?><button type="button" id="addCustomerReceipt" class="px-4 py-2 bg-brand-600 text-white font-semibold rounded-lg text-sm">+ Add receipt</button><?php endif; ?>
            </div>
            <div class="overflow-x-auto">
                <table id="receiptsTable" class="w-full text-left text-xs min-w-[800px]">
                    <thead><tr><th>Receipt</th><th>Date</th><th>Customer</th><th>Orders</th><th>Received</th><th>Discount</th><th>Payment account</th><th>Status</th><th>Action</th></tr></thead>
                    <tbody>
                    <?php foreach ($customerReceipts as $receipt): ?>
                        <tr>
                            <td class="py-3"><?= esc($receipt['receipt_no']) ?></td><td><?= esc($receipt['received_at']) ?></td><td><?= esc($receipt['customer_name']) ?></td><td><?= esc($receipt['invoice_number'] ?? (!empty($receipt['collection_request_hash']) ? 'Multiple / allocated orders' : 'On account')) ?></td>
                            <td><?= number_format((float) $receipt['amount'], 2) ?></td><td><?= number_format((float) $receipt['discount'], 2) ?></td><td><?= esc($receipt['cash_account_name'] ?? str_replace('_', ' ', $receipt['method'])) ?></td><td><?= esc($receipt['status']) ?></td>
                            <td><button type="button" data-print-receipt="<?= (int)$receipt['id'] ?>" class="px-2 py-1 text-brand-600">Print</button><button type="button" data-view-receipt="<?= (int)$receipt['id'] ?>" class="px-2 py-1 text-brand-600" aria-label="View paid orders">View orders</button><?php if ($canCollect && $receipt['status'] === 'active'): ?><button type="button" data-void-receipt="<?= (int) $receipt['id'] ?>" class="px-3 py-2 text-rose-600 rounded-lg border border-rose-200">Void receipt</button><?php elseif ($receipt['status'] === 'cancelled'): ?><span title="<?= esc($receipt['void_reason'] ?? '', 'attr') ?>">Voided</span><?php endif; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
    <?php endif; ?>
    <script type="application/json" id="receiptInvoiceData"><?= json_encode(array_values($invoices), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
    <script type="application/json" id="receiptCustomerData"><?= json_encode(array_values($customers), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
    <dialog id="receiptOrderDetails" class="rounded-2xl p-6 w-full max-w-2xl" aria-label="Order details"><div id="receiptOrderDetailsBody"></div><button type="button" onclick="this.closest('dialog').close()" class="mt-4 px-3 py-2 border rounded-lg">Close</button></dialog>
    <?php if ($canCollect): ?>
    <dialog id="customerReceiptDialog" aria-labelledby="receiptDialogTitle" class="rounded-2xl p-6 w-full max-w-5xl backdrop:bg-slate-900/50">
        <h2 id="receiptDialogTitle" class="text-lg font-bold">Collect customer payment</h2>
        <p id="receiptInvoiceSummary" class="text-sm text-slate-600 my-3" aria-live="polite"></p>
        <form id="customerReceiptForm" class="space-y-4 text-sm">
            <label class="block">Customer<select name="customer_id" required class="block w-full border rounded-lg p-2"><option value="">Choose a customer...</option><?php foreach ($customers as $customer): ?><option value="<?= (int)$customer['id'] ?>"><?= esc($customer['name']) ?></option><?php endforeach; ?></select></label>
            <div class="overflow-x-auto"><table class="w-full text-left text-sm"><thead><tr><th>Select</th><th>Order</th><th>Date</th><th>Total</th><th>Already paid</th><th>Due</th><th>Collected / settled</th><th>Balance</th><th>Action</th></tr></thead><tbody id="receiptDueOrders"></tbody></table></div>
            <label class="block">Amount received<input name="amount" type="number" min="0.01" step="0.01" required class="block w-full border rounded-lg p-2"></label>
            <label class="block">Settlement discount<input name="discount" type="number" min="0" step="0.01" required value="0" class="block w-full border rounded-lg p-2"></label>
            <label class="block">Payment account<select name="account_id" required class="block w-full border rounded-lg p-2"><?= view('web/finance/settlement_account_options', ['settlementAccounts' => $settlementAccounts]) ?></select></label>
            <label class="block">Reference<input name="reference" maxlength="120" class="block w-full border rounded-lg p-2"></label>
            <label class="block">Notes<textarea name="notes" maxlength="255" class="block w-full border rounded-lg p-2"></textarea></label>
            <p id="receiptRemaining" class="font-semibold" aria-live="polite"></p>
            <div class="flex justify-end gap-3"><button type="button" id="closeCustomerReceipt" class="px-3 py-2 border rounded-lg">Cancel</button><button type="submit" id="saveCustomerReceipt" class="px-3 py-2 bg-brand-600 text-white rounded-lg">Save receipt</button></div>
        </form>
    </dialog>
    <?php endif; ?>
</div>
