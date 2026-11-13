<?php
/** Validate the production SQL twice against a disposable database with the pre-return schema. */
declare(strict_types=1);
define('FCPATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
define('ENVIRONMENT', 'development');
require FCPATH . 'app/Config/Paths.php';
$paths = new Config\Paths();
require $paths->systemDirectory . '/Boot.php';
CodeIgniter\Boot::bootConsole($paths);

use App\Libraries\TenantManager;
use App\Libraries\Uuid;

function verify(bool $valid, string $message): void
{
    if (!$valid) throw new RuntimeException($message);
    echo "PASS: $message\n";
}

$manager = new TenantManager();
$tenant = $manager->find(config(Config\Tenants::class)->default);
$manager->connect($tenant);
$db = db_connect();
$original = $db->getDatabase();
$name = 'qa_returns_upgrade_' . bin2hex(random_bytes(6));
$created = false;
try {
    $db->query("CREATE DATABASE `$name` CHARACTER SET utf8mb4");
    $created = true;
    foreach (['sales', 'sale_items', 'customer_invoices', 'supplier_bills', 'permissions'] as $table) {
        $definition = array_values($db->query("SHOW CREATE TABLE `$original`.`$table`")->getRowArray())[1];
        $definition = preg_replace('/^CREATE TABLE `[^`]+`/', "CREATE TABLE `$name`.`$table`", $definition);
        $definition = preg_replace('/^\s*CONSTRAINT .*\n/m', '', $definition);
        $definition = preg_replace('/,\s*\)/', "\n)", $definition);
        $db->query($definition);
    }
    $manager->connect(array_replace($tenant, ['database' => $name]));
    foreach (['sales', 'customer_invoices', 'supplier_bills'] as $table) {
        foreach (['returned_total', 'refunded_total', 'return_discount_total'] as $column) $db->query("ALTER TABLE `$table` DROP COLUMN `$column`");
    }
    $db->query('ALTER TABLE sale_items DROP COLUMN cost_total');
    $db->resetDataCache();
    $db->table('sales')->insert(['id' => 1, 'uuid' => Uuid::v4(), 'branch_id' => 1, 'user_id' => 1, 'grand_total' => 100, 'paid_total' => 50]);
    $db->table('sale_items')->insert(['id' => 1, 'sale_id' => 1, 'product_id' => 1, 'quantity' => 2, 'unit_price' => 50, 'line_total' => 100]);
    $db->table('customer_invoices')->insert(['id' => 1, 'uuid' => Uuid::v4(), 'branch_id' => 1, 'customer_id' => 1, 'invoice_date' => date('Y-m-d'), 'total' => 100, 'paid_total' => 50]);
    $db->table('supplier_bills')->insert(['id' => 1, 'uuid' => Uuid::v4(), 'branch_id' => 1, 'supplier_id' => 1, 'bill_date' => date('Y-m-d'), 'total' => 200, 'paid_total' => 40]);
    $db->table('permissions')->insert(['code' => 'sales.refund', 'description' => 'Existing description']);
    $sql = file_get_contents(FCPATH . 'docs/sql/2026-11-05_document_returns_upgrade.sql');
    verify(str_contains($sql, trim(file_get_contents(FCPATH . 'app/Database/TenantSchema/16_document_returns.sql'))), 'Production SQL includes the exact return-table schema used by the PHP migration.');
    for ($pass = 1; $pass <= 2; $pass++) {
        foreach (explode(';', $sql) as $statement) {
            if (trim($statement) !== '') $db->query($statement);
        }
        $db->resetDataCache();
        foreach (['sales', 'customer_invoices', 'supplier_bills'] as $table) {
            $row = $db->table($table)->where('id', 1)->get()->getRowArray();
            foreach (['returned_total', 'refunded_total', 'return_discount_total'] as $column) verify(isset($row[$column]) && (float)$row[$column] === 0.0, "Pass $pass: $table.$column starts at zero.");
        }
        verify($db->table('sale_items')->where('id', 1)->get()->getRowArray()['cost_total'] === null, "Pass $pass: legacy line cost remains unknown (NULL).");
        verify($db->table('permissions')->where('code', 'purchases.return')->countAllResults() === 1 && $db->table('permissions')->where('code', 'sales.refund')->countAllResults() === 1, "Pass $pass: action permissions are not duplicated.");
        verify($db->table('permissions')->where('code', 'sales.refund')->get()->getRow('description') === 'Existing description', "Pass $pass: existing permission data is preserved.");
    }
    verify((float)$db->table('sales')->where('id', 1)->get()->getRow('grand_total') === 100.0 && (float)$db->table('sales')->where('id', 1)->get()->getRow('paid_total') === 50.0, 'Existing sale amounts are preserved.');
    verify((float)$db->table('customer_invoices')->where('id', 1)->get()->getRow('total') === 100.0 && (float)$db->table('supplier_bills')->where('id', 1)->get()->getRow('paid_total') === 40.0, 'Existing customer and supplier balances are preserved.');
    verify($db->table('document_returns')->countAllResults() === 0 && $db->table('document_return_items')->countAllResults() === 0, 'SQL upgrade creates no sample financial records.');
} finally {
    $manager->connect($tenant);
    if ($created && preg_match('/^qa_returns_upgrade_[a-f0-9]{12}$/D', $name)) $db->query("DROP DATABASE `$name`");
}
