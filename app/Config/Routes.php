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

        // ---- PREMIUM ONLY -------------------------------------------
        $routes->group('', ['filter' => 'plan:premium'], static function (RouteCollection $routes) {
            // accounting + advanced reports routes land here as modules are built
        });
    });
});
