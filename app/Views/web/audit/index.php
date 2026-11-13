<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>

<!-- Page Header & Action Bar -->
<div class="mb-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
    <div>
        <div class="flex items-center gap-2">
            <h1 class="text-2xl sm:text-3xl font-bold text-slate-900 tracking-tight">
                Audit Trail & Security Log
            </h1>
            <span class="px-2.5 py-0.5 rounded-full text-xs font-semibold bg-indigo-50 text-indigo-700 border border-indigo-200">
                Security Ledger
            </span>
        </div>
        <p class="text-xs sm:text-sm text-slate-500 mt-1">
            Complete, immutable audit trail of every login event, staff creation, permission edit, financial transaction, and system update.
        </p>
    </div>

    <div class="flex items-center gap-2.5">
        <button type="button" onclick="exportAuditCSV()"
                class="inline-flex items-center gap-1.5 px-3.5 py-2 bg-white hover:bg-slate-50 text-slate-700 border border-slate-200 text-xs font-semibold rounded-lg shadow-sm transition-all">
            <i data-lucide="download" class="w-4 h-4 text-slate-500"></i>
            <span>Export CSV</span>
        </button>
        <button type="button" onclick="window.print()"
                class="inline-flex items-center gap-1.5 px-3.5 py-2 bg-white hover:bg-slate-50 text-slate-700 border border-slate-200 text-xs font-semibold rounded-lg shadow-sm transition-all">
            <i data-lucide="printer" class="w-4 h-4 text-slate-500"></i>
            <span>Print Log</span>
        </button>
        <button type="button" onclick="location.reload()"
                class="inline-flex items-center gap-1.5 px-3.5 py-2 bg-brand-600 hover:bg-brand-700 active:scale-95 text-white text-xs font-semibold rounded-lg shadow-sm transition-all">
            <i data-lucide="refresh-cw" class="w-4 h-4"></i>
            <span>Refresh Trail</span>
        </button>
    </div>
</div>

<!-- 4 KPI Summary Cards -->
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
    <div class="bg-white p-5 rounded-xl border border-slate-200/80 shadow-sm flex items-center justify-between">
        <div>
            <span class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Total Trail Records</span>
            <div class="text-2xl font-bold text-slate-900 mt-1"><?= number_format($totalLogs) ?></div>
            <div class="text-[11px] text-slate-500 mt-0.5">Immutable logged operations</div>
        </div>
        <div class="w-11 h-11 bg-slate-50 rounded-xl flex items-center justify-center text-slate-600 border border-slate-100">
            <i data-lucide="shield-check" class="w-5 h-5"></i>
        </div>
    </div>

    <div class="bg-white p-5 rounded-xl border border-slate-200/80 shadow-sm flex items-center justify-between">
        <div>
            <span class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Today's Activity</span>
            <div class="text-2xl font-bold text-brand-600 mt-1"><?= number_format($todayCount) ?></div>
            <div class="text-[11px] text-slate-500 mt-0.5">Events logged since 00:00</div>
        </div>
        <div class="w-11 h-11 bg-brand-50 rounded-xl flex items-center justify-center text-brand-600 border border-brand-100">
            <i data-lucide="clock" class="w-5 h-5"></i>
        </div>
    </div>

    <div class="bg-white p-5 rounded-xl border border-slate-200/80 shadow-sm flex items-center justify-between">
        <div>
            <span class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Auth & Security Events</span>
            <div class="text-2xl font-bold text-purple-600 mt-1"><?= number_format($authCount) ?></div>
            <div class="text-[11px] text-slate-500 mt-0.5">Logins, attempts & sessions</div>
        </div>
        <div class="w-11 h-11 bg-purple-50 rounded-xl flex items-center justify-center text-purple-600 border border-purple-100">
            <i data-lucide="key" class="w-5 h-5"></i>
        </div>
    </div>

    <div class="bg-white p-5 rounded-xl border border-slate-200/80 shadow-sm flex items-center justify-between">
        <div>
            <span class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Administrative Changes</span>
            <div class="text-2xl font-bold text-emerald-600 mt-1"><?= number_format($adminChangesCount) ?></div>
            <div class="text-[11px] text-slate-500 mt-0.5">Creates, updates & deletions</div>
        </div>
        <div class="w-11 h-11 bg-emerald-50 rounded-xl flex items-center justify-center text-emerald-600 border border-emerald-100">
            <i data-lucide="edit-3" class="w-5 h-5"></i>
        </div>
    </div>
