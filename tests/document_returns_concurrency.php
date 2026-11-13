<?php
/** Real competing PHP requests, confined to a randomly named disposable QA database. */
declare(strict_types=1);
define('FCPATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
define('ENVIRONMENT', 'development');
require FCPATH . 'app/Config/Paths.php'; $paths=new Config\Paths();
require $paths->systemDirectory.'/Boot.php'; CodeIgniter\Boot::bootConsole($paths);
use App\Libraries\TenantManager;
use App\Libraries\Uuid;
use App\Services\DocumentReturnService;
$manager=new TenantManager(); $tenant=$manager->find(config(Config\Tenants::class)->default); $manager->connect($tenant); $db=db_connect();
function verify(bool $condition,string $message):void { if(!$condition)throw new RuntimeException($message);echo "PASS: $message\n"; }
if (($argv[1]??'')==='--worker') {
    $name=$argv[2]??''; if(!preg_match('/^qa_returns_[a-f0-9]{12}$/D',$name))throw new RuntimeException('Invalid QA database.');
    $manager->connect(array_replace($tenant,['database'=>$name])); echo "READY\n";flush();
    try {
        [$mode,$uuid,$value]=array_slice($argv,3);
        $result=match($mode){
            'collect'=>(new App\Services\ReceivablesService())->recordReceipt(['uuid'=>$uuid,'invoice_id'=>1,'amount'=>(float)$value],1,1,'basic'),
            'pay'=>(new App\Services\PayablesService())->recordPayment(['uuid'=>$uuid,'bill_id'=>1,'amount'=>(float)$value],1,1,'basic'),
            default=>(new DocumentReturnService())->create($mode,1,['uuid'=>$uuid,'reason'=>'Concurrent QA','items'=>[['id'=>1,'quantity'=>(float)$value]]],1,1,'basic'),
        };
        echo json_encode(['ok'=>true,'id'=>$result['id']])."\n";
    }catch(Throwable $e){echo json_encode(['ok'=>false,'error'=>$e->getMessage()])."\n";}
    exit;
}
function race(string $database,array $a,array $b,string $gate='customers'):array {
    $db=db_connect();$db->transBegin();$db->query('SELECT id FROM '.$gate.' WHERE id=1 FOR UPDATE');$workers=[];
    try {
        foreach([$a,$b] as $args){$process=proc_open(array_merge([PHP_BINARY,__FILE__,'--worker',$database],$args),[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,FCPATH);if(!is_resource($process))throw new RuntimeException('Worker failed.');fclose($pipes[0]);$workers[]=[$process,$pipes];}
        foreach($workers as [,$pipes]){stream_set_timeout($pipes[1],15);if(trim((string)fgets($pipes[1]))!=='READY')throw new RuntimeException('Worker not ready.');}
    }finally{$db->transCommit();}
    $results=[];
    foreach($workers as [$process,$pipes]){$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);$result=json_decode(trim($out),true);if($exit!==0||!is_array($result))throw new RuntimeException($out.$err);$results[]=$result;}
    return $results;
}
$original=$db->getDatabase();$name='qa_returns_'.bin2hex(random_bytes(6));$created=false;
$tables=['customers','customer_invoices','customer_receipts','customer_receipt_allocations','customer_invoice_items','products','sales','sale_items','stock_levels','stock_movements','document_returns','document_return_items','financial_periods','audit_log','accounts','account_groups','account_types','pos_payment_methods','bank_accounts','journal_entries','journal_entry_lines','suppliers','purchases','purchase_items','purchase_receipts','purchase_receipt_items','supplier_bills','supplier_payments'];
try {
    $db->query("CREATE DATABASE `$name` CHARACTER SET utf8mb4");$created=true;
    foreach($tables as $table){$sql=array_values($db->query("SHOW CREATE TABLE `$original`.`$table`")->getRowArray())[1];$sql=preg_replace('/^CREATE TABLE `[^`]+`/',"CREATE TABLE `$name`.`$table`",$sql);$sql=preg_replace('/^\s*CONSTRAINT .*\n/m','',$sql);$sql=preg_replace('/,\s*\)/',"\n)",$sql);$db->query($sql);}
    $copies=[];foreach(['accounts','account_groups','account_types','pos_payment_methods'] as $table)$copies[$table]=$db->table($table)->get()->getResultArray();
    $manager->connect(array_replace($tenant,['database'=>$name]));$db->resetDataCache();foreach($copies as $table=>$rows)if($rows)$db->table($table)->insertBatch($rows);
    $db->table('products')->insert(['id'=>1,'name'=>'QA concurrent item','sku'=>'QA','sell_price'=>50,'cost_price'=>10,'is_active'=>1]);
    $db->table('customers')->insert(['id'=>1,'name'=>'QA customer','balance'=>-100]);
    $db->table('sales')->insert(['id'=>1,'uuid'=>Uuid::v4(),'branch_id'=>1,'user_id'=>1,'customer_id'=>1,'invoice_no'=>'QA-1','subtotal'=>100,'grand_total'=>100,'paid_total'=>0,'status'=>'partial']);
    $db->table('sale_items')->insert(['id'=>1,'sale_id'=>1,'product_id'=>1,'description'=>'QA','quantity'=>2,'unit_price'=>50,'line_total'=>100,'cost_total'=>20]);
    $db->table('customer_invoices')->insert(['id'=>1,'uuid'=>Uuid::v4(),'branch_id'=>1,'customer_id'=>1,'sale_id'=>1,'invoice_date'=>date('Y-m-d'),'total'=>100,'subtotal'=>100,'paid_total'=>0,'status'=>'unpaid']);
    $db->table('stock_levels')->insert(['branch_id'=>1,'product_id'=>1,'quantity'=>10]);
    $uuid=Uuid::v4();$results=race($name,['sale',$uuid,'1'],['sale',$uuid,'1']);
    if (count(array_filter($results,fn($r)=>$r['ok'])) !== 2) throw new RuntimeException(json_encode($results));
    verify($results[0]['ok']&&$results[1]['ok']&&$results[0]['id']===$results[1]['id']&&$db->table('document_returns')->countAllResults()===1,'Simultaneous identical submissions create one sales return.');
    $results=race($name,['sale',Uuid::v4(),'1'],['sale',Uuid::v4(),'1']);
    verify(count(array_filter($results,fn($r)=>$r['ok']))===1,'Competing sales returns cannot return the last item twice.');
    verify((float)$db->table('sales')->where('id',1)->get()->getRow('returned_total')===100.0&&(float)$db->table('stock_levels')->where('product_id',1)->get()->getRow('quantity')===12.0,'Concurrent returns preserve the stock cache and full credit value.');
    // Reset only disposable QA rows for a return-versus-collection race.
    $db->table('document_return_items')->emptyTable();$db->table('document_returns')->emptyTable();$db->table('stock_movements')->emptyTable();
    $db->table('customers')->where('id',1)->update(['balance'=>-100]);$db->table('sales')->where('id',1)->update(['returned_total'=>0,'paid_total'=>0,'status'=>'partial']);
    $db->table('customer_invoices')->where('id',1)->update(['total'=>100,'paid_total'=>0,'returned_total'=>0,'status'=>'unpaid']);
    $results=race($name,['sale',Uuid::v4(),'1'],['collect',Uuid::v4(),'75']);
    // If collection wins, the return needs a refund account and safely fails. If return wins,
    // the excessive collection fails. Either ordering must leave a consistent single operation.
    verify(count(array_filter($results,fn($r)=>$r['ok']))===1,'Return versus collection cannot use a stale invoice balance.');
    $invoice=$db->table('customer_invoices')->where('id',1)->get()->getRowArray();
    verify((float)$db->table('customers')->where('id',1)->get()->getRow('balance')===-round((float)$invoice['total']-(float)$invoice['paid_total'],2),'Customer balance agrees after competing collection and return.');
    $db->table('suppliers')->insert(['id'=>1,'name'=>'QA supplier']);
    $db->table('purchases')->insert(['id'=>1,'branch_id'=>1,'supplier_id'=>1,'user_id'=>1,'total'=>100,'status'=>'received']);
    $db->table('purchase_items')->insert(['id'=>1,'purchase_id'=>1,'product_id'=>1,'quantity'=>2,'quantity_received'=>2,'unit_cost'=>50,'line_total'=>100]);
    $db->table('purchase_receipts')->insert(['id'=>1,'uuid'=>Uuid::v4(),'branch_id'=>1,'purchase_id'=>1,'created_by'=>1]);
    $db->table('purchase_receipt_items')->insert(['receipt_id'=>1,'purchase_item_id'=>1,'product_id'=>1,'quantity'=>2,'unit_cost'=>50,'line_total'=>100]);
    $db->table('supplier_bills')->insert(['id'=>1,'uuid'=>Uuid::v4(),'branch_id'=>1,'supplier_id'=>1,'purchase_id'=>1,'bill_date'=>date('Y-m-d'),'total'=>100,'subtotal'=>100,'paid_total'=>0]);
    $uuid=Uuid::v4();$results=race($name,['purchase',$uuid,'1'],['purchase',$uuid,'1'],'purchases');
    verify($results[0]['ok']&&$results[1]['ok']&&$results[0]['id']===$results[1]['id'],'Simultaneous duplicate purchase returns post once.');
    $results=race($name,['purchase',Uuid::v4(),'1'],['pay',Uuid::v4(),'50'],'purchases');
    verify(count(array_filter($results,fn($r)=>$r['ok']))===1,'Supplier payment and return cannot settle or refund the same money twice.');
}finally{
    $manager->connect($tenant);
    if($created&&preg_match('/^qa_returns_[a-f0-9]{12}$/D',$name))$db->query("DROP DATABASE `$name`");
}
