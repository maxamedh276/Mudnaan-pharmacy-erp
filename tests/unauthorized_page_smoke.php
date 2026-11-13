<?php
/** Run: C:/xampp/php/php.exe tests/unauthorized_page_smoke.php. No database connection or writes. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('FCPATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
define('ENVIRONMENT', 'testing');
require FCPATH . 'app/Config/Paths.php';
$paths = new Config\Paths(); require $paths->systemDirectory . '/Boot.php';
CodeIgniter\Boot::bootConsole($paths);

$config = new Config\Exceptions();
$error = (static fn () => new CodeIgniter\HTTP\Exceptions\HTTPException('SECRET INTERNAL PATH /settings/operational', 403))();
// CI's legacy HTTPException does not implement HTTPExceptionInterface, so the global
// exception pipeline supplies 500 here even though the exception's own code is 403.
$handler = $config->handler(500, $error);
$request = new CodeIgniter\HTTP\IncomingRequest(config(Config\App::class), new CodeIgniter\HTTP\URI('http://localhost/settings/operational'), null, new CodeIgniter\HTTP\UserAgent());
$request->setHeader('Accept', 'text/html');
$response = new CodeIgniter\HTTP\Response(config(Config\App::class));
// Show the same template even when development error details are enabled.
ini_set('display_errors', '1');
ob_start(); $handler->handle($error, $request, $response, 500, 1); $html = ob_get_clean();
if ($response->getStatusCode() !== 403 || !str_contains($html, 'Access denied') || !str_contains($html, 'Back to dashboard')) throw new RuntimeException('Custom 403 page was not selected.');
foreach (['SECRET INTERNAL PATH', 'Backtrace', 'HTTPException', 'APPPATH', 'password'] as $private) {
    if (str_contains($html, $private)) throw new RuntimeException('Permission page leaked internal details: ' . $private);
}
$document = new DOMDocument(); libxml_use_internal_errors(true); $document->loadHTML($html); libxml_clear_errors();
$link = $document->getElementsByTagName('a')->item(0);
if ($link->getAttribute('href') !== rtrim(config(Config\App::class)->baseURL, '/') . '/dashboard') throw new RuntimeException('Dashboard navigation is incorrect.');
$request->setHeader('Accept', 'application/json')->setHeader('X-Requested-With', 'XMLHttpRequest');
$response = new CodeIgniter\HTTP\Response(config(Config\App::class));
ob_start(); $handler->handle($error, $request, $response, 500, 1); $json = ob_get_clean();
if (!is_array(json_decode($json, true)) || str_contains($json, '<!doctype html>') || $response->getStatusCode() !== 403) throw new RuntimeException('AJAX exception must remain a JSON 403.');
if (in_array('--preview', $argv, true)) {
    $folder = FCPATH . 'results/unauthorized-page';
    if (!is_dir($folder)) mkdir($folder, 0775, true);
    file_put_contents($folder . '/preview.html', $html);
}
echo "PASS: custom browser 403, development mode, hidden exception details, dashboard navigation and unchanged JSON error format.\n";
