<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>

<!-- Page Header & Action Bar -->
<div class="mb-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
    <div>
        <div class="erp-page-title flex flex-wrap items-center gap-2">
            <h1 class="text-2xl sm:text-3xl font-bold text-slate-900 tracking-tight">
                Customers
            </h1>
            <span class="px-2.5 py-0.5 rounded-full text-xs font-semibold bg-brand-50 text-brand-700 border border-brand-200">
                Customers
            </span>
        </div>
        <p class="text-xs sm:text-sm text-slate-500 mt-1">
            Maintain customer records, manage store credit accounts, and track customer transaction ledgers.
        </p>
    </div>

    <div class="erp-page-actions flex flex-wrap items-center gap-2.5">
        <a href="<?= site_url('pos') ?>" 
           class="inline-flex items-center gap-1.5 px-3.5 py-2 bg-emerald-600 hover:bg-emerald-700 active:scale-95 text-white text-xs font-semibold rounded-lg shadow-sm transition-all">
            <i data-lucide="shopping-bag" class="w-4 h-4"></i>
            <span>Open POS Terminal</span>
        </a>
        <?= view('web/partials/workbook_import', ['importKind' => 'customers']) ?>
        <button type="button" onclick="openAddCustomerModal()" 
                class="inline-flex items-center gap-1.5 px-4 py-2 bg-brand-600 hover:bg-brand-700 active:scale-95 text-white text-xs font-semibold rounded-lg shadow-sm transition-all">
            <i data-lucide="user-plus" class="w-4 h-4"></i>
            <span>Add New Customer</span>
        </button>
    </div>
</div>

<!-- 3 KPI Summary Cards -->
<div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
    <div class="bg-white p-5 rounded-xl border border-slate-200/80 shadow-sm flex items-center justify-between">
        <div>
            <span class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Total Customers</span>
            <div class="text-2xl font-bold text-slate-900 mt-1"><?= number_format($totalCustomers) ?></div>
            <div class="text-[11px] text-slate-500 mt-0.5">Active customer profiles</div>
        </div>
        <div class="w-11 h-11 bg-slate-50 rounded-xl flex items-center justify-center text-slate-600 border border-slate-100">
            <i data-lucide="users" class="w-5 h-5"></i>
        </div>
    </div>

    <div class="bg-white p-5 rounded-xl border border-slate-200/80 shadow-sm flex items-center justify-between">
        <div>
            <span class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Total Receivables (Owed)</span>
            <div class="text-2xl font-bold text-rose-600 mt-1">USD <?= number_format($totalReceivable, 2) ?></div>
            <div class="text-[11px] text-slate-500 mt-0.5">Unpaid credit sales & invoices</div>
        </div>
        <div class="w-11 h-11 bg-rose-50 rounded-xl flex items-center justify-center text-rose-600 border border-rose-100">
            <i data-lucide="file-text" class="w-5 h-5"></i>
        </div>
    </div>

    <div class="bg-white p-5 rounded-xl border border-slate-200/80 shadow-sm flex items-center justify-between">
        <div>
            <span class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Credit Accounts</span>
            <div class="text-2xl font-bold text-amber-600 mt-1"><?= number_format($debtorsCount) ?></div>
            <div class="text-[11px] text-slate-500 mt-0.5">Customers with pending balance</div>
        </div>
        <div class="w-11 h-11 bg-amber-50 rounded-xl flex items-center justify-center text-amber-600 border border-amber-100">
            <i data-lucide="clock" class="w-5 h-5"></i>
        </div>
    </div>
</div>

