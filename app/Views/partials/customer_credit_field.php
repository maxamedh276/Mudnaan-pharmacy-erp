<div>
    <label for="<?= esc($creditFieldId, 'attr') ?>" class="block text-xs font-semibold text-slate-700 mb-1">Customer credit limit (USD)</label>
    <?php if ($canManageCredit): ?>
        <input id="<?= esc($creditFieldId, 'attr') ?>" name="credit_limit" type="number" min="0" max="9999999999.99" step="0.01" value="0.00" required
               class="w-full px-3 py-2 text-xs border border-slate-300 rounded-lg focus:outline-none focus:border-brand-600" aria-describedby="<?= esc($creditFieldId, 'attr') ?>-help">
    <?php else: ?>
        <output id="<?= esc($creditFieldId, 'attr') ?>" class="block text-sm font-semibold text-slate-700">0.00</output>
    <?php endif; ?>
    <p id="<?= esc($creditFieldId, 'attr') ?>-help" class="text-[11px] text-slate-500 mt-1">$0 requires full payment. <?= $canManageCredit ? 'Applies to unpaid invoices across all branches.' : 'Only an Admin or Superadmin can change this limit.' ?></p>
</div>
