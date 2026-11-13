<?php
/** Run: C:\xampp\php\php.exe tests/packaged_inventory_smoke.php
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
    $db->table('suppliers')->insert(['name'=>'Isolated supplier','is_active'=>1]); $supplier = (int)$db->insertID();
    $db->table('categories')->insert(['name'=>'Isolated food','is_active'=>1]); $category = (int)$db->insertID();
    $catalog = new App\Services\Web\InventoryCatalogService();
    $packages = new PackageInventoryService();
    foreach ([['Bariis','kg','Loor',25,20,24,1], ['Saliid','litre','Jirgaan',20,30,38,2]] as [$name,$unit,$pack,$size,$cost,$packagePrice,$loosePrice]) {
        $product = $catalog->saveProduct(['barcode'=>Uuid::v4(),'name'=>$name,'category_id'=>$category,'unit'=>$unit,'sell_price'=>$loosePrice,'reorder_level'=>5,'package_unit'=>$pack,'package_size'=>$size,'package_price'=>$packagePrice,'sealed_barcode'=>Uuid::v4()]); $id = (int)$product['id'];
        $po = (new PurchaseOrderService())->save(['supplier_id'=>$supplier,'expected_received_at'=>date('Y-m-d\TH:i'),'items'=>[['product_id'=>$id,'quantity'=>100,'unit_cost'=>$cost,'stock_mode'=>'sealed']]],1,1);
        check(state($id)['total'] === 0.0 && $po['total'] === (float)(100*$cost), "$name purchase order does not add stock and uses price per package.");
        $receive = new PurchaseReceiptService(); $line = (int)$po['items'][0]['id'];
        rejects(fn()=> $receive->createReceipt($po['id'],['items'=>[['purchase_item_id'=>$line,'quantity'=>0.5]]],1,1,'premium'), 'INVALID_RECEIPT_ITEM');
        $receive->createReceipt($po['id'],['items'=>[['purchase_item_id'=>$line,'quantity'=>40]]],1,1,'premium');
        check(state($id)['sealed'] === 40 && state($id)['opened'] === 0.0 && state($id)['total'] === (float)(40*$size), "$name partial receipt adds sealed packages only.");
        $receive->createReceipt($po['id'],['items'=>[['purchase_item_id'=>$line,'quantity'=>60]]],1,1,'premium');
        check(state($id)['sealed'] === 100 && state($id)['total'] === (float)(100*$size), "$name full receiving preserves conversion and accumulated quantity.");
        check((float)$db->table('products')->where('id',$id)->get()->getRow('cost_price') === round($cost/$size,6), "$name receipt maintains cost per $unit.");
        rejects(fn()=> sell($id,0.5,'loose'), 'opened stock is 0');
        $journals = $db->table('journal_entries')->countAllResults(); $movements = $db->table('stock_movements')->countAllResults();
        $open = ['product_id'=>$id,'quantity'=>1,'request_key'=>Uuid::v4()];
        $packages->open($open,1,1); $packages->open($open,1,1);
        check(state($id)['sealed'] === 99 && state($id)['opened'] === (float)$size, "$name opening transfers exactly one package; identical retry is safe.");
        check($db->table('journal_entries')->countAllResults() === $journals && $db->table('stock_movements')->countAllResults() === $movements, 'Opening preserves total inventory quantity, value and general ledger.');
        rejects(fn()=> $packages->open(array_replace($open,['quantity'=>2]),1,1), 'already used');
        rejects(fn()=> $packages->open(['product_id'=>$id,'quantity'=>1,'request_key'=>Uuid::v4()],2,1), 'only 0 sealed');
        $uuid = Uuid::v4(); $sealedSale = sell($id,2,'sealed','premium',$uuid); sell($id,2,'sealed','premium',$uuid);
        check(state($id)['sealed'] === 97 && state($id)['opened'] === (float)$size, "$name sealed sale consumes sealed pool only; sale retry is safe.");
        check((float)$sealedSale['items'][0]['unit_price'] === (float)$packagePrice && (float)$db->table('sale_items')->where('id',$sealedSale['items'][0]['id'])->get()->getRow('cost_total') === (float)(2*$cost), 'Sealed sale snapshots package price and correctly converted COGS.');
        sell($id,0.4,'loose'); sell($id,0.2,'loose'); sell($id,0.5,'loose');
        check(state($id)['opened'] === round($size-1.1,3) && state($id)['sealed'] === 97, "$name fractional sales consume opened stock only.");
        sell($id,$size-1.6,'loose');
        check(state($id)['opened'] === 0.5, 'Prepare exactly 0.5 opened units remaining.');
        $before = state($id); $saleCount = $db->table('sales')->countAllResults();
        rejects(fn()=>sell($id,2,'loose'),'requested 2');
        check(state($id) === $before && $db->table('sales')->countAllResults() === $saleCount, 'Insufficient opened stock rolls back the whole sale despite available sealed packages.');
        $packages->open(['product_id'=>$id,'quantity'=>1,'request_key'=>Uuid::v4()],1,1);
        sell($id,2,'loose');
        check(state($id)['opened'] === $size-1.5 && state($id)['sealed'] === 96, 'Manual opening adds to the remainder, then the retained requested quantity can be sold.');
        rejects(fn()=>sell($id,0.5,'sealed'),'whole numbers');
        rejects(fn()=> $packages->open(['product_id'=>$id,'quantity'=>0.5,'request_key'=>Uuid::v4()],1,1),'whole number');
        rejects(fn()=> $packages->open(['product_id'=>$id,'quantity'=>999,'request_key'=>Uuid::v4()],1,1),'only 96 sealed');
        rejects(fn()=> $catalog->saveProduct(['id'=>$id,'barcode'=>Uuid::v4(),'name'=>$name,'category_id'=>$category,'unit'=>$unit,'sell_price'=>$loosePrice,'reorder_level'=>5,'package_unit'=>$pack,'package_size'=>$size+1,'package_price'=>$packagePrice]),'cannot change');
        // Mixed units in a single sale must reserve each pool separately.
        $mixed = (new SalesService())->createSale(['items'=>[['product_id'=>$id,'quantity'=>1,'stock_mode'=>'sealed'],['product_id'=>$id,'quantity'=>0.5,'stock_mode'=>'loose']],'payments'=>[['method'=>'cash','amount'=>1000]]],1,1,'premium');
        check(count($mixed['items']) === 2 && state($id)['sealed'] === 95 && state($id)['opened'] === $size-2.0, 'One ticket supports the same product in both units.');
        $before = state($id);
        rejects(fn()=> (new SalesService())->createSale(['items'=>[['product_id'=>$id,'quantity'=>$size-2,'stock_mode'=>'loose'],['product_id'=>$id,'quantity'=>0.1,'stock_mode'=>'loose']],'payments'=>[['method'=>'cash','amount'=>1000]]],1,1,'premium'), 'opened stock');
        check(state($id) === $before, 'Duplicate lines cannot bypass opened stock validation.');
        // Supplier returns cannot remove already-opened packages.
        $returns = new DocumentReturnService();
        rejects(fn()=> $returns->quote('purchase',$po['id'],['items'=>[['id'=>$line,'quantity'=>96]]],1),'only 95 sealed');
        $returns->create('purchase',$po['id'],['uuid'=>Uuid::v4(),'reason'=>'Temporary package return','items'=>[['id'=>$line,'quantity'=>1]]],1,1,'premium');
        check(state($id)['sealed'] === 94 && state($id)['opened'] === $size-2.0, 'Supplier package return consumes sealed pool and converted base stock.');
        $cash = (int)$db->table('accounts')->where('code','1000')->get()->getRow('id');
        $returns->create('sale',$sealedSale['id'],['uuid'=>Uuid::v4(),'reason'=>'Temporary sealed return','account_id'=>$cash,'items'=>[['id'=>$sealedSale['items'][0]['id'],'quantity'=>1]]],1,1,'premium');
        check(state($id)['sealed'] === 95 && state($id)['opened'] === $size-2.0, 'Customer sealed return restores a sealed package without increasing opened stock.');
        sell($id,$size-2,'loose'); check(state($id)['opened'] === 0.0, 'Selling the exact remainder reaches zero without decimal drift.');
    }
    $box = $catalog->saveProduct(['name'=>'Box of soap','category_id'=>$category,'unit'=>'pcs','sell_price'=>2,
        'reorder_level'=>1,'package_unit'=>'Box','package_size'=>12,'package_price'=>20,'barcode'=>'000120',
        'sealed_barcode'=>'000121','opening_sealed'=>2,'opening_quantity'=>6,'opening_unit_cost'=>12]);
    $scan = new App\Services\Web\PosScanService();
    $boxId = (int)$box['id'];
    sell($boxId, 1, $scan->find('000121', 1)['scan_mode']);
    sell($boxId, 1, $scan->find('000120', 1)['scan_mode']);
    check(state($boxId)['sealed'] === 1 && state($boxId)['opened'] === 5.0 && state($boxId)['total'] === 17.0,
        'Selling a scanned box and piece consumes the matching stock pools from one product.');
    // Legacy stock is never guessed to be sealed; existing products remain usable.
    $db->table('products')->insert(['name'=>'Legacy rice','sku'=>Uuid::v4(),'unit'=>'kg','sell_price'=>1,'cost_price'=>0.8,'is_active'=>1]); $legacy=(int)$db->insertID();
    $db->table('stock_levels')->insert(['branch_id'=>1,'product_id'=>$legacy,'quantity'=>19]);
    $catalog->saveProduct(['id'=>$legacy,'barcode'=>Uuid::v4(),'name'=>'Legacy rice','category_id'=>$category,'unit'=>'kg','sell_price'=>1,'reorder_level'=>5,'package_unit'=>'Loor','package_size'=>25,'package_price'=>24,'sealed_barcode'=>Uuid::v4()]);
    check(state($legacy)['sealed'] === 0 && state($legacy)['opened'] === 19.0, 'Enabling packages preserves existing 19 kg as opened stock without inventing sacks.');
    rejects(fn()=>sell($legacy,1,'sealed'),'only 0 sealed'); sell($legacy,0.5,'loose');
    check(state($legacy)['opened'] === 18.5, 'Existing stock remains available for fractional sales.');
    $bad = ['name'=>'Invalid','category_id'=>$category,'unit'=>'kg','sell_price'=>1,'reorder_level'=>1,'package_unit'=>'Loor','package_size'=>0,'package_price'=>1];
    rejects(fn()=> $catalog->saveProduct($bad), 'positive capacity');
    $request = new CodeIgniter\HTTP\IncomingRequest(config(Config\App::class), new CodeIgniter\HTTP\URI('http://localhost/inventory/stock'), null, new CodeIgniter\HTTP\UserAgent());
    $controller = new App\Controllers\Web\InventoryWebController();
    $controller->initController($request, new CodeIgniter\HTTP\Response(config(Config\App::class)), service('logger'));
    AuthContext::$user = (object)['id'=>1,'role_name'=>'Cashier','branch_id'=>1]; AuthContext::$permissions = ['products.view'];
    try { $controller->openPackage(); throw new RuntimeException('Permission bypass.'); }
    catch (CodeIgniter\HTTP\Exceptions\HTTPException $error) { check($error->getCode() === 403, 'Read-only inventory users cannot open packages.'); }
    AuthContext::$user = (object)['id'=>1,'role_name'=>'Superadmin','branch_id'=>1];
    // Force an accounting error after package writes: the split must roll back with the sale.
    $db->table('products')->insert(['name'=>'Rollback rice','sku'=>Uuid::v4(),'unit'=>'kg','sell_price'=>1,'cost_price'=>0.8,'is_active'=>1,'package_unit'=>'Loor','package_size'=>25,'package_price'=>24]); $rollback=(int)$db->insertID();
    $db->table('stock_levels')->insert(['branch_id'=>1,'product_id'=>$rollback,'quantity'=>50]);
    $packages->record(1,$rollback,2,25,'purchase',null,1);
    $db->table('financial_periods')->insert(['name'=>'Closed QA','start_date'=>date('Y-m-d'),'end_date'=>date('Y-m-d'),'status'=>'closed']);
    $before=state($rollback); $count=$db->table('sales')->countAllResults(); rejects(fn()=>sell($rollback,1,'sealed'),'PERIOD_CLOSED');
    check(state($rollback)===$before && $db->table('sales')->countAllResults()===$count, 'Failed accounting rolls back sealed package ledger, base stock, sale and payment.');
    // Verify the accounting entries produced by successful transactions balance.
    $unbalanced=$db->query('SELECT journal_entry_id FROM journal_entry_lines GROUP BY journal_entry_id HAVING ABS(SUM(debit-credit))>0.001')->getResultArray();
    check(!$unbalanced, 'All successful purchase, sale and return journals balance.');
    if ($preview = (array_values(array_intersect(['--preview-stock', '--preview-pos', '--preview-products','--preview-selling','--preview-purchases'], $argv))[0] ?? null)) {
        // Render the actual production views against temporary data. Fetch mutations are blocked.
        $role = $db->table('roles')->where('name','Superadmin')->get()->getRowArray();
        AuthContext::$user = (object)['id'=>1,'name'=>'Isolated QA','role_id'=>(int)$role['id'],'role_name'=>'Superadmin','branch_id'=>1];
        session()->set('user', (array)AuthContext::$user);
        session()->set('plan', 'premium');
        $dir = FCPATH . 'results/packaged-inventory';
        if (!is_dir($dir)) mkdir($dir, 0777, true);
        $request = new CodeIgniter\HTTP\IncomingRequest(config(Config\App::class), new CodeIgniter\HTTP\URI('http://localhost/inventory/stock'), null, new CodeIgniter\HTTP\UserAgent());
        $stockController = new App\Controllers\Web\InventoryWebController();
        $stockController->initController($request, new CodeIgniter\HTTP\Response(config(Config\App::class)), service('logger'));
        $posController = new App\Controllers\Web\PosWebController();
        $posController->initController($request, new CodeIgniter\HTTP\Response(config(Config\App::class)), service('logger'));
        $previewGroup = (new App\Services\Web\SellingOptionsService())->save(['name'=>'Kg fractions (test)','unit'=>'kg','is_active'=>true,'options'=>[['label'=>'Rubac','quantity'=>0.25],['label'=>'Nus','quantity'=>0.5],['label'=>'Tumin','quantity'=>0.125]]]);
        $db->table('products')->where('unit','kg')->update(['selling_group_id'=>$previewGroup['id']]);
        $supplierController = new App\Controllers\Web\SuppliersWebController();
        $supplierController->initController($request,new CodeIgniter\HTTP\Response(config(Config\App::class)),service('logger'));
        $fixtureProducts = (new App\Models\ProductModel())->listWithStock(1, [], 1, 1000)['items'];
        foreach ($fixtureProducts as &$fixtureProduct) { $fixtureProduct['stock_split'] = $packages->balance(1, $fixtureProduct); $fixtureProduct['quantity_shortcuts'] = (new App\Services\Web\SellingOptionsService())->forProduct($fixtureProduct); }
        unset($fixtureProduct);
        $data = json_encode($fixtureProducts, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT);
        $guard = '<script>window.fetch=async function(url){if(String(url).includes("stock-data"))return new Response(JSON.stringify({success:true,data:' . $data . '}),{headers:{"Content-Type":"application/json"}});return new Response(JSON.stringify({success:false,message:"Isolated preview: saving is disabled. Run the PHP smoke test to exercise transactions."}),{status:422,headers:{"Content-Type":"application/json"}});};</script>';
        $page = substr($preview, 10);
        $html = match ($page) { 'stock' => $stockController->stock(), 'pos' => $posController->index(), 'products' => $stockController->products(), 'selling' => $stockController->sellingOptions(), 'purchases' => $supplierController->purchases() };
        foreach ([$page => $html] as $page=>$html) {
            $html = str_replace('<head>', '<head>' . $guard, $html);
            $html = preg_replace('~<script[^>]+src="[^" ]*web-auth-session\.js"[^>]*></script>~', '', $html);
            $html = str_replace('<body', '<body data-isolated-preview="true"', $html);
            $html = str_replace('</body>', '<div role="status" style="position:fixed;top:0;left:0;right:0;z-index:10000;background:#fffbeb;color:#92400e;text-align:center;font:12px sans-serif;padding:6px;pointer-events:none">ISOLATED TEST PREVIEW — saving disabled; temporary test data</div></body>', $html);
            file_put_contents($dir . '/' . $page . '.html', $html);
        }
        echo "Read-only production-view previews generated in results/packaged-inventory.\n";
    }

} finally {
    foreach (array_reverse($shadowed) as $table) $db->query("DROP TEMPORARY TABLE IF EXISTS `$table`");
    foreach ($hashes as $table=>$hash) check(hash('sha256',serialize($db->table($table)->get()->getResultArray()))===$hash, "$table real records remain unchanged.");
    AuthContext::reset();
}
echo "Completed $checks checks.\n";
