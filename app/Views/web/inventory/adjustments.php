<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>

<!-- Page Header & Action Bar -->
<div class="mb-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
    <div>
        <div class="flex items-center gap-2">
            <h1 class="text-2xl sm:text-3xl font-bold text-slate-900 tracking-tight">
                Stock Movements & Audit Ledger
            </h1>
            <span class="px-2.5 py-0.5 rounded-full text-xs font-semibold bg-indigo-50 text-indigo-700 border border-indigo-200">
                Audit Trail
            </span>
        </div>
        <p class="text-xs sm:text-sm text-slate-500 mt-1">
            Complete immutable ledger of every inventory change: sales, inbound shipments, physical recounts, and write-offs.
        </p>
    </div>

    <div class="flex items-center gap-2.5">
        <a href="<?= site_url('inventory/stock') ?>" 
           class="inline-flex items-center gap-1.5 px-3.5 py-2 bg-white hover:bg-slate-50 text-slate-700 border border-slate-200 text-xs font-semibold rounded-lg shadow-sm transition-all">
            <i data-lucide="layers" class="w-4 h-4 text-slate-500"></i>
            <span>Current Stock</span>
        </a>
        <button type="button" onclick="openAdjustModal()" 
                class="inline-flex items-center gap-1.5 px-4 py-2 bg-brand-600 hover:bg-brand-700 active:scale-95 text-white text-xs font-semibold rounded-lg shadow-sm transition-all">
            <i data-lucide="plus" class="w-4 h-4"></i>
            <span>New Adjustment</span>
        </button>
    </div>
</div>

<!-- Stock Movements Table Card -->
<div class="bg-white rounded-xl border border-slate-200/80 shadow-sm overflow-hidden">
    <div class="p-4 sm:p-5 border-b border-slate-100 flex items-center justify-between">
        <div>
            <h2 class="text-sm font-bold text-slate-900">Inventory Movement Transactions</h2>
            <p class="text-xs text-slate-500">Historical chronological log of all stock increases and decreases</p>
        </div>
        <span class="px-2.5 py-0.5 rounded-full text-xs font-bold bg-slate-100 text-slate-700 border border-slate-200">
            <span id="movementCount"><?= count($movements) ?> Transactions</span>
        </span>
    </div>

    <div class="p-4 sm:p-6 overflow-x-auto">
        <table id="movementsTable" class="w-full min-w-[1100px] text-left text-xs text-slate-700">
            <thead>
                <tr class="border-b border-slate-200 text-slate-500 text-[11px] uppercase tracking-wider font-semibold">
                    <th class="py-3 px-3">Date & Time</th>
                    <th class="py-3 px-3">Product Name</th>
                    <th class="py-3 px-3 text-center">Movement Type</th>
                    <th class="py-3 px-3 text-center">Qty Change</th>
                    <th class="py-3 px-3">Selected quantity</th><th class="py-3 px-3">Stock</th><th class="py-3 px-3 text-right">Cost Value</th>
                    <th class="py-3 px-3">Operator</th>
                    <th class="py-3 px-3">Reason / Reference</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <?php foreach ($movements as $m): ?>
                    <?php 
                        $qty = (float) $m['qty_change'];
                        $isPos = $qty > 0;
                    ?>
                        <tr class="hover:bg-slate-50/80 transition-colors">
                            <td class="py-3.5 px-3 text-slate-500 font-mono text-[11px]">
                                <?= esc($m['created_at']) ?>
                            </td>
                            <td class="py-3.5 px-3 font-semibold text-slate-900">
                                <?= esc($m['product_name']) ?>
                            </td>
                            <td class="py-3.5 px-3 text-center">
                                <span class="px-2 py-0.5 rounded-full text-[10px] font-semibold <?= $isPos ? 'bg-emerald-50 text-emerald-700 border border-emerald-200' : 'bg-rose-50 text-rose-700 border border-rose-200' ?>">
                                    <?= esc(strtoupper($m['ref_type'] ?: ($m['reason'] ?: 'Movement'))) ?>
                                </span>
                            </td>
                            <td class="py-3.5 px-3 text-center font-bold font-mono <?= $isPos ? 'text-emerald-600' : 'text-rose-600' ?>">
                                <?= $isPos ? '+' : '' ?><?= rtrim(rtrim(number_format($qty, 3), '0'), '.') ?>
                            </td>
                            <td class="p-3 whitespace-nowrap"><?= isset($m['adjustment_detail']['selected_quantity']) ? esc((float)$m['adjustment_detail']['selected_quantity'].' '.$m['adjustment_detail']['unit_label']) : '' ?></td>
                            <td class="p-3 whitespace-nowrap"><?= isset($m['adjustment_detail']['selected_quantity']) ? ($m['adjustment_detail']['stock_mode'] === 'sealed' ? 'Sealed' : 'Opened / main') : '' ?></td>
                            <td class="py-3.5 px-3 text-right font-medium text-slate-700">
                                USD <?= number_format((float) ($m['cost_value'] ?? 0), 2) ?>
                            </td>
                            <td class="py-3.5 px-3 text-slate-600">
                                <?= esc($m['user_name'] ?: 'System') ?>
                            </td>
                            <td class="py-3.5 px-3 text-slate-500 max-w-xs whitespace-normal break-words">
                                <?= esc(($m['note'] ?? null) ?: ($m['reason'] ?: ($m['ref_type'] ? ($m['ref_type'] . ' #' . $m['ref_id']) : '—'))) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal: New Stock Adjustment -->
<?= view('web/inventory/stock_adjustment_form', ['products' => $products]) ?>

<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
$(function () { erp.initDataTable('#movementsTable', { responsive:false, order: [[0, 'desc']], pageLength: 25 }); });
</script>
<script src="<?= base_url('assets/js/stock-adjustment.js').'?v='.filemtime(FCPATH.'assets/js/stock-adjustment.js') ?>"></script>
<?= $this->endSection() ?>
