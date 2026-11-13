<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>

<!-- Settings Page Header -->
<div class="mb-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
    <div>
        <h1 class="text-2xl sm:text-3xl font-bold text-slate-900 tracking-tight">
            Store Settings
        </h1>
        <p class="text-xs sm:text-sm text-slate-500 mt-1">
            Manage your store business profile, billing preferences, receipt branding, and audit trail.
        </p>
    </div>

    <div class="flex items-center gap-2">
        <button type="button" onclick="saveAllSettings()" id="save-settings-btn"
                class="inline-flex items-center gap-1.5 px-4 py-2 bg-brand-600 hover:bg-brand-700 active:scale-95 text-white text-xs font-semibold rounded-lg shadow-sm transition-all">
            <i data-lucide="save" class="w-4 h-4"></i>
            <span>Save Changes</span>
        </button>
    </div>
</div>

<!-- Tabs Navigation -->
<div class="mb-6 border-b border-slate-200">
    <nav class="erp-settings-tabs flex gap-6 text-xs font-semibold" aria-label="Tabs">
        <button type="button" onclick="switchTab('profile')" id="tab-btn-profile"
                class="tab-btn pb-3 border-b-2 border-brand-600 text-brand-600 transition-colors flex items-center gap-2">
            <i data-lucide="store" class="w-4 h-4"></i>
            <span>Business Profile</span>
        </button>
        <button type="button" onclick="switchTab('receipt')" id="tab-btn-receipt"
                class="tab-btn pb-3 border-b-2 border-transparent text-slate-500 hover:text-slate-900 transition-colors flex items-center gap-2">
            <i data-lucide="receipt" class="w-4 h-4"></i>
            <span>Receipt & Tax</span>
        </button>
        <button type="button" onclick="switchTab('features')" id="tab-btn-features"
                class="tab-btn pb-3 border-b-2 border-transparent text-slate-500 hover:text-slate-900 transition-colors flex items-center gap-2">
            <i data-lucide="sliders" class="w-4 h-4"></i>
            <span>Operational Controls</span>
        </button>
        <a href="<?= site_url('audit') ?>"
           class="tab-btn pb-3 border-b-2 border-transparent text-slate-500 hover:text-brand-600 transition-colors flex items-center gap-1.5">
            <i data-lucide="shield-check" class="w-4 h-4"></i>
            <span>Full Audit Trail</span>
            <i data-lucide="external-link" class="w-3 h-3 text-slate-400"></i>
        </a>
    </nav>
</div>

<!-- TAB 1: Business Profile -->
<div id="tab-content-profile" class="tab-pane space-y-6">
    <div class="bg-white rounded-2xl border border-slate-200/80 p-6 sm:p-8 shadow-2xs">
        <h3 class="text-base font-bold text-slate-900 mb-1">Store Information</h3>
        <p class="text-xs text-slate-500 mb-6">General information displayed across customer receipts, bills, and statements.</p>

        <form id="profile-form" class="grid grid-cols-1 sm:grid-cols-2 gap-5 text-xs">
            <div>
                <label class="block font-semibold text-slate-700 mb-1.5">Business name</label>
                <input type="text" id="company_name" name="company_name" 
                       value="<?= esc($businessName) ?>" readonly aria-describedby="operationalBusinessNameHelp"
                       class="w-full px-3 py-2 text-xs border border-slate-200 rounded-lg focus:outline-none focus:border-brand-600 focus:ring-1 focus:ring-brand-600">
                <p id="operationalBusinessNameHelp" class="text-xs text-slate-500 mt-2">Change the business name in <a href="<?= site_url('settings') ?>" class="text-brand-600">Settings → Business details</a>.</p>
            </div>

            <div>
                <label class="block font-semibold text-slate-700 mb-1.5">Business Industry / Type</label>
                <select id="business_type" name="business_type"
                        class="w-full px-3 py-2 text-xs border border-slate-200 rounded-lg focus:outline-none focus:border-brand-600 focus:ring-1 focus:ring-brand-600">
                    <option value="cafe" <?= ($settings['business_type'] ?? '') === 'cafe' ? 'selected' : '' ?>>Café / Restaurant</option>
                    <option value="shop" <?= ($settings['business_type'] ?? '') === 'shop' ? 'selected' : '' ?>>Retail Shop / Supermarket</option>
                    <option value="pharmacy" <?= ($settings['business_type'] ?? '') === 'pharmacy' ? 'selected' : '' ?>>Pharmacy / Clinic</option>
                    <option value="electronics" <?= ($settings['business_type'] ?? '') === 'electronics' ? 'selected' : '' ?>>Electronics / Hardware</option>
                    <option value="clothing" <?= ($settings['business_type'] ?? '') === 'clothing' ? 'selected' : '' ?>>Clothing & Boutique</option>
                </select>
            </div>

            <div>
                <label class="block font-semibold text-slate-700 mb-1.5">Phone Number</label>
                <input type="text" id="phone" name="phone" 
                       value="<?= esc($branch['phone'] ?? '+252 612 345678') ?>"
                       class="w-full px-3 py-2 text-xs border border-slate-200 rounded-lg focus:outline-none focus:border-brand-600 focus:ring-1 focus:ring-brand-600">
            </div>

            <div>
                <label class="block font-semibold text-slate-700 mb-1.5">Operating Currency</label>
                <select id="currency" name="currency"
                        class="w-full px-3 py-2 text-xs border border-slate-200 rounded-lg focus:outline-none focus:border-brand-600 focus:ring-1 focus:ring-brand-600">
                    <option value="USD" <?= ($settings['currency'] ?? 'USD') === 'USD' ? 'selected' : '' ?>>USD ($) — US Dollar</option>
                    <option value="SOS" <?= ($settings['currency'] ?? '') === 'SOS' ? 'selected' : '' ?>>SOS (Sh.So) — Somali Shilling</option>
                    <option value="EUR" <?= ($settings['currency'] ?? '') === 'EUR' ? 'selected' : '' ?>>EUR (€) — Euro</option>
                    <option value="KES" <?= ($settings['currency'] ?? '') === 'KES' ? 'selected' : '' ?>>KES (KSh) — Kenyan Shilling</option>
                </select>
            </div>

            <div class="sm:col-span-2">
                <label class="block font-semibold text-slate-700 mb-1.5">Physical Address / Location</label>
                <input type="text" id="address" name="address" 
                       value="<?= esc($branch['address'] ?? 'Mogadishu, Somalia') ?>"
                       class="w-full px-3 py-2 text-xs border border-slate-200 rounded-lg focus:outline-none focus:border-brand-600 focus:ring-1 focus:ring-brand-600">
            </div>
        </form>
    </div>
