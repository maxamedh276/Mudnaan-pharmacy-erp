<?php $canManageAccounts = \App\Libraries\AuthContext::can('accounting.manage'); ?>
<?php foreach ($accountCategories as $categoryIndex => $accountCategory): ?>
<details class="account-category group bg-white border border-slate-200 rounded-xl overflow-hidden" data-category-key="<?= (int)$accountCategory['group']['id'] ?>-<?= (int)($accountCategory['root']['id'] ?? 0) ?>" <?= $categoryIndex === 0 ? 'open' : '' ?>>
    <summary class="list-none cursor-pointer flex items-center gap-3 px-5 py-4 hover:bg-slate-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand-600 [&::-webkit-details-marker]:hidden">
        <i data-lucide="chevron-right" class="w-4 h-4 shrink-0 text-slate-400 transition-transform group-open:rotate-90"></i>
        <span class="flex-1 min-w-0"><span class="text-sm font-semibold text-slate-900"><?= esc($accountCategory['label']) ?></span><span class="block mt-1 text-xs text-slate-400"><?= esc($accountCategory['group']['name']) ?></span></span>
        <span class="px-2 py-1 rounded-full bg-slate-100 text-xs text-slate-500"><?= count($accountCategory['rows']) ?> <?= count($accountCategory['rows']) === 1 ? 'account' : 'accounts' ?></span>
    </summary>
    <div class="border-t border-slate-100">
        <?php if ($canManageAccounts): ?>
        <div class="flex justify-end px-5 py-3"><button type="button" data-add-account data-group="<?= (int)$accountCategory['group']['id'] ?>" data-parent="<?= (int)($accountCategory['root']['id'] ?? 0) ?>" data-label="<?= esc($accountCategory['label'], 'attr') ?>" class="px-3 py-2 rounded-lg text-xs font-semibold text-brand-700 bg-brand-50 hover:bg-brand-100 focus-visible:ring-2 focus-visible:ring-brand-600">+ Add account</button></div>
        <?php endif; ?>
        <div class="overflow-x-auto"><table class="w-full min-w-[480px] text-xs text-left">
            <thead class="bg-slate-50 text-slate-500"><tr><th class="px-5 py-3">Code</th><th class="px-5 py-3">Account name</th><th class="px-5 py-3">Normal balance</th><th class="px-5 py-3 text-right">Balance (USD)</th></tr></thead>
            <tbody class="divide-y divide-slate-100">
            <?php foreach ($accountCategory['rows'] as $account): ?>
                <tr><td class="px-5 py-3 font-mono text-slate-500"><?= esc($account['code']) ?></td><td class="px-5 py-3 font-semibold text-slate-800"><?= esc($account['name']) ?><?php if (!$account['is_system']): ?><span class="ml-2 text-[10px] font-normal text-slate-400">Custom</span><?php endif; ?></td><td class="px-5 py-3 capitalize text-slate-500"><?= esc($account['normal_balance']) ?></td><td class="px-5 py-3 text-right font-semibold tabular-nums"><?= number_format($account['balance'], 2) ?></td></tr>
            <?php endforeach; ?>
            <?php if (!$accountCategory['rows']): ?><tr><td colspan="4" class="px-5 py-8 text-center text-slate-400">No accounts yet. Add your first account in this type.</td></tr><?php endif; ?>
            </tbody>
        </table></div>
    </div>
</details>
<?php endforeach; ?>
<?php if (!$accountCategories): ?><p class="p-6 bg-white border rounded-xl text-sm text-slate-500">No account types are configured in this group.</p><?php endif; ?>
