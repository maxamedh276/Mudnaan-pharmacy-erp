<?php
/** Competing requests and production SQL confined to a disposable QA database. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;
define('FCPATH', dirname(__DIR__) . DIRECTORY_SEPARATOR); define('ENVIRONMENT', 'development');
require FCPATH . 'app/Config/Paths.php'; $paths = new Config\Paths();
require $paths->systemDirectory . '/Boot.php'; CodeIgniter\Boot::bootConsole($paths);
use App\Libraries\Uuid;
use App\Services\CustomerCreditService;
$manager = new App\Libraries\TenantManager(); $tenant = $manager->find(config(Config\Tenants::class)->default); $manager->connect($tenant); $db = db_connect();
function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); echo "PASS: $message\n"; }
if (($argv[1] ?? '') === '--worker') {
    $name = $argv[2] ?? '';
    if (!preg_match('/^qa_credit_[a-f0-9]{12}$/D', $name)) throw new RuntimeException('Invalid QA database.');
    $manager->connect(array_replace($tenant, ['database' => $name])); echo "READY\n"; flush();
    try {
        [$mode, $customer, $uuid, $value, $product] = array_slice($argv, 3);
        if ($mode === 'sale') {
            $result = (new App\Services\SalesService())->createSale(['uuid' => $uuid, 'customer_id' => (int)$customer, 'items' => [['product_id' => (int)$product, 'quantity' => 1, 'unit_price' => (float)$value]], 'payments' => []], 1, 1, 'basic');
        } elseif ($mode === 'invoice') {
            $result = (new App\Services\ReceivablesService())->createInvoice(['customer_id' => (int)$customer, 'items' => [['description' => 'QA service', 'quantity' => 1, 'unit_price' => (float)$value]]], 1, 1, 'basic');
        } elseif ($mode === 'limit') {
            App\Libraries\AuthContext::$user = (object)['id' => 1, 'role_name' => 'Admin'];
            $result = (new App\Services\Web\CustomerDetailsService())->save(['name' => 'QA edited limit', 'credit_limit' => $value], (int)$customer);
        } else {
            $result = (new App\Services\CustomerCollectionService())->record(['uuid' => $uuid, 'customer_id' => (int)$customer, 'amount' => (float)$value], 1, 1, 'basic');
        }
        echo json_encode(['ok' => true, 'id' => $result['id']]) . "\n";
    } catch (Throwable $error) { echo json_encode(['ok' => false, 'error' => $error->getMessage()]) . "\n"; }
    exit;
}
function race(string $database, int $customer, array $first, array $second): array {
    $db = db_connect(); $db->transBegin(); $db->query('SELECT id FROM customers WHERE id=? FOR UPDATE', [$customer]); $workers = [];
    try {
        foreach ([$first, $second] as $args) {
            $process = proc_open(array_merge([PHP_BINARY, __FILE__, '--worker', $database], $args), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, FCPATH);
            if (!is_resource($process)) throw new RuntimeException('Worker did not start.');
            fclose($pipes[0]); $workers[] = [$process, $pipes];
        }
        foreach ($workers as [, $pipes]) { stream_set_timeout($pipes[1], 15); if (trim((string)fgets($pipes[1])) !== 'READY') throw new RuntimeException('Worker not ready.'); }
    } finally { $db->transCommit(); }
    $results = [];
    foreach ($workers as [$process, $pipes]) {
        $out = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        $exit = proc_close($process); $result = json_decode(trim($out), true);
        if ($exit !== 0 || !is_array($result)) throw new RuntimeException($out . $error);
        $results[] = $result;
    }
    return $results;
}
$originalDatabase = $db->getDatabase(); $name = 'qa_credit_' . bin2hex(random_bytes(6)); $created = false;
$tables = ['users','categories','suppliers','customers','products','sales','sale_items','payments','stock_levels','stock_movements','customer_invoices','customer_invoice_items','customer_receipts','customer_receipt_allocations','customer_deposits','journal_entries','journal_entry_lines','financial_periods','audit_log','accounts','account_groups','account_types','pos_payment_methods','bank_accounts','settings','tax_rates','document_returns','document_return_items'];
$original = [];
try {
    $db->query("CREATE DATABASE `$name` CHARACTER SET utf8mb4"); $created = true; $copies = [];
    foreach ($tables as $table) {
        $original[$table] = hash('sha256', serialize($db->table($table)->get()->getResultArray()));
        if (in_array($table, ['accounts','account_groups','account_types','pos_payment_methods'], true)) $copies[$table] = $db->table($table)->get()->getResultArray();
        $sql = array_values($db->query("SHOW CREATE TABLE `$originalDatabase`.`$table`")->getRowArray())[1];
        $sql = preg_replace('/^CREATE TABLE `[^`]+`/', "CREATE TABLE `$name`.`$table`", $sql);
        $sql = preg_replace('/^\s*CONSTRAINT .*\n/m', '', $sql); $sql = preg_replace('/,\s*\)/', "\n)", $sql); $db->query($sql);
    }
    $manager->connect(array_replace($tenant, ['database' => $name])); $db->resetDataCache();
    foreach ($copies as $table => $rows) if ($rows) $db->table($table)->insertBatch($rows);
    $db->query('ALTER TABLE customers DROP COLUMN credit_limit'); $db->resetDataCache();
    $db->table('customers')->insert(['id' => 1, 'name' => 'QA simultaneous credit']);
    $upgrade = file_get_contents(FCPATH . 'docs/sql/2026-11-06_customer_credit_limits.sql');
    foreach (explode(';', $upgrade) as $statement) if (trim($statement) !== '') $db->query($statement);
    $db->table('customers')->where('id', 1)->update(['credit_limit' => 100]);
    foreach (explode(';', $upgrade) as $statement) if (trim($statement) !== '') $db->query($statement);
    check((float)$db->table('customers')->where('id', 1)->get()->getRow('credit_limit') === 100.0, 'Production SQL upgrades a legacy schema and safely reruns without resetting limits.');
    foreach ([1,2] as $product) {
        $db->table('products')->insert(['id' => $product, 'name' => 'QA product ' . $product, 'sku' => 'QA-' . $product, 'sell_price' => 60, 'cost_price' => 1, 'is_active' => 1]);
        $db->table('stock_levels')->insert(['branch_id' => 1, 'product_id' => $product, 'quantity' => 100]);
    }
    $credit = new CustomerCreditService();
    $results = race($name, 1, ['sale', '1', Uuid::v4(), '60', '1'], ['sale', '1', Uuid::v4(), '60', '2']);
    if (count(array_filter($results, fn($row) => $row['ok'])) !== 1) throw new RuntimeException('Competing request results: ' . json_encode($results));
    check(count(array_filter($results, fn($row) => $row['ok'])) === 1 && count(array_filter($results, fn($row) => ($row['error'] ?? '') === 'CREDIT_LIMIT_EXCEEDED')) === 1, 'Two cashiers selling different products cannot both consume the same available credit.');
    check($credit->status(1)['outstanding'] === 60.0 && $db->table('sales')->countAllResults() === 1 && $db->table('stock_movements')->countAllResults() === 1, 'Competing sale rejection leaves exactly one sale, invoice and stock movement.');
    $db->table('customers')->insert(['id' => 2, 'name' => 'QA retry', 'credit_limit' => 60]); $uuid = Uuid::v4();
    $results = race($name, 2, ['sale', '2', $uuid, '60', '1'], ['sale', '2', $uuid, '60', '1']);
    check($results[0]['ok'] && $results[1]['ok'] && $results[0]['id'] === $results[1]['id'] && $credit->status(2)['outstanding'] === 60.0, 'Simultaneous identical requests return one sale even when the first request exhausts credit.');
    $results = race($name, 1, ['collect', '1', Uuid::v4(), '20', '1'], ['sale', '1', Uuid::v4(), '50', '2']);
    check($results[0]['ok'], 'A collection can complete while another cashier attempts a credit sale.');
    $expected = $results[1]['ok'] ? 90.0 : 40.0;
    check($credit->status(1)['outstanding'] === $expected && (float)$db->table('customers')->where('id', 1)->get()->getRow('balance') === -$expected, 'Collection versus sale uses current balances after locking and leaves invoice exposure equal to the customer balance.');
    check($credit->status(1)['outstanding'] <= $credit->status(1)['credit_limit'], 'Neither ordering of competing requests exceeds the configured credit limit.');
    $db->table('customers')->insert(['id' => 3, 'name' => 'QA limit edit', 'credit_limit' => 100]);
    (new App\Services\SalesService())->createSale(['customer_id' => 3, 'items' => [['product_id' => 1, 'quantity' => 1, 'unit_price' => 40]], 'payments' => []], 1, 1, 'basic');
    $results = race($name, 3, ['limit', '3', Uuid::v4(), '50', '1'], ['sale', '3', Uuid::v4(), '60', '2']);
    $status = $credit->status(3);
    check($results[0]['ok'] && $status['credit_limit'] === 50.0 && $status['outstanding'] === ($results[1]['ok'] ? 100.0 : 40.0), 'A concurrent admin limit reduction either follows the committed sale or blocks new credit using the reduced limit.');
    $db->table('customers')->insert(['id' => 4, 'name' => 'QA invoice race', 'credit_limit' => 100]);
    $results = race($name, 4, ['invoice', '4', Uuid::v4(), '60', '1'], ['sale', '4', Uuid::v4(), '60', '2']);
    check(count(array_filter($results, fn($row) => $row['ok'])) === 1 && $credit->status(4)['outstanding'] === 60.0, 'A standalone invoice competing with a POS sale cannot bypass the shared credit limit.');
} finally {
    $manager->connect($tenant);
    if ($created && preg_match('/^qa_credit_[a-f0-9]{12}$/D', $name)) $db->query("DROP DATABASE `$name`");
    foreach ($original as $table => $hash) check(hash('sha256', serialize($db->table($table)->get()->getResultArray())) === $hash, "$table real records unchanged.");
}
