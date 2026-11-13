<?php

/**
 * Run: php tests/inventory_web_smoke.php
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
AuthContext::$permissions = ['accounting.view', 'accounting.manage', 'periods.manage'];

$tables = ['categories', 'products', 'stock_levels', 'audit_log'];
$originalCounts = [];
foreach ($tables as $table) {
    $rows = $db->table($table)->get()->getResultArray();
    $originalCounts[$table] = count($rows);
    $definition = array_values($db->query("SHOW CREATE TABLE `$table`")->getRowArray())[1];
    $definition = preg_replace('/^CREATE TABLE/', 'CREATE TEMPORARY TABLE', $definition);
    $definition = preg_replace('/^\s*CONSTRAINT .*\n/m', '', $definition);
    $definition = preg_replace('/,\s*\)/', "\n)", $definition);
    // Mirror the barcode migration in the isolated fixture when the local schema is older.
    if ($table === 'products' && !str_contains($definition, '`sealed_barcode`')) {
        $definition = preg_replace('/\(/', '( `sealed_barcode` VARCHAR(80) NULL,', $definition, 1);
    }
    $db->query($definition);

}


$catalogService = new App\Services\Web\InventoryCatalogService();
function category(string $name, ?int $parentId = null): int
{
    return (int) (new App\Services\Web\InventoryCatalogService())->createCategory(['name' => $name, 'parent_id' => $parentId])['id'];
}
function product(string $name, int $categoryId, float $price): array
{
    return (new App\Services\Web\InventoryCatalogService())->saveProduct([
        'barcode' => \App\Libraries\Uuid::v4(), 'name' => $name, 'category_id' => $categoryId, 'sell_price' => $price,
        'tax_rate' => 40, 'is_active' => 0, 'reorder_level' => 5, 'unit' => 'pcs',
        'cost_price' => 9999, 'supplier_id' => 999999, 'sku' => 'IGNORE-MANUAL-SKU',
    ]);
}
try {
    $electronicsId = category('Electronics');
    $laptopsId = category('Laptops', $electronicsId);
    $macId = category('Mac', $laptopsId);
    $hpId = category('HP', $laptopsId);
    $macbookId = category('MacBook', $macId);
    $proId = category('Pro', $macbookId);
    $otherRootId = category('Other');
    $otherMacId = category('Mac', $otherRootId);
    $hierarchy = array_column($catalogService->categories(), null, 'id');
    verify($hierarchy[$proId]['depth'] === 5 && $hierarchy[$proId]['path'] === 'Electronics › Laptops › Mac › MacBook › Pro', 'Support complete paths beyond three category levels.');
    verify($hierarchy[$otherMacId]['path'] !== $hierarchy[$macId]['path'], 'Distinguish repeated category names in different branches.');
    try { category('Mac', $laptopsId); throw new RuntimeException('Duplicate sibling accepted.'); }
    catch (InvalidArgumentException $exception) { verify(true, 'Reject duplicate sibling categories.'); }

    $macProduct = product('MacBook Pro', $proId, 1299.95);
    $hpProduct = product('HP Laptop', $hpId, 699);
    $otherProduct = product('Other product', $otherMacId, 50);
    verify($macProduct['sku'] !== $hpProduct['sku'] && str_starts_with($macProduct['sku'], 'SKU-'), 'Generate SKU identifiers on the server.');
    verify((float) $macProduct['cost_price'] === 0.0 && $macProduct['supplier_id'] === null, 'New catalog products do not accept fixed cost or supplier values.');
    verify($macProduct['sku'] !== 'IGNORE-MANUAL-SKU', 'Ignore client-supplied SKU input.');
    verify($catalogService->products(1, ['category_id' => $electronicsId])['total'] === 2, 'Parent category filters include products assigned at every descendant level.');
    verify($catalogService->products(1, ['category_id' => $macId])['total'] === 1, 'Intermediate category filters include deeper descendants without siblings.');
    verify($catalogService->products(1, ['min_price' => 600, 'max_price' => 800])['items'][0]['name'] === 'HP Laptop', 'Filter by selling-price range.');
    $db->table('stock_levels')->insert(['branch_id' => 1, 'product_id' => $hpProduct['id'], 'quantity' => 2]);
    $db->table('stock_levels')->insert(['branch_id' => 2, 'product_id' => $macProduct['id'], 'quantity' => 20]);
    verify($catalogService->products(1, ['stock' => 'low'])['items'][0]['name'] === 'HP Laptop', 'Use actual branch stock for low-stock filters.');
    verify($catalogService->products(1, ['stock' => 'out'])['total'] === 2, 'Treat products with no stock row as out of stock.');
    $page = $catalogService->products(1, ['per_page' => 1, 'page' => 2]);
    verify(count($page['items']) === 1 && $page['total'] === 3 && $page['page'] === 2, 'Paginate all matching products without the previous 1,000-row cap.');
    $hierarchy = array_column($catalogService->categories(), null, 'id');
    verify($hierarchy[$electronicsId]['product_count'] === 0 && $hierarchy[$electronicsId]['total_product_count'] === 2, 'Show both direct and descendant product counts.');

    verify((float) $macProduct['tax_rate'] === 0.0 && (int) $macProduct['is_active'] === 1 && $macProduct['brand'] === null, 'New products are active without tax and brand is optional, regardless of client flags.');
    $db->table('products')->where('id', $macProduct['id'])->update(['cost_price' => 725.50, 'supplier_id' => 17, 'tax_rate' => 7, 'is_active' => 0]);
    $updated = $catalogService->saveProduct(['id' => $macProduct['id'], 'name' => 'Renamed MacBook', 'category_id' => $proId, 'sell_price' => 1300, 'tax_rate' => 0, 'reorder_level' => 0, 'unit' => 'pcs', 'cost_price' => 1, 'supplier_id' => 999, 'sku' => 'BAD']);
    verify((float) $updated['cost_price'] === 725.50 && (int) $updated['supplier_id'] === 17 && $updated['sku'] === $macProduct['sku'], 'Editing catalog details preserves purchase-maintained cost and existing supplier/SKU data.');
    verify((float) $updated['tax_rate'] === 7.0 && (int) $updated['is_active'] === 0, 'Editing product details preserves existing tax and status.');
    verify((int) $updated['reorder_level'] === 0, 'Preserve a zero low-stock alert value.');
    $db->table('categories')->where('id', $electronicsId)->update(['is_active' => 0]);
    $hierarchy = array_column($catalogService->categories(), null, 'id');
    verify(!$hierarchy[$proId]['selectable'], 'Prevent new selection through an inactive category ancestor.');
    try { product('Invalid selection', $proId, 10); throw new RuntimeException('Inactive ancestor accepted.'); }
    catch (InvalidArgumentException $exception) { verify(true, 'Validate category hierarchy on the server.'); }
    $cycle = (new App\Services\Web\CategoryHierarchy())->flatten([
        ['id' => 1, 'parent_id' => 2, 'name' => 'A', 'is_active' => 1],
        ['id' => 2, 'parent_id' => 1, 'name' => 'B', 'is_active' => 1],
    ]);
    verify(!$cycle[0]['selectable'] && !$cycle[1]['selectable'], 'Handle legacy category cycles without infinite traversal.');
    $moveParent = category('New parent');
    $moving = category('Original name');
    $movingChild = category('Child stays attached', $moving);
    $movingProduct = product('Product stays assigned', $moving, 5);
    $catalogService->updateCategory($moving, ['name' => 'Renamed category', 'parent_id' => $moveParent]);
    $afterMove = array_column($catalogService->categories(), null, 'id');
    verify(str_contains($afterMove[$movingChild]['path'], 'Renamed category') && $afterMove[$movingChild]['path_ids'][0] == $moveParent, 'Moving a category updates descendant paths.');
    verify((int)$db->table('products')->where('id', $movingProduct['id'])->get()->getRow('category_id') === $moving, 'Moving a category preserves assigned products.');
    category('Existing sibling', $moveParent);
    foreach ([[$movingChild, 'Renamed category', 'under itself'], [$moveParent, 'Existing sibling', 'already exists']] as [$parent, $name, $expected]) {
        try { $catalogService->updateCategory($moving, ['name'=>$name, 'parent_id'=>$parent]); throw new RuntimeException('Expected invalid category edit to fail.'); }
        catch (InvalidArgumentException $error) { verify(str_contains($error->getMessage(), $expected), 'Invalid category edit blocked: ' . $expected); }
    }
    $catalogService->deleteCategory($emptyCategory = category('Unused category'));
    verify($db->table('categories')->where('id', $emptyCategory)->get()->getRowArray()['deleted_at'] !== null, 'Deleting an unused category retains its database row with a deletion timestamp.');
    verify(!in_array($emptyCategory, array_column($catalogService->categories(), 'id')), 'Deleted category is excluded from the category directory.');
    foreach ([[$electronicsId, 'subcategories'], [$proId, 'assigned products'], [$emptyCategory, 'already deleted']] as [$id, $expected]) {
        try { $catalogService->deleteCategory($id); throw new RuntimeException('Expected category deletion to be blocked.'); }
        catch (InvalidArgumentException $error) { verify(str_contains($error->getMessage(), $expected), 'Deletion blocked: ' . $expected); }
    }
} finally {
    foreach (array_reverse($tables) as $table) $db->query("DROP TEMPORARY TABLE IF EXISTS `$table`");
    foreach ($originalCounts as $table => $count) verify($db->table($table)->countAllResults() === $count, "$table original records remain unchanged.");
    AuthContext::reset();
}
