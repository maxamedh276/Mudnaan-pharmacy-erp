<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('FCPATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
define('ENVIRONMENT', 'development');
require FCPATH . 'app/Config/Paths.php';
$paths = new Config\Paths();
require $paths->systemDirectory . '/Boot.php';
CodeIgniter\Boot::bootConsole($paths);
Config\Services::injectMock('request', new CodeIgniter\HTTP\IncomingRequest(config(Config\App::class), new CodeIgniter\HTTP\URI('http://localhost/'), null, new CodeIgniter\HTTP\UserAgent()));

use App\Services\Pharmacy\PharmacyService;

$fixtureDatabase = 'pharmacy_test_' . bin2hex(random_bytes(6));
$connectionConfig = config(Config\Database::class);
$admin = new mysqli($connectionConfig->default['hostname'], $connectionConfig->default['username'], $connectionConfig->default['password'], '', (int) $connectionConfig->default['port']);
$admin->query('CREATE DATABASE `' . $fixtureDatabase . '` CHARACTER SET utf8mb4');
register_shutdown_function(static function () use ($admin, $fixtureDatabase): void {
    // Only the random schema just created by this process is ever removed.
    if (preg_match('/^pharmacy_test_[a-f0-9]{12}$/D', $fixtureDatabase)) $admin->query('DROP DATABASE `' . $fixtureDatabase . '`');
    $admin->close();
});
$connectionConfig->default['database'] = $fixtureDatabase;
$db = db_connect();
$checks = 0;
function check(bool $condition, string $message): void {
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
    echo "PASS $message\n";
}
function rejects(callable $operation): void {
    try { $operation(); } catch (InvalidArgumentException $error) { check(true, $error->getMessage()); return; }
    throw new RuntimeException('Expected operation to be rejected.');
}

// All writes target this process's randomly named, disposable fixture database.
$db->query('CREATE TABLE branches (id BIGINT UNSIGNED PRIMARY KEY) ENGINE=InnoDB');
$db->query('CREATE TABLE users (id BIGINT UNSIGNED PRIMARY KEY) ENGINE=InnoDB');
$db->table('branches')->insert(['id' => 1]);
$db->table('users')->insert(['id' => 1]);
$db->query('CREATE TABLE categories (id BIGINT UNSIGNED PRIMARY KEY,name VARCHAR(120)) ENGINE=InnoDB');
$db->query('CREATE TABLE products (id BIGINT UNSIGNED PRIMARY KEY, category_id BIGINT UNSIGNED NULL, tax_rate DECIMAL(5,2) DEFAULT 0, reorder_level DECIMAL(12,3) DEFAULT 0, sku VARCHAR(80) NULL, name VARCHAR(180), barcode VARCHAR(80), unit VARCHAR(30), sell_price DECIMAL(12,2), deleted_at DATETIME NULL, is_active INT DEFAULT 1) ENGINE=InnoDB');
$db->query('CREATE TABLE roles (id BIGINT UNSIGNED PRIMARY KEY, name VARCHAR(80)) ENGINE=InnoDB');
$db->query('CREATE TABLE permissions (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, code VARCHAR(100) UNIQUE, description VARCHAR(150)) ENGINE=InnoDB');
$db->query('CREATE TABLE role_permissions (role_id BIGINT UNSIGNED, permission_id BIGINT UNSIGNED, PRIMARY KEY(role_id,permission_id)) ENGINE=InnoDB');
$db->query('CREATE TABLE audit_log (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id BIGINT NULL, role VARCHAR(80), action VARCHAR(100), module VARCHAR(80), entity VARCHAR(100), entity_id BIGINT NULL, details JSON NULL, before_values JSON NULL, after_values JSON NULL, ip_address VARCHAR(64), user_agent VARCHAR(255)) ENGINE=InnoDB');
$db->table('roles')->insertBatch([['id' => 1, 'name' => 'Superadmin'], ['id' => 2, 'name' => 'Cashier']]);
require FCPATH . 'app/Database/Migrations/2026-11-11-120000_AddPharmacyFoundation.php';
$migration = new App\Database\Migrations\AddPharmacyFoundation();
$migration->up();
$migration->up();
check($db->table('permissions')->countAllResults() === 6, 'Migration is retryable without duplicate permissions');
check($db->table('role_permissions')->where('role_id', 1)->countAllResults() === 6 && $db->table('role_permissions')->where('role_id', 2)->countAllResults() === 0, 'Migration grants pharmacy permissions to Superadmin only');
$db->table('products')->insertBatch([
    ['id' => 1, 'name' => 'Amoxicillin Brand', 'barcode' => '12345', 'unit' => 'capsule', 'sell_price' => 1, 'deleted_at' => null, 'is_active' => 1],
    ['id' => 2, 'name' => 'Deleted medicine', 'barcode' => '99999', 'unit' => 'tablet', 'sell_price' => 1, 'deleted_at' => date('Y-m-d H:i:s'), 'is_active' => 1],
]);
$service = new PharmacyService();
check($service->ready(), 'Pharmacy schema is ready after migration');
$profile = ['product_id' => 1, 'generic_name' => 'Amoxicillin', 'strength' => '500 mg', 'dosage_form' => 'Capsule', 'prescription_required' => true];
check($service->saveMedicine($profile) === 1, 'Medicine profile reuses inventory product ID');
check(count($service->medicines('Amoxicillin')) === 1, 'Generic/brand medicine search');
$service->saveMedicine($profile + ['manufacturer' => 'Example']);
check($db->table('pharmacy_medicines')->countAllResults() === 1, 'Saving existing profile does not duplicate it');
rejects(fn () => $service->saveMedicine(array_replace($profile, ['product_id' => 2])));
$patient = $service->savePatient(['name' => 'Fixture patient', 'allergies' => 'Fixture only'], 1, 1);
check(count($service->patients(1)) === 1 && $service->patients(2) === [], 'Patients are isolated by branch');
rejects(fn () => $service->savePatient(['name' => 'Fixture', 'date_of_birth' => '2999-01-01'], 1, 1));
$rx = ['patient_id' => $patient, 'prescriber' => 'Fixture prescriber', 'issued_on' => date('Y-m-d'), 'items' => [['product_id' => 1, 'quantity' => 10, 'directions' => 'Fixture instructions']]];
check($service->recordPrescription($rx, 1, 1) > 0, 'Prescription and its medicine line saved');
check(count($service->prescriptions(1)) === 1 && $service->prescriptions(2) === [], 'Prescription listing is isolated by branch');
check(count($service->prescription(1, 1)['items']) === 1, 'Prescription details include the prescribed medicine and directions');
$service->saveMedicine(array_replace($profile, ['strength' => '250 mg']));
check($service->prescription(1, 1)['items'][0]['strength'] === '500 mg', 'Historical prescription strength survives catalog changes');
rejects(fn () => $service->prescription(1, 2));
rejects(fn () => $service->recordPrescription($rx, 2, 1));
rejects(fn () => $service->recordPrescription(array_replace($rx, ['items' => [['product_id' => 2, 'quantity' => 1, 'directions' => 'Fixture']]]), 1, 1));
check($db->table('pharmacy_prescriptions')->countAllResults() === 1, 'Failed prescriptions roll back without partial headers');
check($db->table('pharmacy_prescription_items')->countAllResults() === 1, 'Failed prescriptions leave no partial items');
$audits = $db->table('audit_log')->where('module', 'pharmacy')->get()->getResultArray();
check(count($audits) === 5, 'Pharmacy mutations are audited');
foreach ($audits as $audit) if (in_array($audit['entity'], ['pharmacy_patients', 'pharmacy_prescriptions'], true)) check($audit['after_values'] === null, 'Medical details are excluded from general audit snapshots');
try { $migration->down(); throw new LogicException('Rollback must preserve pharmacy records.'); }
catch (RuntimeException $error) { check(str_contains($error->getMessage(), 'Pharmacy records exist'), 'Rollback refuses to remove medical records'); }
echo "{$checks} checks passed in an isolated disposable database.\n";