</div>

<!-- 4 Core Filter Controls Bar -->
<div class="bg-white rounded-xl border border-slate-200/80 shadow-sm p-4 sm:p-5 mb-6">
    <div class="flex items-center justify-between mb-3 pb-3 border-b border-slate-100">
        <div class="flex items-center gap-2">
            <i data-lucide="filter" class="w-4 h-4 text-brand-600"></i>
            <span class="text-xs font-bold text-slate-800 uppercase tracking-wider">Audit Filter Controls</span>
        </div>
        <div class="flex items-center gap-3">
            <span id="filter-counter" class="text-xs text-slate-500 font-medium">Showing all <?= count($auditLogs) ?> records</span>
            <button type="button" onclick="resetAuditFilters()" 
                    class="text-xs text-brand-600 hover:text-brand-800 font-semibold transition-colors flex items-center gap-1">
                <i data-lucide="rotate-ccw" class="w-3.5 h-3.5"></i>
                <span>Reset Filters</span>
            </button>
        </div>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <!-- Filter 1: Timeframe / Date Range -->
        <div>
            <label class="block text-[11px] font-bold text-slate-700 uppercase tracking-wider mb-1.5 flex items-center gap-1.5">
                <i data-lucide="calendar" class="w-3.5 h-3.5 text-slate-400"></i>
                <span>1. Timeframe / Date</span>
            </label>
            <select id="filter-timeframe" onchange="applyFilters()"
                    class="w-full px-3 py-2 text-xs border border-slate-300 rounded-lg bg-white focus:outline-none focus:border-brand-600 font-medium text-slate-800">
                <option value="all">All Time</option>
                <option value="today">Today</option>
                <option value="yesterday">Yesterday</option>
                <option value="7days">Last 7 Days</option>
                <option value="30days">Last 30 Days</option>
                <option value="custom">Custom Date Range...</option>
            </select>
            <div id="custom-date-container" class="hidden mt-2 grid grid-cols-2 gap-2">
                <input type="date" id="filter-date-from" onchange="applyFilters()" class="px-2 py-1 text-xs border border-slate-300 rounded-lg">
                <input type="date" id="filter-date-to" onchange="applyFilters()" class="px-2 py-1 text-xs border border-slate-300 rounded-lg">
            </div>
        </div>

        <!-- Filter 2: Actor / Staff Member -->
        <div>
            <label class="block text-[11px] font-bold text-slate-700 uppercase tracking-wider mb-1.5 flex items-center gap-1.5">
                <i data-lucide="user" class="w-3.5 h-3.5 text-slate-400"></i>
                <span>2. Actor / Staff Member</span>
            </label>
            <select id="filter-actor" onchange="applyFilters()"
                    class="w-full px-3 py-2 text-xs border border-slate-300 rounded-lg bg-white focus:outline-none focus:border-brand-600 font-medium text-slate-800">
                <option value="all">All Staff Members & System</option>
                <?php foreach ($actors as $actor): ?>
                    <option value="<?= esc($actor['name']) ?>">
                        <?= esc($actor['name']) ?> (<?= esc($actor['role_name'] ?: 'Staff') ?>)
                    </option>
                <?php endforeach; ?>
                <option value="System / Guest">System / Guest Login</option>
            </select>
        </div>

        <!-- Filter 3: Module / Domain -->
        <div>
            <label class="block text-[11px] font-bold text-slate-700 uppercase tracking-wider mb-1.5 flex items-center gap-1.5">
                <i data-lucide="layout-grid" class="w-3.5 h-3.5 text-slate-400"></i>
                <span>3. Business Module</span>
            </label>
            <select id="filter-module" onchange="applyFilters()"
                    class="w-full px-3 py-2 text-xs border border-slate-300 rounded-lg bg-white focus:outline-none focus:border-brand-600 font-medium text-slate-800">
                <option value="all">All Business Modules</option>
                <option value="auth">Authentication & Security (auth)</option>
                <option value="users">User Management (users)</option>
                <option value="roles">Roles & Permissions (roles)</option>
                <option value="inventory">Inventory & Products (inventory)</option>
                <option value="purchases">Procurement & Orders (purchases)</option>
                <option value="suppliers">Supplier Accounts (suppliers)</option>
                <option value="sales">Sales & POS Terminal (sales)</option>
                <option value="payables">Accounts Payable (payables)</option>
                <option value="receivables">Accounts Receivable (receivables)</option>
                <option value="finance">General Ledger & Finance (finance)</option>
                <option value="settings">Store Settings (settings)</option>
            </select>
        </div>

        <!-- Filter 4: Action Severity & Type -->
        <div>
            <label class="block text-[11px] font-bold text-slate-700 uppercase tracking-wider mb-1.5 flex items-center gap-1.5">
                <i data-lucide="tag" class="w-3.5 h-3.5 text-slate-400"></i>
                <span>4. Event / Action Type</span>
            </label>
            <select id="filter-action" onchange="applyFilters()"
                    class="w-full px-3 py-2 text-xs border border-slate-300 rounded-lg bg-white focus:outline-none focus:border-brand-600 font-medium text-slate-800">
                <option value="all">All Event Types</option>
                <option value="auth.">Logins & Security Events</option>
                <option value=".create">Records Created (New)</option>
                <option value=".update">Modifications & Updates</option>
                <option value=".delete">Deletions & Destructive Actions</option>
                <option value=".void">Voids & Reversals</option>
                <option value="adjust">Stock & Financial Adjustments</option>
            </select>
        </div>
    </div>
