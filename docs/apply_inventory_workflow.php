<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('FCPATH', dirname(__DIR__) . DIRECTORY_SEPARATOR); define('ENVIRONMENT','development');
require FCPATH.'app/Config/Paths.php'; $paths = new Config\Paths(); require $paths->systemDirectory.'/Boot.php'; CodeIgniter\Boot::bootConsole($paths);
$db = db_connect();
if (!$db->tableExists('package_movements')) throw new RuntimeException('Apply packaged inventory migration 17 first.');
if ($db->tableExists('product_opening_stocks')) { echo "Inventory workflow schema already exists.\n"; exit; }
if (!service('migrations')->force(APPPATH.'Database/Migrations/2026-11-07-150000_AddInventoryWorkflow.php','App')) throw new RuntimeException('Migration failed.');
echo "Inventory workflow schema applied. Existing stock preserved.\n";
