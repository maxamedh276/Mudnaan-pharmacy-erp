<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>

<div class="grid sm:grid-cols-2 gap-4 mb-6">
    <div class="rounded-2xl border border-brand-100 bg-brand-50 p-5"><h2 class="font-bold text-brand-800">Superadmin</h2><p class="text-sm text-slate-600 mt-2">Full system access, including Settings and Operational Settings.</p></div>
    <div class="rounded-2xl border border-slate-200 bg-white p-5"><h2 class="font-bold">Admin</h2><p class="text-sm text-slate-600 mt-2">Full business access and Settings. Only Superadmins can access Operational Settings or manage Superadmin accounts.</p></div>
</div>

<!-- Page Header with Segmented Tab Switcher -->
<div class="mb-6 flex flex-col md:flex-row md:items-center md:justify-between gap-4">
    <div>
        <div class="erp-page-title flex flex-wrap items-center gap-3">
            <h1 class="text-2xl sm:text-3xl font-bold text-slate-900 tracking-tight">
                Roles & Permissions
            </h1>
            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-blue-50 text-brand-700 border border-blue-200">
                Access Control
            </span>
        </div>
        <p class="text-xs sm:text-sm text-slate-500 mt-1">
            Manage granular backend API action privileges and customize sidebar menu visibility by Role or User.
        </p>
    </div>

    <!-- Main Navigation Tabs: 1. Action Permissions (Existing) vs 2. Sidebar Menu Permissions (New) -->
    <div class="erp-settings-tabs inline-flex p-1 bg-slate-100 rounded-xl border border-slate-200 text-xs font-semibold self-start md:self-auto shadow-2xs">
        <button type="button" id="tab-btn-roles" onclick="switchMainTab('roles')"
                class="flex items-center gap-2 px-4 py-2 rounded-lg transition-all bg-white text-slate-900 shadow-xs font-bold">
            <i data-lucide="shield-check" class="w-4 h-4 text-brand-600"></i>
            <span>Role Permissions</span>
        </button>
        <button type="button" id="tab-btn-menu" onclick="switchMainTab('menu')"
                class="flex items-center gap-2 px-4 py-2 rounded-lg transition-all text-slate-600 hover:text-slate-900">
            <i data-lucide="layout-list" class="w-4 h-4 text-slate-400"></i>
            <span>Sidebar Menu Permissions</span>
            <span class="px-1.5 py-0.2 bg-brand-100 text-brand-700 rounded-full text-[10px] font-bold">NEW</span>
        </button>
    </div>
</div>

<!-- ========================================================================= -->
<!-- TAB 1: ROLE PERMISSIONS (RBAC)                                           -->
<!-- ========================================================================= -->
<div id="tab-content-roles" class="space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h2 class="text-sm font-bold text-slate-900">Configured System & Custom Roles</h2>
            <p class="text-xs text-slate-500">Each role groups granular action permissions that govern backend CRUD capabilities.</p>
        </div>
        <button type="button" onclick="openCreateRoleModal()"
                class="inline-flex items-center gap-1.5 px-4 py-2 bg-brand-600 hover:bg-brand-700 active:scale-95 text-white text-xs font-semibold rounded-lg shadow-sm transition-all">
            <i data-lucide="plus" class="w-4 h-4"></i>
            <span>Create Custom Role</span>
        </button>
    </div>

    <!-- Roles Table Card -->
    <div class="bg-white rounded-2xl border border-slate-200/80 p-6 sm:p-8 shadow-2xs">
        <div class="overflow-x-auto">
            <table id="rolesTable" class="w-full text-left text-xs stripe hover">
                <thead class="bg-slate-50 text-slate-600 font-semibold border-b border-slate-200">
                    <tr>
                        <th class="py-3 px-3">Role Name</th>
                        <th class="py-3 px-3">Description</th>
                        <th class="py-3 px-3">Action Privileges</th>
                        <th class="py-3 px-3">Assigned Staff</th>
                        <th class="py-3 px-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 text-slate-700">
                    <?php foreach ($roles as $r): ?>
                        <?php
                        $isSystemRole = (bool)$r['is_system'];
                        $isFixedRole = \App\Libraries\AdministrativeAccess::isFixedRole($r['name']);
                        $permCount = count($r['permissions'] ?? []);
                        ?>
                        <tr class="hover:bg-slate-50/80 transition-colors">
                            <td class="py-3 px-3">
                                <div class="font-bold text-slate-900 text-sm flex items-center gap-2">
                                    <span><?= esc($r['name']) ?></span>
                                    <?php if ($isSystemRole): ?>
                                        <span class="text-[9px] px-1.5 py-0.2 bg-slate-100 text-slate-600 rounded font-semibold border border-slate-200">
                                            System Role
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td class="py-3 px-3 text-slate-500 max-w-sm">
                                <?= esc(($r['description'] ?? '') ?: ($isSystemRole ? 'Built-in system access role' : 'Custom defined store role')) ?>
                            </td>
                            <td class="py-3 px-3">
                                <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-semibold bg-blue-50 text-brand-700 border border-blue-200">
                                    <?= $permCount ?> privileges
                                </span>
                            </td>
                            <td class="py-3 px-3">
                                <div class="flex items-center gap-1.5 font-semibold text-slate-800">
                                    <i data-lucide="user" class="w-3.5 h-3.5 text-slate-400"></i>
                                    <span><?= esc($r['user_count'] ?? 0) ?> users</span>
                                </div>
                            </td>
                            <td class="py-3 px-3 text-right">
                                <div class="flex items-center justify-end gap-2">
                                    <?php if ($isFixedRole): ?><span class="text-xs text-slate-500">Fixed access</span><?php else: ?>
                                    <button type="button" onclick="editRolePermissions(<?= (int)$r['id'] ?>, '<?= esc($r['name'], 'js') ?>', <?= htmlspecialchars(json_encode($r['permissions'] ?? []), ENT_QUOTES, 'UTF-8') ?>)"
                                            class="inline-flex items-center gap-1 px-2.5 py-1 text-[11px] bg-slate-100 hover:bg-slate-200 text-slate-700 font-semibold rounded-md transition-colors">
                                        <i data-lucide="key" class="w-3 h-3 text-slate-500"></i>
                                        <span>Permissions</span>
                                    </button>
                                    <button type="button" onclick="switchToRoleMenu(<?= (int)$r['id'] ?>)"
                                            class="inline-flex items-center gap-1 px-2.5 py-1 text-[11px] bg-blue-50 hover:bg-blue-100 text-brand-700 font-semibold rounded-md transition-colors" title="Configure Sidebar Menu">
                                        <i data-lucide="layout-list" class="w-3 h-3"></i>
                                        <span>Menu Access</span>
                                    </button>
                                    <?php if (! $isSystemRole): ?>
                                        <button type="button" onclick="deleteRole(<?= (int)$r['id'] ?>, '<?= esc($r['name'], 'js') ?>')"
                                                class="p-1 text-slate-400 hover:text-rose-600 rounded transition-colors" title="Delete Role">
                                            <i data-lucide="trash-2" class="w-3.5 h-3.5"></i>
                                        </button>
                                    <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ========================================================================= -->
