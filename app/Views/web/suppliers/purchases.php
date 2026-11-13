<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>

<!-- Page Header & Action Bar -->
<div class="mb-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
    <div>
        <div class="erp-page-title flex flex-wrap items-center gap-2">
            <h1 class="text-2xl sm:text-3xl font-bold text-slate-900 tracking-tight">
                Purchase Orders
            </h1>
            <span class="px-2.5 py-0.5 rounded-full text-xs font-semibold bg-indigo-50 text-indigo-700 border border-indigo-200">
                Procurement
            </span>
        </div>
        <p class="text-xs sm:text-sm text-slate-500 mt-1">
            Create supplier orders, track fulfillment progress, and initiate inventory receiving.
        </p>
    </div>

    <div class="erp-page-actions flex flex-wrap items-center gap-2.5">
        <a href="<?= site_url('suppliers') ?>" 
           class="inline-flex items-center gap-1.5 px-3.5 py-2 bg-white hover:bg-slate-50 text-slate-700 border border-slate-200 text-xs font-semibold rounded-lg shadow-sm transition-all">
            <i data-lucide="truck" class="w-4 h-4 text-slate-500"></i>
            <span>Supplier Directory</span>
        </a>
        <a href="<?= site_url('suppliers/receiving') ?>" 
           class="inline-flex items-center gap-1.5 px-3.5 py-2 bg-white hover:bg-slate-50 text-slate-700 border border-slate-200 text-xs font-semibold rounded-lg shadow-sm transition-all">
            <i data-lucide="package-check" class="w-4 h-4 text-emerald-600"></i>
            <span>Goods Receiving</span>
        </a>
        <button type="button" onclick="openCreatePOModal()" 
                class="inline-flex items-center gap-1.5 px-4 py-2 bg-brand-600 hover:bg-brand-700 active:scale-95 text-white text-xs font-semibold rounded-lg shadow-sm transition-all">
            <i data-lucide="plus" class="w-4 h-4"></i>
            <span>New Purchase Order</span>
        </button>
    </div>
</div>

<div id="purchaseSummary">
<!-- 4 KPI Summary Cards -->
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
    <div class="bg-white p-5 rounded-xl border border-slate-200/80 shadow-sm flex items-center justify-between">
        <div>
            <span class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Total Purchase Spend</span>
            <div class="text-2xl font-bold text-slate-900 mt-1">USD <?= number_format($totalSpend, 2) ?></div>
            <div class="text-[11px] text-slate-500 mt-0.5">Across all recorded POs</div>
        </div>
        <div class="w-11 h-11 bg-slate-50 rounded-xl flex items-center justify-center text-slate-600 border border-slate-100">
            <i data-lucide="dollar-sign" class="w-5 h-5"></i>
        </div>
    </div>

    <div class="bg-white p-5 rounded-xl border border-slate-200/80 shadow-sm flex items-center justify-between">
        <div>
            <span class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Total Orders</span>
            <div class="text-2xl font-bold text-slate-900 mt-1"><?= number_format(count($purchases)) ?></div>
            <div class="text-[11px] text-slate-500 mt-0.5">Lifetime purchase orders</div>
        </div>
        <div class="w-11 h-11 bg-indigo-50 rounded-xl flex items-center justify-center text-indigo-600 border border-indigo-100">
            <i data-lucide="shopping-bag" class="w-5 h-5"></i>
        </div>
    </div>

    <div class="bg-white p-5 rounded-xl border border-slate-200/80 shadow-sm flex items-center justify-between">
        <div>
            <span class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Pending Fulfillment</span>
            <div class="text-2xl font-bold text-amber-600 mt-1"><?= number_format($pendingCount) ?></div>
            <div class="text-[11px] text-slate-500 mt-0.5">Awaiting goods check-in</div>
        </div>
        <div class="w-11 h-11 bg-amber-50 rounded-xl flex items-center justify-center text-amber-600 border border-amber-100">
            <i data-lucide="clock" class="w-5 h-5"></i>
        </div>
    </div>

    <div class="bg-white p-5 rounded-xl border border-slate-200/80 shadow-sm flex items-center justify-between">
        <div>
            <span class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Fully Received</span>
            <div class="text-2xl font-bold text-emerald-600 mt-1"><?= number_format($receivedCount) ?></div>
            <div class="text-[11px] text-slate-500 mt-0.5">Completed & booked to stock</div>
        </div>
        <div class="w-11 h-11 bg-emerald-50 rounded-xl flex items-center justify-center text-emerald-600 border border-emerald-100">
            <i data-lucide="check-check" class="w-5 h-5"></i>
        </div>
    </div>
