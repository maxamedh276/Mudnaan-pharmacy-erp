<?php $canManagePaymentMethods = \App\Libraries\AuthContext::can('accounting.manage'); ?>
<section id="paymentMethodsWorkspace" data-base-url="<?= site_url('finance/pos-methods') ?>" data-can-manage="<?= $canManagePaymentMethods ? 'true' : 'false' ?>" class="bg-white rounded-2xl border border-slate-200 p-5 sm:p-8">
    <div class="flex flex-wrap justify-between items-start gap-4 mb-6">
        <div><h2 class="text-base font-bold text-slate-900">Payment methods</h2><p class="text-xs text-slate-500 mt-1">Choose how customers pay and which ledger account receives the money.</p></div>
        <?php if ($canManagePaymentMethods): ?><button type="button" id="addPaymentMethod" class="px-4 py-2 bg-brand-600 hover:bg-brand-700 text-white text-xs font-semibold rounded-lg">+ Add payment method</button><?php endif; ?>
    </div>
    <label for="paymentMethodSearch" class="sr-only">Search payment methods</label>
    <div class="relative mb-3"><i data-lucide="search" class="absolute left-3 top-3 w-4 h-4 text-slate-400 pointer-events-none"></i><input id="paymentMethodSearch" type="search" placeholder="Search payment methods, accounts or payment types…" class="w-full pl-10 pr-4 py-3 text-sm border border-slate-200 rounded-xl focus:border-brand-600 focus:ring-2 focus:ring-brand-100 outline-none"></div>
    <p id="paymentOrderHint" class="text-xs text-slate-400 mb-4" aria-live="polite"><?= $canManagePaymentMethods ? 'Drag the handle to set the POS order. You can also focus a handle and press Arrow Up or Arrow Down.' : 'Payment methods appear in POS order.' ?></p>
    <div class="overflow-x-auto"><table id="posMethodsTable" class="w-full min-w-[680px] text-left text-xs">
        <thead class="bg-slate-50 border-y border-slate-100 text-slate-500"><tr><th class="px-3 py-3 w-20">Order</th><th class="px-3 py-3">Display label</th><th class="px-3 py-3">Payment type</th><th class="px-3 py-3">Ledger account</th><th class="px-3 py-3">Status</th><th class="px-3 py-3 text-right">Actions</th></tr></thead>
        <tbody id="paymentMethodRows" class="divide-y divide-slate-100"></tbody>
    </table></div>
    <p id="paymentMethodCount" class="text-xs text-slate-400 mt-4" role="status" aria-live="polite"></p>
    <script type="application/json" id="paymentMethodsInitialData"><?= json_encode($posPaymentMethods, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) ?></script>
</section>
<dialog id="paymentMethodDialog" aria-labelledby="paymentMethodDialogTitle" class="w-11/12 max-w-md max-h-[90vh] overflow-y-auto rounded-2xl p-0 shadow-xl backdrop:bg-slate-900/40">
    <form id="paymentMethodForm" class="p-6 space-y-5">
        <div class="flex items-center justify-between gap-4"><h2 id="paymentMethodDialogTitle" class="font-bold text-slate-900">Add payment method</h2><button type="button" data-close-payment-dialog aria-label="Close payment method form" class="p-2 text-slate-400 hover:text-slate-700"><i data-lucide="x" class="w-4 h-4"></i></button></div>
        <input type="hidden" id="paymentMethodId" name="id">
        <label class="block text-xs font-semibold text-slate-600">Display label<input id="paymentMethodLabel" name="name" required maxlength="50" placeholder="e.g. EVC Plus" class="block mt-2 w-full border border-slate-300 rounded-lg p-2.5 font-normal"></label>
        <label class="block text-xs font-semibold text-slate-600">Ledger account<select id="paymentMethodAccount" name="account_id" required class="block mt-2 w-full border border-slate-300 rounded-lg p-2.5 font-normal"><option value="">Select an asset account</option><?php foreach ($cashAndBankAccounts as $account): ?><option value="<?= (int)$account['id'] ?>"><?= esc($account['code'] . ' · ' . $account['name']) ?></option><?php endforeach; ?></select></label>
        <label class="block text-xs font-semibold text-slate-600">Payment type<select id="paymentMethodType" name="type" required class="block mt-2 w-full border border-slate-300 rounded-lg p-2.5 font-normal"><option value="mobile_money">Mobile money</option><option value="cash">Cash</option><option value="bank">Bank / card</option></select></label>
        <p class="text-xs text-slate-400">New methods are enabled automatically. Change their status from the list whenever needed.</p>
        <div class="flex justify-end gap-2 border-t border-slate-100 pt-4"><button type="button" data-close-payment-dialog class="px-4 py-2 border border-slate-200 rounded-lg text-xs">Cancel</button><button id="savePaymentMethod" type="submit" class="px-4 py-2 bg-brand-600 hover:bg-brand-700 text-white rounded-lg text-xs font-semibold disabled:opacity-50">Save method</button></div>
    </form>
</dialog>
