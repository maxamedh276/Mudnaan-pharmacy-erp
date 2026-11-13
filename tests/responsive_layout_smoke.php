<?php
/** Read-only page rendering checks. Run each page in its own process for layout helpers. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$pages = [
    'pos'=>['PosWebController','index',[]], 'dashboard'=>['DashboardController','index',[]],
    'dashboard-accountant'=>['DashboardController','index',[]], 'dashboard-cashier'=>['DashboardController','index',[]],
    'sales'=>['SalesWebController','index',[]], 'customers'=>['ClientsWebController','index',[]],
    'sales-returns'=>['ReturnsWebController','index',['sale']], 'purchase-returns'=>['ReturnsWebController','index',['purchase']],
    'deposits'=>['ClientsWebController','deposits',[]], 'suppliers'=>['SuppliersWebController','index',[]],
    'purchases'=>['SuppliersWebController','purchases',[]], 'receiving'=>['SuppliersWebController','receiving',[]],
    'products'=>['InventoryWebController','products',[]], 'categories'=>['InventoryWebController','categories',[]],
    'stock'=>['InventoryWebController','stock',[]], 'users'=>['UsersWebController','index',[]],
    'roles'=>['RolesWebController','index',[]], 'settings'=>['SettingsWebController','index',[]],
    'operational'=>['SettingsWebController','operational',[]], 'taxes'=>['TaxesWebController','index',[]],
];
foreach (['accounts','journals','pos-methods','periods','bills','payments','invoices','receipts'] as $section) $pages['finance-'.$section]=['FinanceWebController','index',[$section]];
foreach (['sales','inventory','customers','suppliers','income-statement','balance-sheet','cash-flow','trial-balance'] as $type) $pages['report-'.$type]=['ReportsWebController','index',[$type]];
if (!isset($argv[1])) {
    foreach ($pages as $name=>$unused) {
        $process=proc_open([PHP_BINARY,__FILE__,$name],[1=>['pipe','w'],2=>['pipe','w']],$pipes,dirname(__DIR__));
        if (!is_resource($process)) throw new RuntimeException('Could not start page check.');
        $output=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
        $exit=proc_close($process);echo $output;if ($exit!==0) { fwrite(STDERR,$error);exit($exit); }
    }
    exit;
}
$name=$argv[1];if (!isset($pages[$name])) throw new RuntimeException('Unknown test page.');
define('FCPATH',dirname(__DIR__).DIRECTORY_SEPARATOR);define('ENVIRONMENT','development');
require FCPATH.'app/Config/Paths.php';$paths=new Config\Paths();require $paths->systemDirectory.'/Boot.php';CodeIgniter\Boot::bootConsole($paths);
$manager=new App\Libraries\TenantManager();$tenant=$manager->find(config(Config\Tenants::class)->default);$manager->connect($tenant);
App\Libraries\AuthContext::$tenant=$tenant;App\Libraries\AuthContext::$user=(object)['id'=>1,'branch_id'=>1,'role_id'=>1,'role_name'=>'Superadmin','name'=>'Layout QA'];
if ($name==='dashboard-accountant') { App\Libraries\AuthContext::$user->role_name='Accountant'; App\Libraries\AuthContext::$permissions=['reports.basic','accounting.view','invoices.view','bills.view']; }
if ($name==='dashboard-cashier') { App\Libraries\AuthContext::$user->role_name='Cashier'; App\Libraries\AuthContext::$permissions=['reports.basic','sales.view']; }
session()->set('user',(array)App\Libraries\AuthContext::$user);session()->set('plan','premium');
$request=new CodeIgniter\HTTP\IncomingRequest(config(Config\App::class),new CodeIgniter\HTTP\SiteURI(config(Config\App::class),$name),null,new CodeIgniter\HTTP\UserAgent());
Config\Services::injectMock('request',$request);
[$class,$method,$args]=$pages[$name];$class='App\\Controllers\\Web\\'.$class;$controller=new $class();
$controller->initController($request,new CodeIgniter\HTTP\Response(config(Config\App::class)),service('logger'));
$html=$controller->$method(...$args);if (!is_string($html)) throw new RuntimeException('Expected an HTML page: '.$name);
$doc=new DOMDocument();libxml_use_internal_errors(true);$doc->loadHTML($html);libxml_clear_errors();$xpath=new DOMXPath($doc);
function requireLayout(bool $valid,string $message):void { if (!$valid) throw new RuntimeException($message); }
requireLayout(str_contains($xpath->evaluate('string(//meta[@name="viewport"]/@content)'),'width=device-width'),'Viewport missing: '.$name);
if ($name==='pos') {
    foreach (['posWorkspace','posCatalogPanel','posTicketPanel','mobilePosReview','posInputPaid','posPaymentMethod','payNowBtn'] as $id) requireLayout($xpath->query('//*[@id="'.$id.'"]')->length===1,'Duplicate or missing POS control: '.$id);
    requireLayout($xpath->query('//*[@id="posWorkspace"]/*')->length===4,'POS navigation, panels and summary must share the viewport grid.');
    requireLayout($xpath->query('//*[@id="posTicketPanel"]//*[contains(@class,"pos-ticket-scroll")]//*[@id="cartItemsContainer"]')->length===1,'Cart must be inside the reachable scrolling ticket.');
    requireLayout($xpath->query('//*[@id="posTicketPanel"]//*[contains(@class,"pos-ticket-scroll")]//*[@id="payNowBtn"]')->length===1,'Checkout must remain inside the same scrolling ticket.');
    requireLayout($xpath->query('//button[@data-pos-view-button]')->length===2,'Missing mobile view switcher.');
    requireLayout(str_contains($html,'pos-layout.js?v=')&&str_contains($html,'pos-layout.css?v='),'POS responsive assets must bypass stale caches.');
} else {
    requireLayout($xpath->query('//*[@id="app-main"]/*[contains(@class,"erp-page")]')->length===1,'Missing constrained page content: '.$name);
    requireLayout($xpath->query('//button[@id="mobile-menu-toggle"][@aria-controls="app-sidebar"]')->length===1,'Missing accessible mobile navigation: '.$name);
    requireLayout(str_contains($html,'responsive-layout.css?v=')&&str_contains($html,'responsive-layout.js?v='),'Missing shared responsive assets: '.$name);
}
echo 'PASS: '.$name.' renders viewport, responsive assets and reachable layout structure.'.PHP_EOL;
