<?php
/** Read-only dashboard checks over connection-local financial fixtures. Real records are fingerprinted. */
declare(strict_types=1);
define('FCPATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
define('ENVIRONMENT', 'development');
require FCPATH . 'app/Config/Paths.php';$paths=new Config\Paths();require $paths->systemDirectory.'/Boot.php';CodeIgniter\Boot::bootConsole($paths);
use App\Libraries\AuthContext;
use App\Libraries\TenantManager;
use App\Libraries\Uuid;
use App\Services\RoleDashboardService;
use App\Services\SalesService;
use App\Services\ReceivablesService;
use App\Services\DocumentReturnService;
$checks=0;
function verify(bool $ok,string $message):void {global $checks;if(!$ok)throw new RuntimeException($message);$checks++;echo "PASS: $message\n";}
function actor(int $id,string $role,int $roleId=1,int $branch=1,array $permissions=[]):void {
    AuthContext::$user=(object)['id'=>$id,'branch_id'=>$branch,'role_id'=>$roleId,'role_name'=>$role,'name'=>'QA '.$role];AuthContext::$permissions=$permissions;session()->set('user',(array)AuthContext::$user);
}
function controller(array $query=[]):App\Controllers\Web\DashboardController {
    $request=new CodeIgniter\HTTP\IncomingRequest(config(Config\App::class),new CodeIgniter\HTTP\SiteURI(config(Config\App::class),'dashboard'),null,new CodeIgniter\HTTP\UserAgent());$request->setGlobal('get',$query);
    $controller=new App\Controllers\Web\DashboardController();$controller->initController($request,new CodeIgniter\HTTP\Response(config(Config\App::class)),service('logger'));return $controller;
}
function roleController(array $body):App\Controllers\Web\RolesWebController {
    $request=new CodeIgniter\HTTP\IncomingRequest(config(Config\App::class),new CodeIgniter\HTTP\URI('http://localhost/users/roles/create'),json_encode($body),new CodeIgniter\HTTP\UserAgent());$request->setHeader('Content-Type','application/json');
    $controller=new App\Controllers\Web\RolesWebController();$controller->initController($request,new CodeIgniter\HTTP\Response(config(Config\App::class)),service('logger'));return $controller;
}
$manager=new TenantManager();$tenant=$manager->find(config(Config\Tenants::class)->default);$manager->connect($tenant);AuthContext::$tenant=array_replace($tenant,['plan'=>'premium']);$db=db_connect();
$tables=['users','roles','permissions','role_permissions','role_menu_permissions','user_menu_permissions','customers','products','sales','sale_items','payments','stock_levels','stock_movements','customer_invoices','customer_invoice_items','customer_receipts','customer_receipt_allocations','customer_deposits','journal_entries','journal_entry_lines','financial_periods','audit_log','accounts','pos_payment_methods','bank_accounts','purchases','purchase_items','purchase_receipts','purchase_receipt_items','suppliers','supplier_bills','supplier_bill_items','supplier_payments','settings','tax_rates','document_returns','document_return_items'];
$original=$shadowed=[];
try {
    foreach($tables as $table){$original[$table]=hash('sha256',serialize($db->table($table)->get()->getResultArray()));$copy=in_array($table,['accounts','pos_payment_methods','permissions'])?$db->table($table)->get()->getResultArray():[];$sql=array_values($db->query("SHOW CREATE TABLE `$table`")->getRowArray())[1];$sql=preg_replace('/^CREATE TABLE/','CREATE TEMPORARY TABLE',$sql);$sql=preg_replace('/^\s*CONSTRAINT .*\n/m','',$sql);$sql=preg_replace('/,\s*\)/',"\n)",$sql);$db->query($sql);$shadowed[]=$table;if($copy)$db->table($table)->insertBatch($copy);}
    $db->resetDataCache();
    foreach(['Superadmin','Cashier','Accountant','Restricted','Manager'] as $index=>$name)$db->table('roles')->insert(['id'=>$index+1,'name'=>$name]);
    foreach([[1,1,1,'Admin'],[2,2,1,'Cashier A'],[3,2,1,'PRIVATE CASHIER B'],[4,3,1,'Accountant'],[5,2,2,'PRIVATE BRANCH TWO']] as [$id,$role,$branch,$name])$db->table('users')->insert(['id'=>$id,'role_id'=>$role,'branch_id'=>$branch,'name'=>$name,'password_hash'=>'unused-isolated-fixture']);
    $db->table('products')->insert(['id'=>1,'sku'=>'DASH-QA','name'=>'QA <coffee>','cost_price'=>10,'sell_price'=>100,'is_active'=>1,'unit'=>'pcs','reorder_level'=>500]);
    $account=(int)$db->table('accounts')->where('code','1000')->get()->getRow('id');$bank=(int)$db->table('accounts')->where('code','1010')->get()->getRow('id');
    $accounting=new App\Services\AccountingService();$inventory=new App\Services\InventoryService();
    foreach([1,2] as $branch){$inventory->recordMovement($branch,1,100,'opening','opening',1,1);$accounting->postOpeningStock($branch,1000,1,1);}
    $today=(new DateTimeImmutable('today',new DateTimeZone('Africa/Nairobi')))->format('Y-m-d');$yesterday=date('Y-m-d',strtotime($today.' -1 day'));
    $db->table('customers')->insert(['id'=>1,'name'=>'QA Client A','balance'=>0,'credit_limit'=>100000]);$db->table('customers')->insert(['id'=>2,'name'=>'QA Prior Client','balance'=>0,'credit_limit'=>100000]);
    $sales=new SalesService();$receivables=new ReceivablesService();$returns=new DocumentReturnService();
    actor(1,'Superadmin');
    $a=$sales->createSale(['customer_id'=>1,'sold_at'=>$today.' 10:00:00','items'=>[['product_id'=>1,'quantity'=>1]],'payments'=>[['method'=>'cash','amount'=>40]]],1,2,'premium');
    $walk=$sales->createSale(['sold_at'=>$today.' 11:00:00','items'=>[['product_id'=>1,'quantity'=>1,'unit_price'=>50]],'payments'=>[['method'=>'cash','amount'=>50]]],1,2,'premium');
    $b=$sales->createSale(['sold_at'=>$today.' 12:00:00','items'=>[['product_id'=>1,'quantity'=>1,'unit_price'=>700]],'payments'=>[['method'=>'cash','amount'=>700]]],1,3,'premium');
    $other=$sales->createSale(['sold_at'=>$today.' 12:00:00','items'=>[['product_id'=>1,'quantity'=>1,'unit_price'=>9000]],'payments'=>[['method'=>'cash','amount'=>9000]]],2,5,'premium');
    $prior=$sales->createSale(['customer_id'=>2,'sold_at'=>$yesterday.' 09:00:00','items'=>[['product_id'=>1,'quantity'=>2,'unit_price'=>40]],'payments'=>[]],1,2,'premium');
    $invoice=(int)$db->table('customer_invoices')->where('sale_id',$a['id'])->get()->getRow('id');
    $receivables->recordReceipt(['uuid'=>Uuid::v4(),'invoice_id'=>$invoice,'amount'=>20],1,3,'premium');
    $receivables->recordReceipt(['uuid'=>Uuid::v4(),'invoice_id'=>$invoice,'amount'=>10],1,2,'premium');
    $r=$returns->create('sale',$a['id'],['uuid'=>Uuid::v4(),'reason'=>'Dashboard refund QA','account_id'=>$account,'items'=>[['id'=>$a['items'][0]['id'],'quantity'=>.5]]],1,2,'premium');
    $returns->create('sale',$prior['id'],['uuid'=>Uuid::v4(),'reason'=>'Earlier order return','items'=>[['id'=>$prior['items'][0]['id'],'quantity'=>1]]],1,3,'premium');
    // Fixtures must fall in the selected business day even when database and PHP timezones differ.
    $db->table('customer_receipts')->update(['received_at'=>$today.' 13:00:00']);$db->table('document_returns')->update(['returned_at'=>$today.' 14:00:00']);
    $db->table('customer_invoices')->where('sale_id',$prior['id'])->update(['due_date'=>$yesterday]);
    $db->table('suppliers')->insert(['id'=>1,'name'=>'QA Vendor']);
    $po=(new App\Services\PurchaseService())->createPurchase(['supplier_id'=>1,'purchased_at'=>$today.' 08:00:00','items'=>[['product_id'=>1,'quantity'=>10,'unit_cost'=>10]]],1,1);
    (new App\Services\PurchaseReceiptService())->createReceipt($po['id'],['received_at'=>$today.' 08:00:00','items'=>[['purchase_item_id'=>$po['items'][0]['id'],'quantity'=>10]]],1,1,'premium');
    $bill=(int)$db->table('supplier_bills')->where('purchase_id',$po['id'])->get()->getRow('id');
    (new App\Services\PayablesService())->recordPayment(['bill_id'=>$bill,'amount'=>25],1,1,'premium');
    $returns->create('purchase',$po['id'],['uuid'=>Uuid::v4(),'reason'=>'Supplier credit QA','items'=>[['id'=>$po['items'][0]['id'],'quantity'=>2.5]]],1,1,'premium');
    $db->table('document_returns')->update(['returned_at'=>$today.' 14:00:00']);$db->table('supplier_bills')->where('id',$bill)->update(['due_date'=>$yesterday]);
    $accounting->createEntry(1,$today,'Internal transfer','manual',null,[['account_id'=>$bank,'debit'=>100],['account_id'=>$account,'credit'=>100]],1);
    $db->table('financial_periods')->insert(['name'=>'QA open period','start_date'=>date('Y-m-01',strtotime($today)),'end_date'=>date('Y-m-t',strtotime($today)),'status'=>'open']);
    $presetService=new App\Services\Web\RolePresetService();
    $presetResponse=roleController(['name'=>'Read-only Accountant QA','preset'=>'accountant_read'])->create();verify($presetResponse->getStatusCode()===200,'Authorized role creator can explicitly select the Accountant preset.');
    $presetRole=json_decode($presetResponse->getBody(),true)['data'];
    $expectedPreset=App\Services\Web\RolePresetService::ACCOUNTANT_READ;$actualPreset=$presetRole['permissions'];sort($expectedPreset);sort($actualPreset);
    verify($expectedPreset===$actualPreset,'Explicit Accountant preset assigns exactly the six read-only finance/reporting permissions.');
    verify(!array_intersect($actualPreset,['users.manage','settings.manage','settings.operational','accounting.manage','bills.manage','invoices.manage','sales.create']),'Accountant preset grants no administration or transaction-writing permissions.');
    $custom=$presetService->create(['name'=>'Custom unconfigured QA']);verify($custom['permissions']===[],'Custom role creation never silently assigns finance permissions.');
    try{$presetService->create(['name'=>'Bad QA','preset'=>'admin']);throw new RuntimeException('Preset bypass');}catch(InvalidArgumentException){verify(true,'Unknown or privileged role presets are rejected.');}
    actor(2,'Cashier',2,1,['reports.basic','sales.view']);try{roleController(['name'=>'Unauthorized QA','preset'=>'accountant_read'])->create();throw new RuntimeException('Role creation bypass');}catch(CodeIgniter\HTTP\Exceptions\HTTPException $e){verify($e->getCode()===403,'Accountant preset endpoint rejects users without role-management permission.');}actor(1,'Superadmin');
    $fingerprint=static fn()=>hash('sha256',serialize(array_map(static fn($t)=>$db->table($t)->get()->getResultArray(),$tables)));
    $before=$fingerprint();$service=new RoleDashboardService();
    $admin=$service->get('today');
    verify($admin['profile']==='admin'&&$admin['sales']['revenue']===800.0&&$admin['sales']['order_count']===3,'Admin sees branch-wide net sales and all three orders, excluding another branch.');
    verify($admin['sales']['collected']===800.0,'Admin collections cap tenders, include receipts once and subtract recorded refunds.');
    verify($admin['finance']['receivables']===40.0&&$admin['finance']['payables']===50.0,'Receivables and payables reflect partial payments and returns.');
    verify($admin['finance']['difference']===0.0&&$admin['finance']['ar_ledger']===40.0&&$admin['finance']['ap_ledger']===50.0,'Trial balance and receivable/payable reconciliation agree with the general ledger.');
    verify($admin['finance']['cash']===775.0&&$admin['finance']['movement']===775.0&&$admin['finance']['opening']===0.0,'Cash movement excludes internal transfers and agrees with closing cash.');
    $report=(new App\Services\WebReportService())->build('income-statement',1,['date_from'=>$today,'date_to'=>$today]);
    verify($admin['finance']['income']===$report['summary']['Income']&&$admin['finance']['profit']===$report['summary']['Net profit'],'Dashboard income and net profit match the Income Statement.');
    actor(2,'Cashier',2,1,['reports.basic','sales.view','sales.create','accounting.view','reports.advanced']);
    $cashier=$service->get('today');
    verify($cashier['profile']==='cashier'&&$cashier['sales']['revenue']===100.0&&$cashier['sales']['order_count']===2,'Cashier sees only their own sales even when extra report permissions are present.');
    verify($cashier['sales']['collected']===80.0&&$cashier['sales']['refunds']===20.0,'Cashier collection/refund totals are scoped to the operator who recorded them.');
    verify($cashier['sales']['outstanding']===40.0,'Cashier outstanding balance includes older unpaid own orders.');
    verify($cashier['finance']===null&&$cashier['stock']===[]&&!str_contains(json_encode($cashier),'PRIVATE CASHIER B')&&!str_contains(json_encode($cashier),'PRIVATE BRANCH TWO'),'Cashier payload contains no finance, stock or other-cashier/branch data.');
    $html=view('web/dashboard/panels',['dashboard'=>$cashier]);
    verify(str_contains($html,'My recent orders')&&!str_contains($html,'Net profit')&&!str_contains($html,'Supplier bills due'),'Cashier HTML omits financial and supplier sections.');
    verify(str_contains($html,'QA &lt;coffee&gt;')&&!str_contains($html,'QA <coffee>'),'Product names are safely escaped in dashboard tables.');
    verify($service->get()['range']==='today','Cashier reporting defaults to today.');
    $response=controller(['range'=>'today','profile'=>'admin','user_id'=>3,'branch_id'=>2])->data();
    verify($response->getStatusCode()===200&&!str_contains($response->getBody(),'PRIVATE CASHIER B')&&json_decode($response->getBody(),true)['data']['profile']==='cashier','Tampered refresh URLs cannot change profile, branch or cashier.');
    verify(controller()->order((int)$b['id'])->getStatusCode()===404&&controller()->order((int)$other['id'])->getStatusCode()===404,'Cashier cannot preview another cashier or branch order by changing the URL.');
    $detail=json_decode(controller()->order((int)$a['id'])->getBody(),true)['data'];
    verify((float)$detail['net_total']===50.0&&(float)$detail['refunds']===20.0&&!str_contains(json_encode($detail),'cost_'),'Authorized order preview includes returns and no cost information.');
    verify(controller(['range'=>['bad']])->data()->getStatusCode()===422,'Array filters return a recoverable validation error.');
    actor(4,'Accountant',3,1,['reports.basic','accounting.view','invoices.view','bills.view']);$accountant=$service->get('today');
    verify($accountant['profile']==='accountant'&&$accountant['sales']===null&&$accountant['finance']['cash']===775.0,'Accountant receives financial data without POS or staff activity.');
    verify($service->get()['range']==='month','Accountant reporting defaults to this month.');
    $html=view('web/dashboard/panels',['dashboard'=>$accountant]);verify(str_contains($html,'Reconciliation')&&str_contains($html,'Customer invoices due')&&!str_contains($html,'My recent orders'),'Accountant layout contains financial charts, due documents and reconciliation.');
    actor(4,'Accountant',3,1,['reports.basic','invoices.view']);$limited=$service->get('today');
    verify(!isset($limited['finance']['cash'])&&$limited['finance']['bills']===[]&&!str_contains(view('web/dashboard/panels',['dashboard'=>$limited]),'Net profit'),'Restricted accountant access hides unavailable ledger and supplier information.');
    actor(4,'Accountant',3,1,['reports.basic','accounting.view','invoices.view','bills.view']);AuthContext::$tenant['plan']='basic';$basic=$service->get('today');verify(!$basic['access']['ledger']&&!isset($basic['finance']['cash']),'Basic plan cannot access Premium ledger figures through the dashboard.');AuthContext::$tenant['plan']='premium';
    actor(4,'Accountant',3,99,['reports.basic','accounting.view','invoices.view','bills.view']);$empty=$service->get('today');$emptyHtml=view('web/dashboard/panels',['dashboard'=>$empty]);verify(!$empty['finance']['has_entries']&&str_contains($emptyHtml,'No entries')&&!str_contains($emptyHtml,'>Matched<'),'Empty accounting data is labelled clearly rather than falsely reconciled.');
    actor(1,'Superadmin');$history=$service->get('yesterday');verify($history['finance']['receivables']===80.0&&$history['sales']['revenue']===80.0,'Yesterday excludes later returns and payments from its closing balances.');
    foreach(['bad','2000days',[]] as $range){try{$service->get($range);throw new RuntimeException('Invalid dashboard period accepted.');}catch(InvalidArgumentException){verify(true,'Invalid period rejected.');}}
    actor(4,'Accountant',3,1,[]);$unconfigured=$service->get();verify($unconfigured['profile']==='accountant'&&$unconfigured['finance']===null&&$unconfigured['sales']===null,'New Accountant without action permissions gets an empty dashboard shell, never financial data.');
    $emptyResponse=controller()->data();verify($emptyResponse->getStatusCode()===200&&str_contains($emptyResponse->getBody(),'no dashboard data permissions'),'Allowed dashboard menu shows setup guidance instead of a post-login 403.');
    AuthContext::$user=null;try{controller()->data();throw new RuntimeException('Anonymous access');}catch(CodeIgniter\HTTP\Exceptions\HTTPException $e){verify($e->getCode()===403,'Anonymous dashboard access remains denied.');}
    actor(2,'Cashier',2,1,['reports.basic','sales.view']);$db->table('user_menu_permissions')->insert(['user_id'=>2,'menu_key'=>'dashboard','is_allowed'=>0]);try{controller()->data();throw new RuntimeException('Menu bypass');}catch(CodeIgniter\HTTP\Exceptions\HTTPException $e){verify($e->getCode()===403,'Direct data URL honors a denied Dashboard menu.');}$db->table('user_menu_permissions')->where('user_id',2)->delete();
    actor(1,'Superadmin');verify($fingerprint()===$before,'All dashboard reads and order previews leave financial fixtures unchanged.');
    $page=controller(['range'=>'today'])->index();$dom=new DOMDocument();libxml_use_internal_errors(true);$dom->loadHTML($page);libxml_clear_errors();$xpath=new DOMXPath($dom);
    verify($xpath->query('//*[@id="app-main"][contains(@class,"sm:p-4")]')->length===1&&$xpath->query('//*[@id="app-main"]/*[contains(@class,"max-w-7xl")]')->length===0,'Dashboard uses compact padding and full content width.');
    verify(!str_contains($page,'Quick actions')&&str_contains($page,'dashboard.js?v=')&&str_contains($page,'dashboard.css?v='),'Dashboard includes versioned assets and no quick actions.');
    echo "Verified $checks dashboard checks.\n";
}finally{
    foreach(array_reverse($shadowed) as $table)$db->query("DROP TEMPORARY TABLE IF EXISTS `$table`");
    foreach($original as $table=>$hash)verify(hash('sha256',serialize($db->table($table)->get()->getResultArray()))===$hash,"Real $table records unchanged.");
    AuthContext::reset();
}
