<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../app/Services/Pharmacy/PharmacyInput.php';
require __DIR__ . '/../app/Services/Pharmacy/MedicineUnitService.php';
use App\Services\Pharmacy\PharmacyInput;

$checks = 0;
function check(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
    echo "PASS $message\n";
}
function rejects(callable $action): void
{
    try { $action(); } catch (InvalidArgumentException $exception) { check(true, $exception->getMessage()); return; }
    throw new RuntimeException('Expected validation rejection.');
}

$profile = PharmacyInput::medicine(['generic_name' => '  Amoxicillin ', 'strength' => '500 mg', 'dosage_form' => 'Capsule', 'prescription_required' => '1']);
check($profile['generic_name'] === 'Amoxicillin' && $profile['prescription_required'] === 1, 'Medicine text normalization and prescription classification');
check(PharmacyInput::medicine(['generic_name' => 'Paracetamol', 'strength' => '500 mg', 'dosage_form' => 'Tablet'])['prescription_required'] === 0, 'OTC classification is explicit');
rejects(fn () => PharmacyInput::medicine(['generic_name' => [], 'strength' => '1', 'dosage_form' => 'Tablet']));
rejects(fn () => PharmacyInput::medicine(['generic_name' => 'A', 'strength' => '', 'dosage_form' => 'Tablet']));
rejects(fn () => PharmacyInput::medicine(['generic_name' => 'A', 'strength' => '1', 'dosage_form' => 'Tablet', 'prescription_required' => 'yes']));
check(PharmacyInput::date(['date' => '2024-02-29'], 'date') === '2024-02-29', 'Leap-year date accepted');
foreach (['2025-02-29', '2026-11-31', '2026-1-01', 'tomorrow'] as $date) rejects(fn () => PharmacyInput::date(['date' => $date], 'date'));
check(PharmacyInput::date([], 'date') === null, 'Missing optional date remains unknown');
check(PharmacyInput::quantity('0.125') === 0.125, 'Three decimal places supported');
foreach ([0, -1, INF, NAN, '1.0001', '1000000000', [], true, 'ten'] as $quantity) rejects(fn () => PharmacyInput::quantity($quantity));
check(PharmacyInput::id('123') === 123, 'Integer record identifier accepted');
foreach (['1 OR 1=1', '1.2', -1, 0, null, []] as $id) rejects(fn () => PharmacyInput::id($id));
$english = require __DIR__ . '/../app/Language/en/Pharmacy.php';
$somali = require __DIR__ . '/../app/Language/so/Pharmacy.php';
check(array_keys($english) === array_keys($somali), 'English and Somali translation coverage matches');
$composition=PharmacyInput::medicine(['generic_name'=>'Fixture combination','strength'=>'Recorded composition','dosage_form'=>'Tablet','route_of_administration'=>'Oral','active_ingredients'=>[['name'=>'Ingredient A','strength'=>'10 mg'],['name'=>'Ingredient B','strength'=>'20 mg']]]);
check(count(json_decode($composition['active_ingredients'],true))===2 && $composition['route_of_administration']==='Oral','Structured combination ingredients and route are recorded without generating doses');
rejects(fn()=>PharmacyInput::medicine(['generic_name'=>'Fixture','strength'=>'1','dosage_form'=>'Tablet','active_ingredients'=>[['name'=>'Ingredient A','strength'=>'']]]));
echo "{$checks} checks passed. No database connection or writes.\n";
