<?php
/** Credit policy and rollback checks against connection-local temporary tables only. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;
define('FCPATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
define('ENVIRONMENT', 'development');
require FCPATH . 'app/Config/Paths.php'; $paths = new Config\Paths();
require $paths->systemDirectory . '/Boot.php'; CodeIgniter\Boot::bootConsole($paths);

use App\Libraries\AuthContext;
use App\Libraries\Uuid;
use App\Services\CustomerCreditService;
use App\Services\SalesService;
use App\Services\Web\CustomerDetailsService;

function check(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
    echo "PASS: $message\n";
}
function rejects(callable $action, string $contains): void {
    try { $action(); } catch (Throwable $error) {
        check(str_contains($error->getMessage(), $contains), 'Rejected: ' . $contains);
        return;
    }
    throw new RuntimeException('Expected rejection: ' . $contains);
}
function controller(string $class, array $body = [], bool $ajax = true): object {
    $request = new CodeIgniter\HTTP\IncomingRequest(config(Config\App::class), new CodeIgniter\HTTP\URI('http://localhost/clients'), json_encode($body), new CodeIgniter\HTTP\UserAgent());
    $request->setHeader('Content-Type', 'application/json');
    if ($ajax) $request->setHeader('X-Requested-With', 'XMLHttpRequest');
    $controller = new $class();
    $controller->initController($request, new CodeIgniter\HTTP\Response(config(Config\App::class)), service('logger'));
    return $controller;
}
$manager = new App\Libraries\TenantManager(); $tenant = $manager->find(config(Config\Tenants::class)->default); $manager->connect($tenant); $db = db_connect();
AuthContext::$tenant = $tenant; AuthContext::$user = (object)['id' => 1, 'branch_id' => 1, 'role_name' => 'Admin'];
session()->set('user', (array)AuthContext::$user);
Config\Services::injectMock('request', new CodeIgniter\HTTP\IncomingRequest(config(Config\App::class), new CodeIgniter\HTTP\URI('http://localhost/clients'), null, new CodeIgniter\HTTP\UserAgent()));
$tables = ['customers','products','sales','sale_items','payments','stock_levels','stock_movements','customer_invoices','customer_invoice_items','customer_receipts','customer_receipt_allocations','customer_deposits','journal_entries','journal_entry_lines','financial_periods','audit_log','accounts','pos_payment_methods','bank_accounts','settings','tax_rates','document_returns','document_return_items'];
$original = []; $shadowed = [];
try {
    foreach ($tables as $table) {
        $original[$table] = hash('sha256', serialize($db->table($table)->get()->getResultArray()));
        $copy = in_array($table, ['accounts','pos_payment_methods'], true) ? $db->table($table)->get()->getResultArray() : [];
        $sql = array_values($db->query("SHOW CREATE TABLE `$table`")->getRowArray())[1];
        $sql = preg_replace('/^CREATE TABLE/', 'CREATE TEMPORARY TABLE', $sql);
        $sql = preg_replace('/^\s*CONSTRAINT .*\n/m', '', $sql); $sql = preg_replace('/,\s*\)/', "\n)", $sql);
        $db->query($sql); $shadowed[] = $table;
        if ($copy) $db->table($table)->insertBatch($copy);
    }
    $db->query('ALTER TABLE customers DROP COLUMN credit_limit'); $db->resetDataCache();
    require APPPATH . 'Database/Migrations/2026-11-06-120000_AddCustomerCreditLimits.php';
    $migration = new App\Database\Migrations\AddCustomerCreditLimits(); $migration->up(); $migration->up();
    $details = new CustomerDetailsService(); $credit = new CustomerCreditService();
    $customer = $details->save(['name' => 'QA credit customer', 'credit_limit' => '100.00', 'balance' => 9999], null);
    $id = $customer['id'];
    check($customer['credit_limit'] === 100.0 && $customer['balance'] === 0.0, 'Admin can create a credit limit; submitted balances are ignored.');
    $migration->up();
    check($credit->status($id)['credit_limit'] === 100.0, 'Rerunning the migration preserves configured limits.');
    $db->table('products')->insert(['id' => 1, 'name' => 'QA item', 'sku' => 'CREDIT-QA', 'sell_price' => 10, 'cost_price' => 2, 'tax_rate' => 0, 'is_active' => 1]);
    foreach ([1,2] as $branch) $db->table('stock_levels')->insert(['branch_id' => $branch, 'product_id' => 1, 'quantity' => 100]);
    $sales = new SalesService();
    $sell = static fn(int $customer, float $price, float $paid = 0, int $branch = 1, string $plan = 'basic', ?string $uuid = null) => $sales->createSale(['uuid' => $uuid ?? Uuid::v4(), 'customer_id' => $customer, 'items' => [['product_id' => 1, 'quantity' => 1, 'unit_price' => $price]], 'payments' => $paid ? [['method' => 'cash', 'amount' => $paid]] : []], $branch, 1, $plan);
    $snapshot = static function () use ($db, $tables): string {
        $rows = []; foreach ($tables as $table) $rows[$table] = $db->table($table)->get()->getResultArray();
        return hash('sha256', serialize($rows));
    };
    $first = $sell($id, 80); $uuid = Uuid::v4(); $second = $sell($id, 40, 20, 1, 'basic', $uuid);
    check($credit->status($id)['outstanding'] === 100.0 && $credit->status($id)['available_credit'] === 0.0, 'An $80 debt plus a $40 order with $20 paid reaches the $100 limit exactly.');
    $before = $snapshot(); rejects(fn() => $sell($id, 0.01), 'CREDIT_LIMIT_EXCEEDED');
    check($snapshot() === $before, 'One cent over the limit leaves sales, inventory, invoices, payments, balances and audit unchanged.');
    check($sell($id, 40, 20, 1, 'basic', $uuid)['id'] === $second['id'], 'Retrying a committed sale at the exhausted limit returns the original sale.');
    $sell($id, 30, 30);
    check($credit->status($id)['outstanding'] === 100.0, 'Fully paid orders remain available at the credit limit.');

    $receipt = (new App\Services\CustomerCollectionService())->record(['uuid' => Uuid::v4(), 'customer_id' => $id, 'amount' => 20], 1, 1, 'basic');
    check($credit->status($id)['available_credit'] === 20.0, 'Collecting a receipt immediately restores available credit.');
    $third = $sell($id, 20);
    (new App\Services\ReversalService())->voidCustomerReceipt($receipt['id'], 1, 'QA reversal', 1, 'basic');
    check($credit->status($id)['outstanding'] === 120.0 && $credit->status($id)['over_limit'], 'Voiding an already-used receipt restores debt even if it exceeds the limit.');
    rejects(fn() => $sell($id, 1), 'CREDIT_LIMIT_EXCEEDED'); $sell($id, 1, 1);
    (new App\Services\DocumentReturnService())->create('sale', $third['id'], ['uuid' => Uuid::v4(), 'reason' => 'QA return', 'items' => [['id' => $third['items'][0]['id'], 'quantity' => 1]]], 1, 1, 'basic');
    check($credit->status($id)['outstanding'] === 100.0, 'An unpaid sales return reduces credit exposure once.');
    (new App\Services\ReceivablesService())->recordDeposit(['customer_id' => $id, 'amount' => 50, 'method' => 'cash'], 1, 1, 'basic');
    check($credit->status($id)['available_credit'] === 0.0, 'An unapplied deposit cannot hide unpaid invoices or create additional credit.');

    $zero = $details->save(['name' => 'QA full payment'], null);
    check($zero['credit_limit'] === 0.0, 'New customers default to full payment only.');
    rejects(fn() => $sell($zero['id'], 5), 'CREDIT_LIMIT_EXCEEDED'); $sell($zero['id'], 5, 5);
    $details->save(['name' => $zero['name'], 'credit_limit' => '10'], $zero['id']);
    $sell($zero['id'], 8, 0, 2);
    rejects(fn() => $sell($zero['id'], 3), 'CREDIT_LIMIT_EXCEEDED');
    check($credit->status($zero['id'])['available_credit'] === 2.0, 'One customer-wide limit includes debt created in another branch.');
    $sell($zero['id'], 2.01, .01);
    check($credit->status($zero['id'])['outstanding'] === 10.0, 'Cent arithmetic accepts the exact boundary after partial payment.');

    $invoiceCustomer = $details->save(['name' => 'QA invoices', 'credit_limit' => '50'], null);
    $invoiceData = ['customer_id' => $invoiceCustomer['id'], 'items' => [['description' => 'QA service', 'quantity' => 1, 'unit_price' => 50]]];
    (new App\Services\ReceivablesService())->createInvoice($invoiceData, 1, 1, 'basic');
    rejects(fn() => (new App\Services\ReceivablesService())->createInvoice($invoiceData, 1, 1, 'basic'), 'CREDIT_LIMIT_EXCEEDED');
    check($credit->status($invoiceCustomer['id'])['outstanding'] === 50.0, 'Standalone invoices obey the same credit policy without double counting POS invoices.');

    $before = $snapshot();
    foreach ([-1, 'NaN', INF, [], true, null, '1e2', '1.001', '10000000000'] as $invalid) rejects(fn() => $details->save(['name' => 'QA changed', 'credit_limit' => $invalid], $id), 'credit limit');
    check($snapshot() === $before, 'Invalid limit values cannot change any customer or financial data.');
    $details->save(['name' => 'QA reduced', 'credit_limit' => '0'], $id);
    check($credit->status($id)['outstanding'] === 100.0 && $credit->status($id)['over_limit'], 'Lowering a limit preserves existing debt and history.');
    $details->save(['name' => 'QA increased', 'credit_limit' => '150'], $id);
    check($credit->status($id)['available_credit'] === 50.0, 'An administrator can expand a limit without changing the balance.');
    check($db->table('audit_log')->where('action', 'customer.credit_limit.change')->countAllResults() >= 3, 'Credit-limit edits retain actor and before/after audit records.');

    AuthContext::$user = (object)['id' => 2, 'branch_id' => 1, 'role_name' => 'Cashier'];
    AuthContext::$permissions = ['customers.manage', 'sales.create'];
    $before = $snapshot();
    rejects(fn() => $details->save(['name' => 'Forged', 'credit_limit' => 999], $id), 'Only an Admin');
    rejects(fn() => controller(App\Controllers\Web\ClientsWebController::class, ['name' => 'Forged', 'credit_limit' => 999])->save($id), 'Only an Admin');
    check($snapshot() === $before, 'Even a cashier with customer-management permission cannot forge a limit edit.');
    $created = $details->save(['name' => 'QA cashier-created'], null);
    check($created['credit_limit'] === 0.0, 'Cashiers can create payment-in-full customers without choosing a limit.');
    $readonly = view('partials/customer_credit_field', ['creditFieldId' => 'qa-limit', 'canManageCredit' => false]);
    check(str_contains($readonly, '<output') && !str_contains($readonly, '<input'), 'Cashier forms display the credit policy without an editable limit control.');
    $web = controller(App\Controllers\Web\ClientsWebController::class);
    $statusResponse = json_decode($web->credit($id)->getBody(), true);
    check($statusResponse['data']['available_credit'] === 50, 'Sales permission permits a read-only POS credit lookup.');
    AuthContext::$permissions = [];
    rejects(fn() => $web->credit($id), 'do not have access'); rejects(fn() => $web->save(), 'do not have access');

    AuthContext::$user = (object)['id' => 1, 'branch_id' => 1, 'role_name' => 'Admin'];
    rejects(fn() => controller(App\Controllers\Web\ClientsWebController::class, ['name' => 'Forged form'], false)->save($id), 'Use the customer form');
    $editable = view('partials/customer_credit_field', ['creditFieldId' => 'qa-limit', 'canManageCredit' => true]);
    check(str_contains($editable, 'name="credit_limit"') && str_contains($editable, 'step="0.01"'), 'Admin forms provide a labelled limit field with cent precision.');
    $payload = ['tax_id' => null, 'customer_id' => $created['id'], 'items' => [['product_id' => 1, 'quantity' => 1]], 'payments' => [], 'credit_limit' => 999999];
    $response = controller(App\Controllers\Web\PosWebController::class, $payload)->checkout();
    $result = json_decode($response->getBody(), true);
    check($response->getStatusCode() === 422 && $result['code'] === 'CREDIT_LIMIT_EXCEEDED' && isset($result['creditStatus']), 'Forged checkout totals/limits cannot bypass enforcement; POS receives a recoverable credit status.');
    check($db->table('customers')->where('id', $created['id'])->get()->getRow('credit_limit') == 0, 'Checkout never modifies the customer credit limit.');
    try {
        controller(App\Controllers\Pos\SalesController::class, $payload)->create();
        throw new RuntimeException('Shared API credit bypass.');
    } catch (App\Services\CustomerCreditLimitException $error) {
        check($error->getCode() === 422 && $error instanceof CodeIgniter\Exceptions\HTTPExceptionInterface, 'The unchanged API sale controller enforces credit and exposes a 422 business refusal rather than a server error.');
    }

    $premium = $details->save(['name' => 'QA ledger', 'credit_limit' => '100'], null);
    $sell($premium['id'], 100, 0, 1, 'premium');
    $balance = $db->table('customers')->where('id', $premium['id'])->get()->getRow('balance');
    $journal = $db->table('journal_entry_lines')->select('SUM(debit) debit,SUM(credit) credit')->get()->getRowArray();
    check((float)$balance === -100.0 && $credit->status($premium['id'])['outstanding'] === 100.0 && round((float)$journal['debit'] - (float)$journal['credit'], 2) === 0.0, 'Premium posting keeps customer debt, invoice exposure and balanced journal entries consistent.');
    $before = $snapshot(); rejects(fn() => $sell($premium['id'], 1, 0, 1, 'premium'), 'CREDIT_LIMIT_EXCEEDED');
    check($snapshot() === $before, 'Rejected Premium sales create no journal, inventory movement or financial side effects.');
} finally {
    foreach (array_reverse($shadowed) as $table) $db->query("DROP TEMPORARY TABLE IF EXISTS `$table`");
    foreach ($original as $table => $hash) check(hash('sha256', serialize($db->table($table)->get()->getResultArray())) === $hash, "$table real records unchanged.");
    AuthContext::reset();
}
