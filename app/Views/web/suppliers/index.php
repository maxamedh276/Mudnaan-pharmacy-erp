<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>

<!-- Page Header & Action Bar -->
<div class="mb-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
    <div>
        <div class="erp-page-title flex flex-wrap items-center gap-2">
            <h1 class="text-2xl sm:text-3xl font-bold text-slate-900 tracking-tight">
                Supplier Management
            </h1>
            <span class="px-2.5 py-0.5 rounded-full text-xs font-semibold bg-brand-50 text-brand-700 border border-brand-200">
                Directory
            </span>
        </div>
        <p class="text-xs sm:text-sm text-slate-500 mt-1">
            Maintain your vendor accounts, track payables, inspect account statements, and manage procurement relationships.
        </p>
    </div>

    <div class="erp-page-actions flex flex-wrap items-center gap-2.5">
        <a href="<?= site_url('suppliers/purchases') ?>" 
           class="inline-flex items-center gap-1.5 px-3.5 py-2 bg-white hover:bg-slate-50 text-slate-700 border border-slate-200 text-xs font-semibold rounded-lg shadow-sm transition-all">
            <i data-lucide="shopping-cart" class="w-4 h-4 text-slate-500"></i>
            <span>Purchase Orders</span>
        </a>
        <a href="<?= site_url('suppliers/receiving') ?>" 
           class="inline-flex items-center gap-1.5 px-3.5 py-2 bg-white hover:bg-slate-50 text-slate-700 border border-slate-200 text-xs font-semibold rounded-lg shadow-sm transition-all">
            <i data-lucide="package-check" class="w-4 h-4 text-emerald-600"></i>
            <span>Goods Receiving</span>
        </a>
        <?= view('web/partials/workbook_import', ['importKind' => 'suppliers']) ?>
        <button type="button" onclick="openAddSupplierModal()" 
                class="inline-flex items-center gap-1.5 px-4 py-2 bg-brand-600 hover:bg-brand-700 active:scale-95 text-white text-xs font-semibold rounded-lg shadow-sm transition-all">
            <i data-lucide="plus" class="w-4 h-4"></i>
            <span>Add New Supplier</span>
        </button>
    </div>
</div>

<!-- 3 KPI Summary Cards -->
<div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
    <div class="bg-white p-5 rounded-xl border border-slate-200/80 shadow-sm flex items-center justify-between">
        <div>
            <span class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Total Suppliers</span>
            <div class="text-2xl font-bold text-slate-900 mt-1"><?= number_format($totalSuppliers) ?></div>
            <div class="text-[11px] text-slate-500 mt-0.5"><?= number_format($activeSuppliers) ?> active vendors</div>
        </div>
        <div class="w-11 h-11 bg-slate-50 rounded-xl flex items-center justify-center text-slate-600 border border-slate-100">
            <i data-lucide="truck" class="w-5 h-5"></i>
        </div>
    </div>

    <div class="bg-white p-5 rounded-xl border border-slate-200/80 shadow-sm flex items-center justify-between">
        <div>
            <span class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Active Vendors</span>
            <div class="text-2xl font-bold text-emerald-600 mt-1"><?= number_format($activeSuppliers) ?></div>
            <div class="text-[11px] text-slate-500 mt-0.5">Approved for purchase orders</div>
        </div>
        <div class="w-11 h-11 bg-emerald-50 rounded-xl flex items-center justify-center text-emerald-600 border border-emerald-100">
            <i data-lucide="check-circle" class="w-5 h-5"></i>
        </div>
    </div>

    <div class="bg-white p-5 rounded-xl border border-slate-200/80 shadow-sm flex items-center justify-between">
        <div>
            <span class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Total Payable Balance</span>
            <div class="text-2xl font-bold text-rose-600 mt-1">USD <?= number_format($totalPayable, 2) ?></div>
            <div class="text-[11px] text-slate-500 mt-0.5">Outstanding on supplier bills</div>
        </div>
        <div class="w-11 h-11 bg-rose-50 rounded-xl flex items-center justify-center text-rose-600 border border-rose-100">
            <i data-lucide="receipt" class="w-5 h-5"></i>
        </div>
    </div>