<!-- TAB 2: SIDEBAR MENU PERMISSIONS (BY ROLE OR BY USER)                     -->
<!-- ========================================================================= -->
<div id="tab-content-menu" class="hidden space-y-6">

    <!-- Configuration Mode Bar -->
    <div class="bg-white rounded-2xl border border-slate-200/80 p-5 shadow-2xs">
        <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4">

            <!-- Left: Toggle between By Role vs By User -->
            <div class="flex items-center gap-3">
                <span class="text-xs font-bold text-slate-700">Configure Menu For:</span>
                <div class="inline-flex p-1 bg-slate-100 rounded-lg border border-slate-200 text-xs font-semibold">
                    <button type="button" id="mode-btn-role" onclick="switchMenuMode('role')"
                            class="px-3 py-1.5 rounded-md bg-white text-slate-900 shadow-xs font-bold transition-all">
                        <i data-lucide="shield" class="w-3.5 h-3.5 inline mr-1 text-brand-600"></i>
                        <span>By Role</span>
                    </button>
                    <button type="button" id="mode-btn-user" onclick="switchMenuMode('user')"
                            class="px-3 py-1.5 rounded-md text-slate-600 hover:text-slate-900 transition-all">
                        <i data-lucide="user" class="w-3.5 h-3.5 inline mr-1 text-slate-400"></i>
                        <span>By Staff User</span>
                    </button>
                </div>
            </div>

            <!-- Middle: Dropdown Selectors -->
            <div class="flex items-center gap-3 flex-wrap">
                <!-- Role Selector (Shown when mode == 'role') -->
                <div id="roleSelectContainer" class="flex items-center gap-2">
                    <label class="text-xs font-medium text-slate-500">Select Role:</label>
                    <select id="selected_role_id" onchange="onRoleChanged()"
                            class="px-3 py-1.5 text-xs font-semibold bg-slate-50 border border-slate-300 rounded-lg focus:outline-none focus:ring-1 focus:ring-brand-600 text-slate-800">
                        <?php foreach ($roles as $r): ?>
                            <option value="<?= (int)$r['id'] ?>" data-role-name="<?= esc($r['name'],'attr') ?>" <?= $r['name'] === 'Cashier' ? 'selected' : '' ?>>
                                <?= esc($r['name']) ?> (<?= esc($r['user_count'] ?? 0) ?> users)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- User Selector (Shown when mode == 'user') -->
                <div id="userSelectContainer" class="hidden flex items-center gap-2">
                    <label class="text-xs font-medium text-slate-500">Select Staff:</label>
                    <select id="selected_user_id" onchange="onUserChanged()"
                            class="px-3 py-1.5 text-xs font-semibold bg-slate-50 border border-slate-300 rounded-lg focus:outline-none focus:ring-1 focus:ring-brand-600 text-slate-800">
                        <?php foreach ($users as $u): ?>
                            <option value="<?= (int)$u['id'] ?>" data-role-id="<?= (int)$u['role_id'] ?>" data-role-name="<?= esc($u['role'] ?? 'Staff') ?>">
                                <?= esc($u['name']) ?> (<?= esc($u['role'] ?? 'Staff') ?> - <?= esc($u['email']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- Right: Save & Quick Actions -->
            <div class="flex items-center gap-2">
                <button type="button" id="reset-user-btn" onclick="resetUserToRoleDefaults()"
                        class="hidden inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold text-amber-700 bg-amber-50 hover:bg-amber-100 border border-amber-200 rounded-lg transition-colors"
                        title="Revert custom user menus to match their role defaults">
                    <i data-lucide="rotate-ccw" class="w-3.5 h-3.5"></i>
                    <span>Reset to Role Defaults</span>
                </button>
                <button type="button" onclick="toggleAllMenuCheckboxes(true)"
                        class="px-2.5 py-1.5 text-xs font-medium text-slate-600 hover:text-slate-900 bg-slate-100 hover:bg-slate-200 rounded-lg transition-colors">
                    Select All
                </button>
                <button type="button" onclick="toggleAllMenuCheckboxes(false)"
                        class="px-2.5 py-1.5 text-xs font-medium text-slate-600 hover:text-slate-900 bg-slate-100 hover:bg-slate-200 rounded-lg transition-colors">
                    Deselect All
                </button>
                <button type="button" id="save-menu-perms-btn" onclick="saveMenuPermissions()"
                        class="inline-flex items-center gap-1.5 px-4 py-1.5 bg-brand-600 hover:bg-brand-700 active:scale-95 text-white text-xs font-bold rounded-lg shadow-sm transition-all">
                    <i data-lucide="check" class="w-4 h-4"></i>
                    <span>Save Menu Access</span>
                </button>
            </div>
        </div>

        <!-- Target Info Pill -->
        <div id="targetInfoBanner" class="mt-4 pt-3 border-t border-slate-100 flex items-center justify-between text-xs">
            <div class="flex items-center gap-2 text-slate-700 font-medium">
                <i data-lucide="info" class="w-4 h-4 text-brand-600"></i>
                <span id="targetDescText">Configuring menu access for Role: <strong>Cashier</strong></span>
            </div>
            <div id="userOverrideBadge" class="hidden">
                <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold bg-purple-50 text-purple-700 border border-purple-200">
                    Custom User Overrides Active
                </span>
            </div>
        </div>
    </div>

    <!-- Hierarchical Menu Cards matching Sidebar Layout -->
    <div class="space-y-4" id="menuHierarchyContainer">

        <!-- 1. OVERVIEW SECTION -->
        <div class="bg-white rounded-2xl border border-slate-200/80 p-5 shadow-2xs">
            <div class="flex items-center justify-between pb-3 border-b border-slate-100 mb-4">
                <div class="flex items-center gap-2.5">
                    <div class="w-7 h-7 rounded-lg bg-blue-50 text-brand-600 flex items-center justify-center">
                        <i data-lucide="layout-dashboard" class="w-4 h-4"></i>
                    </div>
                    <div>
                        <h3 class="text-xs font-bold text-slate-900 uppercase tracking-wide">Overview Section</h3>
                        <p class="text-[11px] text-slate-400">Core operational home view</p>
                    </div>
                </div>
                <button type="button" onclick="toggleSectionCheckboxes('overview', this)"
                        class="text-[11px] text-brand-600 hover:text-brand-700 font-semibold">
                    Toggle Section
                </button>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
                <label class="flex items-start gap-3 p-3 rounded-xl border border-slate-200/80 hover:bg-slate-50 cursor-pointer transition-colors select-none">
                    <input type="checkbox" name="menu_keys[]" value="dashboard" data-section="overview"
                           class="menu-perm-cb rounded text-brand-600 focus:ring-brand-600 w-4 h-4 mt-0.5">
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center justify-between gap-1">
                            <span class="font-bold text-slate-900 text-xs">Dashboard</span>
                            <span class="text-[9px] px-1.5 py-0.2 bg-slate-100 text-slate-600 rounded font-mono">/dashboard</span>
                        </div>
                        <span class="text-[11px] text-slate-500 leading-tight block mt-0.5">Business KPIs, charts, and recent orders</span>
                    </div>
                </label>
            </div>
        </div>

        <!-- 2. OPERATIONS SECTION -->
        <div class="bg-white rounded-2xl border border-slate-200/80 p-5 shadow-2xs space-y-5">
            <div class="flex items-center justify-between pb-3 border-b border-slate-100">
                <div class="flex items-center gap-2.5">
                    <div class="w-7 h-7 rounded-lg bg-emerald-50 text-emerald-600 flex items-center justify-center">
                        <i data-lucide="layers" class="w-4 h-4"></i>
                    </div>
                    <div>
                        <h3 class="text-xs font-bold text-slate-900 uppercase tracking-wide">Operations Section</h3>
                        <p class="text-[11px] text-slate-400">High-frequency daily counter and stock management</p>
                    </div>
                </div>
                <button type="button" onclick="toggleSectionCheckboxes('operations', this)"
                        class="text-[11px] text-brand-600 hover:text-brand-700 font-semibold">
                    Toggle Section
                </button>
            </div>

            <!-- Group A: Sales Management -->
            <div>
                <div class="flex items-center justify-between mb-2">
                    <span class="text-xs font-bold text-slate-800 flex items-center gap-1.5">
                        <i data-lucide="shopping-bag" class="w-3.5 h-3.5 text-slate-400"></i>
                        <span>Sales Management</span>
                        <span class="text-[9px] px-1 bg-emerald-50 text-emerald-600 rounded font-bold border border-emerald-200">POS</span>
                    </span>
                    <button type="button" onclick="toggleGroupCheckboxes('sales', this)" class="text-[10px] text-slate-500 hover:underline">Toggle Group</button>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
                    <label class="flex items-start gap-3 p-3 rounded-xl border border-slate-200/80 hover:bg-slate-50 cursor-pointer transition-colors select-none">
                        <input type="checkbox" name="menu_keys[]" value="sales.pos" data-section="operations" data-group="sales"
                               class="menu-perm-cb rounded text-brand-600 focus:ring-brand-600 w-4 h-4 mt-0.5">
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center justify-between gap-1">
                                <span class="font-bold text-slate-900 text-xs">POS Terminal</span>
                                <span class="text-[9px] px-1.5 py-0.2 bg-slate-100 text-slate-600 rounded font-mono">/sales/pos</span>
                            </div>
                            <span class="text-[11px] text-slate-500 leading-tight block mt-0.5">High-speed touch café point of sale</span>
                        </div>
                    </label>

                    <label class="flex items-start gap-3 p-3 rounded-xl border border-slate-200/80 hover:bg-slate-50 cursor-pointer transition-colors select-none">
                        <input type="checkbox" name="menu_keys[]" value="sales.orders" data-section="operations" data-group="sales"
                               class="menu-perm-cb rounded text-brand-600 focus:ring-brand-600 w-4 h-4 mt-0.5">
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center justify-between gap-1">
                                <span class="font-bold text-slate-900 text-xs">Sales Orders</span>
                                <span class="text-[9px] px-1.5 py-0.2 bg-slate-100 text-slate-600 rounded font-mono">/sales</span>
                            </div>
                            <span class="text-[11px] text-slate-500 leading-tight block mt-0.5">View completed and pending customer orders</span>
                        </div>
                    </label>
            <label class="flex items-start gap-3 p-3 rounded-xl border border-slate-200/80 hover:bg-slate-50 cursor-pointer">
                <input type="checkbox" name="menu_keys[]" value="sales.returns" data-section="operations" data-group="sales" class="menu-perm-cb rounded text-brand-600 w-4 h-4 mt-0.5">
                <div><span class="font-bold text-slate-900 text-xs block">Sales Returns</span><span class="text-[11px] text-slate-500">Return items and refund customers. Also requires the sales.refund action permission.</span></div>
            </label>
                </div>
            </div>
            <!-- Group B: Inventory Management -->
            <div class="pt-2 border-t border-slate-100">
                <div class="flex items-center justify-between mb-2">
                    <span class="text-xs font-bold text-slate-800 flex items-center gap-1.5">
                        <i data-lucide="boxes" class="w-3.5 h-3.5 text-slate-400"></i>
                        <span>Inventory Management</span>
                    </span>
                    <button type="button" onclick="toggleGroupCheckboxes('inventory', this)" class="text-[10px] text-slate-500 hover:underline">Toggle Group</button>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-3">
                    <label class="flex items-start gap-3 p-3 rounded-xl border border-slate-200/80 hover:bg-slate-50 cursor-pointer transition-colors select-none">
                        <input type="checkbox" name="menu_keys[]" value="inventory.products" data-section="operations" data-group="inventory"
                               class="menu-perm-cb rounded text-brand-600 focus:ring-brand-600 w-4 h-4 mt-0.5">
                        <div class="min-w-0 flex-1">
                            <span class="font-bold text-slate-900 text-xs block">Medicines & Catalog</span>
                            <span class="text-[11px] text-slate-500 leading-tight block mt-0.5">Medicines, dosage forms, and prices</span>
                        </div>
                    </label>

                    <label class="flex items-start gap-3 p-3 rounded-xl border border-slate-200/80 hover:bg-slate-50 cursor-pointer transition-colors select-none">
                        <input type="checkbox" name="menu_keys[]" value="inventory.categories" data-section="operations" data-group="inventory"
                               class="menu-perm-cb rounded text-brand-600 focus:ring-brand-600 w-4 h-4 mt-0.5">
                        <div class="min-w-0 flex-1">
                            <span class="font-bold text-slate-900 text-xs block">Categories</span>
                            <span class="text-[11px] text-slate-500 leading-tight block mt-0.5">Medicine categories and therapeutic groups</span>
                        </div>
                    </label>

                    <label class="flex items-start gap-3 p-3 rounded-xl border border-slate-200/80 hover:bg-slate-50 cursor-pointer transition-colors select-none">
                        <input type="checkbox" name="menu_keys[]" value="inventory.stock" data-section="operations" data-group="inventory"
                               class="menu-perm-cb rounded text-brand-600 focus:ring-brand-600 w-4 h-4 mt-0.5">
                        <div class="min-w-0 flex-1">
                            <span class="font-bold text-slate-900 text-xs block">Stock Levels & Ledger</span>
                            <span class="text-[11px] text-slate-500 leading-tight block mt-0.5">Real-time ingredient balances</span>
                        </div>
                    </label>

                    <label class="flex items-start gap-3 p-3 rounded-xl border border-slate-200/80 hover:bg-slate-50 cursor-pointer transition-colors select-none">
                        <input type="checkbox" name="menu_keys[]" value="inventory.adjustments" data-section="operations" data-group="inventory"
                               class="menu-perm-cb rounded text-brand-600 focus:ring-brand-600 w-4 h-4 mt-0.5">
                        <div class="min-w-0 flex-1">
                            <span class="font-bold text-slate-900 text-xs block">Stock Adjustments</span>
                            <span class="text-[11px] text-slate-500 leading-tight block mt-0.5">Waste, spoilage reconciliations</span>
                        </div>
                    </label>
                </div>
            </div>

            <!-- Group C: Supplier Management -->
            <div class="pt-2 border-t border-slate-100">
                <div class="flex items-center justify-between mb-2">
                    <span class="text-xs font-bold text-slate-800 flex items-center gap-1.5">
                        <i data-lucide="truck" class="w-3.5 h-3.5 text-slate-400"></i>
                        <span>Supplier Management</span>
                    </span>
                    <button type="button" onclick="toggleGroupCheckboxes('suppliers', this)" class="text-[10px] text-slate-500 hover:underline">Toggle Group</button>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
                    <label class="flex items-start gap-3 p-3 rounded-xl border border-slate-200/80 hover:bg-slate-50 cursor-pointer transition-colors select-none">
                        <input type="checkbox" name="menu_keys[]" value="suppliers.list" data-section="operations" data-group="suppliers"
                               class="menu-perm-cb rounded text-brand-600 focus:ring-brand-600 w-4 h-4 mt-0.5">
                        <div class="min-w-0 flex-1">
                            <span class="font-bold text-slate-900 text-xs block">Suppliers List</span>
                            <span class="text-[11px] text-slate-500 leading-tight block mt-0.5">Vendor directory and contacts</span>
                        </div>
                    </label>

                    <label class="flex items-start gap-3 p-3 rounded-xl border border-slate-200/80 hover:bg-slate-50 cursor-pointer transition-colors select-none">
                        <input type="checkbox" name="menu_keys[]" value="suppliers.purchases" data-section="operations" data-group="suppliers"
                               class="menu-perm-cb rounded text-brand-600 focus:ring-brand-600 w-4 h-4 mt-0.5">
                        <div class="min-w-0 flex-1">
                            <span class="font-bold text-slate-900 text-xs block">Purchase Orders</span>
                            <span class="text-[11px] text-slate-500 leading-tight block mt-0.5">Stock procurement orders</span>
                        </div>
                    </label>

                    <label class="flex items-start gap-3 p-3 rounded-xl border border-slate-200/80 hover:bg-slate-50 cursor-pointer transition-colors select-none">
                        <input type="checkbox" name="menu_keys[]" value="suppliers.receiving" data-section="operations" data-group="suppliers"
                               class="menu-perm-cb rounded text-brand-600 focus:ring-brand-600 w-4 h-4 mt-0.5">
                        <div class="min-w-0 flex-1">
                            <span class="font-bold text-slate-900 text-xs block">Goods Receiving</span>
                            <span class="text-[11px] text-slate-500 leading-tight block mt-0.5">Inbound shipment inspection</span>
                        </div>
                    </label>
            <label class="flex items-start gap-3 p-3 rounded-xl border border-slate-200/80 hover:bg-slate-50 cursor-pointer">
                <input type="checkbox" name="menu_keys[]" value="suppliers.returns" data-section="operations" data-group="suppliers" class="menu-perm-cb rounded text-brand-600 w-4 h-4 mt-0.5">
                <div><span class="font-bold text-slate-900 text-xs block">Purchase Returns</span><span class="text-[11px] text-slate-500">Return received goods and collect supplier refunds. Also requires purchases.return.</span></div>
            </label>
                </div>
            </div>
            <!-- Group D: Client Management -->
            <div class="pt-2 border-t border-slate-100">
                <div class="flex items-center justify-between mb-2">
                    <span class="text-xs font-bold text-slate-800 flex items-center gap-1.5">
                        <i data-lucide="users" class="w-3.5 h-3.5 text-slate-400"></i>
                        <span>Client Management</span>
                    </span>
                    <button type="button" onclick="toggleGroupCheckboxes('clients', this)" class="text-[10px] text-slate-500 hover:underline">Toggle Group</button>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
                    <label class="flex items-start gap-3 p-3 rounded-xl border border-slate-200/80 hover:bg-slate-50 cursor-pointer transition-colors select-none">
                        <input type="checkbox" name="menu_keys[]" value="clients.list" data-section="operations" data-group="clients"
                               class="menu-perm-cb rounded text-brand-600 focus:ring-brand-600 w-4 h-4 mt-0.5">
                        <div class="min-w-0 flex-1">
                            <span class="font-bold text-slate-900 text-xs block">Customer Directory</span>
                            <span class="text-[11px] text-slate-500 leading-tight block mt-0.5">Loyalty customers and credit buyers</span>
                        </div>
                    </label>

                    <label class="flex items-start gap-3 p-3 rounded-xl border border-slate-200/80 hover:bg-slate-50 cursor-pointer transition-colors select-none">
                        <input type="checkbox" name="menu_keys[]" value="clients.receipts" data-section="operations" data-group="clients"
                               class="menu-perm-cb rounded text-brand-600 focus:ring-brand-600 w-4 h-4 mt-0.5">
                        <div class="min-w-0 flex-1">
                            <span class="font-bold text-slate-900 text-xs block">Customer Receipts</span>
                            <span class="text-[11px] text-slate-500 leading-tight block mt-0.5">Collect unpaid and partially paid invoices</span>
                        </div>
                    </label>

                    <label class="flex items-start gap-3 p-3 rounded-xl border border-slate-200/80 hover:bg-slate-50 cursor-pointer transition-colors select-none">
                        <input type="checkbox" name="menu_keys[]" value="clients.deposits" data-section="operations" data-group="clients"
                               class="menu-perm-cb rounded text-brand-600 focus:ring-brand-600 w-4 h-4 mt-0.5">
                        <div class="min-w-0 flex-1">
                            <span class="font-bold text-slate-900 text-xs block">Customer Deposits</span>
                            <span class="text-[11px] text-slate-500 leading-tight block mt-0.5">Advance balances and store credit</span>
                        </div>
                    </label>
                </div>
            </div>
        </div>

        <!-- 3. FINANCE & REPORTS SECTION -->
        <div class="bg-white rounded-2xl border border-slate-200/80 p-5 shadow-2xs space-y-5">
            <div class="flex items-center justify-between pb-3 border-b border-slate-100">
                <div class="flex items-center gap-2.5">
                    <div class="w-7 h-7 rounded-lg bg-amber-50 text-amber-600 flex items-center justify-center">
                        <i data-lucide="landmark" class="w-4 h-4"></i>
                    </div>
                    <div>
                        <h3 class="text-xs font-bold text-slate-900 uppercase tracking-wide">Finance & Reports Section</h3>
                        <p class="text-[11px] text-slate-400">Ledger accounting, vendor bills, and business intelligence</p>
                    </div>
                </div>
                <button type="button" onclick="toggleSectionCheckboxes('finance_reports', this)"
                        class="text-[11px] text-brand-600 hover:text-brand-700 font-semibold">
                    Toggle Section
                </button>
            </div>

            <!-- Group A: Financial Management -->
            <div>
                <div class="flex items-center justify-between mb-2">
                    <span class="text-xs font-bold text-slate-800 flex items-center gap-1.5">
                        <i data-lucide="wallet" class="w-3.5 h-3.5 text-slate-400"></i>
                        <span>Financial Management</span>
                    </span>
                    <button type="button" onclick="toggleGroupCheckboxes('finance', this)" class="text-[10px] text-slate-500 hover:underline">Toggle Group</button>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
                    <label class="flex items-start gap-3 p-3 rounded-xl border border-slate-200/80 hover:bg-slate-50 cursor-pointer transition-colors select-none">
                        <input type="checkbox" name="menu_keys[]" value="finance.bills" data-section="finance_reports" data-group="finance"
                               class="menu-perm-cb rounded text-brand-600 focus:ring-brand-600 w-4 h-4 mt-0.5">
                        <div class="min-w-0 flex-1">
                            <span class="font-bold text-slate-900 text-xs block">Supplier Bills (AP)</span>
                            <span class="text-[11px] text-slate-500 leading-tight block mt-0.5">Accounts payable tracking</span>
                        </div>
                    </label>

                    <label class="flex items-start gap-3 p-3 rounded-xl border border-slate-200/80 hover:bg-slate-50 cursor-pointer transition-colors select-none">
                        <input type="checkbox" name="menu_keys[]" value="finance.payments" data-section="finance_reports" data-group="finance"
                               class="menu-perm-cb rounded text-brand-600 focus:ring-brand-600 w-4 h-4 mt-0.5">
                        <div class="min-w-0 flex-1">
                            <span class="font-bold text-slate-900 text-xs block">Supplier Payments</span>
                            <span class="text-[11px] text-slate-500 leading-tight block mt-0.5">Outbound vendor disbursements</span>
                        </div>
                    </label>

                    <label class="flex items-start gap-3 p-3 rounded-xl border border-slate-200/80 hover:bg-slate-50 cursor-pointer transition-colors select-none">
                        <input type="checkbox" name="menu_keys[]" value="finance.accounts" data-section="finance_reports" data-group="finance"
                               class="menu-perm-cb rounded text-brand-600 focus:ring-brand-600 w-4 h-4 mt-0.5">
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-1.5">
                                <span class="font-bold text-slate-900 text-xs">Chart of Accounts</span>
                                <span class="text-[8px] px-1 bg-amber-50 text-amber-700 font-bold rounded border border-amber-200">PRO</span>
                            </div>
                            <span class="text-[11px] text-slate-500 leading-tight block mt-0.5">General ledger structure</span>
                        </div>
                    </label>

                    <label class="flex items-start gap-3 p-3 rounded-xl border border-slate-200/80 hover:bg-slate-50 cursor-pointer transition-colors select-none">
                        <input type="checkbox" name="menu_keys[]" value="finance.journals" data-section="finance_reports" data-group="finance"
                               class="menu-perm-cb rounded text-brand-600 focus:ring-brand-600 w-4 h-4 mt-0.5">
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-1.5">
                                <span class="font-bold text-slate-900 text-xs">Journal Entries</span>
                                <span class="text-[8px] px-1 bg-amber-50 text-amber-700 font-bold rounded border border-amber-200">PRO</span>
                            </div>
                            <span class="text-[11px] text-slate-500 leading-tight block mt-0.5">Double-entry bookkeeping</span>
                        </div>
                    </label>

                    <label class="flex items-start gap-3 p-3 rounded-xl border border-slate-200/80 hover:bg-slate-50 cursor-pointer transition-colors select-none">
                        <input type="checkbox" name="menu_keys[]" value="finance.pos_methods" data-section="finance_reports" data-group="finance"
                               class="menu-perm-cb rounded text-brand-600 focus:ring-brand-600 w-4 h-4 mt-0.5">
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-1.5">
                                <span class="font-bold text-slate-900 text-xs">POS Payment Methods</span>
                                <span class="text-[8px] px-1 bg-amber-50 text-amber-700 font-bold rounded border border-amber-200">PRO</span>
                            </div>
                            <span class="text-[11px] text-slate-500 leading-tight block mt-0.5">Payment methods and cash accounts</span>
                        </div>
                    </label>

                    <label class="flex items-start gap-3 p-3 rounded-xl border border-slate-200/80 hover:bg-slate-50 cursor-pointer transition-colors select-none">
                        <input type="checkbox" name="menu_keys[]" value="finance.periods" data-section="finance_reports" data-group="finance"
                               class="menu-perm-cb rounded text-brand-600 focus:ring-brand-600 w-4 h-4 mt-0.5">
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-1.5">
                                <span class="font-bold text-slate-900 text-xs">Financial Periods</span>
                                <span class="text-[8px] px-1 bg-amber-50 text-amber-700 font-bold rounded border border-amber-200">PRO</span>
                            </div>
                            <span class="text-[11px] text-slate-500 leading-tight block mt-0.5">Fiscal month & year closing</span>
                        </div>
                    </label>
                    <label class="flex items-start gap-3 p-3 rounded-xl border border-slate-200"><input type="checkbox" name="menu_keys[]" value="finance.taxes" data-section="finance_reports" data-group="finance" class="menu-perm-cb rounded text-brand-600"><span class="text-xs"><strong>Tax Management</strong><br>Manage the active POS tax percentage (requires Settings permission)</span></label>
                </div>
            </div>

            <!-- Group B: Reports -->
            <div class="pt-2 border-t border-slate-100">
                <div class="flex items-center justify-between mb-2">
                    <span class="text-xs font-bold text-slate-800 flex items-center gap-1.5">
                        <i data-lucide="bar-chart-3" class="w-3.5 h-3.5 text-slate-400"></i>
                        <span>Reports & Business Analytics</span>
                    </span>
                    <button type="button" onclick="toggleGroupCheckboxes('reports', this)" class="text-[10px] text-slate-500 hover:underline">Toggle Group</button>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                    <label class="flex items-start gap-3 p-3 rounded-xl border border-slate-200/80 hover:bg-slate-50 cursor-pointer transition-colors select-none">
                        <input type="checkbox" name="menu_keys[]" value="reports.sales" data-section="finance_reports" data-group="reports"
                               class="menu-perm-cb rounded text-brand-600 focus:ring-brand-600 w-4 h-4 mt-0.5">
                        <div class="min-w-0 flex-1">
                            <span class="font-bold text-slate-900 text-xs block">Sales Reports</span>
                            <span class="text-[11px] text-slate-500 leading-tight block mt-0.5">Daily breakdown and 7-day revenue curve</span>
                        </div>
                    </label>

                    <label class="flex items-start gap-3 p-3 rounded-xl border border-slate-200/80 hover:bg-slate-50 cursor-pointer transition-colors select-none">
                        <input type="checkbox" name="menu_keys[]" value="reports.inventory" data-section="finance_reports" data-group="reports"
                               class="menu-perm-cb rounded text-brand-600 focus:ring-brand-600 w-4 h-4 mt-0.5">
                        <div class="min-w-0 flex-1">
                            <span class="font-bold text-slate-900 text-xs block">Inventory Valuation</span>
                            <span class="text-[11px] text-slate-500 leading-tight block mt-0.5">Asset value and turnover analysis</span>
                        </div>
                    </label>

                    <label class="flex items-start gap-3 p-3 rounded-xl border border-slate-200/80 hover:bg-slate-50 cursor-pointer transition-colors select-none">
                        <input type="checkbox" name="menu_keys[]" value="reports.financial" data-section="finance_reports" data-group="reports"
                               class="menu-perm-cb rounded text-brand-600 focus:ring-brand-600 w-4 h-4 mt-0.5">
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-1.5">
                                <span class="font-bold text-slate-900 text-xs">Financial Statements</span>
                                <span class="text-[8px] px-1 bg-amber-50 text-amber-700 font-bold rounded border border-amber-200">PRO</span>
                            </div>
                            <span class="text-[11px] text-slate-500 leading-tight block mt-0.5">P&L statement & balance sheet</span>
                        </div>
                    </label>
                    <?php foreach (['customers'=>'Customer Report','suppliers'=>'Supplier Report','income-statement'=>'Income Statement','balance-sheet'=>'Balance Sheet','cash-flow'=>'Cash Flow','trial-balance'=>'Trial Balance'] as $reportKey=>$reportLabel): ?>
                    <label class="flex items-start gap-3 p-3 rounded-xl border border-slate-200"><input type="checkbox" name="menu_keys[]" value="reports.<?= $reportKey ?>" data-section="finance_reports" data-group="reports" class="menu-perm-cb rounded text-brand-600"><span><?= esc($reportLabel) ?></span></label>
                    <?php endforeach; ?>

                </div>
            </div>
        </div>

        <!-- 4. ADMINISTRATION SECTION -->
        <div class="bg-white rounded-2xl border border-slate-200/80 p-5 shadow-2xs">
            <div class="flex items-center justify-between pb-3 border-b border-slate-100 mb-4">
                <div class="flex items-center gap-2.5">
                    <div class="w-7 h-7 rounded-lg bg-indigo-50 text-indigo-600 flex items-center justify-center">
                        <i data-lucide="shield-check" class="w-4 h-4"></i>
                    </div>
                    <div>
                        <h3 class="text-xs font-bold text-slate-900 uppercase tracking-wide">Administration Section</h3>
                        <p class="text-[11px] text-slate-400">Staff accounts, role definition, and security audit log</p>
                    </div>
                </div>
                <button type="button" onclick="toggleSectionCheckboxes('administration', this)"
                        class="text-[11px] text-brand-600 hover:text-brand-700 font-semibold">
                    Toggle Section
                </button>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                <label class="flex items-start gap-3 p-3 rounded-xl border border-slate-200/80 hover:bg-slate-50 cursor-pointer transition-colors select-none">
                    <input type="checkbox" name="menu_keys[]" value="users.staff" data-section="administration" data-group="users"
                           class="menu-perm-cb rounded text-brand-600 focus:ring-brand-600 w-4 h-4 mt-0.5">
                    <div class="min-w-0 flex-1">
                        <span class="font-bold text-slate-900 text-xs block">Staff Users Directory</span>
                        <span class="text-[11px] text-slate-500 leading-tight block mt-0.5">Manage store employee credentials</span>
                    </div>
                </label>

                <label class="flex items-start gap-3 p-3 rounded-xl border border-slate-200/80 hover:bg-slate-50 cursor-pointer transition-colors select-none">
                    <input type="checkbox" name="menu_keys[]" value="users.roles" data-section="administration" data-group="users"
                           class="menu-perm-cb rounded text-brand-600 focus:ring-brand-600 w-4 h-4 mt-0.5">
                    <div class="min-w-0 flex-1">
                        <span class="font-bold text-slate-900 text-xs block">Roles & Permissions</span>
                        <span class="text-[11px] text-slate-500 leading-tight block mt-0.5">RBAC action & sidebar privileges</span>
                    </div>
                </label>

                <label class="flex items-start gap-3 p-3 rounded-xl border border-slate-200/80 hover:bg-slate-50 cursor-pointer transition-colors select-none">
                    <input type="checkbox" name="menu_keys[]" value="users.audit" data-section="administration" data-group="users"
                           class="menu-perm-cb rounded text-brand-600 focus:ring-brand-600 w-4 h-4 mt-0.5">
                    <div class="min-w-0 flex-1">
                        <span class="font-bold text-slate-900 text-xs block">Audit Trail Log</span>
                        <span class="text-[11px] text-slate-500 leading-tight block mt-0.5">Tamper-evident activity ledger</span>
                    </div>
                </label>
            </div>
        </div>

    </div>
</div>

<!-- ========================================================================= -->
<!-- MODALS                                                                    -->
<!-- ========================================================================= -->

<!-- Modal 1: Create Custom Role Modal -->
<div id="createRoleModal" class="fixed inset-0 z-50 bg-slate-900/50 backdrop-blur-sm hidden flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl shadow-2xl border border-slate-200 w-full max-w-lg overflow-hidden animate-in fade-in zoom-in duration-200">
        <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
            <h3 class="text-sm font-bold text-slate-900">Create New Custom Role</h3>
            <button onclick="closeCreateRoleModal()" class="text-slate-400 hover:text-slate-700">
                <i data-lucide="x" class="w-4 h-4"></i>
            </button>
        </div>

        <form id="createRoleForm" onsubmit="submitCreateRole(event)" class="p-6 space-y-4 text-xs">
            <div>
                <label class="block font-semibold text-slate-700 mb-1">Role Name *</label>
                <input type="text" id="new_role_name" required placeholder="e.g. Inventory Clerk, Supervisor"
                       class="w-full px-3 py-2 border border-slate-200 rounded-lg focus:outline-none focus:border-brand-600 focus:ring-1 focus:ring-brand-600">
            </div>

            <div>
                <label class="block font-semibold text-slate-700 mb-1">Description</label>
                <textarea id="new_role_description" rows="2" placeholder="Responsibilities of this role..."
                          class="w-full px-3 py-2 border border-slate-200 rounded-lg focus:outline-none focus:border-brand-600 focus:ring-1 focus:ring-brand-600"></textarea>
            </div>

            <label class="block font-semibold text-slate-700" for="new_role_preset">Starting permissions
                <select id="new_role_preset" class="mt-1 block w-full border border-slate-200 rounded-lg px-3 py-2">
                    <option value="custom">Custom — configure actions separately</option>
                    <option value="accountant_read">Accountant — read-only finance</option>
                </select>
            </label>
            <p class="text-[11px] text-slate-500">The Accountant preset allows viewing the dashboard, financial reports, accounts, invoices, bills and banking. It cannot post payments, edit transactions, manage staff or change settings. Custom roles show setup guidance until actions are assigned.</p>

            <div class="pt-3 flex justify-end gap-2 border-t border-slate-100">
                <button type="button" onclick="closeCreateRoleModal()" class="px-3.5 py-1.5 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-lg font-medium">Cancel</button>
                <button type="submit" id="create-role-btn" class="px-4 py-1.5 bg-brand-600 hover:bg-brand-700 text-white rounded-lg font-semibold shadow-sm">Save Role</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal 2: Edit Permissions Matrix Modal -->
<div id="permissionsModal" class="fixed inset-0 z-50 bg-slate-900/50 backdrop-blur-sm hidden flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl shadow-2xl border border-slate-200 w-full max-w-2xl max-h-[90vh] flex flex-col overflow-hidden animate-in fade-in zoom-in duration-200">
        <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
            <div>
                <h3 class="text-sm font-bold text-slate-900">Configure Action Privileges</h3>
                <p id="permModalSubtitle" class="text-[11px] text-slate-500">Role: Manager</p>
            </div>
            <button onclick="closePermissionsModal()" class="text-slate-400 hover:text-slate-700">
                <i data-lucide="x" class="w-4 h-4"></i>
            </button>
        </div>

        <form id="editPermissionsForm" onsubmit="submitPermissions(event)" class="flex-1 overflow-y-auto p-6 space-y-6 text-xs">
            <input type="hidden" id="edit_role_id">

            <div class="flex items-center justify-between pb-2 border-b border-slate-100">
                <span class="text-slate-500">Toggle individual backend action privileges for this role:</span>
                <div class="flex gap-2">
                    <button type="button" onclick="toggleAllPerms(true)" class="text-[11px] text-brand-600 hover:underline font-semibold">Select All</button>
                    <span class="text-slate-300">|</span>
                    <button type="button" onclick="toggleAllPerms(false)" class="text-[11px] text-slate-500 hover:underline">Clear All</button>
                </div>
            </div>
            <button type="button" onclick="applyAccountantReadPreset()" class="text-[11px] font-semibold text-brand-700 bg-brand-50 border border-brand-100 rounded-lg px-3 py-2">Select Accountant read-only permissions</button>

            <!-- Permission Checkbox Grid Categorized -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3" id="permissionsCheckboxList">
                <?php foreach ($permissions as $p): ?>
                    <label class="flex items-start gap-2.5 p-2.5 rounded-lg border border-slate-200/80 hover:bg-slate-50 cursor-pointer transition-colors select-none">
                        <input type="checkbox" name="permissions[]" value="<?= esc($p['code']) ?>"
                               class="perm-checkbox rounded text-brand-600 focus:ring-brand-600 w-4 h-4 mt-0.5">
                        <div class="min-w-0 flex-1">
                            <span class="font-bold text-slate-900 block"><?= esc($p['code']) ?></span>
                            <span class="text-[11px] text-slate-500 leading-tight block"><?= esc($p['description']) ?></span>
                        </div>
                    </label>
                <?php endforeach; ?>
            </div>

            <div class="pt-4 flex justify-end gap-2 border-t border-slate-100 sticky bottom-0 bg-white">
                <button type="button" onclick="closePermissionsModal()" class="px-3.5 py-1.5 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-lg font-medium">Cancel</button>
                <button type="submit" id="save-perms-btn" class="px-4 py-1.5 bg-brand-600 hover:bg-brand-700 text-white rounded-lg font-semibold shadow-sm">Save Permissions</button>
            </div>
        </form>
    </div>
</div>

<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
    // Initial State Passed from Server
    const initialRoleMenuMap = <?= json_encode($roleMenuMap, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
    const fixedRoleNames = new Set(['superadmin','owner','admin']);
    let menuAccessFixed = false;
    let currentMenuMode = 'role'; // 'role' or 'user'

    $(document).ready(function() {
        erp.initDataTable('#rolesTable', {
            pageLength: 10,
            columnDefs: [{ orderable: false, targets: [4] }]
        });

        // Initialize Menu Checkboxes for initially selected role
        loadInitialRoleMenu();
    });

    // -------------------------------------------------------------------------
    // Main Tab Switching: Roles vs Menu Permissions
    // -------------------------------------------------------------------------
    function switchMainTab(tab) {
        const btnRoles = document.getElementById('tab-btn-roles');
        const btnMenu  = document.getElementById('tab-btn-menu');
        const contentRoles = document.getElementById('tab-content-roles');
        const contentMenu  = document.getElementById('tab-content-menu');

        if (tab === 'roles') {
            btnRoles.className = 'flex items-center gap-2 px-4 py-2 rounded-lg transition-all bg-white text-slate-900 shadow-xs font-bold';
            btnMenu.className  = 'flex items-center gap-2 px-4 py-2 rounded-lg transition-all text-slate-600 hover:text-slate-900';
            contentRoles.classList.remove('hidden');
            contentMenu.classList.add('hidden');
        } else {
            btnMenu.className  = 'flex items-center gap-2 px-4 py-2 rounded-lg transition-all bg-white text-slate-900 shadow-xs font-bold';
            btnRoles.className = 'flex items-center gap-2 px-4 py-2 rounded-lg transition-all text-slate-600 hover:text-slate-900';
            contentRoles.classList.add('hidden');
            contentMenu.classList.remove('hidden');
            lucide.createIcons();
        }
    }

    function switchToRoleMenu(roleId) {
        switchMainTab('menu');
        switchMenuMode('role');
        const select = document.getElementById('selected_role_id');
        select.value = roleId;
        onRoleChanged();
    }

    // -------------------------------------------------------------------------
    // Menu Mode Switching: By Role vs By Staff User
    // -------------------------------------------------------------------------
    function switchMenuMode(mode) {
        currentMenuMode = mode;
        const btnRole = document.getElementById('mode-btn-role');
        const btnUser = document.getElementById('mode-btn-user');
        const roleContainer = document.getElementById('roleSelectContainer');
        const userContainer = document.getElementById('userSelectContainer');
        const resetBtn = document.getElementById('reset-user-btn');

        if (mode === 'role') {
            btnRole.className = 'px-3 py-1.5 rounded-md bg-white text-slate-900 shadow-xs font-bold transition-all';
            btnUser.className = 'px-3 py-1.5 rounded-md text-slate-600 hover:text-slate-900 transition-all';
            roleContainer.classList.remove('hidden');
            userContainer.classList.add('hidden');
            resetBtn.classList.add('hidden');
            document.getElementById('userOverrideBadge').classList.add('hidden');
            onRoleChanged();
        } else {
            btnUser.className = 'px-3 py-1.5 rounded-md bg-white text-slate-900 shadow-xs font-bold transition-all';
            btnRole.className = 'px-3 py-1.5 rounded-md text-slate-600 hover:text-slate-900 transition-all';
            roleContainer.classList.add('hidden');
            userContainer.classList.remove('hidden');
            onUserChanged();
        }
    }

    // -------------------------------------------------------------------------
    // Role & User Selection Handlers
    // -------------------------------------------------------------------------
    function loadInitialRoleMenu() {
        const roleId = document.getElementById('selected_role_id').value;
        const roleName = document.getElementById('selected_role_id').selectedOptions[0].dataset.roleName;
        document.getElementById('targetDescText').textContent = `Configuring menu access for Role: ${roleName}`;

        const keys = initialRoleMenuMap[roleId] || [];
        setMenuCheckboxes(keys);
        setMenuAccessFixed(roleName);
    }

    async function onRoleChanged() {
        const select = document.getElementById('selected_role_id');
        const roleId = select.value;
        const roleName = select.selectedOptions[0].dataset.roleName;
        document.getElementById('targetDescText').textContent = `Configuring menu access for Role: ${roleName}`;

        try {
            const res = await erp.api('menu-permissions/role/' + roleId);
            setMenuCheckboxes(res.menu_keys || []);
        } catch (e) {
            // Fallback to local map
            const keys = initialRoleMenuMap[roleId] || [];
            setMenuCheckboxes(keys);
        }
        setMenuAccessFixed(roleName);
    }

    async function onUserChanged() {
        const select = document.getElementById('selected_user_id');
        if (!select.value) return;
        const userId = select.value;
        const userName = select.selectedOptions[0].text;
        const roleName = select.selectedOptions[0].getAttribute('data-role-name') || 'Staff';

        document.getElementById('targetDescText').textContent = `Configuring menu access for Staff: ${userName}`;

        try {
            const res = await erp.api('menu-permissions/user/' + userId);
            setMenuCheckboxes(res.menu_keys || []);

            const resetBtn = document.getElementById('reset-user-btn');
            const overrideBadge = document.getElementById('userOverrideBadge');

            if (res.has_custom_overrides) {
                resetBtn.classList.remove('hidden');
                overrideBadge.classList.remove('hidden');
            } else {
                resetBtn.classList.add('hidden');
                overrideBadge.classList.add('hidden');
            }
        } catch (e) {
            // Handled
        }
        setMenuAccessFixed(roleName);
    }

    function setMenuAccessFixed(roleName) {
        menuAccessFixed = fixedRoleNames.has(roleName.trim().toLowerCase());
        document.getElementById('save-menu-perms-btn').disabled = menuAccessFixed;
        document.querySelectorAll('.menu-perm-cb').forEach(cb => cb.disabled = menuAccessFixed);
        if (menuAccessFixed) {
            document.getElementById('targetDescText').textContent = roleName + ': full menu access is fixed by the system.';
            document.getElementById('reset-user-btn').classList.add('hidden');
        }
    }

    function setMenuCheckboxes(keys) {
        const keySet = new Set(keys);
        document.querySelectorAll('.menu-perm-cb').forEach(cb => {
            cb.checked = keySet.has(cb.value);
        });
    }

    // -------------------------------------------------------------------------
    // Checkbox Toggling Helpers
    // -------------------------------------------------------------------------
    function toggleAllMenuCheckboxes(checked) {
        document.querySelectorAll('.menu-perm-cb').forEach(cb => { if (!cb.disabled) cb.checked = checked; });
    }

    function toggleSectionCheckboxes(section, btn) {
        const cbs = document.querySelectorAll(`.menu-perm-cb[data-section="${section}"]`);
        const allChecked = Array.from(cbs).every(cb => cb.checked);
        cbs.forEach(cb => { if (!cb.disabled) cb.checked = !allChecked; });
    }

    function toggleGroupCheckboxes(group, btn) {
        const cbs = document.querySelectorAll(`.menu-perm-cb[data-group="${group}"]`);
        const allChecked = Array.from(cbs).every(cb => cb.checked);
        cbs.forEach(cb => { if (!cb.disabled) cb.checked = !allChecked; });
    }

    // -------------------------------------------------------------------------
    // Save Menu Permissions (AJAX)
    // -------------------------------------------------------------------------
    async function saveMenuPermissions() {
        if (menuAccessFixed) return;
        const btn = document.getElementById('save-menu-perms-btn');
        btn.disabled = true;
        btn.innerHTML = `<i data-lucide="loader-2" class="w-4 h-4 animate-spin"></i><span>Saving...</span>`;
        lucide.createIcons();

        const selectedKeys = Array.from(document.querySelectorAll('.menu-perm-cb:checked')).map(cb => cb.value);

        try {
            if (currentMenuMode === 'role') {
                const roleId = document.getElementById('selected_role_id').value;
                await erp.api('menu-permissions/role/' + roleId, {
                    method: 'PUT',
                    body: JSON.stringify({ menu_keys: selectedKeys })
                });
                initialRoleMenuMap[roleId] = selectedKeys;
                erp.toast('Role sidebar menu access updated successfully!');
            } else {
                const userId = document.getElementById('selected_user_id').value;
                await erp.api('menu-permissions/user/' + userId, {
                    method: 'PUT',
                    body: JSON.stringify({ menu_keys: selectedKeys })
                });
                document.getElementById('reset-user-btn').classList.remove('hidden');
                document.getElementById('userOverrideBadge').classList.remove('hidden');
                erp.toast('User custom menu access saved successfully!');
            }

            // Prompt or refresh after 800ms to immediately reflect in sidebar
            setTimeout(() => window.location.reload(), 800);
        } catch (err) {
            // Handled by erp.api
        } finally {
            btn.disabled = false;
            btn.innerHTML = `<i data-lucide="check" class="w-4 h-4"></i><span>Save Menu Access</span>`;
            lucide.createIcons();
        }
    }

    // -------------------------------------------------------------------------
    // Reset User to Role Defaults
    // -------------------------------------------------------------------------
    async function resetUserToRoleDefaults() {
        const userId = document.getElementById('selected_user_id').value;
        const select = document.getElementById('selected_user_id');
        const userName = select.selectedOptions[0].text;

        if (!await erp.confirm(`Reset custom menu permissions for "${userName}" back to their role's default menus?`)) {
            return;
        }

        try {
            await erp.api('menu-permissions/user/' + userId + '/reset', { method: 'POST' });
            erp.toast('User permissions reset to role defaults.');
            onUserChanged();
            setTimeout(() => window.location.reload(), 800);
        } catch (e) {
            // Handled
        }
    }

    // -------------------------------------------------------------------------
    // Modal 1: Create Role
    // -------------------------------------------------------------------------
    function openCreateRoleModal() {
        document.getElementById('createRoleForm').reset();
        document.getElementById('createRoleModal').classList.remove('hidden');
    }

    function closeCreateRoleModal() {
        document.getElementById('createRoleModal').classList.add('hidden');
    }

    async function submitCreateRole(e) {
        e.preventDefault();
        const btn = document.getElementById('create-role-btn');
        btn.disabled = true;

        const name = document.getElementById('new_role_name').value.trim();
        const description = document.getElementById('new_role_description').value.trim();
        const preset = document.getElementById('new_role_preset').value;

        try {
            await erp.web('<?= site_url('users/roles/create') ?>', {
                method: 'POST',
                body: JSON.stringify({ name, description, preset })
            });
            erp.toast('Role created successfully!');
            closeCreateRoleModal();
            setTimeout(() => window.location.reload(), 600);
        } catch (err) {
            // Handled
        } finally {
            btn.disabled = false;
        }
    }

    // -------------------------------------------------------------------------
    // Modal 2: Edit Permissions Matrix
    // -------------------------------------------------------------------------
    function editRolePermissions(roleId, roleName, currentPerms) {
        document.getElementById('edit_role_id').value = roleId;
        document.getElementById('permModalSubtitle').textContent = 'Role: ' + roleName;

        const permSet = new Set(currentPerms);
        document.querySelectorAll('.perm-checkbox').forEach(cb => {
            cb.checked = permSet.has(cb.value);
        });

        document.getElementById('permissionsModal').classList.remove('hidden');
    }

    function applyAccountantReadPreset() {
        const codes = new Set(<?= json_encode(\App\Services\Web\RolePresetService::ACCOUNTANT_READ) ?>);
        document.querySelectorAll('.perm-checkbox').forEach(checkbox => { checkbox.checked = codes.has(checkbox.value); });
        erp.toast('Read-only Accountant permissions selected. Save Permissions to apply them.', 'info');
    }

    function closePermissionsModal() {
        document.getElementById('permissionsModal').classList.add('hidden');
    }

    function toggleAllPerms(check) {
        document.querySelectorAll('.perm-checkbox').forEach(cb => cb.checked = check);
    }

    async function submitPermissions(e) {
        e.preventDefault();
        const roleId = document.getElementById('edit_role_id').value;
        const btn = document.getElementById('save-perms-btn');
        btn.disabled = true;

        const selected = Array.from(document.querySelectorAll('.perm-checkbox:checked')).map(cb => cb.value);

        try {
            await erp.api('roles/' + roleId, {
                method: 'PUT',
                body: JSON.stringify({ permissions: selected })
            });
            erp.toast('Permissions saved successfully!');
            closePermissionsModal();
            setTimeout(() => window.location.reload(), 600);
        } catch (err) {
            // Handled
        } finally {
            btn.disabled = false;
        }
    }

    // -------------------------------------------------------------------------
    // Delete Role
    // -------------------------------------------------------------------------
    async function deleteRole(roleId, roleName) {
        if (!await erp.confirm(`Delete role "${roleName}"?`, {destructive:true})) {
            return;
        }

        try {
            await erp.api('roles/' + roleId, { method: 'DELETE' });
            erp.toast(`Role "${roleName}" deleted.`);
            setTimeout(() => window.location.reload(), 600);
        } catch (err) {
            // Handled
        }
    }
</script>
<?= $this->endSection() ?>
