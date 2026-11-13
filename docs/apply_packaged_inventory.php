<?php
/** Apply only this feature migration, leaving unrelated legacy migration registrations untouched.
 * Run: C:\\xampp\\php\\php.exe docs/apply_packaged_inventory.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('FCPATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
define('ENVIRONMENT', 'development');
require FCPATH . 'app/Config/Paths.php';
$paths = new Config\Paths();
require $paths->systemDirectory . '/Boot.php';
CodeIgniter\Boot::bootConsole($paths);
$db = db_connect();
if ($db->tableExists('package_movements')) {
    echo "Package inventory schema already exists; no changes made.\n";
    exit;
}
$runner = service('migrations');
if (!$runner->force(APPPATH . 'Database/Migrations/2026-11-07-120000_AddPackagedInventory.php', 'App')) throw new RuntimeException('Migration failed.');
echo "Package inventory migration applied. Existing stock quantities and prices preserved.\n";
