<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>

<!-- Page Header & Action Bar -->
<div class="mb-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
    <div>
        <div class="flex items-center gap-2">
            <h1 class="text-2xl sm:text-3xl font-bold text-slate-900 tracking-tight">
                Stock Levels & Valuation
            </h1>
            <span class="px-2.5 py-0.5 rounded-full text-xs font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200">
                Live Inventory
            </span>
        </div>
        <p class="text-xs sm:text-sm text-slate-500 mt-1">
            Real-time warehouse stock ledger, valuation at cost, reorder alarms, and GL inventory reconciliation.
        </p>
    </div>

    <div class="flex items-center gap-2.5">
        <a href="<?= site_url('inventory/products') ?>" 
           class="inline-flex items-center gap-1.5 px-3.5 py-2 bg-white hover:bg-slate-50 text-slate-700 border border-slate-200 text-xs font-semibold rounded-lg shadow-sm transition-all">
            <i data-lucide="boxes" class="w-4 h-4 text-slate-500"></i>
            <span>Catalog Items</span>
        </a>
        <a href="<?= site_url('inventory/adjustments') ?>" 
           class="inline-flex items-center gap-1.5 px-3.5 py-2 bg-white hover:bg-slate-50 text-slate-700 border border-slate-200 text-xs font-semibold rounded-lg shadow-sm transition-all">
            <i data-lucide="history" class="w-4 h-4 text-slate-500"></i>
            <span>Movements Ledger</span>
        </a>
        <button type="button" onclick="openAdjustModal()" 
                class="inline-flex items-center gap-1.5 px-4 py-2 bg-brand-600 hover:bg-brand-700 active:scale-95 text-white text-xs font-semibold rounded-lg shadow-sm transition-all">
            <i data-lucide="sliders" class="w-4 h-4"></i>
            <span>Adjust Stock</span>
        </button>
    </div>
</div>

<!-- 5 KPI Summary Cards -->
<div id="stockSummary" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4 mb-6">
    <div class="bg-white p-5 rounded-xl border border-slate-200/80 shadow-sm flex items-center justify-between">
        <div>
            <span class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Total Units</span>
            <div class="text-2xl font-bold text-slate-900 mt-1"><?= number_format($summary['total_units'], 1) ?></div>
            <div class="text-[11px] text-slate-500 mt-0.5">Physical units on hand</div>
        </div>
        <div class="w-11 h-11 bg-slate-50 rounded-xl flex items-center justify-center text-slate-600 border border-slate-100">
            <i data-lucide="layers" class="w-5 h-5"></i>
        </div>
    </div>

    <div class="bg-white p-5 rounded-xl border border-slate-200/80 shadow-sm flex items-center justify-between">
        <div>
            <span class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Stock Valuation</span>
            <div class="text-2xl font-bold text-slate-900 mt-1">USD <?= number_format($summary['stock_value'], 2) ?></div>
            <div class="text-[11px] text-slate-500 mt-0.5">Computed at cost price</div>
        </div>
        <div class="w-11 h-11 bg-indigo-50 rounded-xl flex items-center justify-center text-indigo-600 border border-indigo-100">
            <i data-lucide="dollar-sign" class="w-5 h-5"></i>
        </div>
    </div>

    <?php if ($summary['book_value'] !== null): ?>
        <div class="bg-white p-5 rounded-xl border border-slate-200/80 shadow-sm flex items-center justify-between">
            <div>
                <span class="text-xs font-semibold text-slate-500 uppercase tracking-wider">GL Book Value</span>
                <div class="text-2xl font-bold text-emerald-600 mt-1">USD <?= number_format($summary['book_value'], 2) ?></div>
                <div class="text-[11px] text-slate-500 mt-0.5">Account 1200 Inventory</div>
            </div>
            <div class="w-11 h-11 bg-emerald-50 rounded-xl flex items-center justify-center text-emerald-600 border border-emerald-100">
                <i data-lucide="landmark" class="w-5 h-5"></i>
            </div>
        </div>
    <?php endif; ?>

    <div class="bg-white p-5 rounded-xl border border-slate-200/80 shadow-sm flex items-center justify-between">
        <div>
            <span class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Low Stock</span>
            <div class="text-2xl font-bold text-amber-600 mt-1"><?= number_format($summary['low_stock']) ?></div>
            <div class="text-[11px] text-slate-500 mt-0.5">At or under reorder point</div>
        </div>
        <div class="w-11 h-11 bg-amber-50 rounded-xl flex items-center justify-center text-amber-600 border border-amber-100">
            <i data-lucide="alert-triangle" class="w-5 h-5"></i>
        </div>
    </div>

    <div class="bg-white p-5 rounded-xl border border-slate-200/80 shadow-sm flex items-center justify-between">
        <div>
            <span class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Out of Stock</span>
            <div class="text-2xl font-bold text-rose-600 mt-1"><?= number_format($summary['out_of_stock']) ?></div>
            <div class="text-[11px] text-slate-500 mt-0.5">Stock depleted</div>
        </div>
        <div class="w-11 h-11 bg-rose-50 rounded-xl flex items-center justify-center text-rose-600 border border-rose-100">
            <i data-lucide="package-x" class="w-5 h-5"></i>
        </div>
    </div>
