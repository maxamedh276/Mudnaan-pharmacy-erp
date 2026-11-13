<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>

<!-- Page Header & Action Bar -->
<div class="mb-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
    <div>
        <div class="erp-page-title flex flex-wrap items-center gap-2">
            <h1 class="text-2xl sm:text-3xl font-bold text-slate-900 tracking-tight">
                Goods Receiving & Inspection
            </h1>
            <span class="px-2.5 py-0.5 rounded-full text-xs font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200">
                Inbound Stock
            </span>
        </div>
        <p class="text-xs sm:text-sm text-slate-500 mt-1">
            Check in arriving supplier shipments, record partial or full receipts, and update warehouse inventory stock.
        </p>
    </div>

    <div class="erp-page-actions flex flex-wrap items-center gap-2.5">
        <a href="<?= site_url('suppliers') ?>" 
           class="inline-flex items-center gap-1.5 px-3.5 py-2 bg-white hover:bg-slate-50 text-slate-700 border border-slate-200 text-xs font-semibold rounded-lg shadow-sm transition-all">
            <i data-lucide="truck" class="w-4 h-4 text-slate-500"></i>
            <span>Supplier Directory</span>
        </a>
        <a href="<?= site_url('suppliers/purchases') ?>" 
           class="inline-flex items-center gap-1.5 px-3.5 py-2 bg-white hover:bg-slate-50 text-slate-700 border border-slate-200 text-xs font-semibold rounded-lg shadow-sm transition-all">
            <i data-lucide="shopping-cart" class="w-4 h-4 text-slate-500"></i>
            <span>Purchase Orders</span>
        </a>
    </div>
</div>

<!-- Open Orders Awaiting Receiving Card -->
<div class="bg-white rounded-xl border border-slate-200/80 shadow-sm overflow-hidden mb-6">
    <div class="p-4 sm:p-5 border-b border-slate-100 flex items-center justify-between">
        <div>
            <h2 class="text-sm font-bold text-slate-900">Awaiting Check-in & Receipt</h2>
            <p class="text-xs text-slate-500">Purchase orders that have unfulfilled product items ready for arrival</p>
        </div>
        <span class="px-2.5 py-0.5 rounded-full text-xs font-bold bg-amber-50 text-amber-700 border border-amber-200">
            <?= count($receivablePurchases) ?> Open Orders
        </span>
    </div>

    <div class="p-4 sm:p-6 overflow-x-auto">
        <table id="receivableTable" class="w-full text-left text-xs text-slate-700">
            <thead>
                <tr class="border-b border-slate-200 text-slate-500 text-[11px] uppercase tracking-wider font-semibold">
                    <th class="py-3 px-3">Order Ref</th>
                    <th class="py-3 px-3">Supplier Name</th>
                    <th class="py-3 px-3">Order Date</th>
                    <th class="py-3 px-3 text-center">Remaining to Receive</th>
                    <th class="py-3 px-3 text-right">Order Total</th>
                    <th class="py-3 px-3 text-center">Status</th>
                    <th class="py-3 px-3 text-right">Action</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <?php foreach ($receivablePurchases as $p): ?>
                    <?php 
                        $ordered = (float) $p['quantity_ordered'];
                        $received = (float) $p['quantity_received'];
                        $remaining = max(0, $ordered - $received);
                    ?>
                        <tr class="hover:bg-slate-50/80 transition-colors">
                            <td class="py-3.5 px-3 font-mono font-bold text-brand-600">
                                <?= esc($p['reference_no'] ?: 'PO-' . str_pad($p['id'], 5, '0', STR_PAD_LEFT)) ?>
                            </td>
                            <td class="py-3.5 px-3 font-semibold text-slate-900">
                                <?= esc($p['supplier_name'] ?: 'Direct Vendor') ?>
                            </td>
                            <td class="py-3.5 px-3 text-slate-500">
                                <?= esc(substr($p['purchased_at'] ?? '', 0, 10)) ?>
                            </td>
                            <td class="py-3.5 px-3 text-center">
                                <span class="font-bold text-amber-600"><?= number_format($remaining, 1) ?> units</span>
                                <span class="text-[10px] text-slate-400"> (of <?= number_format($ordered, 1) ?>)</span>
                            </td>
                            <td class="py-3.5 px-3 text-right font-bold text-slate-900">
                                USD <?= number_format((float) $p['total'], 2) ?>
                            </td>
                            <td class="py-3.5 px-3 text-center">
                                <span class="px-2 py-0.5 rounded-full text-[10px] font-semibold <?= $p['status'] === 'partially_received' ? 'bg-blue-50 text-blue-700 border border-blue-200' : 'bg-amber-50 text-amber-700 border border-amber-200' ?>">
                                    <?= $p['status'] === 'partially_received' ? 'Partially Received' : 'Pending Delivery' ?>
                                </span>
                            </td>
                            <td class="py-3.5 px-3 text-right">
                                <button type="button" onclick="openReceiveModal(<?= (int) $p['id'] ?>, '<?= esc(addslashes($p['reference_no'] ?: ('PO-' . $p['id']))) ?>')"
                                        class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-emerald-600 hover:bg-emerald-700 active:scale-95 text-white text-xs font-semibold rounded-lg shadow-sm transition-all">
                                    <i data-lucide="package-check" class="w-3.5 h-3.5"></i>
                                    <span>Receive Goods</span>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Recent Goods Receipts History Card -->
