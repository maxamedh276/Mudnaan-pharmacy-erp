<?php
/** One business name across settings, navigation, titles and receipts; isolated settings/audit writes. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('FCPATH',dirname(__DIR__).DIRECTORY_SEPARATOR);define('ENVIRONMENT','development');
require FCPATH.'app/Config/Paths.php';$paths=new Config\Paths();require $paths->systemDirectory.'/Boot.php';CodeIgniter\Boot::bootConsole($paths);

use App\Libraries\AuthContext;
use App\Models\SettingModel;
use App\Services\AppearanceSettingsService;
use App\Services\ReceiptBrandingService;

function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);echo "PASS: $message\n";}
function document(string $html):DOMXPath{$doc=new DOMDocument();libxml_use_internal_errors(true);$doc->loadHTML($html);libxml_clear_errors();return new DOMXPath($doc);}
$manager=new App\Libraries\TenantManager();$tenant=$manager->find(config(Config\Tenants::class)->default);$manager->connect($tenant);$db=db_connect();
session()->set('user',['id'=>999,'branch_id'=>1,'role_id'=>1,'name'=>'QA Admin','role_name'=>'Superadmin']);
AuthContext::$user=(object)session()->get('user');AuthContext::$tenant=$tenant;
$request=new CodeIgniter\HTTP\IncomingRequest(config(Config\App::class),new CodeIgniter\HTTP\SiteURI(config(Config\App::class),'settings'),null,new CodeIgniter\HTTP\UserAgent());
Config\Services::injectMock('request',$request);
$original=[];$shadowed=[];
try{
    foreach(['settings','audit_log'] as $table){
        $original[$table]=hash('sha256',serialize($db->table($table)->get()->getResultArray()));
        $definition=array_values($db->query("SHOW CREATE TABLE `$table`")->getRowArray())[1];
        $definition=preg_replace('/^CREATE TABLE/','CREATE TEMPORARY TABLE',$definition);$definition=preg_replace('/^\s*CONSTRAINT .*\n/m','',$definition);$definition=preg_replace('/,\s*\)/',"\n)",$definition);
        $db->query($definition);$shadowed[]=$table;
    }
    $model=new SettingModel();$model->setVal('system_name','Old editable system');$model->setVal('company_name','Legacy business');
    $branding=new ReceiptBrandingService();
    check($branding->get()['store_name']==='Legacy business'&&$branding->get()['system_name']==='www.mudnaantechnology.com','Legacy business names remain usable with the current fixed receipt attribution.');
    $name='QA <Store> & Co';$settings=new AppearanceSettingsService();
    $settings->save(['business_name'=>$name,'system_name'=>'Forged product name']);
    check($branding->get()['store_name']===$name&&$branding->get()['system_name']==='www.mudnaantechnology.com','The editable business name is separate from the fixed receipt attribution.');
    check($model->getVal('system_name')==='Old editable system','An old form cannot overwrite system identity through settings/save.');
    $before=$model->allAssoc();
    try{$settings->save(['business_name'=>'   ','brand_color'=>'#123456']);throw new RuntimeException('Empty business name accepted.');}
    catch(InvalidArgumentException $error){check($model->allAssoc()===$before,'An invalid business name leaves all settings unchanged.');}
    $html=(new App\Controllers\Web\SettingsWebController())->index();$doc=document($html);
    check($doc->query('//input[@name="business_name"]')->length===1&&$doc->query('//input[@name="system_name"]')->length===0,'Settings provides one Business name input and no System name input.');
    check($doc->evaluate('string(//title)')==='Settings — '.$name,'Page title uses the saved business name and escapes special characters safely.');
    check(trim($doc->evaluate('string(//aside[@id="app-sidebar"]//h1)'))===$name,'Sidebar name matches Business name.');
    check(trim($doc->evaluate('string(//header//span[contains(@class,"font-semibold")])'))===$name,'Mobile navigation uses the same business name.');
    $pos=(new App\Controllers\Web\PosWebController())->index();$posDoc=document($pos);
    check($posDoc->evaluate('string(//title)')===$name.' POS','POS page title uses Business name.');
    $headings=[];foreach($posDoc->query('//h3') as $heading)$headings[]=trim($heading->textContent);
    check(in_array($name,$headings,true)&&str_contains($pos,'Powered by www.mudnaantechnology.com'),'Receipt preview prints Business name with the current fixed attribution.');
    check((new App\Services\ThermalPrintService())->getConfig()['store_name']===$name,'ESC/POS uses the same business name.');
    $auth=new App\Controllers\Web\AuthController();$auth->initController($request,new CodeIgniter\HTTP\Response(config(Config\App::class)),service('logger'));
    $login=$auth->login();$loginDoc=document($login);
    check($loginDoc->evaluate('string(//title)')==='Sign In — '.$name,'Sign-in page title uses Business name.');
    foreach([$doc,$posDoc,$loginDoc] as $page) {
        check($page->query('//*[@id="toast-container"]')->length===1,'Main layout, standalone POS and login each use one shared notification stack.');
        check($page->query('//footer[@data-app-copyright]')->length===1 && str_contains($page->evaluate('string(//footer[@data-app-copyright])'),'Mudnaan Technology. All rights reserved.'), 'Each page shell renders exactly one shared copyright footer.');
    }
    if (in_array('--footer-preview', $argv, true)) {
        $folder=FCPATH.'results/copyright-preview'; if(!is_dir($folder))mkdir($folder,0775,true);
        file_put_contents($folder.'/pos.html',$pos);
        file_put_contents($folder.'/login.html',$login);
    }
    check($loginDoc->query('//button[@type="button"][@data-toast-type="info"]')->length===1&&!str_contains($login,'onclick="alert('),'Forgot Password uses a non-submitting info toast without a native browser alert.');
    $flashMessage='Wrong credentials </script><img src=x onerror=bad()>';
    $flash=view('auth/login',['title'=>'Login','businessName'=>$name,'error'=>$flashMessage,'success'=>'Signed out.','identity'=>'']);$flashDoc=document($flash);
    $flashData=json_decode($flashDoc->evaluate('string(//script[@id="feedbackMessages"])'),true,512,JSON_THROW_ON_ERROR);
    check($flashData[0]['message']===$flashMessage&&$flashData[0]['type']==='error'&&$flashData[1]['type']==='success','Login errors and sign-out messages reach the shared toaster without changing their text.');
    check($flashDoc->query('//img[@src="x"]')->length===0,'Login feedback safely escapes message HTML and script-closing text.');
}finally{
    foreach(array_reverse($shadowed) as $table)$db->query("DROP TEMPORARY TABLE IF EXISTS `$table`");
    foreach($original as $table=>$hash)check(hash('sha256',serialize($db->table($table)->get()->getResultArray()))===$hash,"$table real records remain unchanged.");
    AuthContext::reset();
}
