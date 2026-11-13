<?php

/** Real concurrent MySQL requests against a disposable, randomly named QA database. */
declare(strict_types=1);

define('FCPATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
define('ENVIRONMENT', 'development');
require FCPATH . 'app/Config/Paths.php';
$paths = new Config\Paths();
require $paths->systemDirectory . '/Boot.php';
CodeIgniter\Boot::bootConsole($paths);

use App\Libraries\TenantManager;
use App\Libraries\Uuid;
use App\Services\ReceivablesService;
use App\Services\ReversalService;

function check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
    echo "PASS: $message\n";
}

$manager = new TenantManager();
$tenant = $manager->find(config(Config\Tenants::class)->default);
if (!$tenant) throw new RuntimeException('No configured tenant.');
$manager->connect($tenant);
$db = db_connect();

if (($argv[1] ?? '') === '--worker') {
    $databaseName = $argv[2] ?? '';
    if (!preg_match('/^qa_receipts_[a-f0-9]{12}$/D', $databaseName)) throw new RuntimeException('Invalid QA database.');
    $manager->connect(array_replace($tenant, ['database' => $databaseName]));
    echo "READY\n";
    flush();
    try {
        if ($argv[3] === 'void') {
            $result = (new ReversalService())->voidCustomerReceipt((int) $argv[4], 1, 'Concurrent QA', 1, 'basic');
        } elseif($argv[3]==='tax'){
            $db->query('SELECT id FROM customers WHERE id=1 FOR UPDATE');
            (new App\Services\TaxRateService())->save(['uuid'=>$argv[4],'name'=>$argv[6],'percentage'=>$argv[5]]);
            $result=['id'=>(int)(new App\Services\TaxRateService())->active()['id']];
        } elseif($argv[3]==='allocate'){
            $result=(new App\Services\CustomerCollectionService())->record(['uuid'=>$argv[4],'customer_id'=>1,'amount'=>(float)$argv[5]],1,1,'basic');
        } else {
            $result = (new ReceivablesService())->recordReceipt(['uuid' => $argv[4], 'invoice_id' => 1, 'amount' => (float) $argv[5]], 1, 1, 'basic');
        }
        echo json_encode(['ok' => true, 'id' => $result['id']]) . "\n";
    } catch (Throwable $error) {
        echo json_encode(['ok' => false, 'error' => $error->getMessage()]) . "\n";
    }
    exit;
}

// A transaction gate ensures both PHP processes have started before either can settle.
function race(array $first, array $second, string $databaseName): array
{
    $db = db_connect();
    $db->transBegin();
    $db->query('SELECT id FROM customers WHERE id = 1 FOR UPDATE');
    $workers = [];
    try {
        foreach ([$first, $second] as $args) {
            $pipes = [];
            $process = proc_open(array_merge([PHP_BINARY, __FILE__, '--worker', $databaseName], $args), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, FCPATH);
            if (!is_resource($process)) throw new RuntimeException('Cannot start QA worker.');
            fclose($pipes[0]);
            $workers[] = [$process, $pipes];
        }
        foreach ($workers as [, $pipes]) {
            stream_set_timeout($pipes[1], 15);
            if (trim((string) fgets($pipes[1])) !== 'READY') throw new RuntimeException('Worker did not start.');
        }
    } finally {
        $db->transCommit();
    }
    $results = [];
    foreach ($workers as [$process, $pipes]) {
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        $exit = proc_close($process);
        $result = json_decode(trim($output), true);
        if ($exit !== 0 || !is_array($result)) throw new RuntimeException('QA worker failed: ' . $output . $errors);
        $results[] = $result;
    }
    return $results;
}

