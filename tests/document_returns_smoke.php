<?php
/** Run: php tests/document_returns_smoke.php. Every test write uses connection-local InnoDB temporary tables. */
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
use App\Services\DocumentReturnService;
use App\Services\SalesService;
use App\Services\PurchaseService;
use App\Services\PurchaseReceiptService;
use App\Services\PayablesService;
use App\Services\ReceivablesService;
use App\Services\StatementService;
use App\Services\WebReportService;

$checks = 0;
function check(bool $condition, string $message): void {
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
    echo "PASS: $message\n";
}
function rejects(callable $action, string $contains): void {
    try { $action(); } catch (InvalidArgumentException|RuntimeException $e) {
        check(str_contains($e->getMessage(), $contains), 'Reject: ' . $contains);
        return;
    }
    throw new RuntimeException('Expected rejection: ' . $contains);
}
function record(string $table, int $id): array { return db_connect()->table($table)->where('id', $id)->get()->getRowArray(); }
function payload(int $line, float $qty, int $account): array { return ['uuid'=>Uuid::v4(),'reason'=>'Isolated return QA','account_id'=>$account,'items'=>[['id'=>$line,'quantity'=>$qty]]]; }
function snapshot(): string {
    return hash('sha256', serialize(array_map(static fn($t) => db_connect()->table($t)->get()->getResultArray(), ['document_returns','document_return_items','stock_movements','stock_levels','customers','sales','sale_items','customer_invoices','supplier_bills','products','journal_entries','journal_entry_lines'])));
}