</div>

<!-- Supplier Data Table Card -->
<div class="bg-white rounded-xl border border-slate-200/80 shadow-sm overflow-hidden">
    <div class="p-4 sm:p-5 border-b border-slate-100 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
        <div>
            <h2 class="text-sm font-bold text-slate-900">Vendors & Suppliers</h2>
            <p class="text-xs text-slate-500">Live search with instant ledger balance calculations</p>
        </div>
    </div>

    <div class="p-4 sm:p-6 overflow-x-auto">
        <table id="suppliersTable" class="w-full text-left text-xs text-slate-700">
            <thead>
                <tr class="border-b border-slate-200 text-slate-500 text-[11px] uppercase tracking-wider font-semibold">
                    <th class="py-3 px-3">Supplier Name</th>
                    <th class="py-3 px-3">Contact Phone</th>
                    <th class="py-3 px-3">Email Address</th>
                    <th class="py-3 px-3">Address</th>
                    <th class="py-3 px-3 text-right">Payable Balance</th>
                    <th class="py-3 px-3 text-center">Status</th>
                    <th class="py-3 px-3 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <?php foreach ($suppliers as $s): ?>
                    <tr class="hover:bg-slate-50/80 transition-colors">
                            <td class="py-3.5 px-3 font-semibold text-slate-900">
                                <div class="flex items-center gap-2.5">
                                    <div class="w-8 h-8 rounded-lg bg-brand-50 border border-brand-100 flex items-center justify-center text-brand-700 font-bold text-xs uppercase">
                                        <?= esc(substr($s['name'] ?? 'S', 0, 2)) ?>
                                    </div>
                                    <div>
                                        <div class="font-bold text-slate-900"><?= esc($s['name']) ?></div>
                                        <div class="text-[10px] text-slate-400">ID: SUP-<?= esc($s['id']) ?></div>
                                    </div>
                                </div>
                            </td>
                            <td class="py-3.5 px-3 font-medium text-slate-700">
                                <?= esc($s['phone'] ?: '—') ?>
                            </td>
                            <td class="py-3.5 px-3 text-slate-500">
                                <?= esc($s['email'] ?: '—') ?>
                            </td>
                            <td class="py-3.5 px-3 text-slate-500 max-w-xs truncate">
                                <?= esc($s['address'] ?: '—') ?>
                            </td>
                            <td class="py-3.5 px-3 text-right font-bold <?= ((float)($s['balance'] ?? 0) > 0) ? 'text-rose-600' : 'text-slate-700' ?>">
                                USD <?= number_format((float) ($s['balance'] ?? 0), 2) ?>
                            </td>
                            <td class="py-3.5 px-3 text-center">
                                <?php if (!empty($s['is_active'])): ?>
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200">
                                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span> Active
                                    </span>
                                <?php else: ?>
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-semibold bg-slate-100 text-slate-600 border border-slate-200">
                                        <span class="w-1.5 h-1.5 rounded-full bg-slate-400"></span> Inactive
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="py-3.5 px-3 text-right">
                                <div class="inline-flex items-center gap-1">
                                    <button type="button" onclick='viewStatement(<?= (int) $s['id'] ?>, "<?= esc(addslashes($s['name'])) ?>")'
                                            class="p-1.5 text-slate-500 hover:text-brand-600 hover:bg-slate-100 rounded-lg transition-colors"
                                            title="View Account Statement">
                                        <i data-lucide="file-text" class="w-4 h-4"></i>
                                    </button>
                                    <button type="button" onclick='openEditSupplierModal(<?= json_encode($s) ?>)'
                                            class="p-1.5 text-slate-500 hover:text-brand-600 hover:bg-slate-100 rounded-lg transition-colors"
                                            title="Edit Supplier">
                                        <i data-lucide="edit-3" class="w-4 h-4"></i>
                                    </button>
                                    <button type="button" onclick="toggleSupplierStatus(<?= (int) $s['id'] ?>, <?= !empty($s['is_active']) ? 0 : 1 ?>)"
                                            class="p-1.5 <?= !empty($s['is_active']) ? 'text-slate-400 hover:text-rose-600 hover:bg-rose-50' : 'text-slate-400 hover:text-emerald-600 hover:bg-emerald-50' ?> rounded-lg transition-colors"
                                            title="<?= !empty($s['is_active']) ? 'Deactivate' : 'Activate' ?>">
                                        <i data-lucide="<?= !empty($s['is_active']) ? 'power-off' : 'check' ?>" class="w-4 h-4"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal: Add New Supplier -->
