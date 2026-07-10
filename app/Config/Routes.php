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

        // People
        $routes->get('customers', 'People\CustomersController::index');
        $routes->post('customers', 'People\CustomersController::create');
        $routes->get('customers/(:num)', 'People\CustomersController::show/$1');
        $routes->put('customers/(:num)', 'People\CustomersController::update/$1');
        $routes->delete('customers/(:num)', 'People\CustomersController::delete/$1');
        $routes->get('suppliers', 'People\SuppliersController::index');
        $routes->post('suppliers', 'People\SuppliersController::create');
        $routes->get('suppliers/(:num)', 'People\SuppliersController::show/$1');
        $routes->put('suppliers/(:num)', 'People\SuppliersController::update/$1');
        $routes->delete('suppliers/(:num)', 'People\SuppliersController::delete/$1');

        // Basic dashboard (all plans)
        $routes->get('reports/dashboard', 'Reports\ReportsController::dashboard');

        // ---- PREMIUM ONLY -------------------------------------------
        $routes->group('', ['filter' => 'plan:premium'], static function (RouteCollection $routes) {
            $routes->get('reports/sales', 'Reports\ReportsController::sales');
            $routes->get('reports/inventory', 'Reports\ReportsController::inventory');
            $routes->get('accounts', 'Accounting\AccountsController::index');
            $routes->get('journal-entries', 'Accounting\JournalController::index');
            $routes->post('journal-entries', 'Accounting\JournalController::create');
            $routes->get('journal-entries/(:num)', 'Accounting\JournalController::show/$1');
        });
    });
});