$tenant = (new TenantManager())->find(config(Config\Tenants::class)->default);
(new TenantManager())->connect($tenant);
$db = db_connect();
AuthContext::$tenant = $tenant;
AuthContext::$user = (object)['id'=>1,'role_id'=>1,'role_name'=>'Superadmin','branch_id'=>1];
session()->set('user', ['id'=>1,'role_id'=>1,'role_name'=>'Superadmin','branch_id'=>1]);
$tables = ['customers','products','sales','sale_items','payments','stock_levels','stock_movements','customer_invoices','customer_invoice_items','customer_receipts','customer_receipt_allocations','customer_deposits','journal_entries','journal_entry_lines','financial_periods','audit_log','accounts','pos_payment_methods','bank_accounts','purchases','purchase_items','purchase_receipts','purchase_receipt_items','suppliers','supplier_bills','supplier_bill_items','supplier_payments','settings','tax_rates','document_returns','document_return_items'];
$hashes = $shadowed = [];
try {
    foreach ($tables as $table) {
        $hashes[$table] = hash('sha256', serialize($db->table($table)->get()->getResultArray()));
        $copy = in_array($table, ['accounts','pos_payment_methods'], true) ? $db->table($table)->get()->getResultArray() : [];
        $definition = array_values($db->query("SHOW CREATE TABLE `$table`")->getRowArray())[1];
        $definition = preg_replace('/^CREATE TABLE/', 'CREATE TEMPORARY TABLE', $definition);
        $definition = preg_replace('/^\s*CONSTRAINT .*\n/m', '', $definition);
        $definition = preg_replace('/,\s*\)/', "\n)", $definition);
        $db->query($definition); $shadowed[] = $table;
        if ($copy) $db->table($table)->insertBatch($copy);
    }
    $db->resetDataCache();
    $account = (int)$db->table('accounts')->where('code', '1000')->get()->getRow('id');
    $service = new DocumentReturnService();
    foreach (['basic','premium'] as $plan) {
        foreach ([0, 50, 100] as $paid) {
            $db->table('products')->insert(['name'=>'QA return item','sku'=>Uuid::v4(),'unit'=>'pcs','sell_price'=>25,'cost_price'=>10,'tax_rate'=>0,'is_active'=>1]); $product=(int)$db->insertID();
            $db->table('stock_levels')->insert(['branch_id'=>1,'product_id'=>$product,'quantity'=>20]);
            $db->table('customers')->insert(['name'=>"Return $plan $paid",'balance'=>0,'credit_limit'=>100000]); $customer=(int)$db->insertID();
            $sale = (new SalesService())->createSale(['customer_id'=>$customer,'items'=>[['product_id'=>$product,'quantity'=>4]],'payments'=>$paid ? [['method'=>'cash','amount'=>$paid]] : []],1,1,$plan);
            $line=(int)$sale['items'][0]['id'];
            check((float)record('sale_items',$line)['cost_total']===40.0, 'New sale snapshots original COGS per line.');
            $input=payload($line,3,$account);
            $q=$service->quote('sale',$sale['id'],$input,1);
            check($q['total']===75.0 && $q['debt_reduction']===(float)min(75,100-$paid) && $q['refund']===(float)max(0,75-(100-$paid)), "$plan sale $paid/100: debt first, refund only the paid portion.");
            $r=$service->create('sale',$sale['id'],$input,1,1,$plan);
            check((float)$db->table('stock_levels')->where('branch_id',1)->where('product_id',$product)->get()->getRow('quantity')===19.0, 'Sales return restores stock.');
            check((float)record('customers',$customer)['balance']===-(float)(100-$paid-min(75,100-$paid)), 'Customer cached balance reflects only debt reduction.');
            check((float)(new StatementService())->customerStatement($customer,1,[])['summary']['closing_balance']===-(float)record('customers',$customer)['balance'], 'Customer statement agrees with cached balance after refund.');
            $before=snapshot();
            check($service->create('sale',$sale['id'],$input,1,1,$plan)['id']===$r['id'] && snapshot()===$before, 'Identical retry returns one credit note without side effects.');
            rejects(fn()=> $service->create('sale',$sale['id'],array_replace($input,['reason'=>'Changed']),1,1,$plan),'different return');
            rejects(fn()=> $service->create('sale',$sale['id'],payload($line,2,$account),1,1,$plan),'exceeds');
            rejects(fn()=> $service->create('sale',$sale['id'],payload($line,1,$account),2,1,$plan),'this branch');
            check(snapshot()===$before, 'Rejected returns leave all financial tables unchanged.');
            $service->create('sale',$sale['id'],payload($line,1,$account),1,1,$plan);
            $s=record('sales',$sale['id']);
            check((float)$s['returned_total']===100.0 && (float)$s['paid_total']===0.0 && (float)$s['refunded_total']===(float)$paid && (float)record('customers',$customer)['balance']===0.0, 'Full return clears remaining debt and refunds exactly the money paid.');

            $db->table('suppliers')->insert(['name'=>'QA supplier','is_active'=>1]); $supplier=(int)$db->insertID();
            $purchase=(new PurchaseService())->createPurchase(['supplier_id'=>$supplier,'items'=>[['product_id'=>$product,'quantity'=>4,'unit_cost'=>25]]],1,1);
            $purchaseLine=(int)$purchase['items'][0]['id'];
            rejects(fn()=> $service->create('purchase',$purchase['id'],payload($purchaseLine,1,$account),1,1,$plan),'eligible');
            (new PurchaseReceiptService())->createReceipt($purchase['id'],['items'=>[['purchase_item_id'=>$purchaseLine,'quantity'=>4]]],1,1,$plan);
            $bill=$db->table('supplier_bills')->where('purchase_id',$purchase['id'])->get()->getRowArray();
            if ($paid) (new PayablesService())->recordPayment(['bill_id'=>$bill['id'],'amount'=>$paid,'method'=>'cash'],1,1,$plan);
            $input=payload($purchaseLine,3,$account);
            $q=$service->quote('purchase',$purchase['id'],$input,1);
            check($q['total']===75.0 && $q['refund']===(float)max(0,75-(100-$paid)), "$plan purchase $paid/100: supplier refund excludes unpaid goods.");
            $r=$service->create('purchase',$purchase['id'],$input,1,1,$plan);
            check($service->create('purchase',$purchase['id'],$input,1,1,$plan)['id']===$r['id'],'Purchase return retry is idempotent.');
            $bill=record('supplier_bills',(int)$bill['id']);
            $due=round((float)$bill['total']-(float)$bill['paid_total'],2);
            check((new StatementService())->supplierStatement($supplier,1,[])['summary']['closing_balance']===$due,'Supplier statement agrees with net bill after refund.');
            $supplierRow=(new App\Models\SupplierModel())->withBalances([record('suppliers',$supplier)])[0];
            check($supplierRow['balance']===$due,'Supplier list balance agrees with statement and bill.');
            $service->create('purchase',$purchase['id'],payload($purchaseLine,1,$account),1,1,$plan);
            $bill=record('supplier_bills',(int)$bill['id']);
            check((float)$bill['total']===0.0 && (float)$bill['paid_total']===0.0 && (float)$bill['refunded_total']===(float)$paid,'Full purchase return clears debt and paid portion.');
            check((float)record('purchase_items',$purchaseLine)['quantity_received']===4.0,'Returning goods cannot reopen receiving and duplicate original goods.');
        }
    }
    // Discounted settlement must never turn waived money into a cash refund.
    $db->table('customers')->insert(['name'=>'Discount return','balance'=>0,'credit_limit'=>100000]); $customer=(int)$db->insertID();
    $sale=(new SalesService())->createSale(['customer_id'=>$customer,'items'=>[['product_id'=>$product,'quantity'=>4]],'payments'=>[]],1,1,'premium');
    $invoice=$db->table('customer_invoices')->where('sale_id',$sale['id'])->get()->getRowArray();
    $receipt=(new ReceivablesService())->recordReceipt(['uuid'=>Uuid::v4(),'invoice_id'=>$invoice['id'],'amount'=>90,'discount'=>10],1,1,'premium');
    $line=(int)$sale['items'][0]['id']; $input=payload($line,4,$account);
    $q=$service->quote('sale',$sale['id'],$input,1);
    check($q['refund']===90.0 && $q['discount_reversal']===10.0,'A 90 cash + 10 waived settlement refunds only 90.');
    $service->create('sale',$sale['id'],$input,1,1,'premium');
    rejects(fn()=> (new App\Services\ReversalService())->voidCustomerReceipt($receipt['id'],1,'Attempt after refund',1,'premium'),'INCONSISTENT_BALANCE');
    check((new StatementService())->customerStatement($customer,1,[])['summary']['closing_balance']===0.0,'Discount reversal, return and refund leave customer statement at zero.');

    // Bad payloads, stale previews, inactive accounts, closed periods and injected posting failures.
    $sale=(new SalesService())->createSale(['customer_id'=>$customer,'items'=>[['product_id'=>$product,'quantity'=>4]],'payments'=>[['method'=>'cash','amount'=>100]]],1,1,'premium');
    $line=(int)$sale['items'][0]['id']; $input=payload($line,1,$account); $before=snapshot();
    foreach ([0,-1,1.0001,INF,'bad',[],true] as $qty) { $bad=$input; $bad['items'][0]['quantity']=$qty; rejects(fn()=> $service->create('sale',$sale['id'],$bad,1,1,'premium'),'quantities'); }
    $bad=$input; $bad['items'][]=$bad['items'][0]; rejects(fn()=> $service->create('sale',$sale['id'],$bad,1,1,'premium'),'only once');
    $bad=$input; $bad['items'][0]['id']=999999; rejects(fn()=> $service->create('sale',$sale['id'],$bad,1,1,'premium'),'only once');
    $bad=$input; $bad['account_id']=999999; rejects(fn()=> $service->create('sale',$sale['id'],$bad,1,1,'premium'),'active cash account');
    $bad=$input; $bad['quote_token']=str_repeat('0',64); rejects(fn()=> $service->create('sale',$sale['id'],$bad,1,1,'premium'),'changed after');
    check(snapshot()===$before,'Invalid quantities, foreign lines/accounts and stale previews make no writes.');
    $db->table('financial_periods')->insert(['name'=>'Closed QA','start_date'=>date('Y-m-d'),'end_date'=>date('Y-m-d'),'status'=>'closed']); $period=(int)$db->insertID();
    rejects(fn()=> $service->create('sale',$sale['id'],$input,1,1,'basic'),'PERIOD_CLOSED');
    $db->table('financial_periods')->where('id',$period)->delete();
    $failing=new class extends App\Services\AccountingService {
        public function createEntry(int $branchId,string $entryDate,?string $description,?string $sourceType,?int $sourceId,array $lines,?int $userId=null): int {
            $id=parent::createEntry($branchId,$entryDate,$description,$sourceType,$sourceId,$lines,$userId);
            if (str_ends_with((string)$sourceType,'_return')) throw new RuntimeException('INJECTED_FAILURE');
            return $id;
        }
    };
    $before=snapshot(); rejects(fn()=> (new DocumentReturnService($failing))->create('sale',$sale['id'],$input,1,1,'premium'),'INJECTED_FAILURE');
    check(snapshot()===$before,'Failure after journal insertion rolls back return, refund, stock, costs, invoice and customer balance.');
    $downgraded=$service->create('sale',$sale['id'],$input,1,1,'basic');
    check($db->table('journal_entries')->where('source_type','sale_return')->where('source_id',$downgraded['id'])->countAllResults()===1,'A sale originally posted on Premium still posts its return after a Basic plan downgrade.');

    // A return posted today must not rewrite yesterday's closing statement.
    $db->table('customers')->insert(['name'=>'Historical return','balance'=>0,'credit_limit'=>100000]); $historyCustomer=(int)$db->insertID();
    $yesterday=date('Y-m-d',strtotime('-1 day'));
    $historySale=(new SalesService())->createSale(['sold_at'=>$yesterday.' 10:00:00','customer_id'=>$historyCustomer,'items'=>[['product_id'=>$product,'quantity'=>4]],'payments'=>[['method'=>'cash','amount'=>20]]],1,1,'premium');
    $historyLine=(int)$historySale['items'][0]['id'];
    $service->create('sale',$historySale['id'],payload($historyLine,2,$account),1,1,'premium');
    $previous=(new WebReportService())->build('sales',1,['date_from'=>$yesterday,'date_to'=>$yesterday]);
    $historyRow=array_values(array_filter($previous['rows'],fn($r)=>$r[1]===$historySale['invoice_no']))[0];
    check($historyRow[3]===100.0&&$historyRow[4]===20.0&&$historyRow[5]===80.0,'Sales report before return date preserves original total, initial payment and debt.');
    $current=(new WebReportService())->build('customers',1,['date_from'=>$yesterday,'date_to'=>date('Y-m-d'),'party_id'=>$historyCustomer]);
    check($current['summary']['Net outstanding']===30.0,'Customer closing report reflects the return on its effective date.');
    (new App\Services\CustomerCollectionService())->record(['uuid'=>Uuid::v4(),'customer_id'=>$historyCustomer,'amount'=>30],1,1,'premium');
    check((float)$db->table('sales')->where('id',$historySale['id'])->get()->getRow('paid_total')===50.0,'Receipt after a partial return collects only the reduced balance.');
    $last=$service->create('sale',$historySale['id'],payload($historyLine,2,$account),1,1,'premium');
    check((float)$last['refund']===50.0&&(new StatementService())->customerStatement($historyCustomer,1,[])['summary']['closing_balance']===0.0,'A later return refunds initial payment plus later collection once.');
    $previous=(new WebReportService())->build('sales',1,['date_from'=>$yesterday,'date_to'=>$yesterday]);
    $historyRow=array_values(array_filter($previous['rows'],fn($r)=>$r[1]===$historySale['invoice_no']))[0];
    check($historyRow[3]===100.0&&$historyRow[4]===20.0&&$historyRow[5]===80.0,'Earlier report remains unchanged after both return and subsequent collection/refund.');
    check((new StatementService())->customerStatement($historyCustomer,1,['date_to'=>$yesterday])['summary']['closing_balance']===80.0,'Statement closing date also excludes later return and refund events.');

    // Original tax, order discounts and shipping survive later configuration changes.
    $db->table('tax_rates')->insert(['name'=>'QA original tax','percentage'=>10,'is_active'=>1,'request_uuid'=>Uuid::v4()]);$taxId=(int)$db->insertID();
    $taxSale=(new SalesService())->createSale(['discount'=>1,'shipping'=>2,'items'=>[['product_id'=>$product,'quantity'=>1,'unit_price'=>10],['product_id'=>$product,'quantity'=>1,'unit_price'=>20]],'payments'=>[['method'=>'cash','amount'=>34]]],1,1,'premium');
    $db->table('tax_rates')->where('id',$taxId)->update(['percentage'=>20]);
    $first=$service->create('sale',$taxSale['id'],payload((int)$taxSale['items'][0]['id'],1,$account),1,1,'premium');
    $second=$service->create('sale',$taxSale['id'],payload((int)$taxSale['items'][1]['id'],1,$account),1,1,'premium');
    check(round((float)$first['total']+(float)$second['total'],2)===34.0&&round((float)$first['tax_total']+(float)$second['tax_total'],2)===3.0,'Split returns recover exact original total and tax despite changed active tax.');
    $db->table('tax_rates')->where('id',$taxId)->delete();

    // Recover and freeze legacy sale costs instead of using future catalog costs repeatedly.
    $legacy=(new SalesService())->createSale(['items'=>[['product_id'=>$product,'quantity'=>2]],'payments'=>[['method'=>'cash','amount'=>50]]],1,1,'premium');
    $legacyLine=(int)$legacy['items'][0]['id'];$originalCost=(float)record('sale_items',$legacyLine)['cost_total'];
    $db->table('sale_items')->where('id',$legacyLine)->update(['cost_total'=>null]);
    $first=$service->create('sale',$legacy['id'],payload($legacyLine,1,$account),1,1,'premium');
    $db->table('products')->where('id',$product)->update(['cost_price'=>99]);
    $second=$service->create('sale',$legacy['id'],payload($legacyLine,1,$account),1,1,'premium');
    check(round((float)$first['stock_value']+(float)$second['stock_value'],2)===$originalCost,'Legacy returns reverse exact original posted COGS even after catalog cost changes.');

    // Partial receiving cannot make unreceived goods eligible, and later receipts preserve credits.
    $partial=(new PurchaseService())->createPurchase(['supplier_id'=>$supplier,'items'=>[['product_id'=>$product,'quantity'=>4,'unit_cost'=>25]]],1,1);
    $partialLine=(int)$partial['items'][0]['id'];
    (new PurchaseReceiptService())->createReceipt($partial['id'],['items'=>[['purchase_item_id'=>$partialLine,'quantity'=>2]]],1,1,'premium');
    rejects(fn()=> $service->create('purchase',$partial['id'],payload($partialLine,3,$account),1,1,'premium'),'exceeds');
    $service->create('purchase',$partial['id'],payload($partialLine,1,$account),1,1,'premium');
    (new PurchaseReceiptService())->createReceipt($partial['id'],['items'=>[['purchase_item_id'=>$partialLine,'quantity'=>2]]],1,1,'premium');
    $partialBill=$db->table('supplier_bills')->where('purchase_id',$partial['id'])->get()->getRowArray();
    check((float)$partialBill['total']===75.0&&(float)$partialBill['returned_total']===25.0,'Later receiving grows the net supplier bill without losing an earlier return.');
    $stock=$db->table('stock_levels')->where('branch_id',1)->where('product_id',$product)->get()->getRow('quantity');
    $db->table('stock_levels')->where('branch_id',1)->where('product_id',$product)->update(['quantity'=>0]);
    rejects(fn()=> $service->create('purchase',$partial['id'],payload($partialLine,1,$account),1,1,'premium'),'Not enough stock');
    $db->table('stock_levels')->where('branch_id',1)->where('product_id',$product)->update(['quantity'=>$stock]);
    $purchaseFailure=payload($partialLine,1,$account);$before=snapshot();
    rejects(fn()=> (new DocumentReturnService($failing))->create('purchase',$partial['id'],$purchaseFailure,1,1,'premium'),'INJECTED_FAILURE');
    check(snapshot()===$before,'Purchase posting failure restores supplier bill, goods, costs and journal together.');

    // Endpoint authorization must happen before reading or mutating any source document.
    $request=new CodeIgniter\HTTP\IncomingRequest(config(Config\App::class),new CodeIgniter\HTTP\URI('http://localhost/sales/returns'),null,new CodeIgniter\HTTP\UserAgent());
    $controller=new App\Controllers\Web\ReturnsWebController();$controller->initController($request,new CodeIgniter\HTTP\Response(config(Config\App::class)),service('logger'));
    $oldUser=AuthContext::$user;AuthContext::$user=(object)['id'=>1,'role_id'=>1,'role_name'=>'Staff','branch_id'=>1];AuthContext::$permissions=[];
    foreach (['sale','purchase'] as $kind) {
        try {$controller->source($kind,1);throw new RuntimeException('Expected denied return access.');}
        catch (CodeIgniter\HTTP\Exceptions\HTTPException $e) {check($e->getCode()===403,'Direct return URL is denied without the action permission.');}
    }
    AuthContext::$user=(object)['id'=>999999,'role_id'=>999999,'role_name'=>'Staff','branch_id'=>1];AuthContext::$permissions=['sales.refund','purchases.return'];
    foreach (['sale','purchase'] as $kind) {
        try {$controller->source($kind,1);throw new RuntimeException('Expected denied return menu.');}
        catch (CodeIgniter\HTTP\Exceptions\HTTPException $e) {check($e->getCode()===403,'An action permission alone cannot bypass a denied return menu.');}
    }
    AuthContext::$user=$oldUser;

    $report=(new WebReportService())->build('trial-balance',1,['date_from'=>date('Y-m-01'),'date_to'=>date('Y-m-d')]);
    check(abs($report['summary']['Difference'])<0.001,'Trial balance stays balanced after all returns.');
    foreach (['suppliers','customers','sales','balance-sheet','income-statement','cash-flow'] as $type) {
        $report=(new WebReportService())->build($type,1,['date_from'=>date('Y-m-01'),'date_to'=>date('Y-m-d')]);
        check(isset($report['summary']),"$type report renders with return-adjusted balances.");
    }
    foreach (['sale'] as $kind) {
        $html=view('web/returns/index',['title'=>'Returns QA','kind'=>$kind,'returns'=>$service->listing($kind,1),'sources'=>$service->sources($kind,1),'accounts'=>(new App\Services\ReceiptCashAccountService())->list()]);
        check(str_contains($html,'returnDialog') && str_contains($html,'document-returns.js'),'Return page renders list, searchable selection, modal and script.');
        service('renderer')->resetData();
    }
    echo "Verified $checks return checks.\n";
} finally {
    foreach (array_reverse($shadowed) as $table) $db->query("DROP TEMPORARY TABLE IF EXISTS `$table`");
    foreach ($hashes as $table=>$hash) check(hash('sha256',serialize($db->table($table)->get()->getResultArray()))===$hash,"Real $table records unchanged.");
}
