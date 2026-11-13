<?php
/** Run C:/xampp/php/php.exe tests/pos_scan_lookup_smoke.php.
 * Connection-local temporary tables isolate every fixture write.
 * Exact scanner identifiers, branch stock, inactive products and ambiguity are exercised.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('FCPATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
define('ENVIRONMENT', 'development');
require FCPATH . 'app/Config/Paths.php';
$paths = new Config\Paths(); require $paths->systemDirectory . '/Boot.php';
CodeIgniter\Boot::bootConsole($paths);
use App\Libraries\AuthContext;
use App\Services\Web\WorkbookImportService;
use App\Services\Web\ImportWorkbookReader;
$checks=0;
function check(bool $value,string $message):void { global $checks; if(!$value)throw new RuntimeException($message);$checks++;echo "PASS: $message\n"; }
function rejects(callable $fn,string $text):void { try{$fn();}catch(InvalidArgumentException $e){check(str_contains($e->getMessage(),$text),'Rejected '.$text);return;}throw new RuntimeException('Expected rejection'); }
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

    $db->table('categories')->insert(['id'=>1,'name'=>'QA goods','is_active'=>1]);
    $catalog = new App\Services\Web\InventoryCatalogService();
    $boxed = ['name'=>'QA boxed goods','category_id'=>1,'unit'=>'pcs','sell_price'=>3,'reorder_level'=>1,
        'stock_type'=>'sealed','package_unit'=>'Box','package_size'=>12,'package_price'=>30,'barcode'=>'000040','default_sale_mode'=>'sealed'];
    $noCodes = $catalog->saveProduct(array_replace($boxed,['name'=>'QA box without barcodes','barcode'=>' ']));
    check($noCodes['barcode'] === null && $noCodes['sealed_barcode'] === null,'A sealed product can be saved without either barcode.');
    $optional = $catalog->saveProduct(array_replace($boxed,['name'=>'QA box without sealed barcode','barcode'=>'000039']));
    check($optional['sealed_barcode'] === null,'A sealed product can be saved with only its main barcode.');
    rejects(fn()=>$catalog->saveProduct($boxed + ['sealed_barcode'=>'000040']),'different from the single-unit');
    rejects(fn()=>$catalog->saveProduct(array_replace($boxed,['package_size'=>2.5,'sealed_barcode'=>'000041'])),'whole number of pieces');
    $product = $catalog->saveProduct($boxed + ['sealed_barcode'=>'000041']);
    $model = new App\Models\ProductModel();
    rejects(fn()=>$model->insert(['sku'=>'QA-NEW','name'=>'Duplicate','barcode'=>'000041']),'already assigned');
    rejects(fn()=>$model->insert(['sku'=>'QA-NEW','name'=>'Duplicate','sealed_barcode'=>'000040']),'already assigned');
    rejects(fn()=>$model->insert(['sku'=>'000041','name'=>'Duplicate SKU']),'already assigned');
    $scan=new App\Services\Web\PosScanService();
    check($scan->find('000040',1)['scan_mode']==='loose','Single-unit barcode selects pieces despite the sealed default.');
    check($scan->find('000041',1)['scan_mode']==='sealed','Sealed barcode selects the box and preserves leading zeros.');
    check($scan->find($product['sku'],1)['scan_mode']==='sealed','SKU continues to use the default selling unit.');
    $db->table('products')->insert(['sku'=>'QA-LEGACY','barcode'=>'000042','name'=>'Legacy box','unit'=>'pcs','package_unit'=>'Box','package_size'=>12,'package_price'=>30,'default_sale_mode'=>'sealed','is_active'=>1]);
    check($scan->find('000042',1)['scan_mode']==='sealed','Legacy packaged products retain their scan behavior until a sealed barcode is assigned.');

    $db->table('products')->insert(['id'=>1201,'sku'=>'SKU-QA','barcode'=>'001234','name'=>'QA scanner product','unit'=>'pcs','sell_price'=>5,'is_active'=>1,'image_path'=>'uploads/products/qa.png']);
    $db->table('stock_levels')->insert(['product_id'=>1201,'branch_id'=>1,'quantity'=>3]);
    rejects(fn()=>$model->update(1201,['barcode'=>'000041']),'already assigned');
    rejects(fn()=>$model->update($product['id'],['sealed_barcode'=>'001234']),'already assigned');
    check($scan->find('001234',1)['id']===1201,'Exact barcode preserves leading zeros and finds a product outside the first 1000 IDs.');
    check($scan->find(' SKU-QA ',1)['quantity']===3.0,'SKU lookup trims scanner whitespace and uses current branch stock.');
    check($scan->find('001234',2)['quantity']===0.0,'Another branch does not borrow this branch stock.');
    $page = new DOMDocument();
    $page->loadHTML((new App\Controllers\Web\PosWebController())->index(), LIBXML_NOERROR | LIBXML_NOWARNING);
    $xpath = new DOMXPath($page);
    check($xpath->evaluate('string(//*[@data-id="1201"]//img/@src)') === base_url('uploads/products/qa.png'), 'POS resolves uploaded images from the application root.');
    check($xpath->evaluate('string(//*[@data-id="'.$product['id'].'"]/@data-sealed-barcode)') === '000041', 'POS search cards include the sealed barcode.');
    rejects(fn()=>$scan->find('1234',1),'No product');
    rejects(fn()=>$scan->find('QA scanner',1),'No product');
    rejects(fn()=>$scan->find('https://mudnaantechnology.com',1),'No product');
    rejects(fn()=>$scan->find('',1),'valid product');
    rejects(fn()=>$scan->find(str_repeat('x',81),1),'valid product');
    $db->table('products')->where('id',1201)->update(['is_active'=>0]);
    rejects(fn()=>$scan->find('001234',1),'inactive');
    $db->table('products')->where('id',1201)->update(['is_active'=>1]);
    $db->table('products')->insert(['sku'=>'001234','name'=>'QA ambiguous code','unit'=>'pcs','is_active'=>1]);
    rejects(fn()=>$scan->find('001234',1),'multiple products');
} finally {
    foreach (array_reverse($shadowed) as $table) $db->query("DROP TEMPORARY TABLE IF EXISTS `$table`");
    foreach ($hashes as $table=>$hash) check(hash('sha256',serialize($db->table($table)->get()->getResultArray()))===$hash, "$table real records remain unchanged.");

    AuthContext::reset();
}
echo "Completed $checks scanner lookup checks.\n";
