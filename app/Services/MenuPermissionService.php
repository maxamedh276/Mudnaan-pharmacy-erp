<?php

namespace App\Services;

class MenuPermissionService
{
    /**
     * Definitive hierarchical menu structure matching the ERP sidebar.
     */
    public const MENU_STRUCTURE = [
        'overview' => [
            'label' => 'Overview',
            'badge' => 'Main',
            'icon'  => 'layout-dashboard',
            'items' => [
                'dashboard' => ['label' => 'Dashboard', 'route' => 'dashboard', 'desc' => 'Business KPIs, charts, and recent orders'],
            ],
        ],
        'operations' => [
            'label' => 'Operations',
            'badge' => 'Daily',
            'groups' => [
                'sales' => [
                    'label' => 'Sales Management',
                    'icon'  => 'shopping-bag',
                    'badge' => 'POS',
                    'items' => [
                        'sales.pos'      => ['label' => 'POS Terminal', 'route' => 'sales/pos', 'desc' => 'High-speed touch café point of sale'],
                        'sales.orders'   => ['label' => 'Sales Orders', 'route' => 'sales', 'desc' => 'View completed and pending customer orders'],
                        'sales.returns'  => ['label' => 'Sales Returns', 'route' => 'sales/returns', 'desc' => 'Customer returns, credit notes and refunds; requires sales.refund'],
                    ],
                ],
                'inventory' => [
                    'label' => 'Inventory Management',
                    'icon'  => 'boxes',
                    'items' => [
                        'inventory.products'    => ['label' => 'Medicines & Catalog', 'route' => 'inventory/products', 'desc' => 'Medicines, dosage forms, and prices'],
                        'inventory.categories'  => ['label' => 'Categories', 'route' => 'inventory/categories', 'desc' => 'Medicine categories and therapeutic groups'],
                        'inventory.stock'       => ['label' => 'Stock Levels & Ledger', 'route' => 'inventory/stock', 'desc' => 'Medicine batch balances and expiry tracking'],
                        'inventory.adjustments' => ['label' => 'Stock Adjustments', 'route' => 'inventory/adjustments', 'desc' => 'Waste, spoilage, and count reconciliations'],
                    ],
                ],
                'suppliers' => [
                    'label' => 'Supplier Management',
                    'icon'  => 'truck',
                    'items' => [
                        'suppliers.list'      => ['label' => 'Suppliers List', 'route' => 'suppliers', 'desc' => 'Vendor directory and contact details'],
                        'suppliers.purchases' => ['label' => 'Purchase Orders', 'route' => 'suppliers/purchases', 'desc' => 'Stock procurement and order logs'],
                        'suppliers.receiving' => ['label' => 'Goods Receiving', 'route' => 'suppliers/receiving', 'desc' => 'Inbound shipment inspection and check-in'],
                        'suppliers.returns' => ['label' => 'Purchase Returns', 'route' => 'suppliers/returns', 'desc' => 'Return received goods and collect supplier refunds; requires purchases.return'],
                        'finance.bills'    => ['label' => 'Supplier Bills (AP)', 'route' => 'suppliers/bills', 'desc' => 'Accounts payable and incoming bills'],
                        'finance.payments' => ['label' => 'Supplier Payments', 'route' => 'suppliers/payments', 'desc' => 'Outbound vendor disbursements'],
                    ],
                ],
                'clients' => [
                    'label' => 'Client Management',
                    'icon'  => 'users',
                    'items' => [
                        'clients.list'       => ['label' => 'Customers', 'route' => 'clients', 'desc' => 'Loyalty customers and credit buyers'],
                        'clients.receipts'   => ['label' => 'Customer Receipts', 'route' => 'clients/receipts', 'desc' => 'Collect unpaid and partially paid customer invoices'],
                        'clients.deposits'   => ['label' => 'Customer Deposits', 'route' => 'clients/deposits', 'desc' => 'Advance balances and store credit'],
                    ],
                ],
            ],
        ],
        'finance_reports' => [
            'label' => 'Finance & Reports',
            'badge' => 'Insights',
            'groups' => [
                'finance' => [
                    'label' => 'Financial Management',
                    'icon'  => 'landmark',
                    'items' => [
                        'finance.accounts' => ['label' => 'Chart of Accounts', 'route' => 'finance/accounts', 'desc' => 'General ledger account architecture (PRO)'],
                        'finance.journals' => ['label' => 'Journal Entries', 'route' => 'finance/journals', 'desc' => 'Double-entry bookkeeping transactions (PRO)'],
                        'finance.pos_methods' => ['label' => 'POS Payment Methods', 'route' => 'finance/pos-methods', 'desc' => 'Manage POS payment methods and their ledger accounts'],
                        'finance.periods'  => ['label' => 'Financial Periods', 'route' => 'finance/periods', 'desc' => 'Fiscal month and year closing (PRO)'],
                        'finance.taxes' => ['label'=>'Tax Management','route'=>'finance/taxes','desc'=>'Manage the active POS tax percentage; requires settings management'],
                    ],
                ],
                'reports' => [
                    'label' => 'Reports & Analytics',
                    'icon'  => 'bar-chart-3',
                    'items' => [
                        'reports.sales'      => ['label' => 'Sales Reports', 'route' => 'reports/sales', 'desc' => 'Daily breakdown, top items, revenue curve'],
                        'reports.inventory'  => ['label' => 'Inventory Valuation', 'route' => 'reports/inventory', 'desc' => 'Cost-basis stock value and turnover rates'],
                        'reports.financial'  => ['label' => 'Financial Statements', 'route' => 'reports/financial', 'desc' => 'P&L statements and trial balance (PRO)'],
                        'reports.customers' => ['label'=>'Customer Report','route'=>'reports/customers','desc'=>'Customers, orders, collections and closing balances'],
                        'reports.suppliers' => ['label'=>'Supplier Report','route'=>'reports/suppliers','desc'=>'Supplier purchases, receipts, bills and balances'],
                        'reports.income-statement' => ['label'=>'Income Statement','route'=>'reports/income-statement','desc'=>'Financial statement'],
                        'reports.balance-sheet' => ['label'=>'Balance Sheet','route'=>'reports/balance-sheet','desc'=>'Financial statement'],
                        'reports.cash-flow' => ['label'=>'Cash Flow','route'=>'reports/cash-flow','desc'=>'Financial statement'],
                        'reports.trial-balance' => ['label'=>'Trial Balance','route'=>'reports/trial-balance','desc'=>'Financial statement'],
                    ],
                ],
            ],
        ],
        'administration' => [
            'label' => 'Administration',
            'badge' => 'Security',
            'groups' => [
                'users' => [
                    'label' => 'User Management',
                    'icon'  => 'shield-check',
                    'items' => [
                        'users.staff' => ['label' => 'Staff Users', 'route' => 'users', 'desc' => 'Store employee accounts and login credentials'],
                        'users.roles' => ['label' => 'Roles & Permissions', 'route' => 'users/roles', 'desc' => 'RBAC action privileges and sidebar access'],
                        'users.audit' => ['label' => 'Audit Trail Log', 'route' => 'audit', 'desc' => 'Tamper-evident system activity ledger'],
                    ],
                ],
            ],
        ],
    ];