<!-- Customers Data Table Card -->
<div class="bg-white rounded-xl border border-slate-200/80 shadow-sm overflow-hidden">
    <div class="p-4 sm:p-5 border-b border-slate-100 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
        <div>
            <h2 class="text-sm font-bold text-slate-900">Customers</h2>
            <p class="text-xs text-slate-500">Fast customer lookup, account statements, and contact details</p>
        </div>
    </div>

    <div class="p-4 sm:p-6 overflow-x-auto">
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-4">
            <label class="text-xs font-semibold">Account balance<select id="customerBalanceFilter" class="block mt-2 w-full border p-2.5 rounded-lg"><option value="">All balances</option><option value="owing">Owes store</option><option value="credit">Store credit</option><option value="settled">Settled</option></select></label>
            <label class="text-xs font-semibold">Phone number<select id="customerPhoneFilter" class="block mt-2 w-full border p-2.5 rounded-lg"><option value="">All customers</option><option value="1">Has phone</option><option value="0">Missing phone</option></select></label>
        </div>
        <table id="customersTable" class="w-full text-left text-xs text-slate-700">
            <thead>
                <tr class="border-b border-slate-200 text-slate-500 text-[11px] uppercase tracking-wider font-semibold">
                    <th class="py-3 px-3">Customer Name</th>
                    <th class="py-3 px-3">Phone Number</th>
                    <th class="py-3 px-3">Email Address</th>
                    <th class="py-3 px-3">Address / Location</th>
                    <th class="py-3 px-3 text-right">Account Balance</th>
                    <th class="py-3 px-3 text-right">Credit Limit</th>
                    <th class="py-3 px-3 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <?php foreach ($customers as $c): ?>
                    <?php 
                        $bal = (float) ($c['balance'] ?? 0);
                        $isDebtor = $bal < 0; // negative = owes store
                        $isCreditor = $bal > 0; // positive = advance deposit
                    ?>
                    <tr data-balance="<?= (float) $c['balance'] ?>" data-phone="<?= empty($c['phone']) ? '0' : '1' ?>" class="hover:bg-slate-50/80 transition-colors">
                        <td class="py-3.5 px-3 font-semibold text-slate-900">
                            <div class="flex items-center gap-2.5">
                                <div class="w-8 h-8 rounded-lg bg-brand-50 border border-brand-100 flex items-center justify-center text-brand-700 font-bold text-xs uppercase">
                                    <?= esc(substr($c['name'] ?? 'C', 0, 2)) ?>
                                </div>
                                <div>
                                    <div class="font-bold text-slate-900"><?= esc($c['name']) ?></div>
                                    <div class="text-[10px] text-slate-400">ID: CUST-<?= esc($c['id']) ?></div>
                                </div>
                            </div>
                        </td>
                        <td class="py-3.5 px-3 font-medium text-slate-700">
                            <?= esc($c['phone'] ?: '—') ?>
                        </td>
                        <td class="py-3.5 px-3 text-slate-500">
                            <?= esc($c['email'] ?: '—') ?>
                        </td>
                        <td class="py-3.5 px-3 text-slate-500 max-w-xs truncate">
                            <?= esc($c['address'] ?: '—') ?>
                        </td>
                        <td class="py-3.5 px-3 text-right font-bold <?= $isDebtor ? 'text-rose-600' : ($isCreditor ? 'text-emerald-600' : 'text-slate-600') ?>">
                            <?php if ($isDebtor): ?>
                                -USD <?= number_format(abs($bal), 2) ?>
                                <span class="block text-[10px] font-normal text-rose-500">Owes store</span>
                            <?php elseif ($isCreditor): ?>
                                +USD <?= number_format($bal, 2) ?>
                                <span class="block text-[10px] font-normal text-emerald-500">Deposit credit</span>
                            <?php else: ?>
                                USD 0.00
                            <?php endif; ?>
                        </td>
                        <td class="py-3.5 px-3 text-right whitespace-nowrap">
                            <div class="font-semibold">USD <?= number_format($c['credit_limit'], 2) ?></div>
                            <div class="text-[11px] text-slate-500">Unpaid: <?= number_format($c['outstanding'], 2) ?></div>
                            <div class="text-[11px] <?= $c['available_credit'] > 0 ? 'text-emerald-600' : 'text-amber-700' ?>">Available: <?= number_format($c['available_credit'], 2) ?></div>
                        </td>
                        <td class="py-3.5 px-3 text-right">
                            <div class="inline-flex items-center gap-1">
                                <a href="<?= site_url('clients/' . (int) $c['id'] . '/statement') ?>"
                                        class="p-1.5 text-slate-500 hover:text-brand-600 hover:bg-slate-100 rounded-lg transition-colors"
                                        title="View Account Statement">
                                    <i data-lucide="file-text" class="w-4 h-4"></i>
                                </a>
                                <button type="button" onclick='openEditCustomerModal(<?= esc(json_encode($c), 'attr') ?>)'
                                        class="p-1.5 text-slate-500 hover:text-brand-600 hover:bg-slate-100 rounded-lg transition-colors"
                                        title="Edit Customer Details">
                                    <i data-lucide="edit-3" class="w-4 h-4"></i>
                                </button>
                                <?php if ($c['id'] != 1): // Don't delete Walk-in customer ?>
                                    <button type="button" onclick="deleteCustomer(<?= (int) $c['id'] ?>, '<?= esc(addslashes($c['name'])) ?>')"
                                            class="p-1.5 text-slate-400 hover:text-rose-600 hover:bg-rose-50 rounded-lg transition-colors"
                                            title="Delete Customer">
                                        <i data-lucide="trash-2" class="w-4 h-4"></i>
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

