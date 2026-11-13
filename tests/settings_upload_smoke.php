<?php
/** Logo validation and persistence with temporary settings/audit tables. */
declare(strict_types=1);
define('FCPATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
define('ENVIRONMENT','development');
require FCPATH.'app/Config/Paths.php';$paths=new Config\Paths();require $paths->systemDirectory.'/Boot.php';CodeIgniter\Boot::bootConsole($paths);
$manager=new App\Libraries\TenantManager();$tenant=$manager->find(config(Config\Tenants::class)->default);$manager->connect($tenant);$db=db_connect();
session();
$original=[];$shadowed=[];$files=[];$savedFiles=[];
class TestLogoUpload extends CodeIgniter\HTTP\Files\UploadedFile { public function isValid(): bool {return true;} }
function check(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);echo "PASS: $message\n";}
try {
    foreach(['settings','audit_log'] as $table){$original[$table]=$db->table($table)->countAllResults();$definition=array_values($db->query("SHOW CREATE TABLE `$table`")->getRowArray())[1];$definition=preg_replace('/^CREATE TABLE/','CREATE TEMPORARY TABLE',$definition);$definition=preg_replace('/^\s*CONSTRAINT .*\n/m','',$definition);$definition=preg_replace('/,\s*\)/',"\n)",$definition);$db->query($definition);$shadowed[]=$table;}
    $service=new App\Services\AppearanceSettingsService();
    $svg=tempnam(sys_get_temp_dir(),'qa-logo-');$files[]=$svg;file_put_contents($svg,'<svg xmlns="http://www.w3.org/2000/svg"><script>bad()</script></svg>');
    try{$service->uploadLogo(new TestLogoUpload($svg,'logo.png','image/png',filesize($svg),UPLOAD_ERR_OK));throw new RuntimeException('SVG accepted');}catch(InvalidArgumentException $e){check(str_contains($e->getMessage(),'Upload a PNG'),'Reject disguised SVG/active content.');}
    $png=tempnam(sys_get_temp_dir(),'qa-logo-');$files[]=$png;$image=imagecreatetruecolor(16,16);imagepng($image,$png);imagedestroy($image);file_put_contents($png,'<?php untrusted_payload();',FILE_APPEND);
    $relative=$service->uploadLogo(new TestLogoUpload($png,'../../malicious.php','image/png',filesize($png),UPLOAD_ERR_OK));$saved=FCPATH.$relative;$savedFiles[]=$saved;
    check((bool)preg_match('~^assets/uploads/branding/[a-f0-9]{32}\.png$~',$relative),'Generate a safe random logo path, ignoring the supplied filename.');
    check(is_file($saved)&&!str_contains(file_get_contents($saved),'untrusted_payload'),'Decode/re-encode image content instead of storing arbitrary uploaded bytes.');
    check((new App\Models\SettingModel())->getVal('system_logo')===$relative,'Logo setting points to the validated image.');
    $firstLogoUrl=App\Services\AppearanceSettingsService::logoUrl();
    check(str_contains($firstLogoUrl,$relative.'?v='),'The saved logo has a versioned URL for fresh navigation images.');
    $transparent=tempnam(sys_get_temp_dir(),'qa-logo-');$files[]=$transparent;$image=imagecreatetruecolor(16,16);imagealphablending($image,false);imagesavealpha($image,true);imagefill($image,0,0,imagecolorallocatealpha($image,255,0,0,127));imagepng($image,$transparent);imagedestroy($image);
    $second=$service->uploadLogo(new TestLogoUpload($transparent,'second.png','image/png',filesize($transparent),UPLOAD_ERR_OK));$savedFiles[]=FCPATH.$second;
    $secondLogoUrl=App\Services\AppearanceSettingsService::logoUrl();
    check($second!==$relative&&$secondLogoUrl!==$firstLogoUrl&&str_contains($secondLogoUrl,$second),'Replacing a logo persists a new image URL, bypassing cached previous logos.');
    $decoded=imagecreatefrompng(FCPATH.$second);check(((imagecolorat($decoded,0,0)>>24)&0x7f)===127,'Transparent logo pixels survive validation and PNG re-encoding.');imagedestroy($decoded);
    App\Libraries\AuthContext::$user=(object)['id'=>999,'role_id'=>1,'role_name'=>'Superadmin','name'=>'QA Admin','branch_id'=>1];App\Libraries\AuthContext::$tenant=$tenant;
    session()->set('user',(array)App\Libraries\AuthContext::$user);
    $request=new CodeIgniter\HTTP\IncomingRequest(config(Config\App::class),new CodeIgniter\HTTP\SiteURI(config(Config\App::class),'settings'),null,new CodeIgniter\HTTP\UserAgent());Config\Services::injectMock('request',$request);
    $html=(new App\Controllers\Web\SettingsWebController())->index();$doc=new DOMDocument();libxml_use_internal_errors(true);$doc->loadHTML($html);libxml_clear_errors();$xpath=new DOMXPath($doc);$logos=$xpath->query('//img[@data-workspace-logo]');
    check($logos->length===3,'Settings renders sidebar, mobile header and preview as synchronized logo targets.');
    foreach($logos as $logo)check($logo->getAttribute('src')===$secondLogoUrl,'Rendered workspace logo uses the latest saved upload.');
    check(str_contains($html,'id="qzSigningForm"')&&str_contains($html,'name="private_key"')&&str_contains($html,'qz-signing-settings.js?v='),'Settings renders the computer-specific certificate upload with versioned behavior.');
    check(str_contains($html,'workspace-logo.js?v=')&&str_contains($html,'appearance-settings.js?v='),'Logo and settings scripts are versioned so older cached handlers cannot break upload.');
    $fallback=App\Services\AppearanceSettingsService::logoUrl([]);
    foreach(['../../.env','https://invalid.test/logo.png','assets/uploads/branding/'.str_repeat('0',32).'.png',[]] as $badLogo)check(App\Services\AppearanceSettingsService::logoUrl(['system_logo'=>$badLogo])===$fallback,'Invalid or missing saved logo falls back to the local default.');
    $large=tempnam(sys_get_temp_dir(),'qa-logo-');$files[]=$large;file_put_contents($large,str_repeat('a',2*1024*1024+1));
    try{$service->uploadLogo(new TestLogoUpload($large,'big.png','image/png',filesize($large),UPLOAD_ERR_OK));throw new RuntimeException('Oversized file accepted');}catch(InvalidArgumentException $e){check(true,'Reject oversized logos.');}
    $printers=new App\Services\PrinterManagementService();
    $first=$printers->save(['label'=>'Front desk','type'=>'windows','name'=>'POS80']);
    $second=$printers->save(['label'=>'Kitchen','type'=>'network','ip'=>'192.168.1.50','port'=>9100]);
    check(count(array_filter($second,static fn($p)=>$p['active']))===1&&!$second[0]['active']&&$second[1]['active'],'Adding a second printer disables the previous printer.');
    $active=$printers->save(['action'=>'activate','id'=>$first[0]['id']]);
    check($active[0]['active']&&!$active[1]['active'],'Activating a saved printer leaves exactly one active device.');
    $thermal=new App\Services\ThermalPrintService(connectorFactory:static fn()=>new Mike42\Escpos\PrintConnectors\DummyPrintConnector());
    $slip=$thermal->printTestSlip();
    check($slip['success']&&str_contains(base64_decode($slip['data']),"\x1dV"),'The project Composer library generates receipt/cut bytes without Laragon or printer hardware.');
    $browser=new App\Services\ThermalPrintService(connectorFactory:static function(){throw new RuntimeException('Hardware connector must not run.');},browserPrint:true);
    $browserSlip=$browser->printTestSlip();
    check($browserSlip['success']&&str_contains(base64_decode($browserSlip['data']),'THERMAL PRINTER TEST'),'Browser jobs generate persisted configuration bytes without contacting a server printer.');
    $paper=base64_decode($browserSlip['data']);
    check(str_contains($paper,'https://mudnaantechnology.com')&&str_contains($paper,"Powered by\nMudnaan Technology"),'Thermal output includes the website QR payload and Mudnaan Technology attribution.');
    $connector=new Mike42\Escpos\PrintConnectors\DummyPrintConnector();$printer=new Mike42\Escpos\Printer($connector);
    $build=new ReflectionMethod(App\Services\ThermalPrintService::class,'buildReceipt');
    $build->invoke($browser,$printer,['id'=>1,'invoice_no'=>'QA-INVOICE','sold_at'=>'2026-11-05 12:00:00','subtotal'=>10,'discount'=>0,'grand_total'=>10,'paid_total'=>10,'items'=>[]],$browser->getConfig());
    $salePaper=$connector->getData();$printer->close();
    check(str_contains($salePaper,'https://mudnaantechnology.com')&&str_contains($salePaper,"Powered by\nMudnaan Technology"),'Sale receipt uses the same website QR and printed footer as the test slip.');
    preg_match_all('/\x1b!(.)|\x1d!(.)/s',$salePaper,$modes,PREG_SET_ORDER);
    foreach($modes as $mode){$value=ord($mode[2]??$mode[1]);check(in_array($value,[0,17],true),'Only the shop title uses proportional enlargement; receipt text remains normal-size.');}
    check(str_contains($salePaper,"\x1bE\x01GRAND TOTAL:"),'Grand total is emphasized at its normal font size.');
    $request->setHeader('X-Requested-With','XMLHttpRequest');
    $controller=new App\Controllers\Web\PrintingWebController();$controller->initController($request,new CodeIgniter\HTTP\Response(config(Config\App::class)),service('logger'));
    $job=json_decode($controller->test()->getBody(),true);
    check($job['success']&&$job['data']['printer']==='POS80'&&base64_decode($job['data']['bytes'],true)!==false,'Authenticated web test endpoint returns a printer target and base64 receipt bytes.');
    $eventRequest=new CodeIgniter\HTTP\IncomingRequest(config(Config\App::class),new CodeIgniter\HTTP\URI('http://localhost/web/printing/events'),json_encode(['attempt'=>$job['data']['attempt'],'stage'=>'connection','success'=>false,'code'=>'permission_denied']),new CodeIgniter\HTTP\UserAgent());
    $eventRequest->setHeader('X-Requested-With','XMLHttpRequest');
    $eventController=new App\Controllers\Web\PrintingWebController();$eventController->initController($eventRequest,new CodeIgniter\HTTP\Response(config(Config\App::class)),service('logger'));
    check(json_decode($eventController->event()->getBody(),true)['success'],'Browser failure diagnostics are accepted for a generated session-owned attempt.');
    check($eventController->event()->getStatusCode()===422,'Duplicate or unknown browser diagnostics cannot repeatedly fill the log.');
    $missing=json_decode($controller->sale(2147483647)->getBody(),true);
    check(!$missing['success'],'Missing or inaccessible sales cannot produce print jobs.');
    App\Libraries\AuthContext::$user=(object)['id'=>999,'role_id'=>999,'role_name'=>'QA restricted','branch_id'=>1];App\Libraries\AuthContext::$permissions=[];
    try{$controller->test();throw new RuntimeException('Unauthorized printing accepted.');}catch(CodeIgniter\HTTP\Exceptions\HTTPException $e){check($e->getCode()===403,'The print-test endpoint enforces Settings permission.');}
    App\Libraries\AuthContext::$user=(object)['id'=>999,'role_id'=>1,'role_name'=>'Superadmin','branch_id'=>1];
    $printers->save(['action'=>'disable','id'=>$first[0]['id']]);
    check(!$thermal->getConfig()['enabled']&&!$thermal->printTestSlip()['success'],'Disabling the active device blocks printing.');
    try{$printers->save(['label'=>'Bad','type'=>'network','ip'=>'invalid']);throw new RuntimeException('Invalid IP accepted');}catch(InvalidArgumentException $e){check(true,'Reject invalid printer connections.');}
    try{$printers->save(['label'=>'Front desk','type'=>'windows','name'=>'POS80']);throw new RuntimeException('Duplicate printer accepted');}catch(InvalidArgumentException $e){check(true,'Reject duplicate printer labels.');}
    try{$printers->save(['action'=>'activate','id'=>'missing']);throw new RuntimeException('Missing printer activated');}catch(InvalidArgumentException $e){check(true,'Reject unknown printer activation.');}
    $printers->save(['action'=>'activate','id'=>$first[0]['id']]);
    $remaining=$printers->save(['action'=>'delete','id'=>$second[1]['id']]);
    check(count($remaining)===1 && $remaining[0]['id']===$first[0]['id'] && $thermal->getConfig()['enabled'], 'Deleting a disabled printer keeps the selected printer active.');
    check($printers->save(['action'=>'delete','id'=>$first[0]['id']])===[] && !$thermal->getConfig()['enabled'], 'Deleting the active printer removes the profile and disables receipt printing.');
} finally {
    foreach($savedFiles as $saved)if(is_file($saved) && str_starts_with(str_replace('\\','/',realpath($saved)),str_replace('\\','/',realpath(FCPATH.'assets/uploads/branding')).'/'))unlink($saved);
    foreach($files as $file)if(is_file($file))unlink($file);
    foreach(array_reverse($shadowed) as $table)$db->query("DROP TEMPORARY TABLE IF EXISTS `$table`");
    foreach($original as $table=>$count)check($db->table($table)->countAllResults()===$count,"$table real records remain unchanged.");
    App\Libraries\AuthContext::reset();
}