    /**
     * Returns a flat array of all registered menu keys.
     *
     * @return list<string>
     */
    public function getAllMenuKeys(): array
    {
        $keys = [];
        foreach (self::MENU_STRUCTURE as $section) {
            if (isset($section['items'])) {
                foreach ($section['items'] as $k => $info) {
                    $keys[] = $k;
                }
            }
            if (isset($section['groups'])) {
                foreach ($section['groups'] as $group) {
                    foreach ($group['items'] as $k => $info) {
                        $keys[] = $k;
                    }
                }
            }
        }
        return $keys;
    }

    /**
     * Get menu keys assigned to a role.
     *
     * @return list<string>
     */
    public function getRoleMenuKeys(int $roleId): array
    {
        $db = db_connect();

        // Administrative tiers have universal menus; only Superadmin gets Operational Settings.
        $role = $db->table('roles')->where('id', $roleId)->get()->getRowArray();
        if ($role && \App\Libraries\AdministrativeAccess::isFixedRole($role['name'] ?? '')) {
            return $this->getAllMenuKeys();
        }

        $rows = $db->table('role_menu_permissions')
            ->select('menu_key')
            ->where('role_id', $roleId)
            ->get()
            ->getResultArray();

        if (! empty($rows)) {
            $keys = array_column($rows, 'menu_key');
            if (in_array('sales.invoices',$keys,true)) $keys[]='sales.orders';
            if (in_array('finance.banks', $keys, true)) $keys[] = 'finance.pos_methods';
            return array_values(array_unique($keys));
        }

        // Fallback default if not yet configured
        return $this->getDefaultRoleKeys($role['name'] ?? '');
    }

