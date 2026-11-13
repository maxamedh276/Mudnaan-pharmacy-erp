<div id="modal-adjust-stock" role="dialog" aria-modal="true" aria-labelledby="stockAdjustmentTitle" class="fixed inset-0 z-50 bg-slate-900/40 backdrop-blur-sm hidden flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl shadow-xl border border-slate-200 w-full max-w-lg max-h-[90vh] overflow-y-auto">
        <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
            <h2 id="stockAdjustmentTitle" class="font-bold text-slate-900">Adjust stock</h2>
            <button type="button" aria-label="Close adjustment form" onclick="closeModal('modal-adjust-stock')" class="p-2 rounded-lg hover:bg-slate-100"><i data-lucide="x" class="w-4 h-4"></i></button>
        </div>
        <form id="form-adjust-stock" class="p-6 space-y-5">
            <label class="block text-xs font-semibold text-slate-700" for="adj-product-id">Product</label>
            <select id="adj-product-id" required class="w-full px-3 py-2.5 text-sm border border-slate-300 rounded-lg">
                <option value="">Choose product…</option>
                <?php foreach ($products as $product): $split = (new \App\Services\PackageInventoryService())->balance((int)(session()->get('user')['branch_id'] ?? 1),$product); ?>
                <option value="<?= (int) $product['id'] ?>" data-quantity="<?= esc($product['quantity'], 'attr') ?>" data-opened="<?= $split['opened'] ?>" data-sealed="<?= $split['sealed'] ?>" data-unit="<?= esc($product['unit'], 'attr') ?>" data-package="<?= esc($product['package_unit'] ?? '', 'attr') ?>"><?= esc($product['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <label class="block text-xs font-semibold">Stock to adjust<select id="adj-stock-mode" required class="block mt-2 w-full px-3 py-2.5 text-sm border rounded-lg"><option value="loose">Main stock</option></select></label>
            <div class="grid grid-cols-2 gap-4">
                <label for="adj-direction" class="text-xs font-semibold text-slate-700">Adjustment type
                    <select id="adj-direction" class="block mt-2 w-full px-3 py-2.5 text-sm border border-slate-300 rounded-lg font-normal"><option value="in">Increase stock</option><option value="out">Decrease stock</option></select>
                </label>
                <label for="adj-quantity" class="text-xs font-semibold text-slate-700">Quantity to change
                    <input id="adj-quantity" type="number" min="0.001" step="0.001" required value="1" class="block mt-2 w-full px-3 py-2.5 text-sm border border-slate-300 rounded-lg font-normal">
                </label>
            </div>
            <div class="flex justify-between gap-4 rounded-xl bg-slate-50 border p-4 text-sm" aria-live="polite"><span>Current: <strong id="adjustmentCurrent">—</strong></span><span>After adjustment: <strong id="adjustmentAfter">—</strong></span></div>
            <label for="adj-note" class="block text-xs font-semibold text-slate-700">Reason</label>
            <textarea id="adj-note" required maxlength="500" rows="3" placeholder="e.g. Two damaged items removed" class="w-full px-3 py-2.5 text-sm border border-slate-300 rounded-lg"></textarea>
            <div id="adjustmentError" role="alert" class="hidden text-sm text-rose-600"></div>
            <div class="flex justify-end gap-3 pt-4 border-t">
                <button type="button" onclick="closeModal('modal-adjust-stock')" class="px-4 py-2 border rounded-lg text-sm">Cancel</button>
                <button type="submit" id="btn-submit-adj" class="px-4 py-2 bg-brand-600 text-white rounded-lg text-sm font-semibold">Apply adjustment</button>
            </div>
        </form>
    </div>
</div>
<script id="stockAdjustmentConfig" type="application/json"><?= json_encode(['saveUrl' => site_url('inventory/stock/adjust')], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<style>
#stockTable_wrapper .dataTables_filter, #movementsTable_wrapper .dataTables_filter { float: none; width: 100%; margin: 1rem 0; text-align: left; }
#stockTable_wrapper .dataTables_filter label, #movementsTable_wrapper .dataTables_filter label { display: block; width: 100%; }
#stockTable_wrapper .dataTables_filter input, #movementsTable_wrapper .dataTables_filter input { box-sizing: border-box; width: 100%; margin: 0; }
</style>
