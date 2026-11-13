<?php

/** Web logout regression: no financial or user-table writes. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ob_start();
define('FCPATH',dirname(__DIR__).DIRECTORY_SEPARATOR);
define('ENVIRONMENT','development');
require FCPATH.'app/Config/Paths.php';$paths=new Config\Paths();
require $paths->systemDirectory.'/Boot.php';CodeIgniter\Boot::bootConsole($paths);

function check(bool $condition,string $message): void
{
    if(!$condition)throw new RuntimeException($message);
    echo "PASS: $message\n";
}
$session=session();
// CI deliberately skips native session startup in development CLI; use a fresh test-only session.
if (session_status() !== PHP_SESSION_ACTIVE) { session_name('qa_web_logout'); session_start(); }
$session->set(['token'=>'qa-token','user'=>['id'=>999,'role_name'=>'Superadmin'],'tenant'=>['code'=>'qa'],'permissions'=>['settings.manage'],'plan'=>'premium','redirect_url'=>'/users','_ci_old_input'=>['qa'=>'data']]);
$oldId=session_id();
App\Libraries\AuthContext::$user=(object)['id'=>999,'role_name'=>'Superadmin'];
App\Libraries\AuthContext::$permissions=['settings.manage'];
$request=new CodeIgniter\HTTP\IncomingRequest(config(Config\App::class),new CodeIgniter\HTTP\SiteURI(config(Config\App::class),'logout'),null,new CodeIgniter\HTTP\UserAgent());
Config\Services::injectMock('request', $request);
$controller=new App\Controllers\Web\AuthController();
$controller->initController($request,new CodeIgniter\HTTP\Response(config(Config\App::class)),service('logger'));
$response=$controller->logout();
check($response->getHeaderLine('Location')===site_url('login'),'Logout redirects to the login page.');
$cookie=$response->getCookie('erp_token');
check($cookie!==null&&$cookie->getValue()===''&&$cookie->isExpired()&&$cookie->getPath()==='/','The returned redirect carries the expired login cookie at the original path.');
foreach(['token','user','tenant','permissions','plan','redirect_url','_ci_old_input'] as $key)check($session->get($key)===null,"Logout removes $key from the session.");
check(session_id()!==$oldId&&session_status()===PHP_SESSION_ACTIVE,'The authenticated session ID is destroyed and replaced by an anonymous session.');
check(App\Libraries\AuthContext::$user===null&&App\Libraries\AuthContext::$permissions===[],'Logout clears the request authentication context.');
check($session->getFlashdata('success')==='You have been signed out.','Anonymous session retains the sign-out confirmation.');
$protectedRequest=new CodeIgniter\HTTP\IncomingRequest(config(Config\App::class),new CodeIgniter\HTTP\SiteURI(config(Config\App::class),'users'),null,new CodeIgniter\HTTP\UserAgent());
$filter=new App\Filters\WebAuthFilter();$denied=$filter->before($protectedRequest);
check($denied?->getHeaderLine('Location')===site_url('login'),'A protected-page request after logout returns to login.');
$second=$controller->logout();
check($second->getHeaderLine('Location')===site_url('login')&&$session->get('token')===null,'Repeated logout remains safe and signed out.');
$filter->after($protectedRequest,$second);
check(str_contains($second->getHeaderLine('Cache-Control'),'no-store'),'Protected responses cannot be reused from the HTTP cache.');
$login=$controller->login();
check(is_string($login)&&str_contains($login,'data-authenticated="false"'),'Signed-out login page includes browser credential cleanup.');
$session->destroy();
ob_end_flush();
