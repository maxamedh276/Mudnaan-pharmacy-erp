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
// =====================================================================
//  WEB PORTAL ROUTES (Phase 1: Auth & Core Dashboard Layout)
// =====================================================================

// Public auth routes
$routes->get('login', 'Web\AuthController::login', ['filter'=>'csrf']);
$routes->post('login', 'Web\AuthController::authenticate', ['filter'=>'csrf']);
$routes->get('logout', 'Web\AuthController::logout');
$routes->post('web/session-sync', 'Web\AuthController::sessionSync');

// Protected web routes (requires valid web session / token)
$routes->group('', ['filter' => 'web_auth'], static function (RouteCollection $routes) {
    $routes->get('pharmacy', 'Web\PharmacyWebController::index');
    $routes->get('pharmacy/export', 'Web\PharmacyWebController::export');
    $routes->get('pharmacy/receipts', 'Web\PharmacyWebController::receipts');
    $routes->get('pharmacy/held-sales', 'Web\PharmacyWebController::heldSales');
    $routes->get('pharmacy/storage/data', 'Web\PharmacyWebController::storageData');
    $routes->get('pharmacy/import/template', 'Web\PharmacyWebController::importTemplate');
    $routes->get('pharmacy/counts/data', 'Web\PharmacyWebController::countList');
    $routes->get('pharmacy/counts/(:num)', 'Web\PharmacyWebController::countDetail/$1');
    $routes->post('pharmacy/prescriptions/(:num)/documents', 'Web\PharmacyWebController::prescriptionUpload/$1');
    $routes->get('pharmacy/documents/(:num)', 'Web\PharmacyWebController::prescriptionDownload/$1');
    $routes->get('pharmacy/batches/(:num)/trace', 'Web\PharmacyWebController::batchTrace/$1');
    $routes->get('pharmacy/lookup/(:segment)', 'Web\PharmacyWebController::partyLookup/$1');
    $routes->get('pharmacy/availability', 'Web\PharmacyWebController::availability');
    $routes->get('pharmacy/patients/(:num)/history', 'Web\PharmacyWebController::patientHistory/$1');
    $routes->get('pharmacy/receipt/(:num)', 'Web\PharmacyWebController::receipt/$1');
    $routes->get('pharmacy/labels/(:num)', 'Web\PharmacyWebController::labels/$1');
    $routes->get('pharmacy/prescriptions/(:num)', 'Web\PharmacyWebController::prescription/$1');
    $routes->get('pharmacy/(:segment)', 'Web\PharmacyWebController::index/$1');
    $routes->post('pharmacy/(:segment)/save', 'Web\PharmacyWebController::save/$1');
    $routes->get('web/imports/(:segment)/template', 'Web\WorkbookImportsController::template/$1');
    $routes->post('web/imports/(:segment)', 'Web\WorkbookImportsController::upload/$1');
    $routes->get('/', static fn () => redirect()->to('workspace'));
    $routes->get('workspace', 'Web\WorkspaceController::index');
    $routes->get('workspace/(:segment)', 'Web\WorkspaceController::index/$1');
    $routes->get('dashboard', 'Web\WorkspaceController::index');
    $routes->get('dashboard/data', 'Web\DashboardController::data');
    $routes->get('dashboard/orders/(:num)', 'Web\DashboardController::order/$1');
    $routes->post('web/printing/sales/(:num)', 'Web\PrintingWebController::sale/$1');
    $routes->post('web/printing/receipts/(:num)', 'Web\PrintingWebController::receipt/$1');
    $routes->get('web/printing/signing', 'Web\PrintingWebController::signing');
    $routes->post('web/printing/signing', 'Web\PrintingWebController::saveSigning');
    $routes->post('web/printing/sign', 'Web\PrintingWebController::sign');
    $routes->post('web/printing/events', 'Web\PrintingWebController::event');
    $routes->post('web/printing/test', 'Web\PrintingWebController::test');

    // Phase 2 Web Routes: Financial Management, Users & Roles, Settings
    $routes->get('finance', 'Web\FinanceWebController::index/accounts');
    $routes->get('finance/taxes', 'Web\TaxesWebController::index');
    $routes->post('finance/taxes/save', 'Web\TaxesWebController::save');
    $routes->get('finance/journals/new', 'Web\FinanceWebController::newJournal');
    $routes->get('finance/receipts/orders/(:num)', 'Web\FinanceWebController::receiptInvoice/$1');
    $routes->get('finance/receipts/details/(:num)', 'Web\FinanceWebController::receiptDetails/$1');
    $routes->post('finance/receipts/save', 'Web\FinanceWebController::saveCustomerReceipt');
    $routes->post('finance/supplier-payments/save', 'Web\FinanceWebController::saveSupplierPayment');
    $routes->post('finance/receipts/(:num)/void', 'Web\FinanceWebController::voidCustomerReceipt/$1');
    $routes->post('finance/periods/save', 'Web\FinanceWebController::savePeriod');
    $routes->post('finance/periods/(:num)/(:segment)', 'Web\FinanceWebController::changePeriod/$1/$2');
    $routes->get('finance/accounts/group/(:segment)', 'Web\FinanceWebController::accountGroup/$1');
    $routes->post('finance/accounts/create', 'Web\FinanceWebController::createAccount');
    $routes->get('finance/accounts/group', 'Web\FinanceWebController::accountGroup');
    $routes->get('finance/pos-methods/data', 'Web\FinanceWebController::paymentMethods');
    $routes->post('finance/pos-methods/reorder', 'Web\FinanceWebController::reorderPaymentMethods');
    $routes->post('finance/pos-methods/save', 'Web\FinanceWebController::savePosPaymentMethod');
    $routes->post('finance/pos-methods/toggle/(:num)', 'Web\FinanceWebController::togglePosPaymentMethod/$1');
    $routes->post('finance/pos-methods/delete/(:num)', 'Web\FinanceWebController::deletePosPaymentMethod/$1');
    $routes->get('finance/bills', static fn () => redirect()->to(site_url('suppliers/bills')));
    $routes->get('finance/payments', static fn () => redirect()->to(site_url('suppliers/payments')));
    $routes->get('finance/(:segment)', 'Web\FinanceWebController::index/$1');
    $routes->get('users', 'Web\UsersWebController::index');
    $routes->get('users/roles', 'Web\RolesWebController::index');
    $routes->post('users/roles/create', 'Web\RolesWebController::create');
    $routes->get('users/audit', 'Web\AuditWebController::index');
    $routes->get('audit', 'Web\AuditWebController::index');
    $routes->get('settings', 'Web\SettingsWebController::index');
    $routes->get('settings/operational', 'Web\SettingsWebController::operational');
    $routes->post('settings/save', 'Web\SettingsWebController::save');
    $routes->post('settings/printer', 'Web\SettingsWebController::printer');
    $routes->post('finance/receipts/(:num)/print', 'Web\FinanceWebController::printCustomerReceipt/$1');
    $routes->post('settings/logo', 'Web\SettingsWebController::logo');

    // Phase 3 Web Routes: Supplier Management & Inventory Management
    $routes->get('suppliers', 'Web\SuppliersWebController::index');
    $routes->get('suppliers/returns', 'Web\ReturnsWebController::index/purchase');
    $routes->get('suppliers/returns/source/(:num)', 'Web\ReturnsWebController::source/purchase/$1');
    $routes->get('suppliers/returns/details/(:num)', 'Web\ReturnsWebController::details/purchase/$1');
    $routes->post('suppliers/returns/quote/(:num)', 'Web\ReturnsWebController::quote/purchase/$1');
    $routes->post('suppliers/returns/save/(:num)', 'Web\ReturnsWebController::save/purchase/$1');
    $routes->post('suppliers/purchases/save', 'Web\SuppliersWebController::savePurchase');
    $routes->get('suppliers/(:num)/statement', 'Web\SuppliersWebController::statement/$1');
    $routes->get('suppliers/purchases', 'Web\SuppliersWebController::purchases');
    $routes->get('suppliers/receiving', 'Web\SuppliersWebController::receiving');
    $routes->get('suppliers/bills', 'Web\FinanceWebController::index/bills');
    $routes->get('suppliers/payments', 'Web\FinanceWebController::index/payments');
    $routes->get('suppliers/(:segment)', 'Web\SuppliersWebController::index');

    $routes->get('inventory/selling-options', 'Web\InventoryWebController::sellingOptions');
    $routes->post('inventory/selling-options/save', 'Web\InventoryWebController::saveSellingOptions');
    $routes->get('inventory/categories/data', 'Web\InventoryWebController::categoryData');
    $routes->post('inventory/categories/create', 'Web\InventoryWebController::createCategory');
    $routes->post('inventory/categories/delete/(:num)', 'Web\InventoryWebController::deleteCategory/$1');
    $routes->post('inventory/categories/update/(:num)', 'Web\InventoryWebController::updateCategory/$1');
    $routes->get('inventory/products/data', 'Web\InventoryWebController::productData');
    $routes->post('inventory/products/save', 'Web\InventoryWebController::saveProduct');
    $routes->get('inventory', 'Web\InventoryWebController::products');
    $routes->get('inventory/products', 'Web\InventoryWebController::products');
    $routes->get('inventory/categories', 'Web\InventoryWebController::categories');
    $routes->post('inventory/stock/open-package', 'Web\InventoryWebController::openPackage');
    $routes->post('inventory/stock/adjust', 'Web\InventoryWebController::adjustStock');
    $routes->get('inventory/stock', 'Web\InventoryWebController::stock');
    $routes->get('inventory/adjustments', 'Web\InventoryWebController::adjustments');
    $routes->get('inventory/(:segment)', 'Web\InventoryWebController::products');

    // Phase 4 Web Routes: Sales, POS & Client Management
    $routes->get('pos', 'Web\PharmacyWebController::index/dispense');
    $routes->get('sales/pos', 'Web\PharmacyWebController::index/dispense');
    $routes->get('sales/pos/stock-data', 'Web\PosWebController::stockData');
    $routes->get('sales/pos/scan', 'Web\PosWebController::scan');
    $routes->post('sales/pos/checkout', 'Web\PosWebController::checkout');
    $routes->get('sales', 'Web\SalesWebController::index');
    $routes->get('sales/returns', 'Web\ReturnsWebController::index/sale');
    $routes->get('sales/returns/source/(:num)', 'Web\ReturnsWebController::source/sale/$1');
    $routes->get('sales/returns/details/(:num)', 'Web\ReturnsWebController::details/sale/$1');
    $routes->post('sales/returns/quote/(:num)', 'Web\ReturnsWebController::quote/sale/$1');
    $routes->post('sales/returns/save/(:num)', 'Web\ReturnsWebController::save/sale/$1');
    $routes->get('sales/invoices', 'Web\SalesWebController::index');
    $routes->get('sales/(:segment)', 'Web\SalesWebController::index');

    $routes->get('clients', 'Web\ClientsWebController::index');
    $routes->post('clients/create', 'Web\ClientsWebController::save');
    $routes->post('clients/(:num)/save', 'Web\ClientsWebController::save/$1');
    $routes->get('clients/(:num)/credit', 'Web\ClientsWebController::credit/$1');
    $routes->get('clients/receipts', 'Web\FinanceWebController::index/receipts');
    $routes->get('clients/deposits', 'Web\ClientsWebController::deposits');
    $routes->get('clients/statements', static fn () => redirect()->to(site_url('clients')));
    $routes->get('clients/(:num)/statement', 'Web\ClientsWebController::statement/$1');
    $routes->get('clients/(:num)/statement/data', 'Web\ClientsWebController::statementData/$1');

    // Phase 5: Reports & Analytics Hub
    $routes->get('reports', 'Web\ReportsWebController::index');
    $routes->get('reports/(:segment)/data', 'Web\ReportsWebController::data/$1');
    $routes->get('reports/(:segment)', 'Web\ReportsWebController::index/$1');

    // Shortcuts & Backward Compatibility Aliases
    $routes->get('catalog', 'Web\InventoryWebController::products');
    $routes->get('catalog/(:segment)', 'Web\InventoryWebController::products');
    $routes->get('stock', 'Web\InventoryWebController::stock');
    $routes->get('stock/(:segment)', 'Web\InventoryWebController::stock');
    $routes->get('expenses', 'Web\ModuleController::render/expenses');
    $routes->get('expenses/(:segment)', 'Web\ModuleController::render/expenses/$1');
    $routes->get('team', 'Web\UsersWebController::index');
    $routes->get('team/(:segment)', 'Web\UsersWebController::index');
});

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
        $routes->post('sales/(:num)/print', 'Pos\SalesController::printReceipt/$1');
        $routes->get('sales/(:num)/escpos', 'Pos\SalesController::escposStream/$1');

        // Thermal Printer Hardware API (escpos-php)
        $routes->get('printer/settings', 'Pos\SalesController::getPrinterSettings');
        $routes->post('printer/settings', 'Pos\SalesController::savePrinterSettings');
        $routes->post('printer/test', 'Pos\SalesController::testPrinter');

        // Catalog
        $routes->get('products', 'Pos\ProductsController::index');
        $routes->post('products', 'Pos\ProductsController::create');
        $routes->get('products/(:num)', 'Pos\ProductsController::show/$1');
        $routes->put('products/(:num)', 'Pos\ProductsController::update/$1');
        $routes->post('products/(:num)/image', 'Pos\ProductsController::uploadImage/$1');
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
        $routes->get('purchases/(:num)/receipts', 'Inventory\PurchasesController::receipts/$1');
        $routes->post('purchases/(:num)/receipts', 'Inventory\PurchasesController::receive/$1');

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

        // Staff, roles & permissions (users.manage)
        $routes->get('users', 'Admin\UsersController::index');
        $routes->post('users', 'Admin\UsersController::create');
        $routes->get('users/(:num)', 'Admin\UsersController::show/$1');
        $routes->put('users/(:num)', 'Admin\UsersController::update/$1');
        $routes->post('users/(:num)/active', 'Admin\UsersController::setActive/$1');
        $routes->get('roles', 'Admin\RolesController::index');
        $routes->post('roles', 'Admin\RolesController::create');
        $routes->get('roles/(:num)', 'Admin\RolesController::show/$1');
        $routes->put('roles/(:num)', 'Admin\RolesController::update/$1');
        $routes->delete('roles/(:num)', 'Admin\RolesController::delete/$1');
        $routes->get('permissions', 'Admin\RolesController::permissions');

        // Granular Sidebar Menu Permissions by Role or User
        $routes->get('menu-permissions/role/(:num)', 'Admin\MenuPermissionsController::getRole/$1');
        $routes->put('menu-permissions/role/(:num)', 'Admin\MenuPermissionsController::updateRole/$1');
        $routes->get('menu-permissions/user/(:num)', 'Admin\MenuPermissionsController::getUser/$1');
        $routes->put('menu-permissions/user/(:num)', 'Admin\MenuPermissionsController::updateUser/$1');
        $routes->post('menu-permissions/user/(:num)/reset', 'Admin\MenuPermissionsController::resetUser/$1');
        $routes->get('settings', 'Admin\SettingsController::index');
        $routes->post('settings', 'Admin\SettingsController::update');
        $routes->put('settings', 'Admin\SettingsController::update');

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