</div>

<!-- Audit Trail Data Table Card -->
<div class="bg-white rounded-xl border border-slate-200/80 shadow-sm overflow-hidden">
    <div class="p-4 sm:p-5 border-b border-slate-100 flex items-center justify-between">
        <div>
            <h2 class="text-sm font-bold text-slate-900">Security Audit Trail Ledger</h2>
            <p class="text-xs text-slate-500">Chronological timestamped transactions with IP tracking and snapshot inspection</p>
        </div>
        <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200">
            <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
            <span>Live Audit Active</span>
        </span>
    </div>

    <div class="p-4 sm:p-6 overflow-x-auto">
        <table id="auditFullTable" class="w-full text-left text-xs text-slate-700">
            <thead>
                <tr class="border-b border-slate-200 text-slate-500 text-[11px] uppercase tracking-wider font-semibold">
                    <th class="py-3 px-3">Timestamp</th>
                    <th class="py-3 px-3">Actor / Staff</th>
                    <th class="py-3 px-3">Action Event</th>
                    <th class="py-3 px-3">Module</th>
                    <th class="py-3 px-3">Target Entity</th>
                    <th class="py-3 px-3">IP Address</th>
                    <th class="py-3 px-3">Summary / Details</th>
                    <th class="py-3 px-3 text-right">Inspect</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <?php foreach ($auditLogs as $log): ?>
                    <?php 
                        $act = (string) $log['action'];
                        $isAuth = str_starts_with($act, 'auth.');
                        $isCreate = str_contains($act, '.create');
                        $isUpdate = str_contains($act, '.update') || str_contains($act, 'settings.');
                        $isDelete = str_contains($act, '.delete') || str_contains($act, '.void');
                        $actorName = $log['user_name'] ?: 'System / Guest';
                    ?>
                        <tr class="hover:bg-slate-50/80 transition-colors cursor-pointer"
                            onclick='openInspectModal(<?= json_encode($log) ?>)'>
                            <td class="py-3 px-3 font-mono text-[11px] text-slate-500 whitespace-nowrap">
                                <?= esc($log['created_at']) ?>
                            </td>
                            <td class="py-3 px-3">
                                <div class="flex items-center gap-2">
                                    <div class="w-6 h-6 rounded-full bg-brand-50 text-brand-700 font-bold text-[10px] flex items-center justify-center uppercase">
                                        <?= esc(substr($actorName, 0, 1)) ?>
                                    </div>
                                    <div>
                                        <div class="font-semibold text-slate-900"><?= esc($actorName) ?></div>
                                        <div class="text-[10px] text-slate-400"><?= esc($log['role'] ?: ($log['user_role_name'] ?: '—')) ?></div>
                                    </div>
                                </div>
                            </td>
                            <td class="py-3 px-3">
                                <?php if ($isAuth): ?>
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-semibold bg-purple-50 text-purple-700 border border-purple-200 font-mono">
                                        <?= esc($act) ?>
                                    </span>
                                <?php elseif ($isCreate): ?>
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200 font-mono">
                                        <?= esc($act) ?>
                                    </span>
                                <?php elseif ($isDelete): ?>
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-semibold bg-rose-50 text-rose-700 border border-rose-200 font-mono">
                                        <?= esc($act) ?>
                                    </span>
                                <?php elseif ($isUpdate): ?>
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-semibold bg-blue-50 text-blue-700 border border-blue-200 font-mono">
                                        <?= esc($act) ?>
                                    </span>
                                <?php else: ?>
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-semibold bg-slate-100 text-slate-700 border border-slate-200 font-mono">
                                        <?= esc($act) ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="py-3 px-3 font-medium text-slate-600 uppercase text-[10px] tracking-wider">
                                <?= esc($log['module'] ?: 'System') ?>
                            </td>
                            <td class="py-3 px-3 font-medium text-slate-800">
                                <?= esc($log['entity'] ?: '—') ?>
                                <?php if (!empty($log['entity_id'])): ?>
                                    <span class="font-mono text-[10px] text-slate-400">#<?= esc($log['entity_id']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="py-3 px-3 font-mono text-[11px] text-slate-500 whitespace-nowrap">
                                <?= esc($log['ip_address'] ?: '127.0.0.1') ?>
                            </td>
                            <td class="py-3 px-3 max-w-xs truncate text-[11px] text-slate-600">
                                <?php if (!empty($log['details'])): ?>
                                    <?= esc($log['details']) ?>
                                <?php elseif (!empty($log['after_values'])): ?>
                                    <span class="text-blue-600 font-medium">Data snapshot updated</span>
                                <?php else: ?>
                                    <span class="text-slate-400">Operation recorded</span>
                                <?php endif; ?>
                            </td>
                            <td class="py-3 px-3 text-right">
                                <button type="button" class="p-1.5 text-slate-400 hover:text-brand-600 hover:bg-slate-100 rounded-lg transition-colors">
                                    <i data-lucide="eye" class="w-4 h-4"></i>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal: Inspect Full Audit Record & Snapshots -->