    /**
     * Get allowed menu keys for a specific user (taking into account user-level overrides).
     *
     * @return list<string>
     */
    public function getUserMenuKeys(int $userId, int $roleId): array
    {
        $db = db_connect();
        $role = $db->table('roles')->where('id', $roleId)->get()->getRowArray();
        if (\App\Libraries\AdministrativeAccess::isFixedRole($role['name'] ?? '')) return $this->getAllMenuKeys();

        // Check if user has custom overrides
        $userRows = $db->table('user_menu_permissions')
            ->select('menu_key, is_allowed')
            ->where('user_id', $userId)
            ->get()
            ->getResultArray();

        if (! empty($userRows)) {
            $allowed = [];
            foreach ($userRows as $r) {
                if ((int) $r['is_allowed'] === 1) {
                    $allowed[] = $r['menu_key'];
                }
            }
            if (in_array('sales.invoices',$allowed,true)) $allowed[]='sales.orders';
            if (in_array('finance.banks', $allowed, true)) $allowed[] = 'finance.pos_methods';
            return array_values(array_unique($allowed));
        }

        // Fallback to role-level menu permissions
        return $this->getRoleMenuKeys($roleId);
    }

    /**
     * Checks if a user has custom overrides active or is inheriting from their role.
     */
    public function hasCustomUserOverrides(int $userId): bool
    {
        $db = db_connect();
        return $db->table('user_menu_permissions')->where('user_id', $userId)->countAllResults() > 0;
    }

    /**
     * Save menu keys for a role.
     *
     * @param list<string> $menuKeys
     */
    public function saveRoleMenuKeys(int $roleId, array $menuKeys): void
    {
        $db = db_connect();
        $role = $db->table('roles')->where('id', $roleId)->get()->getRowArray();
        if (!$role) throw new \InvalidArgumentException('ROLE_NOT_FOUND');
        \App\Libraries\AdministrativeAccess::assertRoleEditable($role);
        $db->transException(true)->transStart();

        $db->table('role_menu_permissions')->where('role_id', $roleId)->delete();

        $allValid = $this->getAllMenuKeys();
        $filtered = array_values(array_intersect($menuKeys, $allValid));

        if (! empty($filtered)) {
            $batch = array_map(static fn (string $k): array => [
                'role_id'  => $roleId,
                'menu_key' => $k,
            ], $filtered);

            $db->table('role_menu_permissions')->insertBatch($batch);
        }

        $db->transComplete();

        AuditService::log('role.menu_permissions.update', 'roles', 'roles', $roleId, after: ['menu_keys' => $filtered]);
    }

    /**
     * Save menu keys for a specific user.
     *
     * @param list<string> $menuKeys
     */
    public function saveUserMenuKeys(int $userId, array $menuKeys): void
    {
        $db = db_connect();
        $this->assertUserMenuEditable($userId);
        $db->transException(true)->transStart();

        $db->table('user_menu_permissions')->where('user_id', $userId)->delete();

        $allValid = $this->getAllMenuKeys();
        $filtered = array_values(array_intersect($menuKeys, $allValid));

        $batch = [];
        foreach ($allValid as $k) {
            $batch[] = [
                'user_id'    => $userId,
                'menu_key'   => $k,
                'is_allowed' => in_array($k, $filtered, true) ? 1 : 0,
            ];
        }

        if (! empty($batch)) {
            $db->table('user_menu_permissions')->insertBatch($batch);
        }

        $db->transComplete();

        AuditService::log('user.menu_permissions.update', 'users', 'users', $userId, after: ['menu_keys' => $filtered]);
    }

    /**
     * Reset a user's menu permissions so they inherit dynamically from their role.
     */
    public function resetUserToRole(int $userId): void
    {
        $this->assertUserMenuEditable($userId);
        $db = db_connect();
        $db->table('user_menu_permissions')->where('user_id', $userId)->delete();

        AuditService::log('user.menu_permissions.reset', 'users', 'users', $userId);
    }

    /**
     * Default fallbacks when no records are in the DB.
     */
    private function getDefaultRoleKeys(string $roleName): array
    {
        $lower = strtolower($roleName);
        if (\App\Libraries\AdministrativeAccess::isFixedRole($lower)) {
            return $this->getAllMenuKeys();
        }
        if ($lower === 'manager') {
            return array_values(array_filter($this->getAllMenuKeys(), fn($k) => $k !== 'users.roles'));
        }
        if ($lower === 'cashier') {
            return ['dashboard', 'sales.pos', 'sales.orders', 'inventory.products', 'inventory.stock'];
        }

        // Generic custom role defaults to dashboard and sales POS
        return ['dashboard', 'sales.pos'];
    }

    private function assertUserMenuEditable(int $userId): void
    {
        \App\Libraries\AdministrativeAccess::assertUserManageable($userId);
        $role = db_connect()->table('users u')->select('r.name')->join('roles r','r.id=u.role_id')
            ->where('u.id',$userId)->get()->getRowArray();
        if (!$role) throw new \InvalidArgumentException('USER_NOT_FOUND');
        \App\Libraries\AdministrativeAccess::assertRoleEditable($role);
    }
}
