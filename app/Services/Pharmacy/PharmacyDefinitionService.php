<?php
namespace App\Services\Pharmacy;

use App\Services\AuditService;
use InvalidArgumentException;

/** Immutable unit semantics prevent later configuration changes reinterpreting stock. */
final class PharmacyDefinitionService
{
    public function forms(): array
    {
        $forms = MedicineUnitService::FORMS;
        foreach ($this->custom('form') as $row) $forms[] = $row['name'];
        return array_values(array_unique($forms));
    }

    public function units(): array
    {
        $units = [];
        foreach (MedicineUnitService::UNITS as $name) {
            $family = in_array($name, ['ml', 'litre'], true) ? 'volume' : ($name === 'gram' ? 'mass' : (in_array($name, ['strip','blister','box','bottle','ampoule','vial','tube','pack','sachet'], true) ? 'package' : 'count'));
            $units[$name] = ['name'=>$name, 'family'=>$family, 'fractional'=>in_array($family, ['volume','mass'], true)];
        }
        foreach ($this->custom('unit') as $row) $units[$row['name']] = $row;
        return $units;
    }

    private function custom(string $kind): array
    {
        $db = db_connect();
        if (!$db->tableExists('pharmacy_definitions')) return [];
        return $db->table('pharmacy_definitions')->where('kind', $kind)->orderBy('name')->get()->getResultArray();
    }

    public function save(array $input, int $user): int
    {
        $kind = $input['kind'] ?? null;
        if (!in_array($kind, ['form','unit'], true)) throw new InvalidArgumentException('Choose dosage form or unit.');
        $name = PharmacyInput::text($input, 'name', $kind === 'unit' ? 30 : 80, true);
        if ($kind === 'unit') {
            $name = strtolower($name);
            if (!preg_match('/^[a-z][a-z0-9 _-]*$/D', $name)) throw new InvalidArgumentException('Use letters, numbers, spaces or hyphens for a unit name.');
        }
        $family = $kind === 'unit' ? ($input['family'] ?? '') : 'count';
        if (!in_array($family, ['count','volume','mass','package'], true)) throw new InvalidArgumentException('Select the unit family.');
        if (in_array(strtolower($name), array_map('strtolower', $kind === 'form' ? $this->forms() : array_keys($this->units())), true)) throw new InvalidArgumentException('This name already exists.');
        $db = db_connect();
        if (!$db->tableExists('pharmacy_definitions')) throw new InvalidArgumentException('Apply the pharmacy workspace migration before adding definitions.');
        $db->table('pharmacy_definitions')->insert(['kind'=>$kind,'name'=>$name,'family'=>$family,'fractional'=>in_array($family,['volume','mass'],true)?1:0,'created_by'=>$user]);
        $id = (int)$db->insertID();
        AuditService::log('definition.create', 'pharmacy', 'pharmacy_definitions', $id);
        return $id;
    }
}
