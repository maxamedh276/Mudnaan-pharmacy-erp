<?php

namespace App\Controllers\Web;

use App\Controllers\BaseController;

class ModuleController extends BaseController
{
    public function render(string $module, string $submodule = ''): string
    {
        $user = session()->get('user') ?? [];
        $tenant = session()->get('tenant') ?? [];
        $plan = session()->get('plan') ?? 'premium';

        $moduleTitles = [
            // 8 Top-Level Domains
            'sales' => [
                'Sales Management',
                'Point of sale terminal, customer orders, credit invoices, and receipts',
                'Phase 4',
                'shopping-bag'
            ],
            'inventory' => [
                'Inventory Management',
                'Product catalog, categories, real-time stock levels, ledger movements, and adjustments',
                'Phase 3',
                'boxes'
            ],
            'suppliers' => [
                'Supplier Management',
                'Supplier vendor profiles, purchase orders, goods receiving, and vendor statements',
                'Phase 3',
                'truck'
            ],
            'clients' => [
                'Client Management',
                'Customer directory, credit balances, customer deposits, and account statements',
                'Phase 4',
                'user-check'
            ],
            'finance' => [
                'Financial Management',
                'Accounts payable bills, disbursements, chart of accounts, journal entries, and bank accounts',
                'Phase 2',
                'landmark'
            ],
            'reports' => [
                'Reports & Analytics',
                'Sales analytics, stock valuation, general ledger, profit & loss, and balance sheet',
                'Phase 5',
                'bar-chart-3'
            ],
            'users' => [
                'User Management',
                'Staff user accounts, role definitions, granular permissions matrix, and audit logs',
                'Phase 2',
                'shield-check'
            ],
            'settings' => [
                'Store Settings',
                'Business profile, branches, currencies, tax rates, and receipt printing templates',
                'Phase 2',
                'settings'
            ],

            // Submodules & Aliases
            'pos'      => ['Point of Sale (POS)', 'Terminal, barcode scanner, multi-payment & receipt generator', 'Phase 4', 'shopping-bag'],
            'catalog'  => ['Catalog Management', 'Products, categories, price books, barcodes & variants', 'Phase 3', 'grid'],
            'expenses' => ['Expense & Bill Management', 'Supplier bills, payments, vouchers and running balances', 'Phase 2', 'credit-card'],
            'stock'    => ['Stock & Supply Management', 'Inventory ledger, purchase orders, receiving & adjustments', 'Phase 3', 'boxes'],
            'team'     => ['Team & Roles Management', 'Staff users, custom roles, permissions matrix and audit logs', 'Phase 2', 'users'],
        ];

        $info = $moduleTitles[$module] ?? [ucwords(str_replace('-', ' ', $module)), 'Manage and monitor your business data', 'Active', 'layers'];

        return view('web/module_placeholder', [
            'title'       => $info[0] . ' — Mudnaan Pharmacy',
            'moduleName'  => $info[0],
            'description' => $info[1],
            'phase'       => $info[2],
            'icon'        => $info[3] ?? 'layers',
            'slug'        => $module,
            'submodule'   => $submodule,
            'user'        => $user,
            'tenant'      => $tenant,
            'plan'        => $plan,
        ]);
    }
}
