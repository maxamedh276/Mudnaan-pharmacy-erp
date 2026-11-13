<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('FCPATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
define('ENVIRONMENT', 'development');
require FCPATH . 'app/Config/Paths.php';
$paths = new Config\Paths(); require $paths->systemDirectory . '/Boot.php';
CodeIgniter\Boot::bootConsole($paths);
(new App\Libraries\PharmacyInstaller())->install('pharmacy_main');
$password = bin2hex(random_bytes(10));
if (!is_file(FCPATH . '.env')) file_put_contents(FCPATH . '.env', "CI_ENVIRONMENT = development\njwt.secret = " . bin2hex(random_bytes(64)) . "\n");
$role = db_connect()->table('roles')->where('name','Superadmin')->get()->getRowArray();
db_connect()->table('users')->insert(['branch_id' => 1, 'role_id' => $role['id'], 'name' => 'Pharmacy Administrator', 'email' => 'admin@pharmacy.local', 'password_hash' => password_hash($password, PASSWORD_DEFAULT)]);
echo "Fresh pharmacy installed.\nLogin: admin@pharmacy.local\nInitial password: {$password}\nChange this password after signing in.\n";