</div>

<!-- TAB 2: Receipt & Tax -->
<div id="tab-content-receipt" class="tab-pane space-y-6 hidden">
    <div class="bg-white rounded-2xl border border-slate-200/80 p-6 sm:p-8 shadow-2xs">
        <h3 class="text-base font-bold text-slate-900 mb-1">Receipt Printing & Tax Rate</h3>
        <p class="text-xs text-slate-500 mb-6">Customize the header, footer note, and sales tax displayed on 80mm thermal receipts.</p>

        <form id="receipt-form" class="space-y-5 text-xs max-w-2xl">
            <div>
                <label class="block font-semibold text-slate-700 mb-1.5">Default Sales Tax Rate (%)</label>
                <div class="relative w-48">
                    <input type="number" step="0.01" id="tax_rate" name="tax_rate" 
                           value="<?= esc($settings['tax_rate'] ?? '0.00') ?>"
                           class="w-full px-3 py-2 text-xs border border-slate-200 rounded-lg focus:outline-none focus:border-brand-600 focus:ring-1 focus:ring-brand-600 pr-8">
                    <span class="absolute inset-y-0 right-3 flex items-center text-slate-400 font-bold">%</span>
                </div>
                <p class="text-[11px] text-slate-400 mt-1">Set to 0 if tax is already inclusive or exempted.</p>
            </div>

            <div>
                <label class="block font-semibold text-slate-700 mb-1.5">Receipt Top Greeting / Header</label>
                <input type="text" id="receipt_header" name="receipt_header" 
                       value="<?= esc($settings['receipt_header'] ?? 'Thank you for your visit!') ?>"
                       class="w-full px-3 py-2 text-xs border border-slate-200 rounded-lg focus:outline-none focus:border-brand-600 focus:ring-1 focus:ring-brand-600">
            </div>

            <div>
                <label class="block font-semibold text-slate-700 mb-1.5">Receipt Bottom Message / Footer</label>
                <textarea id="receipt_footer" name="receipt_footer" rows="2"
                          class="w-full px-3 py-2 text-xs border border-slate-200 rounded-lg focus:outline-none focus:border-brand-600 focus:ring-1 focus:ring-brand-600"><?= esc($settings['receipt_footer'] ?? "Goods once sold are not returnable without receipt.\nPlease come again!") ?></textarea>
            </div>

            <!-- ESC/POS Thermal Printer Hardware Integration -->
            <div class="pt-4 border-t border-slate-200">
                <div class="flex items-center justify-between mb-3">
                    <div>
                        <h4 class="font-bold text-slate-900 flex items-center gap-2">
                            <span>ESC/POS Hardware Thermal Printer</span>
                            <span class="text-[10px] px-1.5 py-0.2 bg-emerald-50 text-emerald-700 border border-emerald-200 rounded font-semibold">mike42/escpos-php</span>
                        </h4>
                        <p class="text-[11px] text-slate-500">Directly print tickets, kick cash drawers, and auto-cut paper without browser dialogs.</p>
                    </div>
                    <button type="button" onclick="testThermalPrinter()" id="test-printer-btn"
                            class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-lg text-xs font-semibold transition-colors">
                        <i data-lucide="zap" class="w-3.5 h-3.5 text-amber-500"></i>
                        <span>Test Print Slip</span>
                    </button>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 bg-slate-50 p-4 rounded-xl border border-slate-200">
                    <div>
                        <label class="block font-semibold text-slate-700 mb-1">Printer Connection Type</label>
                        <select id="thermal_printer_type" onchange="togglePrinterFields()"
                                class="w-full px-3 py-2 text-xs bg-white border border-slate-200 rounded-lg focus:outline-none focus:border-brand-600">
                            <option value="windows" <?= ($settings['thermal_printer_type'] ?? 'windows') === 'windows' ? 'selected' : '' ?>>Windows Shared / USB (Local PC)</option>
                            <option value="network" <?= ($settings['thermal_printer_type'] ?? '') === 'network' ? 'selected' : '' ?>>Network / Wi-Fi Printer (Ethernet IP)</option>
                            <option value="dummy" <?= ($settings['thermal_printer_type'] ?? '') === 'dummy' ? 'selected' : '' ?>>Simulation Mode (Raw ESC/POS Stream)</option>
                        </select>
                    </div>

                    <div id="field-printer-name">
                        <label class="block font-semibold text-slate-700 mb-1">Windows Printer Share Name</label>
                        <input type="text" id="thermal_printer_name" 
                               value="<?= esc($settings['thermal_printer_name'] ?? 'POS80') ?>"
                               placeholder="e.g. POS80 or ReceiptPrinter"
                               class="w-full px-3 py-2 text-xs bg-white border border-slate-200 rounded-lg focus:outline-none focus:border-brand-600">
                    </div>

                    <div id="field-printer-ip" class="hidden">
                        <label class="block font-semibold text-slate-700 mb-1">Printer IP Address & Port</label>
                        <div class="flex gap-2">
                            <input type="text" id="thermal_printer_ip" 
                                   value="<?= esc($settings['thermal_printer_ip'] ?? '192.168.1.100') ?>"
                                   placeholder="192.168.1.100"
                                   class="flex-1 px-3 py-2 text-xs bg-white border border-slate-200 rounded-lg focus:outline-none focus:border-brand-600">
                            <input type="number" id="thermal_printer_port" 
                                   value="<?= esc($settings['thermal_printer_port'] ?? '9100') ?>"
                                   placeholder="9100"
                                   class="w-20 px-3 py-2 text-xs bg-white border border-slate-200 rounded-lg focus:outline-none focus:border-brand-600">
                        </div>
                    </div>

                    <div>
                        <label class="block font-semibold text-slate-700 mb-1">Thermal Paper Roll Width</label>
                        <select id="thermal_paper_width" 
                                class="w-full px-3 py-2 text-xs bg-white border border-slate-200 rounded-lg focus:outline-none focus:border-brand-600">
                            <option value="80" <?= ($settings['thermal_paper_width'] ?? '80') == '80' ? 'selected' : '' ?>>80mm Standard (48 columns)</option>
                            <option value="58" <?= ($settings['thermal_paper_width'] ?? '') == '58' ? 'selected' : '' ?>>58mm Compact (32 columns)</option>
                        </select>
                    </div>

                    <div class="sm:col-span-2 flex flex-wrap gap-6 pt-1">
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="checkbox" id="thermal_auto_drawer" <?= ($settings['thermal_auto_drawer'] ?? '1') == '1' ? 'checked' : '' ?>
                                   class="w-4 h-4 rounded text-brand-600 focus:ring-brand-500 border-slate-300">
                            <span class="text-slate-700 font-medium">Auto Kick Cash Drawer (Pulse)</span>
                        </label>

                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="checkbox" id="thermal_auto_cut" <?= ($settings['thermal_auto_cut'] ?? '1') == '1' ? 'checked' : '' ?>
                                   class="w-4 h-4 rounded text-brand-600 focus:ring-brand-500 border-slate-300">
                            <span class="text-slate-700 font-medium">Auto Paper Cut</span>
                        </label>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- TAB 3: Operational Controls -->