<div class="bg-white rounded-xl border border-slate-200/80 shadow-sm overflow-hidden">
    <div class="p-4 sm:p-5 border-b border-slate-100 flex items-center justify-between">
        <div>
            <h2 class="text-sm font-bold text-slate-900">Recent Goods Receipt Notes (GRN)</h2>
            <p class="text-xs text-slate-500">History of checked-in shipments and inbound inventory ledger batches</p>
        </div>
    </div>

    <div class="p-4 sm:p-6 overflow-x-auto">
        <table id="receiptsTable" class="w-full text-left text-xs text-slate-700">
            <thead>
                <tr class="border-b border-slate-200 text-slate-500 text-[11px] uppercase tracking-wider font-semibold">
                    <th class="py-3 px-3">Receipt No</th>
                    <th class="py-3 px-3">Purchase Order</th>
                    <th class="py-3 px-3">Supplier Name</th>
                    <th class="py-3 px-3">Received At</th>
                    <th class="py-3 px-3">Checked In By</th>
                    <th class="py-3 px-3">Notes</th>
                    <th class="py-3 px-3 text-center">Status</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <?php foreach ($recentReceipts as $r): ?>
                    <tr class="hover:bg-slate-50/80 transition-colors">
                            <td class="py-3.5 px-3 font-mono font-bold text-emerald-600">
                                <?= esc($r['receipt_no'] ?: 'GRN-' . str_pad($r['id'], 5, '0', STR_PAD_LEFT)) ?>
                            </td>
                            <td class="py-3.5 px-3 font-mono font-medium text-slate-800">
                                <?= esc($r['reference_no'] ?: 'PO-' . $r['purchase_id']) ?>
                            </td>
                            <td class="py-3.5 px-3 font-semibold text-slate-900">
                                <?= esc($r['supplier_name'] ?: 'Vendor') ?>
                            </td>
                            <td class="py-3.5 px-3 text-slate-500">
                                <?= esc($r['received_at'] ?: $r['created_at']) ?>
                            </td>
                            <td class="py-3.5 px-3 text-slate-700">
                                <?= esc($r['received_by_name'] ?: 'Store Staff') ?>
                            </td>
                            <td class="py-3.5 px-3 text-slate-500 max-w-xs truncate">
                                <?= esc($r['notes'] ?: '—') ?>
                            </td>
                            <td class="py-3.5 px-3 text-center">
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200">
                                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span> Booked into Stock
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal: Receive Shipment Goods -->
<div id="modal-receive" class="fixed inset-0 z-50 bg-slate-900/40 backdrop-blur-sm hidden flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl shadow-2xl border border-slate-200 w-full max-w-2xl overflow-hidden max-h-[92vh] flex flex-col animate-in fade-in zoom-in-95 duration-150">
        <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
            <div class="flex items-center gap-2">
                <div class="w-8 h-8 rounded-lg bg-emerald-50 text-emerald-600 flex items-center justify-center">
                    <i data-lucide="package-check" class="w-4 h-4"></i>
                </div>
                <div>
                    <h3 class="text-sm font-bold text-slate-900" id="receive-modal-title">Receive Shipment Goods</h3>
                    <p class="text-[11px] text-slate-500">Record physical check-in and increment inventory levels</p>
                </div>
            </div>
            <button onclick="closeModal('modal-receive')" class="text-slate-400 hover:text-slate-600">
                <i data-lucide="x" class="w-4 h-4"></i>
            </button>
        </div>

        <form id="form-receive" onsubmit="submitReceiveGoods(event)" class="flex-1 overflow-y-auto p-6 space-y-4">
            <input type="hidden" id="receive-po-id">

            <div class="bg-emerald-50/60 border border-emerald-200/80 rounded-xl p-3 flex items-center gap-2 text-xs text-emerald-800">
                <i data-lucide="info" class="w-4 h-4 text-emerald-600 flex-shrink-0"></i>
                <span>Entering quantities here will instantly update warehouse stock levels and create an inbound audit trail.</span>
            </div>

            <!-- Items Table to Receive -->
            <div class="border border-slate-200 rounded-xl overflow-hidden">
                <table class="w-full text-left text-xs">
                    <thead class="bg-slate-50 border-b border-slate-200 text-slate-500 text-[10px] uppercase font-semibold">
                        <tr>
                            <th class="py-2.5 px-3">Product Name</th>
                            <th class="py-2.5 px-3 text-center">Ordered</th>
                            <th class="py-2.5 px-3 text-center">Already Recv</th>
                            <th class="py-2.5 px-3 text-center">Remaining</th>
                            <th class="py-2.5 px-3 text-center w-32">Receiving Now *</th>
                        </tr>
                    </thead>
                    <tbody id="receive-items-tbody" class="divide-y divide-slate-100 text-slate-700">
                        <tr><td colspan="5" class="py-6 text-center text-slate-400">Loading order items...</td></tr>
                    </tbody>
                </table>
            </div>

            <div>
                <label for="receive-expected-date" class="block text-xs font-semibold text-slate-700 mb-1">Expected receipt date &amp; time</label>
                <input id="receive-expected-date" type="text" readonly class="w-full px-3 py-2 text-xs border border-slate-200 rounded-lg bg-slate-50 text-slate-500">
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 pt-2">
                <div>
                    <label for="receive-date" class="block text-xs font-semibold text-slate-700 mb-1">Date &amp; time received</label>
                    <input type="datetime-local" id="receive-date" required
                           class="w-full px-3 py-2 text-xs border border-slate-300 rounded-lg focus:outline-none focus:border-emerald-600">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-700 mb-1">Inspection / Delivery Notes</label>
                    <input type="text" id="receive-notes" placeholder="Condition good, packaging intact..."
                           class="w-full px-3 py-2 text-xs border border-slate-300 rounded-lg focus:outline-none focus:border-emerald-600">
                </div>
            </div>

            <div class="pt-3 border-t border-slate-100 flex items-center justify-end gap-2">
                <button type="button" onclick="closeModal('modal-receive')"
                        class="px-3.5 py-2 text-xs font-semibold text-slate-600 hover:bg-slate-100 rounded-lg transition-colors">
                    Cancel
                </button>
                <button type="submit" id="btn-submit-receive"
                        class="px-5 py-2 bg-emerald-600 hover:bg-emerald-700 active:scale-95 text-white text-xs font-semibold rounded-lg shadow-sm transition-all flex items-center gap-1.5">
                    <i data-lucide="check-circle" class="w-4 h-4"></i>
                    <span>Confirm Goods In</span>
                </button>
            </div>
        </form>
    </div>
</div>

<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script id="receivingConfig" type="application/json"><?= json_encode(['receivingUrl' => site_url('suppliers/receiving')], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script src="<?= base_url('assets/js/supplier-receiving.js') ?>"></script>
<?= $this->endSection() ?>
