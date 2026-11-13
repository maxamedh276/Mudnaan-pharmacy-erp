<?php
/** Run: C:\xampp\php\php.exe tests/inventory_workflow_smoke.php
 * Every write is redirected to connection-local temporary tables; originals are fingerprinted.
 * Tests stay in this repository. Temporary test data disappears when this CLI connection closes.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('FCPATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
define('ENVIRONMENT', 'development');
require FCPATH . 'app/Config/Paths.php';
$paths = new Config\Paths(); require $paths->systemDirectory . '/Boot.php';
CodeIgniter\Boot::bootConsole($paths);

use App\Libraries\AuthContext;
use App\Libraries\Uuid;
use App\Services\PackageInventoryService;
use App\Services\SalesService;
use App\Services\PurchaseReceiptService;
use App\Services\DocumentReturnService;
use App\Services\Web\PurchaseOrderService;

$checks = 0;
function check(bool $condition, string $message): void {
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++; echo "PASS: $message\n";
}
function rejects(callable $action, string $contains): void {
    try { $action(); } catch (InvalidArgumentException|RuntimeException $error) {
        check(str_contains($error->getMessage(), $contains), 'Rejected: ' . $contains); return;
    }
    throw new RuntimeException('Expected rejection: ' . $contains);
}
function state(int $product, int $branch = 1): array {
    return (new PackageInventoryService())->balance($branch, (new App\Models\ProductModel())->find($product));
}
function sell(int $product, float $quantity, string $mode, string $plan = 'premium', ?string $uuid = null): array {
    return (new SalesService())->createSale(['uuid'=>$uuid ?? Uuid::v4(), 'items'=>[['product_id'=>$product,'quantity'=>$quantity,'stock_mode'=>$mode]],'payments'=>[['method'=>'cash','amount'=>100000]]],1,1,$plan);
}
$manager = new App\Libraries\TenantManager(); $tenant = $manager->find(config(Config\Tenants::class)->default); $manager->connect($tenant); $db = db_connect();
AuthContext::$tenant = $tenant; AuthContext::$user = (object)['id'=>1,'role_name'=>'Superadmin','branch_id'=>1];
session()->set('user', (array)AuthContext::$user);
$tables = ['products','categories','sales','sale_items','payments','stock_levels','stock_movements','package_movements','customers','customer_invoices','customer_invoice_items','customer_receipts','customer_receipt_allocations','customer_deposits','journal_entries','journal_entry_lines','financial_periods','audit_log','accounts','pos_payment_methods','bank_accounts','purchases','purchase_items','purchase_receipts','purchase_receipt_items','suppliers','supplier_bills','supplier_bill_items','supplier_payments','settings','tax_rates','document_returns','document_return_items','web_stock_adjustment_details','selling_option_groups','product_opening_stocks'];
$hashes = $shadowed = [];
try {
    foreach ($tables as $table) {
        $hashes[$table] = hash('sha256', serialize($db->table($table)->get()->getResultArray()));
        $copy = in_array($table, ['accounts','pos_payment_methods'], true) ? $db->table($table)->get()->getResultArray() : [];
        $definition = array_values($db->query("SHOW CREATE TABLE `$table`")->getRowArray())[1];
        $definition = preg_replace('/^CREATE TABLE/', 'CREATE TEMPORARY TABLE', $definition);
        $definition = preg_replace('/^\s*CONSTRAINT .*\n/m', '', $definition);
        $definition = preg_replace('/,\s*\)/', "\n)", $definition);
        // Mirror the barcode migration in the isolated fixture when the local schema is older.
        if ($table === 'products' && !str_contains($definition, '`sealed_barcode`')) {
            $definition = preg_replace('/\(/', '( `sealed_barcode` VARCHAR(80) NULL,', $definition, 1);
        }
        $db->query($definition); $shadowed[] = $table;
        if ($copy) $db->table($table)->insertBatch($copy);
    }
    $db->resetDataCache();

    $db->table('categories')->insert(['name'=>'Workflow QA','is_active'=>1]); $category=(int)$db->insertID();
    $catalog=new App\Services\Web\InventoryCatalogService();
    $groupService=new App\Services\Web\SellingOptionsService();
    $group=$groupService->save(['name'=>'Fractions QA','unit'=>'kg','is_active'=>true,'options'=>[['label'=>'Rubac','quantity'=>0.25],['label'=>'Nus','quantity'=>0.5],['label'=>'Tumin','quantity'=>0.125]]]);
    $base=['barcode'=>Uuid::v4(),'name'=>'QA sealed sugar','category_id'=>$category,'unit'=>'kg','stock_type'=>'sealed','package_unit'=>'Loor','package_size'=>50,'package_price'=>60,'sealed_barcode'=>Uuid::v4(),'sell_price'=>1.5,'reorder_level'=>49,'selling_group_id'=>$group['id'],'default_sale_mode'=>'loose','opening_sealed'=>48,'opening_quantity'=>100,'opening_unit_cost'=>51,'creation_request_key'=>Uuid::v4()];
    $product=$catalog->saveProduct($base);$id=(int)$product['id'];
    check(state($id)['sealed']===48 && state($id)['opened']===100.0 && state($id)['total']===2500.0,'Opening stock accepts 48 sealed Loor and 100 already-opened kg.');
    check((float)$product['cost_price']===1.02,'Opening package cost converts to the correct base-unit cost.');
    $capitalCredit=(float)$db->query("SELECT COALESCE(SUM(l.credit),0) amount FROM journal_entry_lines l JOIN journal_entries e ON e.id=l.journal_entry_id JOIN accounts a ON a.id=l.account_id WHERE e.source_type='opening_stock' AND e.source_id=? AND a.code='3000'",[$id])->getRow('amount');
    check($capitalCredit===2550.0,'Opening stock credits owner capital rather than creating income or a supplier debt.');
    check((float)$db->table('product_opening_stocks')->where('product_id',$id)->get()->getRow('total_value')===2550.0,'Opening balance snapshots cost and value.');
    $count=$db->table('products')->countAllResults();$moves=$db->table('stock_movements')->countAllResults();
    check((int)$catalog->saveProduct($base)['id']===$id && $db->table('products')->countAllResults()===$count && $db->table('stock_movements')->countAllResults()===$moves,'Retrying product creation cannot duplicate the product or opening stock.');
    rejects(fn()=> $catalog->saveProduct(array_replace($base,['name'=>'Changed retry'])),'different product');
    $listed=$catalog->products(1,['stock'=>'low'])['items'];
    check(in_array($id,array_column($listed,'id'),true),'Low stock compares 48 sealed packages with threshold 49, not 2500 kg.');
    check(count($groupService->forProduct($product))===3,'Assigned group supplies reusable user-defined fractions.');
    rejects(fn()=> $groupService->save(['id'=>$group['id'],'name'=>'Changed','unit'=>'litre','is_active'=>true,'options'=>[['label'=>'Half','quantity'=>0.5]]]),'Keep its unit');
    $adjust=new App\Services\Web\StockAdjustmentService();
    $payload=['product_id'=>$id,'qty_change'=>1,'stock_mode'=>'sealed','note'=>'Isolated sealed count correction','request_key'=>Uuid::v4()];
    $adjust->save($payload,1,1,'premium');$adjust->save($payload,1,1,'premium');
    check(state($id)['sealed']===49 && state($id)['opened']===100.0,'Sealed +1 changes packages only and retries are safe.');
    $adjust->save(array_replace($payload,['stock_mode'=>'loose','request_key'=>Uuid::v4()]),1,1,'premium');
    check(state($id)['sealed']===49 && state($id)['opened']===101.0,'Opened +1 changes 100 kg to 101 kg without changing sealed stock.');
    $adjust->save(array_replace($payload,['qty_change'=>-1,'request_key'=>Uuid::v4()]),1,1,'premium');
    check(state($id)['sealed']===48 && state($id)['opened']===101.0,'Sealed decrease does not consume opened kilograms.');
    $adjust->save(array_replace($payload,['stock_mode'=>'loose','qty_change'=>-1,'request_key'=>Uuid::v4()]),1,1,'premium');
    check(state($id)['opened']===100.0,'Opened decrease does not consume sealed packages.');
    rejects(fn()=> $adjust->save(array_replace($payload,['qty_change'=>-49,'request_key'=>Uuid::v4()]),1,1,'premium'),'only 48 sealed');
    rejects(fn()=> $adjust->save(array_replace($payload,['stock_mode'=>'loose','qty_change'=>-101,'request_key'=>Uuid::v4()]),1,1,'premium'),'opened stock is 100');
    rejects(fn()=> $adjust->save(array_replace($payload,['qty_change'=>0.5,'request_key'=>Uuid::v4()]),1,1,'premium'),'whole packages');
    (new PackageInventoryService())->open(['product_id'=>$id,'quantity'=>1,'request_key'=>Uuid::v4()],1,1);
    check(state($id)['sealed']===47 && state($id)['opened']===150.0,'Manual opening remains separate from adjustments and preserves total quantity.');
    $plain=['barcode'=>Uuid::v4(),'name'=>'QA ordinary','category_id'=>$category,'unit'=>'pcs','stock_type'=>'non_sealed','sell_price'=>5,'reorder_level'=>10,'opening_quantity'=>12,'opening_unit_cost'=>2];
    $ordinary=$catalog->saveProduct($plain);$ordinaryId=(int)$ordinary['id'];
    check(state($ordinaryId)['total']===12.0 && empty($ordinary['package_unit']) && (float)$ordinary['cost_price']===2.0,'Non-sealed opening stock uses the main unit and its unit cost.');
    rejects(fn()=> $catalog->saveProduct(array_replace($plain,['id'=>$ordinaryId])),'only entered when creating');
    rejects(fn()=> $catalog->saveProduct(array_replace($base,['creation_request_key'=>Uuid::v4(),'package_unit'=>''])),'required for sealed');
    rejects(fn()=> $catalog->saveProduct(array_replace($base,['creation_request_key'=>Uuid::v4(),'barcode'=>Uuid::v4(),'sealed_barcode'=>Uuid::v4(),'opening_sealed'=>0.5])),'whole packages');
    rejects(fn()=> $catalog->saveProduct(array_replace($plain,['selling_group_id'=>$group['id']])),'matching the base unit');
    rejects(fn()=> $catalog->saveProduct(array_replace($plain,['opening_sealed'=>1,'barcode'=>Uuid::v4()])),'sealed product');
    $before=$db->table('products')->countAllResults();
    $db->table('financial_periods')->insert(['name'=>'Closed QA','start_date'=>date('Y-m-d'),'end_date'=>date('Y-m-d'),'status'=>'closed']);
    rejects(fn()=> $catalog->saveProduct(array_replace($plain,['creation_request_key'=>Uuid::v4(),'barcode'=>Uuid::v4()])),'PERIOD_CLOSED');
    check($db->table('products')->countAllResults()===$before,'Accounting failure rolls back product creation and its opening balance together.');
    $unbalanced=$db->query('SELECT journal_entry_id FROM journal_entry_lines GROUP BY journal_entry_id HAVING ABS(SUM(debit-credit))>0.001')->getResultArray();
    check(!$unbalanced,'Opening balances and split adjustments produce balanced journals.');
    $inventoryValue=(float)$db->query("SELECT COALESCE(SUM(l.debit-l.credit),0) value FROM journal_entry_lines l JOIN accounts a ON a.id=l.account_id WHERE a.code='1200'")->getRow('value');
    $stockValue=(float)$db->query('SELECT COALESCE(SUM(s.quantity*p.cost_price),0) value FROM stock_levels s JOIN products p ON p.id=s.product_id')->getRow('value');
    check(round($inventoryValue,2)===round($stockValue,2),'Inventory book value reconciles with stock after opening balances, both adjustment types and package opening.');
} finally {
    foreach (array_reverse($shadowed) as $table) $db->query("DROP TEMPORARY TABLE IF EXISTS `$table`");
    foreach ($hashes as $table=>$hash) check(hash('sha256',serialize($db->table($table)->get()->getResultArray()))===$hash, "$table real records remain unchanged.");
    AuthContext::reset();
}
echo "Completed $checks workflow checks.\n";