<div id="tab-content-features" class="tab-pane space-y-6 hidden">
    <div class="bg-white rounded-2xl border border-slate-200/80 p-6 sm:p-8 shadow-2xs">
        <h3 class="text-base font-bold text-slate-900 mb-1">Module Feature Toggles</h3>
        <p class="text-xs text-slate-500 mb-6">Enable or disable specialized inventory tracking features according to your business needs.</p>

        <div class="divide-y divide-slate-100 text-xs">
            <div class="py-4 flex items-center justify-between">
                <div>
                    <h4 class="font-bold text-slate-900">Batch & Expiry Date Tracking</h4>
                    <p class="text-[11px] text-slate-500">Track medicine batches, manufacture and expiration dates on product stock.</p>
                </div>
                <label class="relative inline-flex items-center cursor-pointer">
                    <input type="checkbox" id="uses_batches" name="uses_batches" value="1" 
                           class="sr-only peer" <?= ($settings['uses_batches'] ?? '0') === '1' ? 'checked' : '' ?>>
                    <div class="w-11 h-6 bg-slate-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-brand-600"></div>
                </label>
            </div>

            <div class="py-4 flex items-center justify-between">
                <div>
                    <h4 class="font-bold text-slate-900">Serial & IMEI Number Tracking</h4>
                    <p class="text-[11px] text-slate-500">Track unique individual serial numbers and device warranty records per unit sold.</p>
                </div>
                <label class="relative inline-flex items-center cursor-pointer">
                    <input type="checkbox" id="uses_serials" name="uses_serials" value="1" 
                           class="sr-only peer" <?= ($settings['uses_serials'] ?? '0') === '1' ? 'checked' : '' ?>>
                    <div class="w-11 h-6 bg-slate-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-brand-600"></div>
                </label>
            </div>
        </div>
    </div>