<!-- Modal: Add New Customer -->
<div id="modal-add-customer" class="fixed inset-0 z-50 bg-slate-900/40 backdrop-blur-sm hidden flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl shadow-2xl border border-slate-200 w-full max-w-md overflow-hidden animate-in fade-in zoom-in-95 duration-150">
        <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
            <div class="flex items-center gap-2">
                <div class="w-8 h-8 rounded-lg bg-brand-50 text-brand-600 flex items-center justify-center">
                    <i data-lucide="user-plus" class="w-4 h-4"></i>
                </div>
                <h3 class="text-sm font-bold text-slate-900">Add New Customer</h3>
            </div>
            <button onclick="closeModal('modal-add-customer')" class="text-slate-400 hover:text-slate-600">
                <i data-lucide="x" class="w-4 h-4"></i>
            </button>
        </div>

        <form id="form-add-customer" onsubmit="submitAddCustomer(event)" class="p-6 space-y-4">
            <div>
                <label class="block text-xs font-semibold text-slate-700 mb-1">Full Name *</label>
                <input type="text" id="add-cust-name" required placeholder="e.g. Hassan Abdi"
                       class="w-full px-3 py-2 text-xs border border-slate-300 rounded-lg focus:outline-none focus:border-brand-600">
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-semibold text-slate-700 mb-1">Phone Number</label>
                    <input type="text" id="add-cust-phone" placeholder="+252 61..."
                           class="w-full px-3 py-2 text-xs border border-slate-300 rounded-lg focus:outline-none focus:border-brand-600">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-700 mb-1">Email Address</label>
                    <input type="email" id="add-cust-email" placeholder="customer@example.com"
                           class="w-full px-3 py-2 text-xs border border-slate-300 rounded-lg focus:outline-none focus:border-brand-600">
                </div>
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-700 mb-1">Physical Address / Notes</label>
                <textarea id="add-cust-address" rows="2" placeholder="Delivery address or neighborhood"
                          class="w-full px-3 py-2 text-xs border border-slate-300 rounded-lg focus:outline-none focus:border-brand-600"></textarea>
            </div>

            <?= view('partials/customer_credit_field', ['creditFieldId' => 'add-cust-credit-limit', 'canManageCredit' => $canManageCredit]) ?>
            <div class="pt-3 border-t border-slate-100 flex items-center justify-end gap-2">
                <button type="button" onclick="closeModal('modal-add-customer')"
                        class="px-3.5 py-2 text-xs font-semibold text-slate-600 hover:bg-slate-100 rounded-lg transition-colors">
                    Cancel
                </button>
                <button type="submit" id="btn-save-customer"
                        class="px-4 py-2 bg-brand-600 hover:bg-brand-700 active:scale-95 text-white text-xs font-semibold rounded-lg shadow-sm transition-all">
                    Save Customer
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Edit Customer -->
<div id="modal-edit-customer" class="fixed inset-0 z-50 bg-slate-900/40 backdrop-blur-sm hidden flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl shadow-2xl border border-slate-200 w-full max-w-md overflow-hidden animate-in fade-in zoom-in-95 duration-150">
        <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
            <div class="flex items-center gap-2">
                <div class="w-8 h-8 rounded-lg bg-brand-50 text-brand-600 flex items-center justify-center">
                    <i data-lucide="edit-3" class="w-4 h-4"></i>
                </div>
                <h3 class="text-sm font-bold text-slate-900">Edit Customer Details</h3>
            </div>
            <button onclick="closeModal('modal-edit-customer')" class="text-slate-400 hover:text-slate-600">
                <i data-lucide="x" class="w-4 h-4"></i>
            </button>
        </div>

        <form id="form-edit-customer" onsubmit="submitEditCustomer(event)" class="p-6 space-y-4">
            <input type="hidden" id="edit-cust-id">
            <?= view('partials/customer_credit_field', ['creditFieldId' => 'edit-cust-credit-limit', 'canManageCredit' => $canManageCredit]) ?>
            
            <div>
                <label class="block text-xs font-semibold text-slate-700 mb-1">Full Name *</label>
                <input type="text" id="edit-cust-name" required
                       class="w-full px-3 py-2 text-xs border border-slate-300 rounded-lg focus:outline-none focus:border-brand-600">
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-semibold text-slate-700 mb-1">Phone Number</label>
                    <input type="text" id="edit-cust-phone"
                           class="w-full px-3 py-2 text-xs border border-slate-300 rounded-lg focus:outline-none focus:border-brand-600">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-700 mb-1">Email Address</label>
                    <input type="email" id="edit-cust-email"
                           class="w-full px-3 py-2 text-xs border border-slate-300 rounded-lg focus:outline-none focus:border-brand-600">
                </div>
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-700 mb-1">Address / Notes</label>
                <textarea id="edit-cust-address" rows="2"
                          class="w-full px-3 py-2 text-xs border border-slate-300 rounded-lg focus:outline-none focus:border-brand-600"></textarea>
            </div>

            <div class="pt-3 border-t border-slate-100 flex items-center justify-end gap-2">
                <button type="button" onclick="closeModal('modal-edit-customer')"
                        class="px-3.5 py-2 text-xs font-semibold text-slate-600 hover:bg-slate-100 rounded-lg transition-colors">
                    Cancel
                </button>
                <button type="submit" id="btn-update-customer"
                        class="px-4 py-2 bg-brand-600 hover:bg-brand-700 active:scale-95 text-white text-xs font-semibold rounded-lg shadow-sm transition-all">
                    Update Details
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Customer Statement Drawer -->
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<style>
#customersTable_wrapper .dataTables_filter { float: none; width: 100%; margin: 1rem 0; text-align: left; }
#customersTable_wrapper .dataTables_filter label { display: block; width: 100%; }
#customersTable_wrapper .dataTables_filter input { box-sizing: border-box; width: 100%; margin: 0; }
</style>
<script>
    const canManageCustomerCredit = <?= $canManageCredit ? 'true' : 'false' ?>;
    async function saveCustomerProfile(url, payload) {
        const response = await fetch(url, {method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'}, body: JSON.stringify(payload)});
        const result = await response.json();
        if (!response.ok || !result.success) throw new Error(result.message || 'Customer could not be saved. Please try again.');
        return result.data;
    }
    let customersDt = null;
    $.fn.dataTable.ext.search.push((settings, _row, index) => {
        if (settings.nTable.id !== 'customersTable') return true;
        const row = settings.aoData[index].nTr.dataset;
        const balance = document.getElementById('customerBalanceFilter').value;
        const phone = document.getElementById('customerPhoneFilter').value;
        return (!balance || (balance === 'owing' && Number(row.balance) < 0) || (balance === 'credit' && Number(row.balance) > 0) || (balance === 'settled' && Number(row.balance) === 0)) && (!phone || row.phone === phone);
    });
    ['customerBalanceFilter', 'customerPhoneFilter'].forEach(id => document.getElementById(id).addEventListener('change', () => customersDt?.draw()));

    $(document).ready(function() {
        customersDt = erp.initDataTable('#customersTable', {
            order: [[0, 'asc']],
            responsive: false,
            pageLength: 10
        });
    });

    function openModal(id) {
        document.getElementById(id).classList.remove('hidden');
    }

    function closeModal(id) {
        document.getElementById(id).classList.add('hidden');
    }

    function openAddCustomerModal() {
        document.getElementById('form-add-customer').reset();
        openModal('modal-add-customer');
    }

    function openEditCustomerModal(data) {
        document.getElementById('edit-cust-id').value = data.id;
        document.getElementById('edit-cust-name').value = data.name || '';
        document.getElementById('edit-cust-phone').value = data.phone || '';
        document.getElementById('edit-cust-email').value = data.email || '';
        document.getElementById('edit-cust-address').value = data.address || '';
        const limitField = document.getElementById('edit-cust-credit-limit');
        if (canManageCustomerCredit) limitField.value = Number(data.credit_limit || 0).toFixed(2);
        else limitField.textContent = Number(data.credit_limit || 0).toFixed(2);
        openModal('modal-edit-customer');
    }

    // SPA AJAX: Submit New Customer
    async function submitAddCustomer(e) {
        e.preventDefault();
        const btn = document.getElementById('btn-save-customer');
        btn.disabled = true;
        btn.innerHTML = `<i data-lucide="loader" class="w-4 h-4 animate-spin"></i> Saving...`;
        lucide.createIcons();

        const payload = {
            name: document.getElementById('add-cust-name').value.trim(),
            phone: document.getElementById('add-cust-phone').value.trim() || null,
            email: document.getElementById('add-cust-email').value.trim() || null,
            address: document.getElementById('add-cust-address').value.trim() || null,
        };

        try {
            if (canManageCustomerCredit) payload.credit_limit = document.getElementById('add-cust-credit-limit').value;
            await saveCustomerProfile(<?= json_encode(site_url('clients/create')) ?>, payload);
            erp.toast('Customer created successfully!');
            closeModal('modal-add-customer');
            setTimeout(() => location.reload(), 500);
        } catch (err) {
            erp.toast(err.message || 'Customer could not be saved.', 'error');
        } finally {
            btn.disabled = false;
            btn.textContent = 'Save Customer';
        }
    }

    // SPA AJAX: Submit Edit Customer
    async function submitEditCustomer(e) {
        e.preventDefault();
        const id = document.getElementById('edit-cust-id').value;
        const btn = document.getElementById('btn-update-customer');
        btn.disabled = true;
        btn.innerHTML = `<i data-lucide="loader" class="w-4 h-4 animate-spin"></i> Updating...`;
        lucide.createIcons();

        const payload = {
            name: document.getElementById('edit-cust-name').value.trim(),
            phone: document.getElementById('edit-cust-phone').value.trim() || null,
            email: document.getElementById('edit-cust-email').value.trim() || null,
            address: document.getElementById('edit-cust-address').value.trim() || null,
        };

        try {
            if (canManageCustomerCredit) payload.credit_limit = document.getElementById('edit-cust-credit-limit').value;
            await saveCustomerProfile(<?= json_encode(rtrim(site_url('clients'), '/')) ?> + `/${id}/save`, payload);
            erp.toast('Customer updated successfully!');
            closeModal('modal-edit-customer');
            setTimeout(() => location.reload(), 500);
        } catch (err) {
            erp.toast(err.message || 'Customer could not be saved.', 'error');
        } finally {
            btn.disabled = false;
            btn.textContent = 'Update Details';
        }
    }

    // SPA AJAX: Delete Customer
    async function deleteCustomer(id, name) {
        if (!await erp.confirm(`Delete customer "${name}"?`, { title: 'Delete customer?', destructive: true })) return;

        try {
            await erp.api(`customers/${id}`, {
                method: 'DELETE'
            });
            erp.toast('Customer deleted.');
            setTimeout(() => location.reload(), 400);
        } catch (err) {
            // Handled by erp.api
        }
    }

</script>
<?= $this->endSection() ?>
