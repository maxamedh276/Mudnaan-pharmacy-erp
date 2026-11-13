<?php

/**
 * Run: php tests/customers_web_smoke.php
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


$tables = ['customers','customer_deposits','customer_invoices','customer_receipts', 'customer_receipt_allocations','sales','audit_log','accounts','financial_periods','journal_entries','journal_entry_lines'];
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
    $db->table('customers')->insert(['name' => 'Temporary customer', 'balance' => 0]);
    $customerId = (int) $db->insertID();
    $deposit = (new App\Services\ReceivablesService())->recordDeposit(['customer_id' => $customerId, 'amount' => 50, 'method' => 'cash', 'notes' => 'Temporary deposit'], 1, 1, 'premium');
    verify((float) $db->table('customers')->where('id',$customerId)->get()->getRow('balance') === 50.0, 'Recording a deposit increases customer store credit.');
    verify($deposit['amount'] === 50.0 && $deposit['used_amount'] === 0.0, 'New deposit lists its full unused amount.');
    verify($db->table('journal_entries')->countAllResults() === 1, 'A Premium deposit posts its existing accounting entry.');
    $db->table('customer_deposits')->where('id',$deposit['id'])->update(['deposited_at' => '2090-01-01 09:00:00']);
    $db->table('customer_invoices')->insert(['uuid' => App\Libraries\Uuid::v4(), 'branch_id' => 1, 'customer_id' => $customerId, 'invoice_no' => 'TEST-INV', 'invoice_date' => '2090-01-02', 'total' => 80, 'subtotal' => 80, 'paid_total' => 0, 'status' => 'unpaid', 'created_by' => 1]);
    $statementService = new App\Services\Web\CustomerStatementService();
    $statement = $statementService->get($customerId, 1, []);
    verify(count($statement['entries']) === 2 && $statement['summary']['closing_balance'] === 30.0, 'Statement combines deposits and invoices with the correct net balance.');
    $filtered = $statementService->get($customerId, 1, ['date_from' => '2090-01-02', 'date_to' => '2090-01-02', 'type' => 'invoice', 'q' => 'TEST']);
    verify(count($filtered['entries']) === 1 && $filtered['summary']['opening_balance'] === -50.0 && $filtered['entries'][0]['balance'] === 30.0, 'Date and reference filters preserve opening and running balances from the full ledger.');
    verify($filtered['summary']['total_debit'] === 80.0 && $filtered['summary']['total_credit'] === 0.0, 'Statement totals match the filtered transactions.');
    verify($statementService->get($customerId, 1, ['date_to' => '2090-01-01'])['summary']['closing_balance'] === -50.0, 'Closing balance respects the selected end date.');
    verify($statementService->get($customerId, 2, [])['entries'] === [], 'Statements do not leak transactions across branches.');
    foreach ([['date_from' => '2090-02-30'], ['date_from' => '2090-01-03', 'date_to' => '2090-01-01']] as $invalid) {
        try { $statementService->get($customerId, 1, $invalid); throw new RuntimeException('Invalid dates accepted.'); }
        catch (InvalidArgumentException $exception) { verify(true, 'Reject invalid or reversed date ranges.'); }
    }
    verify((new App\Services\ReceivablesService())->listDeposits(2, [], 1, 25)['items'] === [], 'Deposit list remains branch scoped.');
    $request = new IncomingRequest(config(Config\App::class), new URI('http://localhost/clients'), null, new CodeIgniter\HTTP\UserAgent());
    $controller = new App\Controllers\Web\ClientsWebController();
    $controller->initController($request, new Response(config(Config\App::class)), service('logger'));
    AuthContext::$permissions = [];
    try { $controller->statementData($customerId); throw new RuntimeException('Unauthorized statement accepted.'); }
    catch (CodeIgniter\HTTP\Exceptions\HTTPException $exception) { verify($exception->getCode() === 403, 'Statement data requires customer-view permission.'); }
} finally {
    foreach (array_reverse($tables) as $table) $db->query("DROP TEMPORARY TABLE IF EXISTS `$table`");
    foreach ($originalCounts as $table => $count) verify($db->table($table)->countAllResults() === $count, "$table original records remain unchanged.");
    AuthContext::reset();
}
