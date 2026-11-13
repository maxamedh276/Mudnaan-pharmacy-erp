<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('FCPATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
define('ENVIRONMENT', 'development');
require FCPATH . 'app/Config/Paths.php';
$paths = new Config\Paths(); require $paths->systemDirectory . '/Boot.php';
CodeIgniter\Boot::bootConsole($paths);
Config\Services::injectMock('request', new CodeIgniter\HTTP\IncomingRequest(config(Config\App::class), new CodeIgniter\HTTP\URI('http://localhost/'), null, new CodeIgniter\HTTP\UserAgent()));
use App\Libraries\AuthContext;
use App\Libraries\Uuid;
use App\Services\Pharmacy\MedicineBatchService;
use App\Services\Pharmacy\MedicineUnitService;
use App\Services\Pharmacy\PharmacyService;
use App\Services\Pharmacy\PharmacyOperationsService;
use App\Services\SalesService;
$checks = 0;
function check(bool $result, string $message): void { global $checks; if (!$result) throw new RuntimeException($message); $checks++; echo "PASS {$message}\n"; }
function rejects(callable $operation): void { try { $operation(); } catch (InvalidArgumentException $error) { check(true, $error->getMessage()); return; } throw new RuntimeException('Expected rejection.'); }
$name = 'pharmacy_test_' . bin2hex(random_bytes(6));
$config = config(Config\Database::class)->default;
$admin = new mysqli($config['hostname'], $config['username'], $config['password'], '', $config['port']);
register_shutdown_function(static function () use ($admin, $name) { if (preg_match('/^pharmacy_test_[a-f0-9]{12}$/D', $name)) $admin->query('DROP DATABASE IF EXISTS `' . $name . '`'); });
(new App\Libraries\PharmacyInstaller())->install($name);
$db = db_connect();
$role = $db->table('roles')->where('name','Superadmin')->get()->getRowArray();
$db->table('users')->insert(['branch_id'=>1,'role_id'=>$role['id'],'name'=>'Fixture pharmacist','email'=>'fixture@example.test','password_hash'=>password_hash('Fixture-only-123', PASSWORD_DEFAULT)]);
$user = (int)$db->insertID();
AuthContext::$user = (object)['id'=>$user,'branch_id'=>1,'role_name'=>'Superadmin'];
AuthContext::$tenant = ['code'=>'fixture','name'=>'Fixture pharmacy','database'=>$name,'plan'=>'premium'];
AuthContext::$permissions = array_column($db->table('permissions')->get()->getResultArray(),'code');
$service = new PharmacyService(); $operations = new PharmacyOperationsService();
$category = (int)$db->table('categories')->get()->getRow('id');
$profile = ['name'=>'Fixture tablets','category_id'=>$category,'base_unit'=>'tablet','generic_name'=>'Fixture ingredient','strength'=>'100 mg','dosage_form'=>'Tablet','sell_price'=>1,'reorder_level'=>10,'units'=>[
    ['name'=>'tablet','factor'=>1,'sell_price'=>1],['name'=>'strip','factor'=>10,'sell_price'=>8],['name'=>'box','factor'=>100,'sell_price'=>70]
]];
$product = $service->saveMedicine($profile);
check($product > 0, 'Medicine creation reuses ERP catalog and category management');
$units = array_column((new MedicineUnitService())->units($product),null,'name');
check(MedicineUnitService::baseQuantity($units['box'],2) === 200.0, 'Two boxes convert to 200 base tablets');
check(MedicineUnitService::baseQuantity($units['strip'],2) === 20.0, 'Two strips convert to 20 base tablets');
rejects(fn () => MedicineUnitService::baseQuantity($units['strip'],0.5));
$stock = ['product_id'=>$product,'medicine_unit_id'=>$units['box']['id'],'quantity'=>1,'unit_cost'=>20,'batch_no'=>'LOT-A','expiry_date'=>date('Y-m-d',strtotime('+90 days')),'reason'=>'Fixture opening stock','action'=>'receive','request_key'=>Uuid::v4()];
$batchA = $operations->stock($stock,1,$user,'premium');
check($operations->stock($stock,1,$user,'premium') === $batchA, 'Duplicate opening request does not duplicate inventory');
$batchB = $operations->stock(array_replace($stock,['medicine_unit_id'=>$units['strip']['id'],'quantity'=>1,'unit_cost'=>2,'batch_no'=>'LOT-B','expiry_date'=>date('Y-m-d',strtotime('+5 days')),'request_key'=>Uuid::v4()]),1,$user,'premium');
$input = ['uuid'=>Uuid::v4(),'items'=>[['product_id'=>$product,'medicine_unit_id'=>$units['strip']['id'],'quantity'=>2]],'payments'=>[['method'=>'cash','amount'=>16]]];
$sale = (new SalesService())->createSale($input,1,$user,'premium');
check((float)$sale['grand_total'] === 16.0, 'Selling-unit catalog price determines the sale total');
$batches = array_column((new MedicineBatchService())->batches(1,$product),null,'id');
check((float)$batches[$batchB]['quantity'] === 0.0 && (float)$batches[$batchA]['quantity'] === 90.0, 'FEFO consumes the earliest-expiring batch across lots');
check((float)$db->table('stock_levels')->where('product_id',$product)->get()->getRow('quantity') === 90.0, 'Batch movements and aggregate ERP stock reconcile');
$db->table('pharmacy_medicines')->where('product_id',$product)->update(['prescription_required'=>1]);
$before = (int)$db->table('sales')->countAllResults();
rejects(fn () => (new SalesService())->createSale(array_replace($input,['uuid'=>Uuid::v4()]),1,$user,'premium'));
check((int)$db->table('sales')->countAllResults() === $before, 'Missing prescription rolls back the sale');
$patient = $service->savePatient(['name'=>'Fixture patient'],1,$user);
$rx = $service->recordPrescription(['patient_id'=>$patient,'prescriber'=>'Fixture prescriber','issued_on'=>date('Y-m-d'),'items'=>[['product_id'=>$product,'quantity'=>10,'directions'=>'Fixture directions only']]],1,$user);
$operations->approve(['prescription_id'=>$rx],1);
$rxSale = ['uuid'=>Uuid::v4(),'prescription_id'=>$rx,'items'=>[['product_id'=>$product,'medicine_unit_id'=>$units['tablet']['id'],'quantity'=>2]],'payments'=>[['method'=>'cash','amount'=>2]]];
$first = (new SalesService())->createSale($rxSale,1,$user,'premium');
check($service->prescription($rx,1)['status'] === 'partial', 'Partial dispensing preserves the unfilled prescription balance');
$duplicate = (new SalesService())->createSale($rxSale,1,$user,'premium');
check($duplicate['id'] === $first['id'] && $db->table('pharmacy_dispensings')->countAllResults() === 1, 'Checkout retry does not dispense twice');
$rxSale['uuid'] = Uuid::v4(); $rxSale['items'][0]['quantity'] = 9; $rxSale['payments'][0]['amount'] = 9;
rejects(fn () => (new SalesService())->createSale($rxSale,1,$user,'premium'));
$rxSale['uuid'] = Uuid::v4(); $rxSale['items'][0]['quantity'] = 8; $rxSale['payments'][0]['amount'] = 8;
(new SalesService())->createSale($rxSale,1,$user,'premium');
check($service->prescription($rx,1)['status'] === 'dispensed', 'Final fill completes the prescription');
$operations->changeStatus(['batch_id'=>$batchA,'status'=>'recalled','reason'=>'Fixture recall'],1,$user);
$db->table('pharmacy_medicines')->where('product_id',$product)->update(['prescription_required'=>0]);
rejects(fn () => (new SalesService())->createSale(array_replace($input,['uuid'=>Uuid::v4()]),1,$user,'premium'));
$operations->changeStatus(['batch_id'=>$batchA,'status'=>'active','reason'=>'Fixture release'],1,$user);
$db->table('product_batches')->where('id',$batchA)->update(['expiry_date'=>'2020-01-01']);
rejects(fn () => (new SalesService())->createSale(array_replace($input,['uuid'=>Uuid::v4()]),1,$user,'premium'));
$db->table('suppliers')->insert(['name'=>'Fixture supplier']);
$supplier = (int)$db->insertID();
$purchase = (new App\Services\Web\PurchaseOrderService())->save(['supplier_id'=>$supplier,'expected_received_at'=>date('Y-m-d H:i:s'),'items'=>[['product_id'=>$product,'medicine_unit_id'=>$units['strip']['id'],'quantity'=>2,'unit_cost'=>3]]],1,$user);
$purchaseLine = (int)$purchase['items'][0]['id'];
rejects(fn () => (new App\Services\PurchaseReceiptService())->createReceipt((int)$purchase['id'],['items'=>[['purchase_item_id'=>$purchaseLine,'quantity'=>1]]],1,$user,'premium'));
check($db->table('purchase_receipts')->where('purchase_id',$purchase['id'])->countAllResults() === 0, 'Missing receiving batch metadata rolls back the goods receipt');
$receipt = (new App\Services\PurchaseReceiptService())->createReceipt((int)$purchase['id'],['items'=>[['purchase_item_id'=>$purchaseLine,'quantity'=>2,'batch_no'=>'PO-LOT','expiry_date'=>date('Y-m-d',strtotime('+120 days'))]]],1,$user,'premium');
check((float)$db->table('stock_levels')->where('product_id',$product)->get()->getRow('quantity') === 100.0, 'Receiving two strips adds twenty base tablets to ERP inventory');
$cash = (int)$db->table('accounts')->where('code','1000')->get()->getRow('id');
$returns = new App\Services\DocumentReturnService();
$returns->create('purchase',(int)$purchase['id'],['uuid'=>Uuid::v4(),'reason'=>'Fixture supplier return','account_id'=>$cash,'items'=>[['id'=>$purchaseLine,'quantity'=>1]]],1,$user,'premium');
check((float)$db->table('stock_levels')->where('product_id',$product)->get()->getRow('quantity') === 90.0, 'Supplier return removes the original purchase batch in the original selling units');
$returns->create('sale',(int)$sale['id'],['uuid'=>Uuid::v4(),'reason'=>'Fixture customer return','account_id'=>$cash,'items'=>[['id'=>$sale['items'][0]['id'],'quantity'=>1]]],1,$user,'premium');
$quarantined = array_filter((new MedicineBatchService())->batches(1,$product),static fn($row)=>$row['status']==='quarantine' && (float)$row['quantity']===10.0);
check(count($quarantined) === 1, 'Customer medicine returns enter quarantine instead of sellable stock');
$returned = array_values($quarantined)[0];
rejects(fn () => $operations->changeStatus(['batch_id'=>$returned['id'],'status'=>'active','reason'=>'Fixture unsafe release'],1,$user));
$ledger = $db->table('journal_entry_lines')->select('SUM(debit) AS debit, SUM(credit) AS credit')->get()->getRowArray();
check(round((float)$ledger['debit']-(float)$ledger['credit'],2) === 0.0, 'Pharmacy stock and sales preserve double-entry accounting balance');
// Mixed units of one medicine share base stock and prescription limits.
$mixedProduct = $service->saveMedicine(array_replace($profile,['name'=>'Mixed-unit fixture tablets']));
$mixedUnits = array_column((new MedicineUnitService())->units($mixedProduct),null,'name');
$operations->stock(array_replace($stock,['product_id'=>$mixedProduct,'medicine_unit_id'=>$mixedUnits['box']['id'],'request_key'=>Uuid::v4(),'batch_no'=>'MIXED-LOT']),1,$user,'premium');
$remaining=100;
foreach ([5,2,4] as $loose) {
    $mixedInput=['uuid'=>Uuid::v4(),'items'=>[
        ['product_id'=>$mixedProduct,'medicine_unit_id'=>$mixedUnits['strip']['id'],'quantity'=>1],
        ['product_id'=>$mixedProduct,'medicine_unit_id'=>$mixedUnits['tablet']['id'],'quantity'=>$loose],
    ],'payments'=>[['method'=>'cash','amount'=>8+$loose]]];
    $mixedSale=(new SalesService())->createSale($mixedInput,1,$user,'premium');
    $remaining-=10+$loose;
    check((float)$mixedSale['grand_total']===(float)(8+$loose) && count($mixedSale['items'])===2,'One strip plus '.$loose.' individual tablets retains both unit prices on one invoice');
    check((float)$db->table('stock_levels')->where('product_id',$mixedProduct)->get()->getRow('quantity')===(float)$remaining,'Mixed-unit sale deducts '.(10+$loose).' base tablets');
}
(new SalesService())->createSale($mixedInput,1,$user,'premium');
check((float)$db->table('stock_levels')->where('product_id',$mixedProduct)->get()->getRow('quantity')===59.0,'Retrying a mixed-unit sale does not deduct stock twice');
$db->table('pharmacy_medicines')->where('product_id',$mixedProduct)->update(['prescription_required'=>1]);
$mixedRx=$service->recordPrescription(['patient_id'=>$patient,'prescriber'=>'Fixture prescriber','issued_on'=>date('Y-m-d'),'items'=>[['product_id'=>$mixedProduct,'quantity'=>14,'directions'=>'Fixture only']]],1,$user);
$operations->approve(['prescription_id'=>$mixedRx],1);
$mixedInput['uuid']=Uuid::v4();$mixedInput['prescription_id']=$mixedRx;$mixedInput['items'][1]['quantity']=5;$mixedInput['payments'][0]['amount']=13;
rejects(fn ()=>(new SalesService())->createSale($mixedInput,1,$user,'premium'));
check((float)$db->table('stock_levels')->where('product_id',$mixedProduct)->get()->getRow('quantity')===59.0,'Combined strip and loose quantities cannot exceed a prescription limit');
$mixedInput['uuid']=Uuid::v4();$mixedInput['items'][1]['quantity']=4;$mixedInput['payments'][0]['amount']=12;
(new SalesService())->createSale($mixedInput,1,$user,'premium');
check($service->prescription($mixedRx,1)['status']==='dispensed','One strip plus four tablets completes a fourteen-tablet prescription');
echo "{$checks} pharmacy integration checks passed in a disposable database.\n";
