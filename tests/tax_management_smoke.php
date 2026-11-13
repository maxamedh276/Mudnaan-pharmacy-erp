<?php
/** Tax validation/activation/rollback uses connection-local temporary tables only. */
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
define('FCPATH',dirname(__DIR__).DIRECTORY_SEPARATOR);define('ENVIRONMENT','development');
require FCPATH.'app/Config/Paths.php';$paths=new Config\Paths();require $paths->systemDirectory.'/Boot.php';CodeIgniter\Boot::bootConsole($paths);

use App\Libraries\AuthContext;
use App\Libraries\Uuid;
use App\Services\TaxRateService;

function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);echo "PASS: $message\n";}
$manager=new App\Libraries\TenantManager();$tenant=$manager->find(config(Config\Tenants::class)->default);$manager->connect($tenant);$db=db_connect();
session()->set('user',[]);$original=[];$shadowed=[];
try{
    foreach(['tax_rates','audit_log'] as $table){
        $original[$table]=hash('sha256',serialize($db->table($table)->get()->getResultArray()));
        $definition=array_values($db->query("SHOW CREATE TABLE `$table`")->getRowArray())[1];
        $definition=preg_replace('/^CREATE TABLE/','CREATE TEMPORARY TABLE',$definition);$definition=preg_replace('/^\s*CONSTRAINT .*\n/m','',$definition);$definition=preg_replace('/,\s*\)/',"\n)",$definition);
        $db->query($definition);$shadowed[]=$table;
    }
    $taxes=new TaxRateService();$uuid=Uuid::v4();$taxes->save(['uuid'=>$uuid,'name'=>'VAT','percentage'=>'5.25']);$first=$taxes->active();
    check((float)$first['percentage']===5.25&&count($taxes->list())===1,'First tax stores a percentage and becomes active.');
    $taxes->save(['uuid'=>Uuid::v4(),'name'=>'Sales tax','percentage'=>'10']);$second=$taxes->active();
    check($db->table('tax_rates')->where('is_active',1)->countAllResults()===1&&(int)$first['id']!==(int)$second['id'],'Adding another tax deactivates the previous rate.');
    $taxes->save(['uuid'=>$uuid,'name'=>'VAT','percentage'=>'5.25']);
    check(count($taxes->list())===2&&(int)$taxes->active()['id']===(int)$second['id'],'Identical request retry creates no duplicate and does not reactivate an older rate.');
    $snapshot=static fn()=>hash('sha256',serialize($taxes->list()));
    $valid=['uuid'=>Uuid::v4(),'name'=>'Valid','percentage'=>'5'];
    foreach([['percentage'=>-1],['percentage'=>101],['percentage'=>'5.255'],['percentage'=>'NaN'],['percentage'=>INF],['percentage'=>[]],['name'=>[]],['name'=>''],['uuid'=>'bad'],['name'=>'vat'],['uuid'=>$uuid,'name'=>'Changed']] as $invalid){
        $before=$snapshot();try{$taxes->save(array_replace($valid,$invalid));throw new RuntimeException('Invalid tax accepted.');}catch(InvalidArgumentException $e){check($snapshot()===$before,'Reject invalid/duplicate tax without changing the active rate.');}
    }
    $taxes->save(['action'=>'activate','id'=>$first['id']]);check((int)$taxes->active()['id']===(int)$first['id'],'Activating an existing tax replaces the current rate.');
    foreach([['action'=>'activate','id'=>999999],['action'=>'activate','id'=>[]],['action'=>'disable','id'=>$first['id']]] as $invalid){$before=$snapshot();try{$taxes->save($invalid);throw new RuntimeException('Invalid action accepted.');}catch(InvalidArgumentException $e){check($snapshot()===$before,'Invalid activation cannot leave the tenant without its active tax.');}}
    $taxes->save(['uuid'=>Uuid::v4(),'name'=>'Zero tax','percentage'=>'0']);check((float)$taxes->active()['percentage']===0.0,'A zero percent tax is a valid explicit tax rate.');
    $taxes->save(['uuid'=>Uuid::v4(),'name'=>'Boundary tax','percentage'=>'100']);check((float)$taxes->active()['percentage']===100.0,'The upper percentage boundary is accepted.');
    AuthContext::$user=(object)['id'=>999,'branch_id'=>1,'role_id'=>3,'role_name'=>'Cashier'];AuthContext::$permissions=[];
    foreach(['index','save'] as $method){try{(new App\Controllers\Web\TaxesWebController())->$method();throw new RuntimeException('Permission bypass');}catch(CodeIgniter\HTTP\Exceptions\HTTPException $e){check($e->getCode()===403,'Unauthorized Tax Management '.$method.' returns 403.');}}
    $super=$db->table('roles')->where('name','Superadmin')->get()->getRowArray();AuthContext::$user=(object)['id'=>999,'branch_id'=>1,'role_id'=>(int)$super['id'],'role_name'=>'Superadmin'];AuthContext::$tenant=$tenant;session()->set('user',(array)AuthContext::$user);
    $html=(new App\Controllers\Web\TaxesWebController())->index();
    preg_match('/<script type="application\/json" id="taxConfig">(.*?)<\/script>/s',$html,$configMatch);$config=json_decode($configMatch[1]??'{}',true);
    check(str_contains($html,'id="taxDialog"')&&str_contains($html,'name="percentage"')&&($config['saveUrl']??'')===site_url('finance/taxes/save'),'Tax Management renders its list and percentage modal.');
    $before=$snapshot();
    $db->query("ALTER TABLE tax_rates ADD CONSTRAINT qa_activation_fail CHECK (NOT(is_active=1 AND name='Fail activation'))");
    try{$taxes->save(['uuid'=>Uuid::v4(),'name'=>'Fail activation','percentage'=>'7']);throw new RuntimeException('Injected activation error did not occur.');}
    catch(CodeIgniter\Database\Exceptions\DatabaseException $e){check($snapshot()===$before,'An activation failure rolls back the new row and restores the previous active tax.');}
}finally{
    foreach(array_reverse($shadowed) as $table)$db->query("DROP TEMPORARY TABLE IF EXISTS `$table`");
    foreach($original as $table=>$hash)check(hash('sha256',serialize($db->table($table)->get()->getResultArray()))===$hash,"$table real records remain unchanged.");
    AuthContext::reset();
}
