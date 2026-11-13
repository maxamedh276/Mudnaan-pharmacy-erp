<?php
if (PHP_SAPI !== 'cli-server') { http_response_code(404); exit; }
$root = dirname(__DIR__);
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/');
$file = realpath($root . $path);
if ($file && is_file($file) && preg_match('/\.(css|js|png|jpe?g|gif|svg|webp|ico|woff2?|ttf|map)$/i', $file)) {
    foreach (['assets', 'public/assets', 'uploads/products'] as $directory) {
        $allowed = realpath($root . '/' . $directory);
        if ($allowed && str_starts_with(strtolower($file), strtolower($allowed . DIRECTORY_SEPARATOR))) return false;
    }
}
if (preg_match('#^/(?:\.git|\.env|\.tools|\.branches|vendor|app|tests|tools|writable)(?:/|$)#i', $path)) { http_response_code(404); exit; }
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['PHP_SELF'] = '/index.php';
require $root . '/index.php';