</div>

<!-- Stock Levels Table Card -->
<div class="bg-white rounded-xl border border-slate-200/80 shadow-sm overflow-hidden">
    <div class="p-4 sm:p-5 border-b border-slate-100 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
        <div>
            <h2 class="text-sm font-bold text-slate-900">Inventory Status by SKU</h2>
            <p class="text-xs text-slate-500">Live quantity on hand, reorder thresholds, and unit valuation</p>
        </div>
    </div>

    <div class="p-4 sm:p-6 overflow-x-auto">
        <table id="stockTable" class="w-full min-w-[1150px] text-left text-xs text-slate-700">
            <thead><tr class="border-b text-slate-500 whitespace-nowrap">
            <?php foreach (['Product','Category','Main stock','Sealed','Opened','Total equivalent','Cost / base unit','Reorder at','Valuation','Status','Actions'] as $heading): ?><th class="px-3 py-3"><?= esc($heading) ?></th><?php endforeach; ?>
            </tr></thead><tbody class="divide-y divide-slate-100">
            <?php foreach ($products as $p):
                $split = !empty($p['package_unit']) ? (new \App\Services\PackageInventoryService())->balance((int)($user['branch_id'] ?? 1),$p) : null;
                $qty = (float)$p['quantity']; $reorder = (float)$p['reorder_level']; $cost = (float)$p['cost_price'];
                $low = ($split ? $split['sealed'] : $qty) <= $reorder;
                $fmt = static fn($n) => rtrim(rtrim(number_format((float)$n,3),'0'),'.');
            ?>
            <tr class="hover:bg-slate-50">
                <td class="p-3"><div class="font-semibold text-slate-900"><?= esc($p['name']) ?></div><div class="text-[10px] text-slate-400"><?= esc($p['sku']) ?></div></td>
                <td class="p-3"><?= esc($p['category_name'] ?? '') ?></td>
                <td class="p-3 whitespace-nowrap"><?= !$split ? $fmt($qty).' '.esc($p['unit']) : '' ?></td>
                <td class="p-3 whitespace-nowrap font-semibold"><?= $split ? $split['sealed'].' '.esc($p['package_unit']) : '' ?></td>
                <td class="p-3 whitespace-nowrap"><?= $split ? $fmt($split['opened']).' '.esc($p['unit']) : '' ?></td>
                <td class="p-3 whitespace-nowrap"><?= $split ? $fmt($qty).' '.esc($p['unit']) : '' ?></td>
                <td class="p-3 whitespace-nowrap">USD <?= number_format($cost,2) ?></td>
                <td class="p-3 whitespace-nowrap"><?= $fmt($reorder).' '.esc($split ? $p['package_unit'] : $p['unit']) ?></td>
                <td class="p-3 whitespace-nowrap font-semibold">USD <?= number_format($qty*$cost,2) ?></td>
                <td class="p-3 whitespace-nowrap"><span class="<?= $low ? 'text-amber-700' : 'text-emerald-700' ?>"><?= $qty <= 0 ? 'Depleted' : ($low ? 'Low stock' : 'Healthy') ?></span><?php if ($split && $split['opened'] <= 0): ?><span class="block text-amber-700 text-[10px]">Opened stock empty</span><?php endif; ?></td>
                <td class="p-3 whitespace-nowrap"><?php if (\App\Libraries\AuthContext::can('stock.adjust')): ?><?php if ($split): ?><button type="button" data-open-package="<?= (int)$p['id'] ?>" class="px-2 py-2 font-semibold text-brand-600">Open <?= esc($p['package_unit']) ?></button><?php endif; ?><button type="button" onclick="openAdjustModal(<?= (int)$p['id'] ?>)" class="px-2 py-2 font-semibold">Adjust</button><?php endif; ?></td>
            </tr><?php endforeach; ?></tbody>
        </table>
    </div>