<div id="modal-add-supplier" class="fixed inset-0 z-50 bg-slate-900/40 backdrop-blur-sm hidden flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl shadow-2xl border border-slate-200 w-full max-w-md overflow-hidden animate-in fade-in zoom-in-95 duration-150">
        <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
            <div class="flex items-center gap-2">
                <div class="w-8 h-8 rounded-lg bg-brand-50 text-brand-600 flex items-center justify-center">
                    <i data-lucide="truck" class="w-4 h-4"></i>
                </div>
                <h3 class="text-sm font-bold text-slate-900">Add New Supplier</h3>
            </div>
            <button onclick="closeModal('modal-add-supplier')" class="text-slate-400 hover:text-slate-600">
                <i data-lucide="x" class="w-4 h-4"></i>
            </button>
        </div>

        <form id="form-add-supplier" onsubmit="submitAddSupplier(event)" class="p-6 space-y-4">
            <div>
                <label class="block text-xs font-semibold text-slate-700 mb-1">Company / Supplier Name *</label>
                <input type="text" id="add-supplier-name" required placeholder="e.g. Al-Baraka Imports Ltd"
                       class="w-full px-3 py-2 text-xs border border-slate-300 rounded-lg focus:outline-none focus:border-brand-600 focus:ring-1 focus:ring-brand-600">
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-semibold text-slate-700 mb-1">Phone Number</label>
                    <input type="text" id="add-supplier-phone" placeholder="+252 61..."
                           class="w-full px-3 py-2 text-xs border border-slate-300 rounded-lg focus:outline-none focus:border-brand-600 focus:ring-1 focus:ring-brand-600">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-700 mb-1">Email Address</label>
                    <input type="email" id="add-supplier-email" placeholder="vendor@example.com"
                           class="w-full px-3 py-2 text-xs border border-slate-300 rounded-lg focus:outline-none focus:border-brand-600 focus:ring-1 focus:ring-brand-600">
                </div>
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-700 mb-1">Physical Address / Office</label>
                <textarea id="add-supplier-address" rows="2" placeholder="Street, City, Country"
                          class="w-full px-3 py-2 text-xs border border-slate-300 rounded-lg focus:outline-none focus:border-brand-600 focus:ring-1 focus:ring-brand-600"></textarea>
            </div>

            <div class="pt-3 border-t border-slate-100 flex items-center justify-end gap-2">
                <button type="button" onclick="closeModal('modal-add-supplier')"
                        class="px-3.5 py-2 text-xs font-semibold text-slate-600 hover:bg-slate-100 rounded-lg transition-colors">
                    Cancel
                </button>
                <button type="submit" id="btn-save-supplier"
                        class="px-4 py-2 bg-brand-600 hover:bg-brand-700 active:scale-95 text-white text-xs font-semibold rounded-lg shadow-sm transition-all">
                    Save Supplier
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Edit Supplier -->
<div id="modal-edit-supplier" class="fixed inset-0 z-50 bg-slate-900/40 backdrop-blur-sm hidden flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl shadow-2xl border border-slate-200 w-full max-w-md overflow-hidden animate-in fade-in zoom-in-95 duration-150">
        <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
            <div class="flex items-center gap-2">
                <div class="w-8 h-8 rounded-lg bg-brand-50 text-brand-600 flex items-center justify-center">
                    <i data-lucide="edit-3" class="w-4 h-4"></i>
                </div>
                <h3 class="text-sm font-bold text-slate-900">Edit Supplier</h3>
            </div>
            <button onclick="closeModal('modal-edit-supplier')" class="text-slate-400 hover:text-slate-600">
                <i data-lucide="x" class="w-4 h-4"></i>
            </button>
        </div>

        <form id="form-edit-supplier" onsubmit="submitEditSupplier(event)" class="p-6 space-y-4">
            <input type="hidden" id="edit-supplier-id">
            
            <div>
                <label class="block text-xs font-semibold text-slate-700 mb-1">Company / Supplier Name *</label>
                <input type="text" id="edit-supplier-name" required
                       class="w-full px-3 py-2 text-xs border border-slate-300 rounded-lg focus:outline-none focus:border-brand-600 focus:ring-1 focus:ring-brand-600">
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-semibold text-slate-700 mb-1">Phone Number</label>
                    <input type="text" id="edit-supplier-phone"
                           class="w-full px-3 py-2 text-xs border border-slate-300 rounded-lg focus:outline-none focus:border-brand-600 focus:ring-1 focus:ring-brand-600">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-700 mb-1">Email Address</label>
                    <input type="email" id="edit-supplier-email"
                           class="w-full px-3 py-2 text-xs border border-slate-300 rounded-lg focus:outline-none focus:border-brand-600 focus:ring-1 focus:ring-brand-600">
                </div>
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-700 mb-1">Physical Address</label>
                <textarea id="edit-supplier-address" rows="2"
                          class="w-full px-3 py-2 text-xs border border-slate-300 rounded-lg focus:outline-none focus:border-brand-600 focus:ring-1 focus:ring-brand-600"></textarea>
            </div>

            <div class="pt-3 border-t border-slate-100 flex items-center justify-end gap-2">
                <button type="button" onclick="closeModal('modal-edit-supplier')"
                        class="px-3.5 py-2 text-xs font-semibold text-slate-600 hover:bg-slate-100 rounded-lg transition-colors">
                    Cancel
                </button>
                <button type="submit" id="btn-update-supplier"
                        class="px-4 py-2 bg-brand-600 hover:bg-brand-700 active:scale-95 text-white text-xs font-semibold rounded-lg shadow-sm transition-all">
                    Update Details
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Supplier Statement Drawer -->
<div id="modal-statement" class="fixed inset-0 z-50 bg-slate-900/40 backdrop-blur-sm hidden flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl shadow-2xl border border-slate-200 w-full max-w-3xl overflow-hidden max-h-[90vh] flex flex-col">
        <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
            <div class="flex items-center gap-2">
                <div class="w-8 h-8 rounded-lg bg-emerald-50 text-emerald-600 flex items-center justify-center">
                    <i data-lucide="file-text" class="w-4 h-4"></i>
                </div>
                <div>
                    <h3 class="text-sm font-bold text-slate-900" id="statement-supplier-title">Supplier Account Statement</h3>
                    <p class="text-[11px] text-slate-500">Live transaction ledger & running balance</p>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <button type="button" onclick="window.print()" 
                        class="px-2.5 py-1.5 text-xs text-slate-600 hover:bg-slate-100 border border-slate-200 rounded-lg transition-colors flex items-center gap-1">
                    <i data-lucide="printer" class="w-3.5 h-3.5"></i>
                    <span>Print</span>
                </button>
                <button onclick="closeModal('modal-statement')" class="text-slate-400 hover:text-slate-600">
                    <i data-lucide="x" class="w-4 h-4"></i>
                </button>
            </div>
        </div>

        <div class="p-6 overflow-y-auto flex-1 space-y-4">
            <!-- Statement Header Summary -->
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 bg-slate-50 p-4 rounded-xl border border-slate-100 text-xs">
                <div>
                    <span class="text-[10px] text-slate-400 font-semibold uppercase">Opening Balance</span>
                    <div class="font-bold text-slate-800 text-sm mt-0.5" id="stmt-opening">USD 0.00</div>
                </div>
                <div>
                    <span class="text-[10px] text-slate-400 font-semibold uppercase">Total Billed</span>
                    <div class="font-bold text-rose-600 text-sm mt-0.5" id="stmt-billed">USD 0.00</div>
                </div>
                <div>
                    <span class="text-[10px] text-slate-400 font-semibold uppercase">Current Closing Balance</span>
                    <div class="font-bold text-slate-900 text-sm mt-0.5" id="stmt-closing">USD 0.00</div>
                </div>
            </div>

            <!-- Statement Transactions Table -->
            <div class="overflow-x-auto border border-slate-200 rounded-xl">
                <table class="w-full text-left text-xs">
                    <thead class="bg-slate-50 border-b border-slate-200 text-slate-500 text-[10px] uppercase font-semibold">
                        <tr>
                            <th class="py-2.5 px-3">Date</th>
                            <th class="py-2.5 px-3">Type</th>
                            <th class="py-2.5 px-3">Reference</th>
                            <th class="py-2.5 px-3 text-right">Debit (Paid)</th>
                            <th class="py-2.5 px-3 text-right">Credit (Billed)</th>
                            <th class="py-2.5 px-3 text-right">Balance</th>
                        </tr>
                    </thead>
                    <tbody id="stmt-tbody" class="divide-y divide-slate-100 text-slate-700">
                        <tr>
                            <td colspan="6" class="py-6 text-center text-slate-400">Loading statement lines...</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
    let suppliersDt = null;

    $(document).ready(function() {
        suppliersDt = erp.initDataTable('#suppliersTable', {
            order: [[0, 'asc']],
            pageLength: 10
        });
    });

    function openModal(id) {
        document.getElementById(id).classList.remove('hidden');
    }

    function closeModal(id) {
        document.getElementById(id).classList.add('hidden');
    }

    function openAddSupplierModal() {
        document.getElementById('form-add-supplier').reset();
        openModal('modal-add-supplier');
    }

    function openEditSupplierModal(data) {
        document.getElementById('edit-supplier-id').value = data.id;
        document.getElementById('edit-supplier-name').value = data.name || '';
        document.getElementById('edit-supplier-phone').value = data.phone || '';
        document.getElementById('edit-supplier-email').value = data.email || '';
        document.getElementById('edit-supplier-address').value = data.address || '';
        openModal('modal-edit-supplier');
    }

    // SPA AJAX: Submit New Supplier
    async function submitAddSupplier(e) {
        e.preventDefault();
        const btn = document.getElementById('btn-save-supplier');
        btn.disabled = true;
        btn.innerHTML = `<i data-lucide="loader" class="w-4 h-4 animate-spin"></i> Saving...`;
        lucide.createIcons();

        const payload = {
            name: document.getElementById('add-supplier-name').value.trim(),
            phone: document.getElementById('add-supplier-phone').value.trim() || null,
            email: document.getElementById('add-supplier-email').value.trim() || null,
            address: document.getElementById('add-supplier-address').value.trim() || null,
        };

        try {
            await erp.api('suppliers', {
                method: 'POST',
                body: JSON.stringify(payload)
            });
            erp.toast('Supplier created successfully!');
            closeModal('modal-add-supplier');
            setTimeout(() => location.reload(), 500);
        } catch (err) {
            // Handled by erp.api
        } finally {
            btn.disabled = false;
            btn.textContent = 'Save Supplier';
        }
    }

    // SPA AJAX: Submit Edit Supplier
    async function submitEditSupplier(e) {
        e.preventDefault();
        const id = document.getElementById('edit-supplier-id').value;
        const btn = document.getElementById('btn-update-supplier');
        btn.disabled = true;
        btn.innerHTML = `<i data-lucide="loader" class="w-4 h-4 animate-spin"></i> Updating...`;
        lucide.createIcons();

        const payload = {
            name: document.getElementById('edit-supplier-name').value.trim(),
            phone: document.getElementById('edit-supplier-phone').value.trim() || null,
            email: document.getElementById('edit-supplier-email').value.trim() || null,
            address: document.getElementById('edit-supplier-address').value.trim() || null,
        };

        try {
            await erp.api(`suppliers/${id}`, {
                method: 'PUT',
                body: JSON.stringify(payload)
            });
            erp.toast('Supplier updated successfully!');
            closeModal('modal-edit-supplier');
            setTimeout(() => location.reload(), 500);
        } catch (err) {
            // Handled by erp.api
        } finally {
            btn.disabled = false;
            btn.textContent = 'Update Details';
        }
    }

    // SPA AJAX: 1-click Activate/Deactivate
    async function toggleSupplierStatus(id, newStatus) {
        const action = newStatus === 1 ? 'activate' : 'deactivate';
        if (!await erp.confirm(`Are you sure you want to ${action} this supplier?`, {
            title: newStatus === 1 ? 'Activate supplier?' : 'Deactivate supplier?',
            confirmButtonText: newStatus === 1 ? 'Activate' : 'Deactivate',
            destructive: newStatus !== 1,
        })) return;

        try {
            await erp.api(`suppliers/${id}`, {
                method: 'PUT',
                body: JSON.stringify({ is_active: newStatus })
            });
            erp.toast(`Supplier ${action}d successfully!`);
            setTimeout(() => location.reload(), 400);
        } catch (err) {
            // Handled by erp.api
        }
    }

    function escapeStatementText(value) {
        const element = document.createElement('span');
        element.textContent = String(value ?? '');
        return element.innerHTML.replaceAll('"', '&quot;');
    }
    // SPA AJAX: View Statement
    async function viewStatement(supplierId, supplierName) {
        document.getElementById('statement-supplier-title').textContent = `${supplierName} — Account Statement`;
        openModal('modal-statement');
        const tbody = document.getElementById('stmt-tbody');
        tbody.innerHTML = `<tr><td colspan="6" class="py-6 text-center text-slate-400">Loading ledger...</td></tr>`;

        try {
            const data = (await erp.web(<?= json_encode(site_url('suppliers')) ?> + `/${supplierId}/statement`)).data;
            document.getElementById('stmt-opening').textContent = 'USD 0.00';
            document.getElementById('stmt-billed').textContent = `USD ${parseFloat(data.summary?.total_debit || 0).toFixed(2)}`;
            document.getElementById('stmt-closing').textContent = `USD ${parseFloat(data.summary?.closing_balance || 0).toFixed(2)}`;

            const lines = data.entries || data.lines || [];
            if (lines.length === 0) {
                tbody.innerHTML = `<tr><td colspan="6" class="py-6 text-center text-slate-400">No transactions recorded yet for this supplier.</td></tr>`;
            } else {
                tbody.innerHTML = lines.map(line => `
                    <tr class="hover:bg-slate-50/60">
                        <td class="py-2.5 px-3 font-medium">${line.date || '—'}</td>
                        <td class="py-2.5 px-3">
                            <span class="px-2 py-0.5 rounded text-[10px] font-semibold ${line.type === 'bill' ? 'bg-amber-50 text-amber-700 border border-amber-200' : 'bg-emerald-50 text-emerald-700 border border-emerald-200'}">
                                ${line.type ? line.type.toUpperCase() : 'ENTRY'}
                            </span>
                        </td>
                        <td class="py-2.5 px-3 font-mono text-[11px] text-slate-600">${line.purchase_url ? `<a class="text-brand-600 hover:underline" href="${escapeStatementText(line.purchase_url)}">${escapeStatementText(line.ref || '—')}</a>` : escapeStatementText(line.ref || '—')}</td>
                        <td class="py-2.5 px-3 text-right font-semibold text-emerald-600">${line.credit > 0 ? ('$' + parseFloat(line.credit).toFixed(2)) : '—'}</td>
                        <td class="py-2.5 px-3 text-right font-semibold text-rose-600">${line.debit > 0 ? ('$' + parseFloat(line.debit).toFixed(2)) : '—'}</td>
                        <td class="py-2.5 px-3 text-right font-bold text-slate-800">$${parseFloat(line.balance || 0).toFixed(2)}</td>
                    </tr>
                `).join('');
            }
        } catch (err) {
            tbody.innerHTML = `<tr><td colspan="6" class="py-6 text-center text-rose-500">Failed to load statement.</td></tr>`;
        }
    }
</script>
<?= $this->endSection() ?>
