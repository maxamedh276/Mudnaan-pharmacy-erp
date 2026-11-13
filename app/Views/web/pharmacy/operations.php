<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>
<?php
use App\Libraries\AuthContext;
$t = static fn (string $key): string => esc(lang('Pharmacy.' . $key));
if (!session()->get('pharmacy_csrf')) session()->set('pharmacy_csrf', bin2hex(random_bytes(32)));
$today = date('Y-m-d');
$soon = date('Y-m-d', strtotime('+90 days'));
$counts = ['medicines' => count($medicines), 'expired' => 0, 'nearExpiry' => 0, 'quarantine' => 0, 'lowStock' => 0];
foreach ($medicines as $medicine) if ((float)($medicine['available_quantity'] ?? 0) <= (float)$medicine['reorder_level']) $counts['lowStock']++;
foreach ($batches as $batch) {
    if ((float)$batch['quantity'] <= 0) continue;
    if ($batch['expiry_date'] && $batch['expiry_date'] < $today) $counts['expired']++;
    elseif ($batch['expiry_date'] && $batch['expiry_date'] <= $soon) $counts['nearExpiry']++;
    if ($batch['status'] !== 'active') $counts['quarantine']++;
}
?>
<section id="pharmacyOperations" data-base-url="<?= site_url('pharmacy') ?>" data-token="<?= esc(session()->get('pharmacy_csrf'), 'attr') ?>" data-section="<?= esc($section, 'attr') ?>">
<header class="flex flex-wrap justify-between gap-4 mb-6"><div><p class="text-xs uppercase tracking-widest text-emerald-600 font-bold">Mudnaan Pharmacy</p><h1 class="text-2xl font-bold mt-1"><?= $t($section) ?></h1></div><div class="flex gap-2"><a class="border rounded-lg px-3 py-2 bg-white" href="<?= site_url('pharmacy/' . $section) ?>?language=en">English</a><a class="border rounded-lg px-3 py-2 bg-white" href="<?= site_url('pharmacy/' . $section) ?>?language=so">Soomaali</a></div></header>
<?= $this->include('web/pharmacy/navigation') ?>
<?php if ($section === 'reports'): ?><a href="<?= site_url('pharmacy/export') ?>" class="inline-block mb-5 bg-white border rounded-lg px-4 py-2">CSV export</a><?php endif; ?>
<?php if (!$ready): ?><p role="status" class="bg-amber-50 border border-amber-200 rounded-xl p-6"><?= $t('setupRequired') ?></p><?php else: ?>
<script type="application/json" id="pharmacyMedicineData"><?= json_encode($medicines, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<?php if ($section === 'overview' || $section === 'reports'): ?>
<div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6"><?php foreach ($counts as $key => $value): ?><div class="rounded-xl bg-white border p-5"><p class="text-sm text-slate-500"><?= $t($key) ?></p><p class="text-3xl font-bold mt-2 <?= $key === 'expired' ? 'text-red-600' : 'text-emerald-700' ?>"><?= $value ?></p></div><?php endforeach; ?></div>
<div class="flex flex-wrap gap-3 mb-6"><a class="bg-emerald-600 text-white rounded-lg px-4 py-3" href="<?= site_url('pharmacy/dispense') ?>"><?= $t('dispense') ?></a><a class="bg-white border rounded-lg px-4 py-3" href="<?= site_url('pharmacy/medicines') ?>"><?= $t('medicines') ?></a><a class="bg-white border rounded-lg px-4 py-3" href="<?= site_url('inventory/categories') ?>"><?= $t('categories') ?></a><a class="bg-white border rounded-lg px-4 py-3" href="<?= site_url('suppliers/purchases') ?>"><?= $t('purchases') ?></a><a class="bg-white border rounded-lg px-4 py-3" href="<?= site_url('reports') ?>"><?= $t('financialReports') ?></a></div>
<?php endif; ?>
<?php if ($section === 'stock' && AuthContext::can('pharmacy.stock.manage')): ?>
<div class="grid lg:grid-cols-2 gap-5 mb-6">
<form data-operation="stock" class="bg-white border rounded-xl p-5 space-y-4"><h2 class="font-bold text-lg"><?= $t('stockEntry') ?></h2><p class="text-sm text-slate-500"><?= $t('stockHint') ?></p>
<label class="block text-sm"><?= $t('operation') ?><select name="action" class="block border rounded-lg p-2 w-full mt-1"><option value="receive"><?= $t('receive') ?></option><option value="allocate_existing"><?= $t('allocateExisting') ?></option><option value="dispose"><?= $t('dispose') ?></option></select></label>
<div class="grid sm:grid-cols-2 gap-3"><label class="text-sm"><?= $t('medicines') ?><select name="product_id" data-medicine required class="block border rounded-lg p-2 w-full mt-1"><option value=""><?= $t('select') ?></option><?php foreach ($medicines as $medicine): ?><option value="<?= (int)$medicine['product_id'] ?>"><?= esc($medicine['name']) ?></option><?php endforeach; ?></select></label><label class="text-sm"><?= $t('sellingUnit') ?><select name="medicine_unit_id" data-unit required class="block border rounded-lg p-2 w-full mt-1"></select></label>
<?php foreach (['quantity' => 'number', 'unit_cost' => 'number', 'batch_no' => 'text', 'expiry_date' => 'date', 'mfg_date' => 'date'] as $field => $type): ?><label class="text-sm"><?= $t($field) ?><input type="<?= $type ?>" name="<?= $field ?>" <?= $type === 'number' ? 'step="0.001" min="0"' : '' ?> <?= $field === 'quantity' ? 'required' : '' ?> maxlength="80" class="block border rounded-lg p-2 w-full mt-1"></label><?php endforeach; ?>
<label class="text-sm"><?= $t('batch_id') ?><select name="batch_id" class="block border rounded-lg p-2 w-full mt-1"><option value=""><?= $t('select') ?></option><?php foreach ($batches as $batch): ?><option value="<?= (int)$batch['id'] ?>"><?= esc($batch['name'] . ' · ' . $batch['batch_no']) ?></option><?php endforeach; ?></select></label></div>
<label class="block text-sm"><?= $t('reason') ?><textarea name="reason" required maxlength="500" class="block border rounded-lg p-2 w-full mt-1"></textarea></label><p data-feedback role="status" class="text-sm text-red-700"></p><button class="bg-emerald-600 text-white px-4 py-2 rounded-lg"><?= $t('save') ?></button></form>
<form data-operation="batch-status" class="bg-white border rounded-xl p-5 space-y-4 self-start"><h2 class="font-bold text-lg"><?= $t('batchStatus') ?></h2><label class="block text-sm"><?= $t('batch_id') ?><select name="batch_id" required class="block border rounded-lg p-2 w-full mt-1"><option value=""><?= $t('select') ?></option><?php foreach ($batches as $batch): ?><option value="<?= (int)$batch['id'] ?>"><?= esc($batch['name'] . ' · ' . $batch['batch_no']) ?></option><?php endforeach; ?></select></label><label class="block text-sm"><?= $t('status') ?><select name="status" class="block border rounded-lg p-2 w-full mt-1"><?php foreach (['quarantine','recalled','active'] as $status): ?><option value="<?= $status ?>"><?= $t($status) ?></option><?php endforeach; ?></select></label><label class="block text-sm"><?= $t('reason') ?><textarea name="reason" required maxlength="500" class="block border rounded-lg p-2 w-full mt-1"></textarea></label><p data-feedback role="status" class="text-sm text-red-700"></p><button class="bg-slate-800 text-white px-4 py-2 rounded-lg"><?= $t('save') ?></button></form>
</div>
<?php endif; ?>
<?php if ($section === 'dispense'): ?>
<?= $this->include('web/pharmacy/pos') ?>
<?php else: ?>
<div class="bg-white rounded-xl border p-5"><h2 class="font-bold text-lg mb-4"><?= $t('batchLedger') ?></h2><div class="overflow-x-auto"><table class="w-full text-left text-sm"><thead class="text-slate-500 bg-slate-50"><tr><?php foreach (['medicines','batch_no','expiry_date','quantity','status'] as $field): ?><th class="p-3"><?= $t($field) ?></th><?php endforeach; ?></tr></thead><tbody class="divide-y"><?php foreach ($batches as $batch): ?><tr class="<?= $batch['expiry_date'] && $batch['expiry_date'] < $today ? 'bg-red-50' : '' ?>"><td class="p-3"><?= esc($batch['name']) ?></td><td class="p-3"><?= esc($batch['batch_no']) ?><?php if(AuthContext::can('sales.view')): ?><button type="button" data-batch-trace="<?= (int)$batch['id'] ?>" class="block underline text-emerald-700 text-xs mt-1">Trace distribution</button><?php endif; ?></td><td class="p-3"><?= esc($batch['expiry_date'] ?? '—') ?></td><td class="p-3"><?= esc($batch['quantity'] . ' ' . $batch['unit']) ?></td><td class="p-3"><?= $t($batch['status']) ?></td></tr><?php endforeach; ?><?php if (!$batches): ?><tr><td colspan="5" class="p-6 text-center text-slate-400"><?= $t('empty') ?></td></tr><?php endif; ?></tbody></table></div></div>
<?php endif; ?>
<?php if ($section === 'reports'): ?>
<div class="bg-white rounded-xl border p-5 mt-6"><h2 class="font-bold text-lg mb-4"><?= $t('availability') ?></h2><div class="overflow-x-auto"><table class="w-full text-left text-sm"><thead class="bg-slate-50 text-slate-500"><tr><?php foreach (['medicines','sellable','physicalStock','reorder_level'] as $field): ?><th class="p-3"><?= $t($field) ?></th><?php endforeach; ?></tr></thead><tbody class="divide-y"><?php foreach ($medicines as $medicine): ?><tr class="<?= $medicine['available_quantity'] <= $medicine['reorder_level'] ? 'bg-amber-50' : '' ?>"><td class="p-3"><?= esc($medicine['name']) ?></td><td class="p-3"><?= esc($medicine['available_quantity'].' '.$medicine['unit']) ?></td><td class="p-3"><?= esc($medicine['physical_quantity'].' '.$medicine['unit']) ?></td><td class="p-3"><?= esc($medicine['reorder_level']) ?></td></tr><?php endforeach; ?></tbody></table></div></div>
<?php endif; endif; ?>
<?php if (AuthContext::can('sales.view')): ?><?= $this->include('web/pharmacy/batch_trace') ?><?php endif; ?>
</section>
<?= $this->endSection() ?>
<?= $this->section('scripts') ?><script src="<?= base_url('assets/js/pharmacy-operations.js').'?v='.filemtime(FCPATH.'assets/js/pharmacy-operations.js') ?>"></script><script src="<?= base_url('assets/js/pharmacy-batch-trace.js').'?v='.filemtime(FCPATH.'assets/js/pharmacy-batch-trace.js') ?>"></script><script src="<?= base_url('assets/js/pharmacy-party-search.js').'?v='.filemtime(FCPATH.'assets/js/pharmacy-party-search.js') ?>"></script><?= $this->endSection() ?>
