<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>
<div class="flex flex-wrap justify-between items-center gap-4 mb-6"><div><h1 class="text-2xl font-bold">Customer Deposits</h1><p class="text-sm text-slate-500 mt-2">Customer advances and remaining store credit.</p></div><?php if ($canManage): ?><button id="addDeposit" class="px-4 py-2 bg-brand-600 text-white font-semibold rounded-lg text-sm">+ Add deposit</button><?php endif; ?></div>
<div class="bg-white border rounded-xl p-5 sm:p-6">
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-4">
        <label class="text-xs font-semibold">Customer<select id="depositCustomerFilter" class="block mt-2 w-full border p-2.5 rounded-lg"><option value="">All customers</option><?php foreach ($customers as $customer): ?><option value="<?= (int) $customer['id'] ?>"><?= esc($customer['name']) ?></option><?php endforeach; ?></select></label>
        <label class="text-xs font-semibold">Status<select id="depositStatusFilter" class="block mt-2 w-full border p-2.5 rounded-lg"><option value="">All statuses</option><option value="active">Active</option><option value="used">Used</option><option value="refunded">Refunded</option></select></label>
    </div>
    <div class="overflow-x-auto"><table id="depositsTable" class="min-w-[900px] w-full text-left text-xs"><thead><tr class="bg-slate-50"><th class="p-3">Deposit #</th><th class="p-3">Customer</th><th class="p-3">Date</th><th class="p-3">Method</th><th class="p-3">Amount (USD)</th><th class="p-3">Used (USD)</th><th class="p-3">Remaining (USD)</th><th class="p-3">Status</th></tr></thead><tbody>
    <?php foreach ($deposits as $deposit): ?><tr data-customer="<?= (int) $deposit['customer_id'] ?>" data-status="<?= esc($deposit['status'], 'attr') ?>">
        <td class="p-3 font-semibold"><?= esc($deposit['deposit_no']) ?></td><td class="p-3"><a class="text-brand-600 hover:underline" href="<?= site_url('clients/' . (int) $deposit['customer_id'] . '/statement') ?>"><?= esc($deposit['customer_name']) ?></a></td><td class="p-3"><?= esc($deposit['deposited_at']) ?></td><td class="p-3"><?= esc(str_replace('_', ' ', $deposit['method'])) ?></td>
        <td class="p-3"><?= number_format($deposit['amount'], 2) ?></td><td class="p-3"><?= number_format($deposit['used_amount'], 2) ?></td><td class="p-3"><?= number_format($deposit['status'] === 'refunded' ? 0 : $deposit['available'], 2) ?></td><td class="p-3"><?= esc(ucfirst($deposit['status'])) ?></td>
    </tr><?php endforeach; ?>
    </tbody></table></div>
</div>
<?php if ($canManage): ?>
<div id="depositDialog" role="dialog" aria-modal="true" aria-labelledby="depositTitle" class="hidden fixed inset-0 z-50 bg-slate-900/40 backdrop-blur-sm flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl w-full max-w-lg max-h-[90vh] overflow-auto p-6">
        <div class="flex items-center justify-between mb-5"><h2 id="depositTitle" class="text-lg font-bold">Add customer deposit</h2><button type="button" data-close-deposit aria-label="Close deposit form" class="p-2">×</button></div>
        <form id="depositForm" class="space-y-4">
            <label class="block text-xs font-semibold">Customer<select name="customer_id" required class="block mt-2 w-full border p-2.5 rounded-lg"><option value="">Select customer</option><?php foreach ($customers as $customer): ?><option value="<?= (int) $customer['id'] ?>"><?= esc($customer['name']) ?></option><?php endforeach; ?></select></label>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4"><label class="text-xs font-semibold">Amount (USD)<input name="amount" type="number" required min="0.01" max="9999999999.99" step="0.01" class="block mt-2 w-full border p-2.5 rounded-lg"></label><label class="text-xs font-semibold">Payment method<select name="method" class="block mt-2 w-full border p-2.5 rounded-lg"><?php foreach (['cash','card','mobile_money','bank','cheque'] as $method): ?><option value="<?= $method ?>"><?= ucfirst(str_replace('_',' ', $method)) ?></option><?php endforeach; ?></select></label></div>
            <label id="depositBankField" class="hidden block text-xs font-semibold">Bank account<select name="bank_account_id" class="block mt-2 w-full border p-2.5 rounded-lg"><option value="">Select bank account</option><?php foreach ($bankAccounts as $account): ?><option value="<?= (int) $account['id'] ?>"><?= esc($account['name']) ?></option><?php endforeach; ?></select></label>
            <label class="block text-xs font-semibold">Reference (optional)<input name="reference" maxlength="120" class="block mt-2 w-full border p-2.5 rounded-lg"></label>
            <label class="block text-xs font-semibold">Notes (optional)<textarea name="notes" rows="2" maxlength="1000" class="block mt-2 w-full border p-2.5 rounded-lg"></textarea></label>
            <div class="flex justify-end gap-3 pt-4 border-t"><button type="button" data-close-deposit class="border rounded-lg px-4 py-2 text-sm">Cancel</button><button id="saveDeposit" class="bg-brand-600 text-white rounded-lg px-4 py-2 text-sm font-semibold">Save deposit</button></div>
        </form>
    </div>
</div>
<?php endif; ?>
<?= $this->endSection() ?>
<?= $this->section('scripts') ?>
<style>
#depositsTable_wrapper .dataTables_filter { float:none; width:100%; margin:1rem 0; text-align:left; }
#depositsTable_wrapper .dataTables_filter label { display:block; width:100%; }
#depositsTable_wrapper .dataTables_filter input { box-sizing:border-box; width:100%; margin:0; }
</style>
<script src="<?= base_url('assets/js/customer-deposits.js') ?>"></script>
<?= $this->endSection() ?>
