<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('FCPATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
define('ENVIRONMENT', 'development');
require FCPATH . 'app/Config/Paths.php';
$paths = new Config\Paths(); require $paths->systemDirectory . '/Boot.php';
CodeIgniter\Boot::bootConsole($paths);
if (($argv[1] ?? '') === 'drop') {
    $name = $argv[2] ?? '';
    if (!preg_match('/^pharmacy_test_[a-f0-9]{12}$/D', $name)) throw new InvalidArgumentException('Refusing to remove a non-fixture database.');
    $config = config(Config\Database::class)->default;
    $admin = new mysqli($config['hostname'],$config['username'],$config['password'],'',$config['port']);
    $exists=$admin->query('SHOW TABLES FROM `'.$name.'` LIKE \'pharmacy_prescription_documents\'');
    if ($exists->num_rows) {
        $documents=$admin->query('SELECT stored_name FROM `'.$name.'`.pharmacy_prescription_documents');
        $directory=realpath(WRITEPATH.'pharmacy-documents');
        while ($document=$documents->fetch_assoc()) if ($directory && preg_match('/^[a-f0-9]{32}\.(pdf|png|jpg)$/D',$document['stored_name'])) {
            $file=realpath($directory.DIRECTORY_SEPARATOR.$document['stored_name']);
            if ($file && str_starts_with(strtolower($file),strtolower($directory.DIRECTORY_SEPARATOR)) && is_file($file)) unlink($file);
        }
    }
    $admin->query('DROP DATABASE `' . $name . '`');
    exit;
}
$name = 'pharmacy_test_' . bin2hex(random_bytes(6));
(new App\Libraries\PharmacyInstaller())->install($name);
$password = bin2hex(random_bytes(16));
$role = db_connect()->table('roles')->where('name','Superadmin')->get()->getRowArray();
db_connect()->table('users')->insert(['branch_id'=>1,'role_id'=>$role['id'],'name'=>'Browser fixture pharmacist','email'=>'fixture@example.test','password_hash'=>password_hash($password,PASSWORD_DEFAULT)]);
echo json_encode(['database'=>$name,'identity'=>'fixture@example.test','password'=>$password],JSON_THROW_ON_ERROR);
