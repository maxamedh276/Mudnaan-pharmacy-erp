<?php

/**
 * Run: php tests/stock_adjustment_web_smoke.php
 * Uses connection-local temporary tables; existing ERP records are never changed.
 * Requires the configured local tenant database and its schema.
 */
declare(strict_types=1);

define('FCPATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
define('ENVIRONMENT', 'development');
require FCPATH . 'app/Config/Paths.php';
$paths = new Config\Paths();
require $paths->systemDirectory . '/Boot.php';
CodeIgniter\Boot::bootConsole($paths);

use App\Controllers\Web\FinanceWebController;
use App\Libraries\AuthContext;
use App\Libraries\TenantManager;
use App\Services\ChartOfAccountsService;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\Response;
use CodeIgniter\HTTP\URI;

function verify(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
    echo "PASS: $message\n";
}

$tenant = (new TenantManager())->find(config(Config\Tenants::class)->default);
if (!$tenant) throw new RuntimeException('No configured tenant.');
(new TenantManager())->connect($tenant);
$db = db_connect();
AuthContext::$tenant = $tenant;
AuthContext::$user = (object) ['id' => 1, 'branch_id' => 1];
AuthContext::$permissions = ['stock.view', 'stock.adjust'];


session()->set('user', ['branch_id' => 1]);


$tables = ['products', 'stock_levels', 'stock_movements', 'web_stock_adjustment_details', 'audit_log', 'financial_periods', 'accounts', 'journal_entries', 'journal_entry_lines'];
$originalCounts = [];
foreach ($tables as $table) {
    $originalCounts[$table] = $db->table($table)->countAllResults();
    $rows = $table === 'accounts' ? $db->table($table)->get()->getResultArray() : [];
    $definition = array_values($db->query("SHOW CREATE TABLE `$table`")->getRowArray())[1];
    $definition = preg_replace('/^CREATE TABLE/', 'CREATE TEMPORARY TABLE', $definition);
    $definition = preg_replace('/^\s*CONSTRAINT .*\n/m', '', $definition);
    $definition = preg_replace('/,\s*\)/', "\n)", $definition);
    $db->query($definition);
    if ($rows) $db->table($table)->insertBatch($rows);
}
try {
    $db->table('products')->insert(['name' => 'Temporary adjustment product', 'sku' => 'TEMP-ADJ', 'unit' => 'pcs', 'sell_price' => 8, 'cost_price' => 2, 'is_active' => 1]);
    $productId = (int) $db->insertID();
    $service = new App\Services\Web\StockAdjustmentService();
    $input = ['product_id' => $productId, 'qty_change' => 1.125, 'note' => 'Physical count: damaged box replaced', 'request_key' => App\Libraries\Uuid::v4()];
    $first = $service->save($input, 1, 1, 'premium');
    verify($first['product']['quantity'] === 1.125, 'The first adjustment creates stock for a product without a stock row.');
    verify($db->table('stock_movements')->countAllResults() === 1 && $db->table('journal_entries')->countAllResults() === 1, 'Post exactly one stock movement and Premium journal.');
    verify($service->notesForMovements(1, [$first['movement_id']])[$first['movement_id']] === $input['note'], 'Display the exact typed reason against its movement.');
    $retried = $service->save($input, 1, 1, 'premium');
    verify($retried['already_saved'] && $retried['product']['quantity'] === 1.125 && $db->table('journal_entries')->countAllResults() === 1, 'Retrying a saved submission never adjusts stock or journals twice.');
    $decrease = array_replace($input, ['request_key' => App\Libraries\Uuid::v4(), 'qty_change' => -0.125, 'note' => 'One partial unit damaged']);
    verify($service->save($decrease, 1, 1, 'premium')['product']['quantity'] === 1.0, 'Decrease stock using three-decimal precision.');
    try { $service->save(array_replace($decrease, ['request_key' => App\Libraries\Uuid::v4(), 'qty_change' => -2]), 1, 1, 'premium'); throw new RuntimeException('Negative stock accepted.'); }
    catch (InvalidArgumentException $exception) { verify($exception->getMessage() === 'INSUFFICIENT_STOCK', 'Reject a decrease below zero.'); }
    $db->table('financial_periods')->insert(['name' => 'Temporary closed period', 'start_date' => date('Y-m-d'), 'end_date' => date('Y-m-d'), 'status' => 'closed']);
    try { $service->save(array_replace($input, ['request_key' => App\Libraries\Uuid::v4()]), 1, 1, 'premium'); throw new RuntimeException('Closed-period posting accepted.'); }
    catch (RuntimeException $exception) { verify($exception->getMessage() === 'PERIOD_CLOSED', 'Report a closed period as a failed adjustment.'); }
    verify((new App\Services\InventoryService())->currentQuantity(1, $productId) === 1.0 && $db->table('stock_movements')->countAllResults() === 2 && $db->table('web_stock_adjustment_details')->countAllResults() === 2, 'Accounting failure rolls back quantity, movement, and reason metadata.');
    $db->table('financial_periods')->where('id >', 0)->delete();
    verify($service->save(array_replace($input, ['request_key' => App\Libraries\Uuid::v4()]), 1, 1, 'premium')['product']['quantity'] === 2.125, 'A valid subsequent adjustment works after a failed attempt.');
    $db->table('stock_movements')->insert(['branch_id' => 1, 'product_id' => $productId, 'qty_change' => 3, 'reason' => 'adjustment', 'ref_type' => 'adjustment', 'user_id' => 1, 'created_at' => date('Y-m-d H:i:s', time() - 30)]);
    $legacyId = (int) $db->insertID();
    $db->table('audit_log')->insert(['action' => 'stock.adjust', 'module' => 'inventory', 'entity' => 'stock_movements', 'entity_id' => $productId, 'user_id' => 1, 'after_values' => json_encode(['qty_change' => 3, 'note' => 'Legacy typed reason']), 'created_at' => date('Y-m-d H:i:s', time() - 30)]);
    verify($service->notesForMovements(1, [$legacyId])[$legacyId] === 'Legacy typed reason', 'Recover uniquely matched legacy reasons from the audit log without rewriting records.');
    verify($service->notesForMovements(2, [$legacyId]) === [], 'Do not show another branch movement reasons.');
    $db->table('audit_log')->insert(['action' => 'stock.adjust', 'module' => 'inventory', 'entity' => 'stock_movements', 'entity_id' => $productId, 'user_id' => 1, 'after_values' => json_encode(['qty_change' => 3, 'note' => 'Ambiguous reason']), 'created_at' => date('Y-m-d H:i:s', time() - 30)]);
    verify($service->notesForMovements(1, [$legacyId]) === [], 'Do not guess a reason when multiple legacy audit records match.');
    foreach (['note' => '', 'qty_change' => 0.0001] as $field => $value) {
        try { $service->save(array_replace($input, [$field => $value, 'request_key' => App\Libraries\Uuid::v4()]), 1, 1, 'premium'); throw new RuntimeException('Invalid adjustment accepted.'); }
        catch (InvalidArgumentException $exception) { verify(true, 'Validate required reason and quantity precision.'); }
    }
    $request = new IncomingRequest(config(Config\App::class), new URI('http://localhost/inventory/stock/adjust'), json_encode($input), new CodeIgniter\HTTP\UserAgent());
    $request->setHeader('Content-Type', 'application/json');
    $controller = new App\Controllers\Web\InventoryWebController();
    $controller->initController($request, new Response(config(Config\App::class)), service('logger'));
    AuthContext::$permissions = ['stock.view'];
    try { $controller->adjustStock(); throw new RuntimeException('Unauthorized adjustment accepted.'); }
    catch (CodeIgniter\HTTP\Exceptions\HTTPException $exception) { verify($exception->getCode() === 403, 'Read-only stock users cannot submit adjustments.'); }

} finally {
    foreach (array_reverse($tables) as $table) $db->query("DROP TEMPORARY TABLE IF EXISTS `$table`");
    foreach ($originalCounts as $table => $count) verify($db->table($table)->countAllResults() === $count, "$table original records remain unchanged.");
    AuthContext::reset();
}
