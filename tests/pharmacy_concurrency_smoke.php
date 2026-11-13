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
if (($argv[1] ?? '') === '--worker') {
    if (!preg_match('/^pharmacy_test_[a-f0-9]{12}$/D', $argv[2] ?? '')) exit(2);
    config(Config\Database::class)->default['database'] = $argv[2];
    AuthContext::$user = (object)['id'=>1,'branch_id'=>1,'role_name'=>'Superadmin'];
    AuthContext::$tenant = ['code'=>'fixture','database'=>$argv[2],'name'=>'Fixture','plan'=>'premium'];
    AuthContext::$permissions = array_column(db_connect()->table('permissions')->get()->getResultArray(),'code');
    try {
        $sale = (new App\Services\SalesService())->createSale(['uuid'=>Uuid::v4(),'items'=>[['product_id'=>(int)$argv[3],'medicine_unit_id'=>(int)$argv[4],'quantity'=>4]],'payments'=>[['method'=>'cash','amount'=>4]]],1,1,'premium');
        echo json_encode(['success'=>true,'sale'=>$sale['id']]);
    } catch (InvalidArgumentException|RuntimeException $error) { echo json_encode(['success'=>false,'error'=>$error->getMessage()]); }
    exit;
}
$name = 'pharmacy_test_' . bin2hex(random_bytes(6));
$config = config(Config\Database::class)->default;
$admin = new mysqli($config['hostname'],$config['username'],$config['password'],'',$config['port']);
register_shutdown_function(static function () use ($admin,$name) { $admin->query('DROP DATABASE IF EXISTS `' . $name . '`'); });
(new App\Libraries\PharmacyInstaller())->install($name);
$db=db_connect();$role=$db->table('roles')->where('name','Superadmin')->get()->getRow('id');
$db->table('users')->insert(['branch_id'=>1,'role_id'=>$role,'name'=>'Fixture pharmacist','email'=>'fixture@example.test','password_hash'=>password_hash('Fixture-only',PASSWORD_DEFAULT)]);
AuthContext::$user=(object)['id'=>1,'branch_id'=>1,'role_name'=>'Superadmin'];AuthContext::$tenant=['code'=>'fixture','database'=>$name,'name'=>'Fixture','plan'=>'premium'];
AuthContext::$permissions=array_column($db->table('permissions')->get()->getResultArray(),'code');
$product=(new App\Services\Pharmacy\PharmacyService())->saveMedicine(['name'=>'Concurrency fixture','category_id'=>$db->table('categories')->get()->getRow('id'),'base_unit'=>'tablet','generic_name'=>'Fixture','strength'=>'100 mg','dosage_form'=>'Tablet','units'=>[['name'=>'tablet','factor'=>1,'sell_price'=>1]]]);
$unit=(new App\Services\Pharmacy\MedicineUnitService())->units($product)[0];
(new App\Services\Pharmacy\PharmacyOperationsService())->stock(['product_id'=>$product,'medicine_unit_id'=>$unit['id'],'quantity'=>5,'unit_cost'=>0.2,'batch_no'=>'CONCURRENT','expiry_date'=>date('Y-m-d',strtotime('+90 days')),'action'=>'receive','reason'=>'Fixture','request_key'=>Uuid::v4()],1,1,'premium');
$processes=[];
for($i=0;$i<2;$i++) {
    $pipes=[];$process=proc_open([PHP_BINARY,__FILE__,'--worker',$name,(string)$product,(string)$unit['id']],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,FCPATH,null,['bypass_shell'=>true,'create_no_window'=>true]);
    if (!is_resource($process)) throw new RuntimeException('Could not start concurrency worker.');
    fclose($pipes[0]);$processes[]=[$process,$pipes];
}
$results=[];
foreach($processes as [$process,$pipes]) { $output=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);if($exit!==0) throw new RuntimeException($error.$output);$results[]=json_decode($output,true,512,JSON_THROW_ON_ERROR); }
$success=count(array_filter($results,static fn($result)=>$result['success']));
if($success!==1 || (float)$db->table('stock_levels')->where('product_id',$product)->get()->getRow('quantity')!==1.0 || $db->table('sales')->countAllResults()!==1) throw new RuntimeException('Concurrent pharmacy checkout oversold or created inconsistent sale records.');
$batch=(new App\Services\Pharmacy\MedicineBatchService())->batches(1,$product)[0];
if((float)$batch['quantity']!==1.0) throw new RuntimeException('Concurrent batch and aggregate inventory disagree.');
echo "PASS Two simultaneous requests cannot oversell the same medicine batch.\nPASS Rejected checkout leaves no partial sale or accounting records.\nPASS Batch and aggregate balances remain equal after contention.\n";