<div id="modal-inspect" class="fixed inset-0 z-50 bg-slate-900/40 backdrop-blur-sm hidden flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl shadow-2xl border border-slate-200 w-full max-w-3xl overflow-hidden max-h-[92vh] flex flex-col animate-in fade-in zoom-in-95 duration-150">
        <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
            <div class="flex items-center gap-2">
                <div class="w-8 h-8 rounded-lg bg-indigo-50 text-indigo-600 flex items-center justify-center">
                    <i data-lucide="shield-alert" class="w-4 h-4"></i>
                </div>
                <div>
                    <h3 class="text-sm font-bold text-slate-900" id="inspect-action-title">Audit Record Inspection</h3>
                    <p class="text-[11px] text-slate-500" id="inspect-timestamp">—</p>
                </div>
            </div>
            <button onclick="closeModal('modal-inspect')" class="text-slate-400 hover:text-slate-600">
                <i data-lucide="x" class="w-4 h-4"></i>
            </button>
        </div>

        <div class="p-6 overflow-y-auto flex-1 space-y-4 text-xs">
            <!-- Actor & System Metadata -->
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 bg-slate-50 p-4 rounded-xl border border-slate-100">
                <div>
                    <span class="text-[10px] text-slate-400 font-semibold uppercase">Actor / Staff</span>
                    <div class="font-bold text-slate-900 mt-0.5" id="inspect-actor">—</div>
                </div>
                <div>
                    <span class="text-[10px] text-slate-400 font-semibold uppercase">Assigned Role</span>
                    <div class="font-medium text-slate-700 mt-0.5" id="inspect-role">—</div>
                </div>
                <div>
                    <span class="text-[10px] text-slate-400 font-semibold uppercase">IP Address</span>
                    <div class="font-mono text-slate-700 mt-0.5" id="inspect-ip">—</div>
                </div>
                <div>
                    <span class="text-[10px] text-slate-400 font-semibold uppercase">Target Entity</span>
                    <div class="font-bold text-brand-600 mt-0.5" id="inspect-entity">—</div>
                </div>
            </div>

            <!-- Device User Agent -->
            <div>
                <span class="text-[10px] text-slate-400 font-semibold uppercase tracking-wider block mb-1">Client Device / User Agent</span>
                <div class="p-2.5 bg-slate-50 rounded-lg border border-slate-200 font-mono text-[10px] text-slate-600 break-all" id="inspect-ua">
                    —
                </div>
            </div>

            <!-- Before vs After Data Diff -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <span class="text-[10px] text-slate-400 font-semibold uppercase tracking-wider block mb-1">State Before Change</span>
                    <pre id="inspect-before" class="p-3 bg-slate-900 text-slate-100 rounded-xl overflow-x-auto font-mono text-[11px] max-h-56 leading-relaxed">None</pre>
                </div>
                <div>
                    <span class="text-[10px] text-slate-400 font-semibold uppercase tracking-wider block mb-1">State After Change</span>
                    <pre id="inspect-after" class="p-3 bg-slate-900 text-emerald-400 rounded-xl overflow-x-auto font-mono text-[11px] max-h-56 leading-relaxed">None</pre>
                </div>
            </div>

            <!-- Additional Details / JSON Payload -->
            <div>
                <span class="text-[10px] text-slate-400 font-semibold uppercase tracking-wider block mb-1">Additional Payload / Context</span>
                <pre id="inspect-details" class="p-3 bg-slate-50 text-slate-800 border border-slate-200 rounded-xl overflow-x-auto font-mono text-[11px] max-h-40">None</pre>
            </div>
        </div>

        <div class="px-6 py-3 border-t border-slate-100 bg-slate-50 flex items-center justify-end">
            <button type="button" onclick="closeModal('modal-inspect')"
                    class="px-4 py-2 bg-slate-900 hover:bg-slate-800 text-white text-xs font-semibold rounded-lg transition-colors">
                Close Record
            </button>
        </div>
    </div>
