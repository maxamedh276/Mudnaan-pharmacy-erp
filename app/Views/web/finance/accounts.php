<section id="chartOfAccounts" data-group-url="<?= site_url('finance/accounts/group') ?>" data-create-url="<?= site_url('finance/accounts/create') ?>" data-selected-group="<?= esc($selectedAccountType, 'attr') ?>">
    <p class="text-sm text-slate-500 mb-6">Browse account groups and expand an account type to manage its ledger accounts.</p>
    <div role="tablist" aria-label="Account groups" class="flex gap-2 overflow-x-auto border-b border-slate-200 mb-5">
        <?php foreach ($accountTypes as $accountType): $isSelected = $accountType['code'] === $selectedAccountType; ?>
        <button type="button" role="tab" id="account-tab-<?= esc($accountType['code'], 'attr') ?>" data-account-group="<?= esc($accountType['code'], 'attr') ?>" aria-selected="<?= $isSelected ? 'true' : 'false' ?>" aria-controls="account-panel-<?= esc($accountType['code'], 'attr') ?>" tabindex="<?= $isSelected ? '0' : '-1' ?>" class="account-group-tab shrink-0 px-4 py-3 text-sm font-semibold border-b-2 transition-colors focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand-600 <?= $isSelected ? 'border-brand-600 text-brand-600' : 'border-transparent text-slate-500 hover:text-slate-900' ?>"><?= esc($accountType['name']) ?></button>
        <?php endforeach; ?>
    </div>
    <p id="accountGroupStatus" class="text-xs text-slate-500 mb-3" role="status" aria-live="polite"></p>
    <?php foreach ($accountTypes as $accountType): ?>
    <div id="account-panel-<?= esc($accountType['code'], 'attr') ?>" data-account-panel="<?= esc($accountType['code'], 'attr') ?>" role="tabpanel" aria-labelledby="account-tab-<?= esc($accountType['code'], 'attr') ?>" class="space-y-3" <?= $accountType['code'] !== $selectedAccountType ? 'hidden' : '' ?>>
        <?= view('web/finance/account_categories', ['accountCategories' => $accountCategoriesByType[$accountType['code']]], ['saveData' => false]) ?>
    </div>
    <?php endforeach; ?>
</section>
<dialog id="accountDialog" class="w-11/12 max-w-md rounded-2xl p-0 shadow-xl backdrop:bg-slate-900/40">
<form id="accountForm" class="p-6 space-y-4">
<div class="flex justify-between items-center"><h2 id="accountDialogTitle" class="font-bold text-slate-900">Add account</h2><button type="button" onclick="document.getElementById('accountDialog').close()" aria-label="Close" class="p-2 text-slate-500">✕</button></div>
<input type="hidden" id="accountGroup"><input type="hidden" id="accountParent">
<label class="block text-xs font-semibold text-slate-600">Account name<input id="accountName" required maxlength="150" class="block mt-2 w-full border border-slate-300 rounded-lg p-2.5" placeholder="e.g. Petty Cash"></label>
<label class="block text-xs font-semibold text-slate-600">Description <span class="font-normal">(optional)</span><textarea id="accountDescription" maxlength="255" class="block mt-2 w-full border border-slate-300 rounded-lg p-2.5" rows="2"></textarea></label>

<div class="flex justify-end gap-2"><button type="button" onclick="document.getElementById('accountDialog').close()" class="px-4 py-2 text-xs rounded-lg border border-slate-200">Cancel</button><button id="accountSave" class="px-4 py-2 text-xs rounded-lg bg-brand-600 text-white disabled:opacity-50">Save account</button></div>
</form>
</dialog>

<script defer src="<?= base_url('assets/js/finance-accounts.js') ?>"></script>
