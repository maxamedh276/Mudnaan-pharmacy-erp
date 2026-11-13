<?php

/**
 * Run: php tests/purchasing_web_smoke.php
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
AuthContext::$permissions = ['purchases.view', 'purchases.create', 'suppliers.view'];


session()->set('user', ['branch_id' => 1]);

$tables = ['purchases', 'purchase_items', 'purchase_receipts', 'purchase_receipt_items', 'products', 'suppliers', 'stock_levels', 'stock_movements', 'supplier_bills', 'supplier_payments', 'audit_log'];
$originalCounts = [];
foreach ($tables as $table) {
    $originalCounts[$table] = $db->table($table)->countAllResults();
    $definition = array_values($db->query("SHOW CREATE TABLE `$table`")->getRowArray())[1];
    $definition = preg_replace('/^CREATE TABLE/', 'CREATE TEMPORARY TABLE', $definition);
    $definition = preg_replace('/^\s*CONSTRAINT .*\n/m', '', $definition);
    $definition = preg_replace('/,\s*\)/', "\n)", $definition);
    $db->query($definition);
}
try {
    $db->table('suppliers')->insert(['name' => 'Temporary supplier', 'is_active' => 1]);
    $supplierId = (int) $db->insertID();
    $db->table('products')->insert(['name' => 'Temporary product', 'sku' => 'TEMP-PO', 'unit' => 'pcs', 'sell_price' => 8, 'cost_price' => 0, 'is_active' => 1]);
    $productId = (int) $db->insertID();
    $service = new App\Services\Web\PurchaseOrderService();
    $input = ['supplier_id' => $supplierId, 'reference_no' => 'TEST-ORDER', 'notes' => 'Delivery instruction', 'expected_received_at' => '2026-11-05T14:30', 'items' => [['product_id' => $productId, 'quantity' => 5, 'unit_cost' => 4.25]]];
    $purchase = $service->save($input, 1, 1);
    verify($purchase['expected_received_at'] === '2026-11-05 14:30:00' && $purchase['notes'] === 'Delivery instruction', 'Persist expected receipt date/time and procurement notes.');
    verify($purchase['status'] === 'pending' && $purchase['total'] === 21.25, 'Create pending purchase with correct totals.');
    verify($db->table('stock_movements')->countAllResults() === 0 && $db->table('supplier_bills')->countAllResults() === 0, 'Creating an order does not post stock or supplier bills.');
    $input['id'] = $purchase['id'];
    $input['items'][0]['quantity'] = 3;
    $input['items'][0]['unit_cost'] = 6;
    $input['expected_received_at'] = '2026-11-06T09:15';
    $edited = $service->save($input, 1, 1);
    verify($edited['total'] === 18.0 && count($edited['items']) === 1 && $edited['items'][0]['quantity'] === 3.0, 'Edit unreceived order lines and recalculate totals.');
    verify($edited['purchased_at'] === $purchase['purchased_at'] && $edited['expected_received_at'] === '2026-11-06 09:15:00', 'Editing preserves order creation date and updates its delivery schedule.');
    foreach (['2026-02-30T12:00', '', 'invalid'] as $invalidDate) {
        try { $service->save(array_replace($input, ['expected_received_at' => $invalidDate]), 1, 1); throw new RuntimeException('Invalid date accepted.'); }
        catch (InvalidArgumentException $exception) { verify(true, 'Reject invalid or missing expected receipt date.'); }
    }
    try { $service->save($input, 2, 1); throw new RuntimeException('Cross-branch edit accepted.'); }
    catch (InvalidArgumentException $exception) { verify(true, 'Reject editing another branch order.'); }
    $badInput = $input;
    $badInput['items'][0]['unit_cost'] = -1;
    try { $service->save($badInput, 1, 1); throw new RuntimeException('Negative cost accepted.'); }
    catch (InvalidArgumentException $exception) { verify(true, 'Reject negative purchase costs before mutation.'); }
    // Exercise the unchanged receiving pipeline entirely inside temporary tables.
    $receiptService = new App\Services\PurchaseReceiptService();
    $receipt = $receiptService->createReceipt($edited['id'], ['received_at' => $edited['expected_received_at'], 'items' => [['purchase_item_id' => $edited['items'][0]['id'], 'quantity' => 1]]], 1, 1, 'basic');
    verify($receipt['received_at'] === '2026-11-06 09:15:00', 'Receiving persists the selected actual receipt time.');
    verify((float) $db->table('stock_levels')->where('product_id', $productId)->get()->getRow('quantity') === 1.0, 'Partial receipt updates inventory by the received quantity.');
    verify((float) $db->table('supplier_bills')->get()->getRow('total') === 6.0, 'Partial receipt bills only the received value.');
    $before = (new App\Models\PurchaseModel())->findDetailed($edited['id'], 1);
    try { $service->save($input, 1, 1); throw new RuntimeException('Received order edited.'); }
    catch (InvalidArgumentException $exception) { verify(true, 'Reject editing an order after goods are received.'); }
    $after = (new App\Models\PurchaseModel())->findDetailed($edited['id'], 1);
    verify($before === $after, 'Rejected edit preserves received order and item records.');
    verify($after['status'] === 'partially_received', 'Partial receipt keeps the existing fulfillment workflow.');
    $request = new IncomingRequest(config(Config\App::class), new URI('http://localhost/suppliers'), null, new CodeIgniter\HTTP\UserAgent());
    $controller = new App\Controllers\Web\SuppliersWebController();
    $controller->initController($request, new Response(config(Config\App::class)), service('logger'));
    $statement = json_decode($controller->statement($supplierId)->getBody(), true)['data'];
    verify($statement['entries'][0]['ref'] === 'TEST-ORDER', 'Web statement displays the linked purchase order reference.');
    verify(str_ends_with($statement['entries'][0]['purchase_url'], '?po=' . $edited['id']), 'Purchase bill links open the matching purchase order.');
    verify((float) $statement['summary']['total_debit'] === 6.0 && (float) $statement['summary']['closing_balance'] === 6.0, 'Statement totals report the billed amount and closing balance.');
    $db->table('purchases')->where('id', $edited['id'])->update(['reference_no' => null]);
    $statement = json_decode($controller->statement($supplierId)->getBody(), true)['data'];
    verify($statement['entries'][0]['ref'] === 'PO-' . $edited['id'], 'Orders without a manual reference use a stable PO identifier.');
    AuthContext::$permissions = ['suppliers.view'];
    $statement = json_decode($controller->statement($supplierId)->getBody(), true)['data'];
    verify(!isset($statement['entries'][0]['purchase_url']), 'Supplier viewers without purchase access do not receive a purchase detail link.');

} finally {
    foreach (array_reverse($tables) as $table) $db->query("DROP TEMPORARY TABLE IF EXISTS `$table`");
    foreach ($originalCounts as $table => $count) verify($db->table($table)->countAllResults() === $count, "$table original records remain unchanged.");
    AuthContext::reset();
}
