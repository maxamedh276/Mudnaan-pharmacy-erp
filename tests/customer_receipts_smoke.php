<?php

/** Run: php tests/customer_receipts_smoke.php. All writes use connection-local InnoDB temporary tables. */
declare(strict_types=1);

define('FCPATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
define('ENVIRONMENT', 'development');
require FCPATH . 'app/Config/Paths.php';
$paths = new Config\Paths();
require $paths->systemDirectory . '/Boot.php';
CodeIgniter\Boot::bootConsole($paths);

use App\Libraries\AuthContext;
use App\Libraries\TenantManager;
use App\Libraries\Uuid;
use App\Services\AccountingService;
use App\Services\ReceivablesService;
use App\Services\ReversalService;
use App\Services\SalesService;

function verify(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
    echo "PASS: $message\n";
}
function rejects(callable $operation, string $code): void
{
    try { $operation(); } catch (InvalidArgumentException | RuntimeException $error) {
        verify($error->getMessage() === $code, "Reject $code without partial writes.");
        return;
    }
    throw new RuntimeException("Expected $code");
}
function row(string $table, int $id): array
{
    return db_connect()->table($table)->where('id', $id)->get()->getRowArray();
}
function receiptController(array $body = []): App\Controllers\Web\FinanceWebController
{
    $request = new CodeIgniter\HTTP\IncomingRequest(config(Config\App::class), new CodeIgniter\HTTP\URI('http://localhost/finance/receipts'), json_encode($body), new CodeIgniter\HTTP\UserAgent());
    $request->setHeader('Content-Type', 'application/json')->setHeader('X-Requested-With', 'XMLHttpRequest');
    $controller = new App\Controllers\Web\FinanceWebController();
    $controller->initController($request, new CodeIgniter\HTTP\Response(config(Config\App::class)), service('logger'));
    return $controller;
}

$tenant = (new TenantManager())->find(config(Config\Tenants::class)->default);
if (!$tenant) throw new RuntimeException('No configured tenant.');
(new TenantManager())->connect($tenant);
$db = db_connect();
session()->set('user', ['branch_id' => 1]);
AuthContext::$tenant = $tenant;
AuthContext::$user = (object) ['id' => 1, 'branch_id' => 1];
AuthContext::$permissions = ['invoices.view', 'invoices.manage'];
$tables = ['customers', 'products', 'sales', 'sale_items', 'payments', 'stock_levels', 'stock_movements', 'customer_invoices', 'customer_invoice_items', 'customer_receipts', 'customer_receipt_allocations', 'customer_deposits', 'journal_entries', 'journal_entry_lines', 'financial_periods', 'audit_log', 'accounts', 'pos_payment_methods', 'bank_accounts', 'purchases', 'purchase_items', 'purchase_receipts', 'purchase_receipt_items', 'suppliers', 'supplier_bills', 'supplier_bill_items', 'supplier_payments', 'settings', 'tax_rates', 'document_returns', 'document_return_items'];
$originalCounts = [];
$shadowed = [];
try {
    foreach ($tables as $table) {
        $originalCounts[$table] = $db->table($table)->countAllResults();
        $copy = in_array($table, ['accounts', 'pos_payment_methods'], true) ? $db->table($table)->get()->getResultArray() : [];
        $definition = array_values($db->query("SHOW CREATE TABLE `$table`")->getRowArray())[1];
        $definition = preg_replace('/^CREATE TABLE/', 'CREATE TEMPORARY TABLE', $definition);
        $definition = preg_replace('/^\s*CONSTRAINT .*\n/m', '', $definition);
        $definition = preg_replace('/,\s*\)/', "\n)", $definition);
        $db->query($definition);
        $shadowed[] = $table;
        if ($copy) $db->table($table)->insertBatch($copy);
    }
    $db->query('ALTER TABLE customer_receipts DROP COLUMN account_id');
    $db->resetDataCache();
    require FCPATH . 'app/Database/Migrations/2026-11-04-130000_AddCustomerReceiptCashAccount.php';
    $migration = new App\Database\Migrations\AddCustomerReceiptCashAccount();
    $migration->up();
    $migration->up();
    verify($db->fieldExists('account_id', 'customer_receipts'), 'Cash-account migration upgrades legacy receipts and can run again safely.');
    $db->table('products')->insert(['name' => 'Receipt QA product', 'sku' => 'RCPT-QA', 'unit' => 'pcs', 'sell_price' => 300, 'cost_price' => 90, 'tax_rate' => 0, 'is_active' => 1]);
    $productId = (int) $db->insertID();
    $db->table('stock_levels')->insert(['branch_id' => 1, 'product_id' => $productId, 'quantity' => 100]);
    $receivables = new ReceivablesService();
    $reversals = new ReversalService();

    foreach (['basic', 'premium'] as $plan) {
        foreach ([0, 150, 200] as $upfront) {
            $db->table('customers')->insert(['name' => "QA $plan $upfront", 'balance' => 0, 'credit_limit' => 100000]);
            $customerId = (int) $db->insertID();
            $sale = (new SalesService())->createSale(['uuid' => Uuid::v4(), 'customer_id' => $customerId, 'items' => [['product_id' => $productId, 'quantity' => 1]], 'payments' => $upfront ? [['method' => 'cash', 'amount' => $upfront]] : []], 1, 1, $plan);
            $invoice = $db->table('customer_invoices')->where('sale_id', $sale['id'])->get()->getRowArray();
            $invoiceId = (int) $invoice['id'];
            verify((float) $invoice['total'] === 300.0 && (float) $invoice['paid_total'] === (float) $upfront, "$plan: POS invoice preserves initial payment $upfront/300.");
            $due = 300 - $upfront;
            verify((float) row('customers', $customerId)['balance'] === (float) -$due, 'Initial customer balance equals the unpaid amount.');
            $payload = ['uuid' => Uuid::v4(), 'invoice_id' => $invoiceId, 'customer_id' => $customerId, 'amount' => $due / 2, 'method' => 'cash'];
            $receipt = $receivables->recordReceipt($payload, 1, 1, $plan);
            verify(row('customer_invoices', $invoiceId)['status'] === 'partial' && (float) row('sales', (int) $sale['id'])['paid_total'] === (float) ($upfront + $due / 2), 'Collect part of the remaining debt and synchronize the POS sale.');
            $again = $receivables->recordReceipt($payload, 1, 1, $plan);
            verify($again['id'] === $receipt['id'] && $db->table('customer_receipts')->where('uuid', $payload['uuid'])->countAllResults() === 1, 'Identical request retry creates exactly one receipt.');
            rejects(fn () => $receivables->recordReceipt(array_replace($payload, ['amount' => 1]), 1, 1, $plan), 'DUPLICATE_REQUEST');
            rejects(fn () => $receivables->recordReceipt(['uuid' => Uuid::v4(), 'invoice_id' => $invoiceId, 'amount' => $due], 1, 1, $plan), 'OVERPAYMENT');
            $last = $receivables->recordReceipt(['uuid' => Uuid::v4(), 'invoice_id' => $invoiceId, 'amount' => $due / 2], 1, 1, $plan);
            verify(row('customer_invoices', $invoiceId)['status'] === 'paid' && row('sales', (int) $sale['id'])['status'] === 'completed' && (float) row('customers', $customerId)['balance'] === 0.0, 'Final collection fully settles invoice, POS sale, and customer.');
            $statement = (new App\Services\StatementService())->customerStatement($customerId, 1, []);
            verify($statement['summary']['closing_balance'] === 0.0, 'Statement agrees with the settled customer balance.');
            $reversals->voidCustomerReceipt($last['id'], 1, 'QA deletion', 1, $plan);
            verify(row('customer_receipts', $last['id'])['status'] === 'cancelled' && (float) row('customers', $customerId)['balance'] === (float) (-$due / 2) && row('customer_invoices', $invoiceId)['status'] === 'partial', 'Void restores the debt and keeps the original receipt in history.');
            rejects(fn () => $reversals->voidCustomerReceipt($last['id'], 1, 'Repeated', 1, $plan), 'ALREADY_CANCELLED');
            $reversals->voidCustomerReceipt($receipt['id'], 1, 'QA deletion', 1, $plan);
            verify((float) row('sales', (int) $sale['id'])['paid_total'] === (float) $upfront && (float) row('customers', $customerId)['balance'] === (float) -$due, 'Voiding all collections preserves the original POS payment.');
            verify(row('customer_invoices', $invoiceId)['status'] === ($upfront ? 'partial' : 'unpaid'), 'Invoice returns to its original payment status.');
            $replay = $receivables->recordReceipt($payload, 1, 1, $plan);
            verify($replay['status'] === 'cancelled', 'Retry of a voided receipt cannot recollect money.');
            if ($plan === 'premium') {
                verify($db->table('journal_entries')->where('source_type', 'customer_receipt_reversal')->where('source_id', $last['id'])->countAllResults() === 1, 'Receipt reversal posts exactly one mirror journal.');
            }
        }
    }

    // Settlement discounts must clear the same debt in every view.
    $discountReceipt = $receivables->recordReceipt(['uuid' => Uuid::v4(), 'invoice_id' => $invoiceId, 'amount' => 90, 'discount' => 10], 1, 1, 'premium');
    $statement = (new App\Services\StatementService())->customerStatement($customerId, 1, []);
    verify($statement['summary']['closing_balance'] === 0.0 && (float) row('customers', $customerId)['balance'] === 0.0, 'Discounted settlement agrees across statements and balances.');
    $reversals->voidCustomerReceipt($discountReceipt['id'], 1, 'Undo discount', 1, 'basic');
    verify((float) row('customers', $customerId)['balance'] === -100.0 && $db->table('journal_entries')->where('source_type', 'customer_receipt_reversal')->where('source_id', $discountReceipt['id'])->countAllResults() === 1, 'Reversal restores cash plus discount, including after a plan change.');

    rejects(fn () => $receivables->recordReceipt(['invoice_id' => $invoiceId, 'amount' => 1], 2, 1, 'basic'), 'INVOICE_NOT_FOUND');
    rejects(fn () => $receivables->recordReceipt(['invoice_id' => $invoiceId, 'customer_id' => 999999, 'amount' => 1], 1, 1, 'basic'), 'CUSTOMER_REQUIRED');
    rejects(fn () => $receivables->recordReceipt(['invoice_id' => $invoiceId, 'amount' => 'garbage'], 1, 1, 'basic'), 'INVALID_AMOUNT');
    rejects(fn () => $receivables->recordReceipt(['invoice_id' => $invoiceId, 'amount' => INF], 1, 1, 'basic'), 'INVALID_AMOUNT');
    rejects(fn () => $receivables->recordReceipt(['invoice_id' => $invoiceId, 'amount' => 1, 'bank_account_id' => 999999], 1, 1, 'basic'), 'BANK_ACCOUNT_NOT_FOUND');

    $db->table('financial_periods')->insert(['name' => 'QA closed period', 'start_date' => date('Y-m-d'), 'end_date' => date('Y-m-d'), 'status' => 'closed']);
    $periodId = (int) $db->insertID();
    rejects(fn () => $receivables->recordReceipt(['invoice_id' => $invoiceId, 'amount' => 1], 1, 1, 'basic'), 'PERIOD_CLOSED');
    $closedSnapshot=static fn()=>hash('sha256',serialize(array_map(static fn($table)=>$db->table($table)->get()->getResultArray(),['sales','sale_items','payments','stock_levels','stock_movements','customers','customer_invoices','journal_entries','journal_entry_lines'])));
    $beforeClosed=$closedSnapshot();$oldPermissions=AuthContext::$permissions;AuthContext::$permissions[]='sales.create';
    $closedRequest=new CodeIgniter\HTTP\IncomingRequest(config(Config\App::class),new CodeIgniter\HTTP\URI('http://localhost/sales/pos/checkout'),json_encode(['uuid'=>Uuid::v4(),'tax_id'=>null,'customer_id'=>$customerId,'items'=>[['product_id'=>$productId,'quantity'=>1]],'payments'=>[]]),new CodeIgniter\HTTP\UserAgent());
    $closedRequest->setHeader('Content-Type','application/json');$closedController=new App\Controllers\Web\PosWebController();
    $closedController->initController($closedRequest,new CodeIgniter\HTTP\Response(config(Config\App::class)),service('logger'));
    $closedResponse=$closedController->checkout();AuthContext::$permissions=$oldPermissions;
    verify($closedResponse->getStatusCode()===409&&json_decode($closedResponse->getBody(),true)['code']==='PERIOD_CLOSED'&&$closedSnapshot()===$beforeClosed,'Unpaid credit checkout in a closed period returns a friendly 409 and rolls back sale, stock, debt and ledger changes.');
    $db->table('financial_periods')->where('id', $periodId)->delete();
    rejects(fn () => $receivables->recordReceipt(['uuid' => 'bad-id', 'invoice_id' => $invoiceId, 'amount' => 1], 1, 1, 'basic'), 'INVALID_UUID');

    $failingAccounting = new class extends AccountingService {
        public function postCustomerReceipt(array $receipt, ?int $userId): void {
            parent::postCustomerReceipt($receipt, $userId);
            throw new RuntimeException('INJECTED_POSTING_FAILURE');
        }
        public function postSale(array $sale, array $payments, float $costOfGoods, int $branchId, ?int $userId): void {
            parent::postSale($sale, $payments, $costOfGoods, $branchId, $userId);
            throw new RuntimeException('INJECTED_POSTING_FAILURE');
        }
        public function postPurchaseReceipt(array $receipt, float $value, int $branchId, ?int $userId): void {
            parent::postPurchaseReceipt($receipt, $value, $branchId, $userId);
            throw new RuntimeException('INJECTED_POSTING_FAILURE');
        }
        public function postSupplierPayment(array $payment, ?int $userId): void {
            parent::postSupplierPayment($payment, $userId);
            throw new RuntimeException('INJECTED_POSTING_FAILURE');
        }
        public function reverseSource(string $sourceType, int $sourceId, int $branchId, ?int $userId): int {
            parent::reverseSource($sourceType, $sourceId, $branchId, $userId);
            throw new RuntimeException('INJECTED_REVERSAL_FAILURE');
        }
    };
    $snapshot = static function () use ($db, $tables): string {
        $state = [];
        foreach ($tables as $table) {
            $state[$table] = $db->table($table)->get()->getResultArray();
            sort($state[$table]);
        }
        return hash('sha256', serialize($state));
    };
    $before = $snapshot();
    rejects(fn () => (new ReceivablesService(accounting: $failingAccounting))->recordReceipt(['uuid' => Uuid::v4(), 'invoice_id' => $invoiceId, 'amount' => 25], 1, 1, 'premium'), 'INJECTED_POSTING_FAILURE');
    verify($snapshot() === $before, 'Receipt posting failure rolls back receipt, sale, invoice, balance, journals, and audit.');
    rejects(fn () => (new SalesService(accounting: $failingAccounting))->createSale(['customer_id' => $customerId, 'items' => [['product_id' => $productId, 'quantity' => 1]], 'payments' => []], 1, 1, 'premium'), 'INJECTED_POSTING_FAILURE');
    verify($snapshot() === $before, 'POS posting failure rolls back sales, invoice, stock, balance, and journals.');
    $good = $receivables->recordReceipt(['uuid' => Uuid::v4(), 'invoice_id' => $invoiceId, 'amount' => 25], 1, 1, 'premium');
    verify(receiptController(['uuid' => $good['uuid'], 'invoice_id' => $invoiceId, 'amount' => 25])->saveCustomerReceipt()->getStatusCode() === 200, 'Previously saved receipts can still be safely replayed after the cash-account update.');
    $before = $snapshot();
    rejects(fn () => (new ReversalService(accounting: $failingAccounting))->voidCustomerReceipt($good['id'], 1, 'Failure test', 1, 'premium'), 'INJECTED_REVERSAL_FAILURE');
    verify($snapshot() === $before, 'Failed reversal rolls back mirror journals and preserves the active receipt.');

    $db->table('suppliers')->insert(['name' => 'Receipt QA supplier', 'is_active' => 1]);
    $supplierId = (int) $db->insertID();
    $purchase = (new App\Services\PurchaseService())->createPurchase(['supplier_id' => $supplierId, 'items' => [['product_id' => $productId, 'quantity' => 2, 'unit_cost' => 90]]], 1, 1);
    $goods = ['items' => [['purchase_item_id' => $purchase['items'][0]['id'], 'quantity' => 1]]];
    $before = $snapshot();
    rejects(fn () => (new App\Services\PurchaseReceiptService(accounting: $failingAccounting))->createReceipt($purchase['id'], $goods, 1, 1, 'premium'), 'INJECTED_POSTING_FAILURE');
    verify($snapshot() === $before, 'Goods receiving failure rolls back inventory, costs, purchase, payable bill, and journals.');
    (new App\Services\PurchaseReceiptService())->createReceipt($purchase['id'], $goods, 1, 1, 'premium');
    $billId = (int) $db->table('supplier_bills')->where('purchase_id', $purchase['id'])->get()->getRow('id');
    $before = $snapshot();
    rejects(fn () => (new App\Services\PayablesService(accounting: $failingAccounting))->recordPayment(['bill_id' => $billId, 'amount' => 45], 1, 1, 'premium'), 'INJECTED_POSTING_FAILURE');
    verify($snapshot() === $before, 'Supplier payment failure rolls back payment, bill balance, and journals.');

    $cashRoot = $db->table('accounts')->where('code', '1000')->get()->getRowArray();
    $cashAccount = (new App\Services\ChartOfAccountsService())->create([
        'code' => 'QA-RCPT-CASH', 'name' => 'QA Receipt Cash', 'account_group_id' => $cashRoot['account_group_id'], 'parent_id' => $cashRoot['id'],
    ]);
    $cashAccountId = (int) $cashAccount['id'];
    $availableCash = (new App\Services\ReceiptCashAccountService())->list();
    verify(in_array($cashAccountId, array_column($availableCash, 'id'), true) && $db->table('bank_accounts')->where('account_id', $cashAccountId)->countAllResults() === 0, 'Read cash accounts directly from the chart without a bank/wallet registration.');
    $cashPayload = ['uuid' => Uuid::v4(), 'invoice_id' => $invoiceId, 'amount' => 10, 'account_id' => $cashAccountId];
    $cashReceipt = $receivables->recordReceipt($cashPayload, 1, 1, 'premium');
    $journalId = $db->table('journal_entries')->where('source_type', 'customer_receipt')->where('source_id', $cashReceipt['id'])->get()->getRow('id');
    verify((int) row('customer_receipts', $cashReceipt['id'])['account_id'] === $cashAccountId && (float) $db->table('journal_entry_lines')->where('journal_entry_id', $journalId)->where('account_id', $cashAccountId)->get()->getRow('debit') === 10.0, 'Persist the selected chart account and debit that exact account.');
    rejects(fn () => $receivables->recordReceipt(array_replace($cashPayload, ['account_id' => $cashRoot['id']]), 1, 1, 'premium'), 'DUPLICATE_REQUEST');
    $reversals->voidCustomerReceipt($cashReceipt['id'], 1, 'Undo selected cash account', 1, 'premium');
    $reversalId = $db->table('journal_entries')->where('source_type', 'customer_receipt_reversal')->where('source_id', $cashReceipt['id'])->get()->getRow('id');
    verify((float) $db->table('journal_entry_lines')->where('journal_entry_id', $reversalId)->where('account_id', $cashAccountId)->get()->getRow('credit') === 10.0, 'Void credits the same cash account used by the receipt.');
    $revenueId = (int) $db->table('accounts')->where('code', '4000')->get()->getRow('id');
    rejects(fn () => $receivables->recordReceipt(['invoice_id' => $invoiceId, 'amount' => 1, 'account_id' => $revenueId], 1, 1, 'premium'), 'INVALID_CASH_ACCOUNT');
    rejects(fn () => $receivables->recordReceipt(['invoice_id' => $invoiceId, 'amount' => 1, 'account_id' => 999999], 1, 1, 'basic'), 'INVALID_CASH_ACCOUNT');
    $bankRoot = $db->table('accounts')->where('code', '1010')->get()->getRowArray();
    $settlementIds = ['cash' => $cashAccountId];
    foreach (['bank', 'mobile_money'] as $kind) {
        $account = (new App\Services\ChartOfAccountsService())->create([
            'code' => 'QA-RCPT-' . $kind, 'name' => 'QA Settlement ' . $kind,
            'account_group_id' => $bankRoot['account_group_id'], 'parent_id' => $bankRoot['id'],
        ]);
        $accountId = $settlementIds[$kind] = (int) $account['id'];
        if ($kind === 'mobile_money') {
            $db->table('bank_accounts')->insert(['account_id' => $accountId, 'type' => $kind, 'name' => 'QA wallet']);
        }
        $payload = array_replace($cashPayload, ['uuid' => Uuid::v4(), 'account_id' => $accountId]);
        $receipt = $receivables->recordReceipt($payload, 1, 1, 'premium');
        $journalId = $db->table('journal_entries')->where('source_type', 'customer_receipt')->where('source_id', $receipt['id'])->get()->getRow('id');
        verify($receipt['method'] === $kind
            && (float) $db->table('journal_entry_lines')->where('journal_entry_id', $journalId)->where('account_id', $accountId)->get()->getRow('debit') === 10.0,
            "Receipt classifies $kind and debits the selected chart account.");
        verify($receivables->recordReceipt($payload, 1, 1, 'premium')['id'] === $receipt['id'], "A $kind receipt retry returns the original receipt.");
        $reversals->voidCustomerReceipt($receipt['id'], 1, 'Undo selected account', 1, 'premium');
    }
    // Multi-order collection: FIFO, explicit selection, replay, reversal and credit reconciliation.
    $db->table('customers')->insert(['name'=>'Allocation QA','balance'=>0,'credit_limit'=>100000]);$allocationCustomer=(int)$db->insertID();$orderIds=[];
    foreach([100,200,150] as $price){$order=(new SalesService())->createSale(['customer_id'=>$allocationCustomer,'items'=>[['product_id'=>$productId,'quantity'=>1,'unit_price'=>$price]],'payments'=>[]],1,1,'premium');$orderIds[]=(int)$db->table('customer_invoices')->where('sale_id',$order['id'])->get()->getRow('id');}
    $collection=new App\Services\CustomerCollectionService();
    $collectionData=['uuid'=>Uuid::v4(),'customer_id'=>$allocationCustomer,'account_id'=>$settlementIds['mobile_money'],'amount'=>250];
    $before=$snapshot();
    rejects(fn()=>(new App\Services\CustomerCollectionService(new ReceivablesService(accounting:$failingAccounting)))->record($collectionData,1,1,'premium'),'INJECTED_POSTING_FAILURE');
    verify($before===$snapshot(),'Multi-order receipt rolls back all financial changes when accounting fails.');
    $collected=$collection->record($collectionData,1,1,'premium');
    verify($collected['method'] === 'mobile_money' && (int) $collected['account_id'] === $settlementIds['mobile_money'], 'A multi-order receipt keeps the selected wallet account.');
    verify((float)row('customer_invoices',$orderIds[0])['paid_total']===100.0&&(float)row('customer_invoices',$orderIds[1])['paid_total']===150.0&&(float)row('customer_invoices',$orderIds[2])['paid_total']===0.0,'FIFO pays oldest orders first and partially settles the next.');
    verify((float)row('customers',$allocationCustomer)['balance']===-200.0 && (new App\Services\StatementService())->customerStatement($allocationCustomer,1,[])['summary']['closing_balance']===200.0,'Allocated receipt reconciles customer balance and statement.');
    verify($collection->record($collectionData,1,1,'premium')['id']==$collected['id'],'FIFO retry returns the original receipt without reallocating.');
    $selected=['uuid'=>Uuid::v4(),'customer_id'=>$allocationCustomer,'account_id'=>$cashAccountId,'amount'=>30,'allocations'=>[['invoice_id'=>$orderIds[2],'amount'=>30]]];
    $manual=$collection->record($selected,1,1,'premium');
    verify((float)row('customer_invoices',$orderIds[1])['paid_total']===150.0&&(float)row('customer_invoices',$orderIds[2])['paid_total']===30.0,'Explicit allocation pays the selected order and leaves other orders unchanged.');
    rejects(fn()=>$collection->record(array_replace($selected,['allocations'=>[['invoice_id'=>$orderIds[1],'amount'=>30]]]),1,1,'premium'),'DUPLICATE_REQUEST');
    rejects(fn()=>$collection->record(['uuid'=>Uuid::v4(),'customer_id'=>$allocationCustomer,'account_id'=>$cashAccountId,'amount'=>999],1,1,'premium'),'INVALID_ALLOCATION');
    rejects(fn()=>$collection->record(['uuid'=>Uuid::v4(),'customer_id'=>$allocationCustomer,'account_id'=>$cashAccountId,'amount'=>10,'allocations'=>[['invoice_id'=>$invoiceId,'amount'=>10]]],1,1,'premium'),'INVALID_ALLOCATION');
    rejects(fn()=>$collection->record(['uuid'=>Uuid::v4(),'customer_id'=>$allocationCustomer,'account_id'=>$cashAccountId,'amount'=>10,'allocations'=>[['invoice_id'=>$orderIds[2],'amount'=>10],['invoice_id'=>$orderIds[2],'amount'=>10]]],1,1,'premium'),'INVALID_ALLOCATION');
    $reversals->voidCustomerReceipt((int)$collected['id'],1,'Undo FIFO',1,'premium');
    verify((float)row('customer_invoices',$orderIds[0])['paid_total']===0.0&&(float)row('customer_invoices',$orderIds[1])['paid_total']===0.0&&(float)row('customer_invoices',$orderIds[2])['paid_total']===30.0,'Voiding a multi-order receipt restores only its own allocations.');
    rejects(fn()=>$reversals->voidCustomerReceipt((int)$collected['id'],1,'Repeated',1,'premium'),'ALREADY_CANCELLED');
    $receivables->recordDeposit(['customer_id'=>$allocationCustomer,'amount'=>50],1,1,'premium');
    $collection->record(['uuid'=>Uuid::v4(),'customer_id'=>$allocationCustomer,'account_id'=>$cashAccountId,'amount'=>410,'discount'=>10],1,1,'premium');
    verify((float)row('customers',$allocationCustomer)['balance']===50.0&&(new App\Services\StatementService())->customerStatement($allocationCustomer,1,[])['summary']['closing_balance']===-50.0,'Discounted FIFO settlement preserves unused deposit credit and reconciles the statement.');
    foreach($orderIds as $orderId)verify(row('customer_invoices',$orderId)['status']==='paid','Allocated order is fully paid.');
    rejects(fn()=>(new App\Services\PayablesService())->recordPayment(['supplier_id'=>$supplierId,'amount'=>1],1,1,'premium'),'BILL_NOT_FOUND');
    rejects(fn()=>(new App\Services\PayablesService())->recordPayment(['bill_id'=>$billId,'amount'=>91],1,1,'premium'),'OVERPAYMENT');
    rejects(fn()=>(new App\Services\PayablesService())->recordPayment(['bill_id'=>$billId,'amount'=>INF],1,1,'premium'),'INVALID_AMOUNT');
    rejects(fn()=>(new SalesService())->createSale(['customer_id'=>$allocationCustomer,'discount'=>-100,'items'=>[['product_id'=>$productId,'quantity'=>1]],'payments'=>[]],1,1,'premium'),'INVALID_SALE_ITEM');
    rejects(fn()=>(new SalesService())->createSale(['customer_id'=>$allocationCustomer,'items'=>[['product_id'=>$productId,'quantity'=>1,'unit_price'=>-5]],'payments'=>[]],1,1,'premium'),'INVALID_SALE_ITEM');
    foreach([[new App\Models\CustomerModel(),$allocationCustomer],[new App\Models\SupplierModel(),$supplierId],[new App\Models\ProductModel(),$productId]] as [$model,$recordId]) {
        try{$model->delete($recordId);throw new RuntimeException('Financial record deletion bypass');}catch(CodeIgniter\HTTP\Exceptions\HTTPException $error){verify($error->getCode()===422,'Prevent deletion of a record with unsettled balances, credit, or stock.');}
    }
    $db->table('customers')->insert(['name'=>'Empty QA customer','balance'=>0,'credit_limit'=>100000]);$emptyCustomer=(int)$db->insertID();
    verify((new App\Models\CustomerModel())->delete($emptyCustomer)===true,'Allow deleting a customer with no unsettled obligations.');
    $available=(new App\Services\InventoryService())->currentQuantity(1,$productId);$before=$snapshot();
    rejects(fn()=>(new SalesService())->createSale(['customer_id'=>$allocationCustomer,'items'=>[['product_id'=>$productId,'quantity'=>$available],['product_id'=>$productId,'quantity'=>$available]],'payments'=>[]],1,1,'premium'),'INSUFFICIENT_STOCK');
    verify($snapshot()===$before,'Repeated product rows cannot bypass stock checks or leave financial writes.');
    $supplierPayment=(new App\Services\PayablesService())->recordPayment(['bill_id'=>$billId,'amount'=>90],1,1,'premium');
    rejects(fn()=>(new App\Services\PayablesService())->recordPayment(['bill_id'=>$billId,'amount'=>1],1,1,'premium'),'OVERPAYMENT');
    $reversals->voidSupplierPayment((int)$supplierPayment['id'],1,'Undo QA',1,'basic');
    verify((float)row('supplier_bills',$billId)['paid_total']===0.0,'Supplier payment reversal restores payable and accounting even after a plan change.');
    $reports=new App\Services\WebReportService();$filter=['date_from'=>date('Y-m-d'),'date_to'=>date('Y-m-d')];
    $supplierReport=$reports->build('suppliers',1,$filter);
    $supplierStatement=(new App\Services\StatementService())->supplierStatement($supplierId,1,[]);
    verify($supplierReport['summary']['Net payable']===$supplierStatement['summary']['closing_balance'],'Supplier Balances reconciles with the supplier statement after purchase receiving, payments and reversals.');
    verify($reports->build('suppliers',99999,$filter)['summary']['Net payable']===0.0,'Supplier Balances excludes activity from other branches.');
    $oldSuppliers=$reports->build('suppliers',1,['date_from'=>'1900-01-01','date_to'=>'1900-01-01']);
    verify($oldSuppliers['rows']===[]&&$oldSuppliers['summary']['Net payable']===0.0,'Supplier balance cutoff excludes later bills and payments.');
    $supplierDetail=$supplierReport['details']['purchase-'.$purchase['id']];
    verify(count($supplierDetail['tables'][0]['rows'])===1&&$supplierDetail['tables'][0]['rows'][0][1]===2.0&&$supplierDetail['tables'][0]['rows'][0][2]===1.0&&$supplierDetail['tables'][0]['rows'][0][3]===1.0,'Supplier report expands ordered, received and pending quantities without treating unreceived goods as debt.');
    verify($supplierDetail['tables'][1]['rows'][0][5]===90.0&&$supplierDetail['tables'][2]['rows'][0][4]===90.0&&$supplierDetail['tables'][2]['rows'][0][5]==='unpaid','Supplier detail shows dated goods receiving and the unpaid bill consistently.');
    verify(count($supplierDetail['tables'][3]['rows'])===1&&$supplierDetail['tables'][3]['rows'][0][4]==='voided','Voided supplier payments remain visible in report audit details without settling the bill.');
    $customerReport=$reports->build('customers',1,$filter+['party_id'=>(string)$allocationCustomer]);
    verify(count($customerReport['rows'])===1&&$customerReport['rows'][0][2]===3&&$customerReport['summary']['Net outstanding']===-50.0,'Customer report includes paid orders once, with unused deposit credit in the closing balance.');
    verify(count($customerReport['details']['customer-'.$allocationCustomer]['tables'][0]['rows'])===3,'POS sales and mirrored invoices do not duplicate customer orders.');
    $receiptBefore=row('customer_receipts',(int)$manual['id']);$invoiceBefore=row('customer_invoices',$orderIds[2]);$tomorrow=date('Y-m-d',strtotime('+1 day'));
    $db->table('customer_receipts')->where('id',$manual['id'])->update(['received_at'=>$tomorrow.' 09:00:00']);
    verify($reports->build('customers',1,$filter+['party_id'=>(string)$allocationCustomer])['summary']['Net outstanding']===-20.0,'Historical customer balance excludes a collection after the closing date.');
    $historicalSales=$reports->build('sales',1,$filter);$historicalOrder=array_values(array_filter($historicalSales['rows'],static fn($r)=>$r[1]===$invoiceBefore['invoice_no']));
    verify(count($historicalOrder)===1&&$historicalOrder[0][5]===30.0,'Sales Report and Customer Report use the same historical collection cutoff.');
    $db->table('customer_receipts')->where('id',$manual['id'])->update(['received_at'=>$receiptBefore['received_at'],'status'=>'cancelled','voided_at'=>$tomorrow.' 09:00:00']);
    $db->table('customer_invoices')->where('id',$orderIds[2])->update(['paid_total'=>(float)$invoiceBefore['paid_total']-30]);
    verify($reports->build('customers',1,$filter+['party_id'=>(string)$allocationCustomer])['summary']['Net outstanding']===-50.0,'A later receipt reversal does not rewrite the earlier closing balance.');
    verify($reports->build('customers',1,['date_from'=>$tomorrow,'date_to'=>$tomorrow,'party_id'=>(string)$allocationCustomer])['summary']['Net outstanding']===-20.0,'Customer receipt reversal restores debt on and after the reversal date.');
    $db->table('customer_receipts')->where('id',$manual['id'])->update($receiptBefore);$db->table('customer_invoices')->where('id',$orderIds[2])->update($invoiceBefore);
    $paymentBefore=row('supplier_payments',(int)$supplierPayment['id']);
    $db->table('supplier_payments')->where('id',$supplierPayment['id'])->update(['voided_at'=>$tomorrow.' 09:00:00']);
    verify($reports->build('suppliers',1,$filter)['summary']['Net payable']===0.0,'A supplier payment remains effective in a report dated before its later reversal.');
    $db->table('supplier_payments')->where('id',$supplierPayment['id'])->update($paymentBefore);
    $stockBefore=(float)$db->table('stock_levels')->where('product_id',$productId)->where('branch_id',1)->get()->getRow('quantity');
    $db->transBegin();
    try{
        (new App\Services\PurchaseReceiptService())->createReceipt($purchase['id'],$goods+['received_at'=>$tomorrow.' 10:00:00'],1,1,'premium');
        $historicalSupplier=$reports->build('suppliers',1,$filter);
        verify($historicalSupplier['summary']['Net payable']===90.0&&$historicalSupplier['details']['purchase-'.$purchase['id']]['tables'][0]['rows'][0][2]===1.0,'Later partial receiving does not inflate historical supplier bills or received quantities.');
        $stockReport=$reports->build('inventory',1,$filter);
        verify($stockReport['rows'][0][3]===$stockBefore,'Inventory closing quantity includes opening stock and excludes future goods receiving.');
    }finally{$db->transRollback();}
    $incomeReport=$reports->build('income-statement',1,$filter);
    verify(array_column($incomeReport['sections'],'key')===['cash','income','expenses'],'Income statement shows cash balances, income and expenses in separate sections.');
    verify($incomeReport['sections'][1]['total']===$incomeReport['summary']['Income']&&$incomeReport['sections'][2]['total']===$incomeReport['summary']['Expenses'],'Income and expense account rows reconcile with statement totals.');
    verify($incomeReport['sections'][0]['total']===$reports->build('cash-flow',1,$filter)['summary']['Closing cash'],'Separate cash closing balances reconcile with the cash flow report.');
    $searchedIncome=$reports->build('income-statement',1,$filter+['q'=>'no-such-account']);
    verify($searchedIncome['rows']===[]&&$searchedIncome['summary']===$incomeReport['summary'],'Account search filters statement details without silently changing the financial totals.');
    verify($reports->build('suppliers',99999,$filter)['details']===[]&&$reports->build('customers',99999,$filter)['details']===[],'Expanded report details never contain another branch’s documents.');
    verify($reports->build('customers',1,$filter+['party_id'=>'999999'])['rows']===[],'Unknown customer filter returns an empty report.');
    rejects(fn()=>$reports->build('suppliers',1,$filter+['party_id'=>['bad']]),'Select a valid customer or supplier.');
    rejects(fn()=>$reports->build('customers',1,$filter+['party_id'=>'1 OR 1=1']),'Select a valid customer or supplier.');
    $reportFixtures=[];
    foreach(array_keys(App\Services\WebReportService::TITLES) as $type){$report=$reports->build($type,1,$filter);$reportFixtures[]=$report;verify(isset($report['columns'],$report['rows'],$report['summary']),"Build $type with one normalized snapshot for screen and exports.");}
    if(in_array('--export-fixtures',$argv,true)){if(!is_dir(FCPATH.'build'))mkdir(FCPATH.'build');file_put_contents(FCPATH.'build/erp-report-fixtures.json',json_encode($reportFixtures));}
    $trial=$reports->build('trial-balance',1,$filter);$balanceSheet=$reports->build('balance-sheet',1,$filter);
    verify($trial['summary']['Difference']===0.0&&$balanceSheet['summary']['Difference']===0.0,'Trial balance and balance sheet remain balanced after receipts, discounts, purchases, deposits, and reversals.');
    $flow=$reports->build('cash-flow',1,$filter);verify(round($flow['summary']['Opening cash']+$flow['summary']['Cash in']-$flow['summary']['Cash out'],2)===$flow['summary']['Closing cash'],'Cash flow reconciles opening plus movement to closing cash.');
    verify($reports->build('trial-balance',2,$filter)['summary']['Total debits']===0.0,'Reports do not disclose another branch’s activity.');
    rejects(fn()=>$reports->build('sales',1,['date_from'=>'2026-02-30','date_to'=>'2026-03-01']),'Select valid report dates.');
    rejects(fn()=>$reports->build('sales',1,['date_from'=>'2026-03-02','date_to'=>'2026-03-01']),'Start date must not be after end date.');
    rejects(fn()=>$reports->build('sales',1,['date_from'=>['not-a-date']]),'Report filters must be text values.');
    $dashboardService=new App\Services\DashboardService();$dashboard=$dashboardService->get(1,'today');
    $dashboardReport=$reports->build('sales',1,['date_from'=>$dashboard['from'],'date_to'=>$dashboard['to']]);
    verify($dashboard['revenue']===$dashboardReport['summary']['Sales']&&$dashboard['orders']===count($dashboardReport['rows']),'Dashboard sales revenue and order count reconcile with the Sales Report.');
    verify(count($dashboardService->get(1,'7days')['daily'])===7&&count($dashboardService->get(1,'30days')['daily'])===30,'Dashboard date filters include every day, including days without sales.');
    $emptyDashboard=$dashboardService->get(99999,'today');
    verify($emptyDashboard['revenue']===0.0&&$emptyDashboard['collected']===0.0&&$emptyDashboard['outstanding']===0.0&&$emptyDashboard['recentOrders']===[],'An empty branch shows zero activity without demo orders or another branch’s collections.');
    $initialCollected=(float)$db->query("SELECT COALESCE(SUM(LEAST(s.grand_total,COALESCE(p.tender,0))),0) n FROM sales s LEFT JOIN (SELECT sale_id,SUM(amount) tender FROM payments GROUP BY sale_id) p ON p.sale_id=s.id WHERE s.branch_id=1 AND s.status IN ('completed','partial') AND DATE(s.sold_at)=?",[$dashboard['from']])->getRow('n');
    $receiptCollected=(float)$db->query("SELECT COALESCE(SUM(amount),0) n FROM customer_receipts WHERE branch_id=1 AND status='active' AND DATE(received_at)=?",[$dashboard['from']])->getRow('n');
    verify($dashboard['collected']===round($initialCollected+$receiptCollected,2),'Dashboard collections include active receipts and cap initial tenders to exclude change.');
    rejects(fn()=>$dashboardService->get(1,'invalid'),'Select a valid dashboard date range.');
    rejects(fn()=>$collection->record(['uuid'=>['bad'],'customer_id'=>$allocationCustomer,'amount'=>1],1,1,'premium'),'INVALID_UUID');
    rejects(fn()=>$receivables->recordReceipt(['uuid'=>Uuid::v4(),'invoice_id'=>[$invoiceId],'amount'=>1],1,1,'premium'),'INVOICE_NOT_FOUND');
    $old=(new App\Services\FinancialReportService())->incomeStatement('1900-01-01','1900-01-02');verify($old['net_profit']===0.0,'Shared income statement excludes activity outside the date range.');
    $appearance=new App\Services\AppearanceSettingsService();
    rejects(fn()=>$appearance->save(['brand_color'=>'red; background:url(evil)']),'Choose a valid color.');
    rejects(fn()=>$appearance->save(['system_name'=>'Changed','thermal_paper_width'=>'500']),'Select 58mm or 80mm paper.');
    verify((new App\Models\SettingModel())->getVal('system_name')===null,'Invalid settings update leaves all settings unchanged.');
    $appearance->save(['system_name'=>'QA ERP','business_name'=>'QA Shop','business_address'=>'QA Location','business_phone'=>'+252 123456','receipt_header'=>'Welcome QA','receipt_footer'=>'Thank you QA','brand_color'=>'#123456','accent_color'=>'#112233','background_color'=>'#f1f2f3','receipt_style'=>'compact','thermal_paper_width'=>'58']);
    $printer=(new App\Services\ThermalPrintService())->getConfig();verify($printer['store_name']==='QA Shop'&&$printer['receipt_style']==='compact'&&$printer['paper_width']===58,'Saved branding and thermal style reach the real printer configuration.');
    $branding=(new App\Services\ReceiptBrandingService())->get(1);
    verify($branding['store_name']===$printer['store_name']&&$branding['address']===$printer['address']&&$branding['phone']===$printer['phone']&&$branding['header']===$printer['header']&&$branding['footer']===$printer['footer'],'Receipt previews and ESC/POS share saved business name, address, telephone, header and footer.');
    (new App\Models\SettingModel())->setVal('thermal_printer_type','dummy');
    $compact=(new App\Services\ThermalPrintService())->printSale((int)$order['id'],1);
    (new App\Models\SettingModel())->setVal('receipt_style','classic');
    $classic=(new App\Services\ThermalPrintService())->printSale((int)$order['id'],1);
    verify($compact['success']&&$classic['success']&&$compact['data']!==$classic['data']&&str_contains(base64_decode($compact['data']),'QA Shop'),'Classic and compact settings generate different valid ESC/POS streams with the saved business name; no hardware is contacted.');
    foreach(['QA Location','+252 123456','Welcome QA','Thank you QA'] as $detail)verify(str_contains(base64_decode($classic['data']),$detail),'ESC/POS sale output contains saved branding: '.$detail);
    $receiptStream=(new App\Services\ThermalPrintService(connectorFactory:static fn()=>new Mike42\Escpos\PrintConnectors\DummyPrintConnector()))->printCustomerReceipt((int)$manual['id'],1);
    verify($receiptStream['success']&&str_contains(base64_decode($receiptStream['data']),"\x1dV"),'Allocated customer receipts print persisted order allocations and an automatic paper-cut command without hardware.');
    $taxes=new App\Services\TaxRateService();$taxes->save(['uuid'=>Uuid::v4(),'name'=>'QA VAT','percentage'=>'5']);
    $firstTax=$taxes->active();
    $taxSale=(new SalesService())->createSale(['uuid'=>Uuid::v4(),'customer_id'=>$allocationCustomer,'tax_id'=>$firstTax['id'],'items'=>[['product_id'=>$productId,'quantity'=>1,'unit_price'=>100]],'payments'=>[]],1,1,'premium');
    verify((float)$taxSale['tax_total']===5.0&&(float)$taxSale['grand_total']===105.0&&(float)$taxSale['items'][0]['tax_rate']===5.0,'New POS sale applies the active percentage and stores its historical item rate.');
    $taxes->save(['uuid'=>Uuid::v4(),'name'=>'QA VAT new','percentage'=>'10']);
    $before=$snapshot();
    try{(new SalesService())->createSale(['customer_id'=>$allocationCustomer,'tax_id'=>$firstTax['id'],'items'=>[['product_id'=>$productId,'quantity'=>1,'unit_price'=>100]],'payments'=>[]],1,1,'premium');throw new RuntimeException('Stale tax accepted');}
    catch(App\Services\TaxChangedException $error){verify((float)$error->activeTax['percentage']===10.0&&$snapshot()===$before,'Stale POS tax rejects checkout and rolls back without changing cart-related financial data.');}
    $checkoutBody=['customer_id'=>$allocationCustomer,'tax_id'=>$firstTax['id'],'items'=>[['product_id'=>$productId,'quantity'=>1,'unit_price'=>100]],'payments'=>[]];
    $checkoutRequest=new CodeIgniter\HTTP\IncomingRequest(config(Config\App::class),new CodeIgniter\HTTP\URI('http://localhost/sales/pos/checkout'),json_encode($checkoutBody),new CodeIgniter\HTTP\UserAgent());
    $checkoutRequest->setHeader('Content-Type','application/json');$checkoutController=new App\Controllers\Web\PosWebController();
    $checkoutController->initController($checkoutRequest,new CodeIgniter\HTTP\Response(config(Config\App::class)),service('logger'));
    $receiptPermissions=AuthContext::$permissions;AuthContext::$permissions[]='sales.create';$checkoutResponse=$checkoutController->checkout();AuthContext::$permissions=$receiptPermissions;
    $checkoutResult=json_decode($checkoutResponse->getBody(),true);
    verify($checkoutResponse->getStatusCode()===409&&(float)$checkoutResult['activeTax']['percentage']===10.0&&$snapshot()===$before,'Web checkout returns the updated tax for review, with no partial sale or stock writes.');
    $savedTaxSale=(new App\Models\SaleModel())->findDetailed((int)$taxSale['id'],1);
    verify((float)$savedTaxSale['tax_total']===5.0&&(float)$savedTaxSale['items'][0]['tax_rate']===5.0,'Activating a different tax does not recalculate saved sales or receipts.');
    $taxInvoiceId=(int)$db->table('customer_invoices')->where('sale_id',$taxSale['id'])->get()->getRow('id');
    $taxReceipt=$receivables->recordReceipt(['uuid'=>Uuid::v4(),'invoice_id'=>$taxInvoiceId,'amount'=>52.5],1,1,'premium');
    verify((float)row('customer_invoices',$taxInvoiceId)['paid_total']===52.5&&(float)row('sales',(int)$taxSale['id'])['paid_total']===52.5,'A partial customer receipt settles the tax-inclusive sale total consistently.');
    $reversals->voidCustomerReceipt((int)$taxReceipt['id'],1,'Tax QA reversal',1,'premium');
    verify((float)row('sales',(int)$taxSale['id'])['paid_total']===0.0&&(float)row('sales',(int)$taxSale['id'])['tax_total']===5.0,'Voiding a tax-inclusive collection restores debt without changing the original tax.');
    verify($reports->build('trial-balance',1,$filter)['summary']['Difference']===0.0,'Tax-inclusive sale and receipt reversal leave the trial balance balanced.');
    // Direct URL/controller access must fail before any data is loaded.
    AuthContext::$permissions=[];
    foreach([[new App\Controllers\Web\TaxesWebController(),'index'],[new App\Controllers\Web\TaxesWebController(),'save'],[new App\Controllers\Web\PosWebController(),'checkout']] as [$controller,$method]){try{$controller->$method();throw new RuntimeException('Tax/checkout permission bypass');}catch(CodeIgniter\HTTP\Exceptions\HTTPException $error){verify($error->getCode()===403,'Reject unauthorized tax or checkout URL: '.$method);}}
    // Dashboard shell access is menu-based; role_dashboards_smoke tests its widget permissions.
    foreach(['SalesWebController','PosWebController','UsersWebController','RolesWebController','AuditWebController','SettingsWebController','SuppliersWebController'] as $class){$class='App\\Controllers\\Web\\'.$class;try{(new $class())->index();throw new RuntimeException('Permission bypass');}catch(CodeIgniter\HTTP\Exceptions\HTTPException $error){verify($error->getCode()===403,'Reject unauthorized '.$class.' URL.');}}
    $reportController=new App\Controllers\Web\ReportsWebController();
    foreach(array_keys(App\Services\WebReportService::TITLES) as $type){try{$reportController->data($type);throw new RuntimeException('Report permission bypass');}catch(CodeIgniter\HTTP\Exceptions\HTTPException $error){verify($error->getCode()===403,'Reject unauthorized report data URL: '.$type);}}
    foreach(['save','logo','operational'] as $method){try{(new App\Controllers\Web\SettingsWebController())->$method();throw new RuntimeException('Settings mutation bypass');}catch(CodeIgniter\HTTP\Exceptions\HTTPException $error){verify($error->getCode()===403,'Reject unauthorized settings action '.$method);}}
    AuthContext::$permissions=['invoices.view','invoices.manage'];

    $webPayload = ['uuid' => Uuid::v4(), 'invoice_id' => $invoiceId, 'amount' => 10, 'account_id' => $cashAccountId];
    verify(receiptController($webPayload)->saveCustomerReceipt()->getStatusCode() === 200, 'Web collection endpoint accepts an authorized invoice collection.');
    verify(receiptController(['amount' => 10])->saveCustomerReceipt()->getStatusCode() === 422, 'Web collection requires an invoice.');
    verify(receiptController(['uuid' => Uuid::v4(), 'invoice_id' => $invoiceId, 'amount' => 10])->saveCustomerReceipt()->getStatusCode() === 422, 'Web collection requires a cash account.');
    AuthContext::$permissions = ['invoices.view'];
    try { receiptController($webPayload)->saveCustomerReceipt(); throw new RuntimeException('Permission bypass'); }
    catch (CodeIgniter\HTTP\Exceptions\HTTPException $error) { verify($error->getCode() === 403, 'Read-only users cannot collect payments.'); }
    AuthContext::$permissions[] = 'invoices.manage';
    $html = receiptController()->index('receipts');
    verify(str_contains($html, 'customerReceiptManager') && str_contains($html, 'customer-receipts.js') && str_contains($html, 'id="customerReceiptDialog"'), 'Customer Receipts page renders collection controls and receipt history.');
    verify(str_contains($html, 'id="addCustomerReceipt"') && !str_contains($html, 'receiptInvoicesTable') && !str_contains($html, 'Client Prepayment Deposits') && !str_contains($html, 'Bank / wallet account'), 'Receipts page contains only receipt history and Add receipt, with invoice selection and cash accounts inside the modal.');
    $page = new DOMDocument();
    $page->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
    $options = new DOMXPath($page);
    $groups = [];
    foreach ($options->query('//select[@name="account_id"]/optgroup') as $group) $groups[] = $group->getAttribute('label');
    verify($groups === ['Cash', 'Bank', 'Mobile Money'], 'Receipt account picker renders all eligible Chart of Accounts categories.');
    try { receiptController()->saveSupplierPayment(); throw new RuntimeException('Permission bypass'); }
    catch (CodeIgniter\HTTP\Exceptions\HTTPException $error) { verify($error->getCode() === 403, 'Supplier payment requires bill management permission.'); }
    $originalPermissions = AuthContext::$permissions;
    AuthContext::$permissions[] = 'bills.manage';
    foreach ($settlementIds as $kind => $accountId) {
        $payload = ['uuid' => Uuid::v4(), 'bill_id' => $billId, 'account_id' => $accountId, 'amount' => 10];
        $result = receiptController($payload)->saveSupplierPayment();
        $payment = json_decode($result->getBody(), true)['data'] ?? [];
        verify($result->getStatusCode() === 200 && $payment['method'] === $kind && (int) $payment['account_id'] === $accountId, "Supplier form records the selected $kind account.");
        $journalId = $db->table('journal_entries')->where('source_type', 'supplier_payment')->where('source_id', $payment['id'])->get()->getRow('id');
        verify((float) $db->table('journal_entry_lines')->where('journal_entry_id', $journalId)->where('account_id', $accountId)->get()->getRow('credit') === 10.0, "Supplier payment credits the exact $kind account.");
        $retry = json_decode(receiptController($payload)->saveSupplierPayment()->getBody(), true);
        verify($retry['data']['id'] === $payment['id'] && (float) row('supplier_bills', $billId)['paid_total'] === 10.0, 'Retrying the supplier form does not settle the bill twice.');
        $reversals->voidSupplierPayment($payment['id'], 1, 'Undo selected account', 1, 'premium');
        $reversalId = $db->table('journal_entries')->where('source_type', 'supplier_payment_reversal')->where('source_id', $payment['id'])->get()->getRow('id');
        verify((float) $db->table('journal_entry_lines')->where('journal_entry_id', $reversalId)->where('account_id', $accountId)->get()->getRow('debit') === 10.0
            && (float) row('supplier_bills', $billId)['paid_total'] === 0.0, "Voiding a $kind supplier payment reverses the account and restores the bill.");
    }
    $before = $snapshot();
    $invalidPayment = receiptController(['uuid' => Uuid::v4(), 'bill_id' => $billId, 'account_id' => $revenueId, 'amount' => 10])->saveSupplierPayment();
    verify($invalidPayment->getStatusCode() === 422 && $snapshot() === $before, 'A forged supplier payment account leaves the bill and ledger unchanged.');
    AuthContext::$permissions = $originalPermissions;

} finally {
    foreach (array_reverse($shadowed) as $table) $db->query("DROP TEMPORARY TABLE IF EXISTS `$table`");
    foreach ($originalCounts as $table => $count) verify($db->table($table)->countAllResults() === $count, "$table real records remain unchanged.");
    AuthContext::reset();
}