</div>

<div class="mt-6 p-5 bg-white border rounded-xl">
    <h2 class="text-sm font-bold mb-3">Package opening history (latest 50)</h2>
    <div class="overflow-x-auto"><table class="w-full text-left text-xs"><thead><tr><th class="p-2">Time</th><th class="p-2">Product</th><th class="p-2">Opened</th><th class="p-2">Added to loose stock</th><th class="p-2">By</th></tr></thead><tbody id="packageHistory">
    <?php foreach ($packageHistory as $opening): ?><tr><td class="p-2"><?= esc($opening['created_at']) ?></td><td class="p-2"><?= esc($opening['name']) ?></td><td class="p-2"><?= abs((int)$opening['package_change']) ?> <?= esc($opening['package_unit']) ?></td><td class="p-2"><?= abs((int)$opening['package_change']) * (float)$opening['package_size'] ?> <?= esc($opening['unit']) ?></td><td class="p-2"><?= esc($opening['opened_by'] ?? '—') ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
</div>
<!-- Modal: Quick Adjust Stock -->
<?= view('web/inventory/stock_adjustment_form', ['products' => $products]) ?>

<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
$(function () { erp.initDataTable('#stockTable', { responsive: false, order: [[0, 'asc']], pageLength: 25 }); });
document.addEventListener('click', async event => {
    const button = event.target.closest('[data-open-package]');
    if (!button || button.disabled) return;
    const result = await Swal.fire({title: 'Open sealed packages', text: 'Enter the whole number to open. This transfers their contents to opened stock.', input: 'number', inputValue: 1, inputAttributes: {min: '1', step: '1'}, showCancelButton: true, confirmButtonText: 'Open packages', returnFocus: true,
        inputValidator: value => !Number.isSafeInteger(Number(value)) || Number(value) < 1 ? 'Enter a positive whole number.' : undefined});
    if (!result.isConfirmed) return;
    button.disabled = true;
    // Keep this key on a network failure so retrying cannot open the same packages twice.
    const quantity = Number(result.value);
    if (button.dataset.openQuantity !== String(quantity)) { button.dataset.openKey = crypto.randomUUID(); button.dataset.openQuantity = String(quantity); }
    try {
        const response = await erp.web(<?= json_encode(site_url('inventory/stock/open-package')) ?>, {method:'POST', body:JSON.stringify({product_id:Number(button.dataset.openPackage), quantity, request_key:button.dataset.openKey})});
        delete button.dataset.openKey; delete button.dataset.openQuantity;
        erp.toast(`Opened stock: ${response.data.opened} ${response.data.unit}. Sealed: ${response.data.sealed}.`);
        const refresh = await fetch(location.href, {credentials:'same-origin'});
        if (!refresh.ok) throw new Error('Stock saved. Refresh the page to see the latest balances.');
        const page = new DOMParser().parseFromString(await refresh.text(), 'text/html');
        if (!page.getElementById('stockTable')) throw new Error('Stock saved. Refresh the page to see the latest balances.');
        $('#stockTable').DataTable().destroy();
        document.getElementById('stockTable').replaceWith(page.getElementById('stockTable'));
        document.getElementById('packageHistory').replaceChildren(...page.getElementById('packageHistory').childNodes);
        erp.initDataTable('#stockTable', {responsive:false,order:[[0,'asc']],pageLength:25}); lucide.createIcons();
    } catch (error) { if (!error.reported) erp.toast(error.message || 'Unable to open packages.', 'error'); }
    finally { button.disabled = false; }
});
</script>
<script src="<?= base_url('assets/js/stock-adjustment.js').'?v='.filemtime(FCPATH.'assets/js/stock-adjustment.js') ?>"></script>
<?= $this->endSection() ?>