$sourceDatabase = $db->getDatabase();
$databaseName = 'qa_receipts_' . bin2hex(random_bytes(6));
$created = false;
try {
    $db->query("CREATE DATABASE `$databaseName` CHARACTER SET utf8mb4");
    $created = true;
    foreach (['customers', 'customer_invoices', 'customer_receipts', 'customer_receipt_allocations', 'financial_periods', 'audit_log', 'journal_entries', 'journal_entry_lines'] as $table) {
        $definition = array_values($db->query("SHOW CREATE TABLE `$sourceDatabase`.`$table`")->getRowArray())[1];
        $definition = preg_replace('/^CREATE TABLE `[^`]+`/', "CREATE TABLE `$databaseName`.`$table`", $definition);
        $definition = preg_replace('/^\s*CONSTRAINT .*\n/m', '', $definition);
        $definition = preg_replace('/,\s*\)/', "\n)", $definition);
        $db->query($definition);
    }
    $manager->connect(array_replace($tenant, ['database' => $databaseName]));
    $db->query(file_get_contents(FCPATH.'app/Database/TenantSchema/15_tax_management.sql'));
    $db->resetDataCache();
    $db->table('customers')->insert(['id' => 1, 'name' => 'Concurrent QA', 'balance' => -100]);
    $db->table('customer_invoices')->insert(['id' => 1, 'uuid' => Uuid::v4(), 'branch_id' => 1, 'customer_id' => 1, 'invoice_date' => date('Y-m-d'), 'total' => 100, 'subtotal' => 100, 'paid_total' => 0, 'status' => 'unpaid']);

    $results = race(['collect', Uuid::v4(), '75'], ['collect', Uuid::v4(), '75'], $databaseName);
    check(count(array_filter($results, static fn ($r) => $r['ok'])) === 1 && count(array_filter($results, static fn ($r) => ($r['error'] ?? '') === 'OVERPAYMENT')) === 1, 'Concurrent collections cannot overpay the same invoice.');
    check((float) $db->table('customer_invoices')->where('id', 1)->get()->getRow('paid_total') === 75.0 && (float) $db->table('customers')->where('id', 1)->get()->getRow('balance') === -25.0, 'Concurrent collection keeps invoice and customer balances consistent.');
    $receiptId = (int) $db->table('customer_receipts')->get()->getRow('id');
    $results = race(['void', (string) $receiptId], ['void', (string) $receiptId], $databaseName);
    check(count(array_filter($results, static fn ($r) => $r['ok'])) === 1 && count(array_filter($results, static fn ($r) => ($r['error'] ?? '') === 'ALREADY_CANCELLED')) === 1, 'Concurrent void requests restore the debt exactly once.');
    $uuid = Uuid::v4();
    $results = race(['collect', $uuid, '40'], ['collect', $uuid, '40'], $databaseName);
    check($results[0]['ok'] && $results[1]['ok'] && $results[0]['id'] === $results[1]['id'], 'Concurrent duplicate submissions return the same receipt.');
    check($db->table('customer_receipts')->where('uuid', $uuid)->countAllResults() === 1 && (float) $db->table('customer_invoices')->where('id', 1)->get()->getRow('paid_total') === 40.0, 'Duplicate requests apply the payment only once.');
    (new ReversalService())->voidCustomerReceipt((int)$results[0]['id'],1,'Reset QA',1,'basic');
    $uuid=Uuid::v4();$results=race(['allocate',$uuid,'75'],['allocate',$uuid,'75'],$databaseName);
    check($results[0]['ok']&&$results[1]['ok']&&$results[0]['id']===$results[1]['id'],'Concurrent FIFO submissions create one receipt and one allocation set.');
    check((float)$db->table('customer_invoices')->where('id',1)->get()->getRow('paid_total')===75.0,'Concurrent FIFO retries settle the order once.');
    $taxResults=race(['tax',Uuid::v4(),'5','QA Tax A'],['tax',Uuid::v4(),'10','QA Tax B'],$databaseName);
    check($taxResults[0]['ok']&&$taxResults[1]['ok']&&$db->table('tax_rates')->countAllResults()===2&&$db->table('tax_rates')->where('is_active',1)->countAllResults()===1,'Concurrent tax additions both succeed while leaving exactly one active rate.');
    $taxUuid=Uuid::v4();$taxResults=race(['tax',$taxUuid,'7.5','QA Tax Retry'],['tax',$taxUuid,'7.5','QA Tax Retry'],$databaseName);
    check($taxResults[0]['ok']&&$taxResults[1]['ok']&&$db->table('tax_rates')->where('request_uuid',$taxUuid)->countAllResults()===1,'Concurrent duplicate tax requests create exactly one tax.');
} finally {
    $manager->connect($tenant);
    // Only the exact random QA database created above may be removed.
    if ($created && preg_match('/^qa_receipts_[a-f0-9]{12}$/D', $databaseName) && $databaseName !== $sourceDatabase) {
        $db->query("DROP DATABASE `$databaseName`");
        echo "PASS: Disposable QA database removed; tenant financial tables were never written.\n";
    }
}