</div>

<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
    let auditDt = null;
    const allAuditData = <?= json_encode($auditLogs) ?>;

    $(document).ready(function() {
        auditDt = erp.initDataTable('#auditFullTable', {
            order: [[0, 'desc']], // Most recent first
            pageLength: 20
        });

        // Custom search extension for the 4 filters
        $.fn.dataTable.ext.search.push(function(settings, data, dataIndex) {
            if (settings.nTable.id !== 'auditFullTable') return true;

            const rowTimestamp = data[0] || '';
            const rowActor = (data[1] || '').toLowerCase();
            const rowAction = (data[2] || '').toLowerCase();
            const rowModule = (data[3] || '').toLowerCase();

            // 1. Timeframe Filter
            const timeframe = document.getElementById('filter-timeframe').value;
            if (timeframe !== 'all') {
                const logDate = rowTimestamp.substring(0, 10);
                const today = new Date();
                const todayStr = today.toISOString().substring(0, 10);

                if (timeframe === 'today') {
                    if (logDate !== todayStr) return false;
                } else if (timeframe === 'yesterday') {
                    const y = new Date();
                    y.setDate(today.getDate() - 1);
                    if (logDate !== y.toISOString().substring(0, 10)) return false;
                } else if (timeframe === '7days') {
                    const cutoff = new Date();
                    cutoff.setDate(today.getDate() - 7);
                    if (logDate < cutoff.toISOString().substring(0, 10)) return false;
                } else if (timeframe === '30days') {
                    const cutoff = new Date();
                    cutoff.setDate(today.getDate() - 30);
                    if (logDate < cutoff.toISOString().substring(0, 10)) return false;
                } else if (timeframe === 'custom') {
                    const from = document.getElementById('filter-date-from').value;
                    const to = document.getElementById('filter-date-to').value;
                    if (from && logDate < from) return false;
                    if (to && logDate > to) return false;
                }
            }

            // 2. Actor Filter
            const actorFilter = document.getElementById('filter-actor').value.toLowerCase();
            if (actorFilter !== 'all') {
                if (!rowActor.includes(actorFilter)) return false;
            }

            // 3. Module Filter
            const moduleFilter = document.getElementById('filter-module').value.toLowerCase();
            if (moduleFilter !== 'all') {
                if (!rowModule.includes(moduleFilter)) return false;
            }

            // 4. Action Type Filter
            const actionFilter = document.getElementById('filter-action').value.toLowerCase();
            if (actionFilter !== 'all') {
                if (!rowAction.includes(actionFilter)) return false;
            }

            return true;
        });
    });

    function applyFilters() {
        const timeframe = document.getElementById('filter-timeframe').value;
        const customContainer = document.getElementById('custom-date-container');
        if (timeframe === 'custom') {
            customContainer.classList.remove('hidden');
        } else {
            customContainer.classList.add('hidden');
        }

        if (auditDt) {
            auditDt.draw();
            const count = auditDt.rows({ filter: 'applied' }).count();
            document.getElementById('filter-counter').textContent = `Showing ${count} of ${allAuditData.length} records`;
        }
    }

    function resetAuditFilters() {
        document.getElementById('filter-timeframe').value = 'all';
        document.getElementById('filter-actor').value = 'all';
        document.getElementById('filter-module').value = 'all';
        document.getElementById('filter-action').value = 'all';
        document.getElementById('filter-date-from').value = '';
        document.getElementById('filter-date-to').value = '';
        document.getElementById('custom-date-container').classList.add('hidden');
        applyFilters();
    }

    function openModal(id) {
        document.getElementById(id).classList.remove('hidden');
    }

    function closeModal(id) {
        document.getElementById(id).classList.add('hidden');
    }

    function openInspectModal(log) {
        document.getElementById('inspect-action-title').textContent = `Action: ${log.action}`;
        document.getElementById('inspect-timestamp').textContent = `Recorded at ${log.created_at} (ID: #${log.id})`;
        document.getElementById('inspect-actor').textContent = log.user_name || 'System / Guest';
        document.getElementById('inspect-role').textContent = log.role || log.user_role_name || 'Staff';
        document.getElementById('inspect-ip').textContent = log.ip_address || '127.0.0.1';
        document.getElementById('inspect-entity').textContent = `${log.module || 'system'} / ${log.entity || 'general'}` + (log.entity_id ? ` #${log.entity_id}` : '');
        document.getElementById('inspect-ua').textContent = log.user_agent || 'Unknown client browser or device';

        // Parse and format JSON payloads
        const formatJson = (val) => {
            if (!val) return 'None recorded';
            try {
                const parsed = typeof val === 'string' ? JSON.parse(val) : val;
                return JSON.stringify(parsed, null, 2);
            } catch (e) {
                return val;
            }
        };

        document.getElementById('inspect-before').textContent = formatJson(log.before_values);
        document.getElementById('inspect-after').textContent = formatJson(log.after_values);
        document.getElementById('inspect-details').textContent = formatJson(log.details);

        openModal('modal-inspect');
    }

    function exportAuditCSV() {
        if (!allAuditData || allAuditData.length === 0) {
            erp.toast('No audit records to export.', 'error');
            return;
        }

        const headers = ['ID', 'Timestamp', 'Actor', 'Role', 'Action', 'Module', 'Entity', 'Entity ID', 'IP Address', 'Details'];
        const csvRows = [headers.join(',')];

        allAuditData.forEach(l => {
            const row = [
                l.id,
                `"${l.created_at}"`,
                `"${(l.user_name || 'System').replace(/"/g, '""')}"`,
                `"${(l.role || '').replace(/"/g, '""')}"`,
                `"${(l.action || '').replace(/"/g, '""')}"`,
                `"${(l.module || '').replace(/"/g, '""')}"`,
                `"${(l.entity || '').replace(/"/g, '""')}"`,
                l.entity_id || '',
                `"${l.ip_address || ''}"`,
                `"${(l.details || '').toString().replace(/"/g, '""')}"`
            ];
            csvRows.push(row.join(','));
        });

        const blob = new Blob([csvRows.join('\n')], { type: 'text/csv;charset=utf-8;' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `audit-trail-${new Date().toISOString().slice(0, 10)}.csv`;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
        erp.toast('Audit trail exported successfully!');
    }
</script>
<?= $this->endSection() ?>