</div>

<!-- TAB 4: Audit Trail Log (Equipped with DataTable) -->
<div id="tab-content-audit" class="tab-pane space-y-6 hidden">
    <div class="bg-white rounded-2xl border border-slate-200/80 p-6 sm:p-8 shadow-2xs">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-6">
            <div>
                <h3 class="text-base font-bold text-slate-900 mb-0.5">Audit Trail Log</h3>
                <p class="text-xs text-slate-500">Complete, tamper-evident security record of every financial and administrative action.</p>
            </div>
            <div class="flex items-center gap-2">
                <span class="text-[11px] px-2.5 py-1 bg-blue-50 text-brand-700 font-semibold rounded-full border border-blue-200">
                    Live Trail Records
                </span>
            </div>
        </div>

        <!-- DataTable -->
        <div class="overflow-x-auto">
            <table id="auditTable" class="w-full text-left text-xs stripe hover">
                <thead class="bg-slate-50 text-slate-600 font-semibold border-b border-slate-200">
                    <tr>
                        <th class="py-3 px-3">Timestamp</th>
                        <th class="py-3 px-3">Actor / User</th>
                        <th class="py-3 px-3">Action</th>
                        <th class="py-3 px-3">Module</th>
                        <th class="py-3 px-3">Target Entity</th>
                        <th class="py-3 px-3">IP Address</th>
                        <th class="py-3 px-3">Details</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 text-slate-700">
                    <?php foreach ($auditLogs as $log): ?>
                        <tr class="hover:bg-slate-50/80 transition-colors">
                            <td class="py-3 px-3 text-slate-500 whitespace-nowrap">
                                <?= esc($log['created_at']) ?>
                            </td>
                            <td class="py-3 px-3 font-semibold text-slate-900">
                                <?= esc($log['user_name'] ?? 'System') ?>
                                <span class="block text-[10px] text-slate-400 font-normal"><?= esc($log['role'] ?? '') ?></span>
                            </td>
                            <td class="py-3 px-3">
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-mono font-medium bg-slate-100 text-slate-800 border border-slate-200">
                                    <?= esc($log['action']) ?>
                                </span>
                            </td>
                            <td class="py-3 px-3 text-slate-600 capitalize">
                                <?= esc($log['module']) ?>
                            </td>
                            <td class="py-3 px-3 text-slate-500">
                                <?= esc($log['entity']) ?> #<?= esc($log['entity_id'] ?? '—') ?>
                            </td>
                            <td class="py-3 px-3 font-mono text-[11px] text-slate-400">
                                <?= esc($log['ip_address'] ?? '127.0.0.1') ?>
                            </td>
                            <td class="py-3 px-3 max-w-xs truncate text-[11px] text-slate-500">
                                <?= esc($log['details'] ?: ($log['after_values'] ? 'Values updated' : 'Recorded')) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
    // Tab Switcher
    function switchTab(tab) {
        document.querySelectorAll('.tab-pane').forEach(el => el.classList.add('hidden'));
        document.querySelectorAll('.tab-btn').forEach(el => {
            el.classList.remove('border-brand-600', 'text-brand-600');
            el.classList.add('border-transparent', 'text-slate-500');
        });

        const targetPane = document.getElementById('tab-content-' + tab);
        const targetBtn = document.getElementById('tab-btn-' + tab);
        if (targetPane && targetBtn) {
            targetPane.classList.remove('hidden');
            targetBtn.classList.remove('border-transparent', 'text-slate-500');
            targetBtn.classList.add('border-brand-600', 'text-brand-600');
        }

        // Auto adjust datatable columns on audit tab
        if (tab === 'audit' && $.fn.DataTable.isDataTable('#auditTable')) {
            setTimeout(() => {
                $('#auditTable').DataTable().columns.adjust().responsive.recalc();
            }, 50);
        }
    }

    // Initialize Audit DataTable with fast search and pagination
    $(document).ready(function() {
        erp.initDataTable('#auditTable', {
            order: [[0, 'desc']],
            pageLength: 10
        });

        // Check query param for default tab
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('tab') === 'audit') {
            switchTab('audit');
        }
    });

    function togglePrinterFields() {
        const type = document.getElementById('thermal_printer_type').value;
        const nameField = document.getElementById('field-printer-name');
        const ipField = document.getElementById('field-printer-ip');

        if (type === 'network') {
            nameField.classList.add('hidden');
            ipField.classList.remove('hidden');
        } else if (type === 'dummy') {
            nameField.classList.add('hidden');
            ipField.classList.add('hidden');
        } else {
            nameField.classList.remove('hidden');
            ipField.classList.add('hidden');
        }
    }

    async function testThermalPrinter() {
        const btn = document.getElementById('test-printer-btn');
        btn.disabled = true;
        btn.innerHTML = `<i data-lucide="loader" class="w-3.5 h-3.5 animate-spin"></i> Testing...`;
        lucide.createIcons();

        try {
            const res = {data: await window.erpPrinting.test()};
            if (res.data && res.data.success) {
                erp.toast('✓ ' + res.data.message);
            } else {
                erp.toast(res.data.error || 'Test failed', 'error');
            }
        } catch (err) {
            erp.toast(err.message || 'Thermal printer test failed', 'error');
        } finally {
            btn.disabled = false;
            btn.innerHTML = `<i data-lucide="zap" class="w-3.5 h-3.5 text-amber-500"></i> Test Print Slip`;
            lucide.createIcons();
        }
    }

    // Save All Settings via AJAX (SPA rule)
    async function saveAllSettings() {
        const btn = document.getElementById('save-settings-btn');
        btn.disabled = true;
        btn.innerHTML = `<i data-lucide="loader" class="w-4 h-4 animate-spin"></i> Saving...`;
        lucide.createIcons();

        const payload = {
            business_type: document.getElementById('business_type').value,
            phone: document.getElementById('phone').value.trim(),
            currency: document.getElementById('currency').value,
            address: document.getElementById('address').value.trim(),
            tax_rate: document.getElementById('tax_rate').value.trim(),
            receipt_header: document.getElementById('receipt_header').value.trim(),
            receipt_footer: document.getElementById('receipt_footer').value.trim(),
            uses_batches: document.getElementById('uses_batches').checked ? '1' : '0',
            uses_serials: document.getElementById('uses_serials').checked ? '1' : '0',
            thermal_printer_type: document.getElementById('thermal_printer_type').value,
            thermal_printer_name: document.getElementById('thermal_printer_name').value.trim(),
            thermal_printer_ip: document.getElementById('thermal_printer_ip').value.trim(),
            thermal_printer_port: document.getElementById('thermal_printer_port').value.trim(),
            thermal_paper_width: document.getElementById('thermal_paper_width').value,
            thermal_auto_drawer: document.getElementById('thermal_auto_drawer').checked ? '1' : '0',
            thermal_auto_cut: document.getElementById('thermal_auto_cut').checked ? '1' : '0',
        };

        try {
            await erp.api('settings', {
                method: 'POST',
                body: JSON.stringify(payload)
            });
            erp.toast('Settings updated successfully!');
        } catch (err) {
            // Handled by erp.api
        } finally {
            btn.disabled = false;
            btn.innerHTML = `<i data-lucide="save" class="w-4 h-4"></i> Save Changes`;
            lucide.createIcons();
        }
    }
</script>
<?= $this->endSection() ?>
