<?php

use CodeIgniter\Router\RouteCollection;

/**
 * All API endpoints, grouped by access level (see backend_structure.md §4):
 *   1. PUBLIC          — no token needed
 *   2. AUTHENTICATED   — any logged-in client (Basic OR Premium)
 *   3. PREMIUM ONLY    — the extra 'plan:premium' filter blocks Basic
 *
 * @var RouteCollection $routes
 */
$routes->get('/', 'Home::index');

$routes->group('api/v1', ['namespace' => 'App\Controllers'], static function (RouteCollection $routes) {

    // ---- PUBLIC: no token needed ------------------------------------
    $routes->post('auth/login', 'Auth\LoginController::login');

    // ---- AUTHENTICATED: any logged-in client ------------------------
    $routes->group('', ['filter' => 'auth'], static function (RouteCollection $routes) {

        $routes->get('auth/me', 'Auth\LoginController::me');
        $routes->post('auth/refresh', 'Auth\LoginController::refresh');
        $routes->post('auth/logout', 'Auth\LoginController::logout');

        // POS / Sales
        $routes->get('sales', 'Pos\SalesController::index');
        $routes->post('sales', 'Pos\SalesController::create');
        $routes->get('sales/(:num)', 'Pos\SalesController::show/$1');

        // Catalog
        $routes->get('products', 'Pos\ProductsController::index');
        $routes->post('products', 'Pos\ProductsController::create');
        $routes->get('products/(:num)', 'Pos\ProductsController::show/$1');
        $routes->put('products/(:num)', 'Pos\ProductsController::update/$1');
        $routes->delete('products/(:num)', 'Pos\ProductsController::delete/$1');
        $routes->get('categories', 'Pos\CategoriesController::index');
        $routes->post('categories', 'Pos\CategoriesController::create');

        // Inventory
        $routes->get('stock', 'Inventory\StockController::index');
        $routes->get('stock/movements', 'Inventory\StockController::movements');
        $routes->post('stock/adjustments', 'Inventory\StockController::adjust');

        // Purchases
        $routes->get('purchases', 'Inventory\PurchasesController::index');
        $routes->post('purchases', 'Inventory\PurchasesController::create');
        $routes->get('purchases/(:num)', 'Inventory\PurchasesController::show/$1');
        $routes->post('purchases/(:num)/void', 'Inventory\PurchasesController::void/$1');

        // People
        $routes->get('customers', 'People\CustomersController::index');
        $routes->post('customers', 'People\CustomersController::create');
        $routes->get('customers/(:num)', 'People\CustomersController::show/$1');
        $routes->get('customers/(:num)/statement', 'People\CustomersController::statement/$1');
        $routes->put('customers/(:num)', 'People\CustomersController::update/$1');
        $routes->delete('customers/(:num)', 'People\CustomersController::delete/$1');
        $routes->get('suppliers', 'People\SuppliersController::index');
        $routes->post('suppliers', 'People\SuppliersController::create');
        $routes->get('suppliers/(:num)', 'People\SuppliersController::show/$1');
        $routes->get('suppliers/(:num)/statement', 'People\SuppliersController::statement/$1');
        $routes->put('suppliers/(:num)', 'People\SuppliersController::update/$1');
        $routes->delete('suppliers/(:num)', 'People\SuppliersController::delete/$1');

        // Accounts payable — supplier bills + payments (all plans;
        // journal posting only happens for premium tenants)
        $routes->get('supplier-bills', 'Payables\SupplierBillsController::index');
        $routes->post('supplier-bills', 'Payables\SupplierBillsController::create');
        $routes->get('supplier-bills/(:num)', 'Payables\SupplierBillsController::show/$1');
        $routes->get('supplier-payments', 'Payables\SupplierPaymentsController::index');
        $routes->post('supplier-payments', 'Payables\SupplierPaymentsController::create');
        $routes->post('supplier-payments/(:num)/void', 'Payables\SupplierPaymentsController::void/$1');

        // Accounts receivable — customer invoices, receipts, deposits
        $routes->get('customer-invoices', 'Receivables\InvoicesController::index');
        $routes->post('customer-invoices', 'Receivables\InvoicesController::create');
        $routes->get('customer-invoices/(:num)', 'Receivables\InvoicesController::show/$1');
        $routes->get('customer-receipts', 'Receivables\ReceiptsController::index');
        $routes->post('customer-receipts', 'Receivables\ReceiptsController::create');
        $routes->post('customer-receipts/(:num)/void', 'Receivables\ReceiptsController::void/$1');
        $routes->get('customer-deposits', 'Receivables\DepositsController::index');
        $routes->post('customer-deposits', 'Receivables\DepositsController::create');
        $routes->post('customer-deposits/(:num)/refund', 'Receivables\DepositsController::refund/$1');

        // Basic dashboard (all plans)
        $routes->get('reports/dashboard', 'Reports\ReportsController::dashboard');

        // ---- PREMIUM ONLY -------------------------------------------
        $routes->group('', ['filter' => 'plan:premium'], static function (RouteCollection $routes) {
            $routes->get('reports/sales', 'Reports\ReportsController::sales');
            $routes->get('reports/inventory', 'Reports\ReportsController::inventory');
            // Financial statements
            $routes->get('reports/trial-balance', 'Reports\ReportsController::trialBalance');
            $routes->get('reports/income-statement', 'Reports\ReportsController::incomeStatement');
            $routes->get('reports/balance-sheet', 'Reports\ReportsController::balanceSheet');
            $routes->get('reports/cash-flow', 'Reports\ReportsController::cashFlow');
            $routes->get('reports/general-ledger', 'Reports\ReportsController::generalLedger');
            $routes->get('reports/account-ledger/(:num)', 'Reports\ReportsController::accountLedger/$1');
            // Sales
            $routes->get('reports/sales-by-customer', 'Reports\ReportsController::salesByCustomer');
            $routes->get('reports/sales-by-product', 'Reports\ReportsController::salesByProduct');
            $routes->get('reports/sales-by-category', 'Reports\ReportsController::salesByCategory');
            $routes->get('reports/most-sold', 'Reports\ReportsController::mostSold');
            $routes->get('reports/least-sold', 'Reports\ReportsController::leastSold');
            // Inventory
            $routes->get('reports/stock-valuation', 'Reports\ReportsController::stockValuation');
            $routes->get('reports/stock-movement', 'Reports\ReportsController::stockMovement');
            // Customer / supplier
            $routes->get('reports/customer-balances', 'Reports\ReportsController::customerBalances');
            $routes->get('reports/customer-aging', 'Reports\ReportsController::customerAging');
            $routes->get('reports/top-customers', 'Reports\ReportsController::topCustomers');
            $routes->get('reports/supplier-balances', 'Reports\ReportsController::supplierBalances');
            $routes->get('reports/supplier-aging', 'Reports\ReportsController::supplierAging');
            $routes->get('reports/top-suppliers', 'Reports\ReportsController::topSuppliers');
            $routes->get('accounts', 'Accounting\AccountsController::index');
            $routes->get('account-groups', 'Accounting\AccountsController::groups');
            $routes->post('accounts', 'Accounting\AccountsController::create');
            $routes->put('accounts/(:num)', 'Accounting\AccountsController::update/$1');
            $routes->post('accounts/(:num)/active', 'Accounting\AccountsController::deactivate/$1');
            $routes->delete('accounts/(:num)', 'Accounting\AccountsController::delete/$1');
            $routes->get('journal-entries', 'Accounting\JournalController::index');
            $routes->post('journal-entries', 'Accounting\JournalController::create');
            $routes->get('journal-entries/(:num)', 'Accounting\JournalController::show/$1');

            // Financial periods
            $routes->get('financial-periods', 'Accounting\FinancialPeriodsController::index');
            $routes->post('financial-periods', 'Accounting\FinancialPeriodsController::create');
            $routes->post('financial-periods/(:num)/close', 'Accounting\FinancialPeriodsController::close/$1');
            $routes->post('financial-periods/(:num)/reopen', 'Accounting\FinancialPeriodsController::reopen/$1');

            // Bank accounts
            $routes->get('bank-accounts', 'Accounting\BankAccountsController::index');
            $routes->post('bank-accounts', 'Accounting\BankAccountsController::create');
            $routes->put('bank-accounts/(:num)', 'Accounting\BankAccountsController::update/$1');

            // Budgets (budget vs actual)
            $routes->get('budgets', 'Accounting\BudgetsController::index');
            $routes->post('budgets', 'Accounting\BudgetsController::create');
            $routes->get('budgets/(:num)', 'Accounting\BudgetsController::show/$1');

            // Audit trail
            $routes->get('audit-log', 'Admin\AuditLogController::index');
            $routes->get('audit-log/filters', 'Admin\AuditLogController::filters');
        });
    });
});