</div>

</div>
<!-- Purchase Orders Table Card -->
<div class="bg-white rounded-xl border border-slate-200/80 shadow-sm overflow-hidden">
    <div class="p-4 sm:p-5 border-b border-slate-100 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
        <div>
            <h2 class="text-sm font-bold text-slate-900">Procurement Orders Log</h2>
            <p class="text-xs text-slate-500">Track PO fulfillment progress and goods inspection</p>
        </div>
    </div>

    <div class="p-4 sm:p-6 overflow-x-auto">
        <div class="space-y-4 mb-5">
            <label for="purchaseSearch" class="sr-only">Search purchase orders</label>
            <input id="purchaseSearch" type="search" placeholder="Search orders, suppliers or references…" class="w-full px-4 py-3 border border-slate-300 rounded-lg text-sm">
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                <label class="text-xs font-semibold">Status<select id="purchaseStatus" class="block mt-2 w-full p-2.5 border border-slate-300 rounded-lg font-normal"><option value="">All statuses</option><option value="pending">Pending</option><option value="partially_received">Partially received</option><option value="received">Received</option><option value="cancelled">Cancelled</option></select></label>
                <label class="text-xs font-semibold">Supplier<select id="purchaseSupplier" class="block mt-2 w-full p-2.5 border border-slate-300 rounded-lg font-normal"><option value="">All suppliers</option><?php foreach ($suppliers as $supplier): ?><option value="<?= (int) $supplier['id'] ?>"><?= esc($supplier['name']) ?></option><?php endforeach; ?></select></label>
                <label class="text-xs font-semibold">Ordered from<input id="purchaseFrom" type="date" class="block mt-2 w-full p-2.5 border border-slate-300 rounded-lg font-normal"></label>
                <label class="text-xs font-semibold">Ordered through<input id="purchaseTo" type="date" class="block mt-2 w-full p-2.5 border border-slate-300 rounded-lg font-normal"></label>
            </div>
            <button type="button" onclick="clearPurchaseFilters()" class="text-xs font-semibold text-brand-600">Clear filters</button>
        </div>
        <table id="purchasesTable" class="w-full text-left text-xs text-slate-700">
            <thead>
                <tr class="border-b border-slate-200 text-slate-500 text-[11px] uppercase tracking-wider font-semibold">
                    <th class="py-3 px-3">Order Ref</th>
                    <th class="py-3 px-3">Supplier</th>
                    <th class="py-3 px-3">Order Date</th>
                    <th class="py-3 px-3 text-center">Fulfillment Progress</th>
                    <th class="py-3 px-3 text-right">Order Amount</th>
                    <th class="py-3 px-3 text-center">Status</th>
                    <th class="py-3 px-3 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <?php foreach ($purchases as $p): ?>
                    <?php 
                        $ordered = (float) $p['quantity_ordered'];
                        $received = (float) $p['quantity_received'];
                        $pct = ($ordered > 0) ? min(100, round(($received / $ordered) * 100)) : 0;
                    ?>
                        <tr data-status="<?= esc($p['status'], 'attr') ?>" data-supplier="<?= (int) $p['supplier_id'] ?>" data-date="<?= esc(substr($p['purchased_at'], 0, 10), 'attr') ?>" class="hover:bg-slate-50/80 transition-colors">
                            <td class="py-3.5 px-3 font-mono font-bold text-brand-600">
                                <?= esc($p['reference_no'] ?: 'PO-' . str_pad($p['id'], 5, '0', STR_PAD_LEFT)) ?>
                            </td>
                            <td class="py-3.5 px-3 font-semibold text-slate-900">
                                <?= esc($p['supplier_name'] ?: 'Direct Vendor') ?>
                            </td>
                            <td class="py-3.5 px-3 text-slate-500">
                                <?= esc(substr($p['purchased_at'] ?? '', 0, 10)) ?>
                            </td>
                            <td class="py-3.5 px-3 text-center min-w-[140px]">
                                <div class="flex items-center gap-2 justify-center">
                                    <div class="w-20 bg-slate-100 rounded-full h-2 overflow-hidden border border-slate-200">
                                        <div class="h-full rounded-full <?= $pct == 100 ? 'bg-emerald-500' : ($pct > 0 ? 'bg-blue-500' : 'bg-amber-400') ?>" 
                                             style="width: <?= $pct ?>%"></div>
                                    </div>
                                    <span class="text-[11px] font-bold text-slate-600"><?= $pct ?>%</span>
                                </div>
                                <div class="text-[10px] text-slate-400 mt-0.5">
                                    <?= number_format($received, 1) ?> / <?= number_format($ordered, 1) ?> units
                                </div>
                            </td>
                            <td class="py-3.5 px-3 text-right font-bold text-slate-900">
                                USD <?= number_format((float) $p['total'], 2) ?>
                            </td>
                            <td class="py-3.5 px-3 text-center">
                                <?php if ($p['status'] === 'received'): ?>
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200">
                                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span> Received
                                    </span>
                                <?php elseif ($p['status'] === 'partially_received'): ?>
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-semibold bg-blue-50 text-blue-700 border border-blue-200">
                                        <span class="w-1.5 h-1.5 rounded-full bg-blue-500"></span> Partial
                                    </span>
                                <?php elseif ($p['status'] === 'pending'): ?>
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-semibold bg-amber-50 text-amber-700 border border-amber-200">
                                        <span class="w-1.5 h-1.5 rounded-full bg-amber-500"></span> Pending
                                    </span>
                                <?php else: ?>
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-semibold bg-rose-50 text-rose-700 border border-rose-200">
                                        <span class="w-1.5 h-1.5 rounded-full bg-rose-500"></span> Cancelled
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="py-3.5 px-3 text-right">
                                <div class="inline-flex items-center gap-1">
                                    <button type="button" onclick="viewPODetails(<?= (int) $p['id'] ?>)"
                                            class="p-1.5 text-slate-500 hover:text-brand-600 hover:bg-slate-100 rounded-lg transition-colors"
                                            title="View Order Details">
                                        <i data-lucide="eye" class="w-4 h-4"></i>
                                    </button>

                                    <?php if ($p['status'] === 'pending' || $p['status'] === 'partially_received'): ?>
                                        <a href="<?= site_url('suppliers/receiving?po=' . $p['id']) ?>"
                                           class="p-1.5 text-slate-500 hover:text-emerald-600 hover:bg-emerald-50 rounded-lg transition-colors"
                                           title="Receive Items">
                                            <i data-lucide="package-check" class="w-4 h-4"></i>
                                        </a>
                                    <?php endif; ?>

                                    <?php if ($p['status'] === 'pending' && $received == 0): ?>
                                        <button type="button" onclick="editPurchaseOrder(<?= (int) $p['id'] ?>)" class="p-1.5 text-brand-600 hover:bg-brand-50 rounded-lg" title="Edit purchase order"><i data-lucide="pencil" class="w-4 h-4"></i></button>
                                        <button type="button" onclick="voidPO(<?= (int) $p['id'] ?>)"
                                                class="p-1.5 text-slate-400 hover:text-rose-600 hover:bg-rose-50 rounded-lg transition-colors"
                                                title="Void Order">
                                            <i data-lucide="x-circle" class="w-4 h-4"></i>
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal: Create New Purchase Order -->
<div id="modal-create-po" class="fixed inset-0 z-50 bg-slate-900/40 backdrop-blur-sm hidden flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl shadow-2xl border border-slate-200 w-full max-w-5xl overflow-hidden max-h-[92vh] flex flex-col">
        <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
            <div class="flex items-center gap-2">
                <div class="w-8 h-8 rounded-lg bg-brand-50 text-brand-600 flex items-center justify-center">
                    <i data-lucide="shopping-cart" class="w-4 h-4"></i>
                </div>
                <h3 id="purchaseEditorTitle" class="text-sm font-bold text-slate-900">Create Purchase Order</h3>
            </div>
            <button onclick="closeModal('modal-create-po')" class="text-slate-400 hover:text-slate-600">
                <i data-lucide="x" class="w-4 h-4"></i>
            </button>
        </div>

        <form id="form-create-po" onsubmit="submitCreatePO(event)" class="flex-1 overflow-y-auto p-6 space-y-4">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-semibold text-slate-700 mb-1">Select Supplier *</label>
                    <select id="po-supplier-id" required
                            class="w-full px-3 py-2 text-xs border border-slate-300 rounded-lg bg-white focus:outline-none focus:border-brand-600 focus:ring-1 focus:ring-brand-600">
                        <option value="">Choose vendor...</option>
                        <?php foreach ($suppliers as $s): ?>
                            <option value="<?= esc($s['id']) ?>"><?= esc($s['name']) ?> (SUP-<?= esc($s['id']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-700 mb-1">PO Reference (Optional)</label>
                    <input type="text" id="po-reference" placeholder="e.g. PO-<?= date('Ymd-Hi') ?>"
                           class="w-full px-3 py-2 text-xs border border-slate-300 rounded-lg focus:outline-none focus:border-brand-600 focus:ring-1 focus:ring-brand-600">
                </div>
            </div>

            <input type="hidden" id="purchaseOrderId">
            <label for="purchaseExpectedDate" class="block text-xs font-semibold text-slate-700">Expected receipt date &amp; time</label>
            <input id="purchaseExpectedDate" type="datetime-local" required class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg">
            <!-- Dynamic Items Repeater -->
            <div class="pt-2">
                <div class="flex items-center justify-between mb-2">
                    <label class="block text-xs font-bold text-slate-800 uppercase tracking-wider">Order Line Items</label>
                    <button type="button" onclick="addPOLineItem()"
                            class="px-2.5 py-1 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-semibold rounded-lg transition-colors flex items-center gap-1">
                        <i data-lucide="plus" class="w-3.5 h-3.5"></i> Add Line
                    </button>
                </div>

                <div class="border border-slate-200 rounded-xl overflow-x-auto">
                    <table class="w-full min-w-[800px] text-left text-xs">
                        <thead class="bg-slate-50 border-b border-slate-200 text-slate-500 text-[10px] uppercase font-semibold">
                            <tr>
                                <th class="py-2.5 px-3">Product Item</th>
                                <th class="py-2.5 px-3 w-36">Purchase unit</th><th class="py-2.5 px-3 w-28">Quantity</th>
                                <th class="py-2.5 px-3 w-32">Unit Cost ($)</th>
                                <th class="py-2.5 px-3 w-32 text-right">Line Total ($)</th>
                                <th class="py-2.5 px-2 w-10 text-center"></th>
                            </tr>
                        </thead>
                        <tbody id="po-items-tbody" class="divide-y divide-slate-100">
                            <!-- Injected by JavaScript -->
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Total Calculation Summary -->
            <div class="flex justify-end pt-2">
                <div class="w-full sm:w-64 bg-slate-50 p-4 rounded-xl border border-slate-200 space-y-2 text-xs">
                    <div class="flex justify-between text-slate-500">
                        <span>Total Items:</span>
                        <span id="po-calc-items" class="font-semibold text-slate-800">0</span>
                    </div>
                    <div class="flex justify-between text-slate-900 text-sm font-bold border-t border-slate-200 pt-2">
                        <span>Estimated Total:</span>
                        <span id="po-calc-total" class="text-brand-600 font-extrabold">USD 0.00</span>
                    </div>
                </div>
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-700 mb-1">Procurement Notes / Instructions</label>
                <textarea id="po-notes" rows="2" placeholder="Delivery time, payment terms or shipping details..."
                          class="w-full px-3 py-2 text-xs border border-slate-300 rounded-lg focus:outline-none focus:border-brand-600 focus:ring-1 focus:ring-brand-600"></textarea>
            </div>

            <div class="pt-3 border-t border-slate-100 flex items-center justify-end gap-2">
                <button type="button" onclick="closeModal('modal-create-po')"
                        class="px-3.5 py-2 text-xs font-semibold text-slate-600 hover:bg-slate-100 rounded-lg transition-colors">
                    Cancel
                </button>
                <button type="submit" id="btn-save-po"
                        class="px-5 py-2 bg-brand-600 hover:bg-brand-700 active:scale-95 text-white text-xs font-semibold rounded-lg shadow-sm transition-all">
                    Create Order
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Purchase Order Details -->
<div id="modal-view-po" class="fixed inset-0 z-50 bg-slate-900/40 backdrop-blur-sm hidden flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl shadow-2xl border border-slate-200 w-full max-w-2xl overflow-hidden max-h-[90vh] flex flex-col">
        <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
            <div class="flex items-center gap-2">
                <div class="w-8 h-8 rounded-lg bg-indigo-50 text-indigo-600 flex items-center justify-center">
                    <i data-lucide="file-text" class="w-4 h-4"></i>
                </div>
                <div>
                    <h3 class="text-sm font-bold text-slate-900" id="view-po-ref">Purchase Order Details</h3>
                    <p class="text-[11px] text-slate-500" id="view-po-meta">Loading details...</p>
                </div>
            </div>
            <button onclick="closeModal('modal-view-po')" class="text-slate-400 hover:text-slate-600">
                <i data-lucide="x" class="w-4 h-4"></i>
            </button>
        </div>

        <div class="p-6 overflow-y-auto flex-1 space-y-4">
            <!-- PO Info Card -->
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 bg-slate-50 p-4 rounded-xl border border-slate-100 text-xs">
                <div>
                    <span class="text-[10px] text-slate-400 font-semibold uppercase">Vendor</span>
                    <div class="font-bold text-slate-800 mt-0.5" id="view-po-supplier">—</div>
                </div>
                <div>
                    <span class="text-[10px] text-slate-400 font-semibold uppercase">Status</span>
                    <div class="mt-0.5" id="view-po-status">—</div>
                </div>
                <div>
                    <span class="text-[10px] text-slate-400 font-semibold uppercase">Recorded By</span>
                    <div class="font-medium text-slate-700 mt-0.5" id="view-po-user">—</div>
                </div>
                <div>
                    <span class="text-[10px] text-slate-400 font-semibold uppercase">Total Amount</span>
                    <div class="font-extrabold text-slate-900 mt-0.5" id="view-po-total">USD 0.00</div>
                </div>
            </div>

            <!-- Items Breakdown Table -->
            <div>
                <h4 class="text-xs font-bold text-slate-800 mb-2 uppercase tracking-wider">Ordered Products</h4>
                <div class="border border-slate-200 rounded-xl overflow-x-auto">
                    <table class="w-full min-w-[800px] text-left text-xs">
                        <thead class="bg-slate-50 border-b border-slate-200 text-slate-500 text-[10px] uppercase font-semibold">
                            <tr>
                                <th class="py-2 px-3">Product Name</th>
                                <th class="py-2 px-3 text-center">Ordered</th>
                                <th class="py-2 px-3 text-center">Received</th>
                                <th class="py-2 px-3 text-right">Unit Cost</th>
                                <th class="py-2 px-3 text-right">Line Total</th>
                            </tr>
                        </thead>
                        <tbody id="view-po-items-tbody" class="divide-y divide-slate-100 text-slate-700">
                            <!-- Injected by JavaScript -->
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Goods Receipts History -->
            <div>
                <h4 class="text-xs font-bold text-slate-800 mb-2 uppercase tracking-wider">Goods Receipts (Check-ins)</h4>
                <div id="view-po-receipts" class="text-xs text-slate-500">
                    <!-- Injected by JavaScript -->
                </div>
            </div>
        </div>

        <div class="px-6 py-3 border-t border-slate-100 bg-slate-50 flex items-center justify-between" id="view-po-footer-actions">
            <!-- Dynamic Buttons (Receive, Void, Close) -->
        </div>
    </div>
</div>

<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script id="purchaseConfig" type="application/json"><?= json_encode([
    'products' => $products,
    'saveUrl' => site_url('suppliers/purchases/save'),
    'receivingUrl' => site_url('suppliers/receiving'),
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script src="<?= base_url('assets/js/supplier-purchases.js') . '?v=' . filemtime(FCPATH . 'assets/js/supplier-purchases.js') ?>"></script>
<?= $this->endSection() ?>
