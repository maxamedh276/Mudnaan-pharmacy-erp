<?php

namespace App\Services\Pharmacy;

use App\Services\AuditService;
use App\Services\FinancialTransaction;
use InvalidArgumentException;

final class PharmacyService
{
    public function ready(): bool
    {
        return db_connect()->tableExists('pharmacy_medicines')
            && db_connect()->tableExists('pharmacy_patients')
            && db_connect()->tableExists('pharmacy_prescriptions')
            && db_connect()->tableExists('pharmacy_prescription_items');
    }

    public function medicines(string $search = '', ?array $productIds = null): array
    {
        if ($productIds===[]) return [];
        $query = db_connect()->table('pharmacy_medicines m')->select('m.*, p.name, p.is_active, p.barcode, p.sku, p.unit, p.sell_price, p.tax_rate, p.reorder_level, c.name AS category_name')
            ->join('products p', 'p.id=m.product_id')->join('categories c', 'c.id=p.category_id', 'left')->where('p.deleted_at', null);
        if ($search !== '') $query->groupStart()->like('p.name', $search)->orLike('m.generic_name', $search)->orLike('p.barcode', $search)->groupEnd();
        if ($productIds!==null) $query->whereIn('p.id',$productIds);
        $rows = $query->orderBy('p.name')->get()->getResultArray();
        foreach ($rows as &$row) if (array_key_exists('active_ingredients',$row)) $row['active_ingredients']=json_decode($row['active_ingredients']??'[]',true)??[];unset($row);
        if (db_connect()->tableExists('pharmacy_medicine_units')) {
            $allUnits = [];
            $unitQuery=db_connect()->table('pharmacy_medicine_units')->orderBy('factor');
            if ($productIds!==null) $unitQuery->whereIn('product_id',$productIds);
            foreach ($unitQuery->get()->getResultArray() as $unit) $allUnits[$unit['product_id']][] = $unit;
            foreach ($rows as &$row) $row['units'] = $allUnits[$row['product_id']] ?? [];
            unset($row);
        }
        return $rows;
    }

    public function saveMedicine(array $input): int
    {
        $id = empty($input['product_id']) ? null : PharmacyInput::id($input['product_id']);
        $values = PharmacyInput::medicine($input);
        return FinancialTransaction::run(function ($db) use ($id, $values, $input) {
            if ($id === null) {
                $base = PharmacyInput::text($input, 'base_unit', 30, true);
                if (!isset((new PharmacyDefinitionService())->units()[$base])) throw new InvalidArgumentException('Select a registered pharmacy base unit.');
                $product = (new \App\Services\Web\InventoryCatalogService())->saveProduct([
                    'name' => PharmacyInput::text($input, 'name', 180, true),
                    'category_id' => PharmacyInput::id($input['category_id'] ?? null),
                    'barcode' => PharmacyInput::text($input, 'barcode', 80),
                    'unit' => $base, 'sell_price' => $input['sell_price'] ?? 0,
                    'reorder_level' => $input['reorder_level'] ?? 10, 'stock_type' => 'non_sealed',
                    'creation_request_key' => $input['creation_request_key'] ?? null,
                ]);
                $id = (int)$product['id'];
            }
            $product = $db->query('SELECT id FROM products WHERE id=? AND deleted_at IS NULL FOR UPDATE', [$id])->getRowArray();
            if (!$product) throw new InvalidArgumentException('Product not found. Create it in the inventory catalog first.');
            $existing = $db->table('pharmacy_medicines')->where('product_id', $id)->get()->getRowArray();
            if ($db->fieldExists('clinical_verified','pharmacy_medicines')) {
                $reviewer=\App\Libraries\AuthContext::can('pharmacy.medicines.clinical');
                if (!$reviewer && !empty($existing['clinical_verified'])) foreach ($values as $field=>$value) {
                    $same=$field==='active_ingredients' ? (json_decode($existing[$field]??'[]',true)??[])===(json_decode($value??'[]',true)??[]) : (string)($existing[$field]??'')===(string)$value;
                    if (!$same) throw new InvalidArgumentException('Changing verified medicine clinical facts requires clinical-review permission.');
                }
                $requested=$input['clinical_reviewed']??true;
                if (!in_array($requested,[true,false,0,1,'0','1'],true)) throw new InvalidArgumentException('Invalid clinical review flag.');
                if ($reviewer) {
                    $values['clinical_verified']=$requested?1:0;
                    $values['clinical_verified_by']=$requested?\App\Libraries\AuthContext::userId():null;
                    $values['clinical_verified_at']=$requested?date('Y-m-d H:i:s'):null;
                } elseif (!$existing) $values['clinical_verified']=0;
            }
            if ($existing) $db->table('pharmacy_medicines')->where('product_id', $id)->update($values);
            else $db->table('pharmacy_medicines')->insert(['product_id' => $id] + $values);
            if ($db->tableExists('pharmacy_medicine_units')) {
                $product = $db->table('products')->where('id', $id)->get()->getRowArray();
                if (!empty($product['package_unit'])) throw new InvalidArgumentException('Legacy sealed products must be reconciled before conversion to pharmacy units.');
                $units = $input['units'] ?? [];
                if (!is_array($units)) throw new InvalidArgumentException('Enter valid pharmacy selling units.');
                $baseUnit = null;
                foreach ($units as $unit) if (is_array($unit) && (float)($unit['factor'] ?? 0) === 1.0) $baseUnit = $unit['name'] ?? null;
                if ($baseUnit !== $product['unit']) throw new InvalidArgumentException('The factor-1 unit must match the inventory base unit.');
                (new MedicineUnitService())->save($id, $units);
                $basePrice = $db->table('pharmacy_medicine_units')->select('sell_price')->where('product_id', $id)->where('name', $product['unit'])->get()->getRow('sell_price');
                $db->table('products')->where('id', $id)->update(['track_batches' => 1, 'sell_price' => $basePrice]);
            }
            AuditService::log('medicine.save', 'pharmacy', 'pharmacy_medicines', $id, before: $existing, after: $values);
            return $id;
        });
    }

    public function patients(int $branch, string $search = ''): array
    {
        $query = db_connect()->table('pharmacy_patients')->select('id, name, phone, date_of_birth, allergies')->where('branch_id', $branch);
        if ($search !== '') $query->groupStart()->like('name', $search)->orLike('phone', $search)->groupEnd();
        return $query->orderBy('name')->limit(200)->get()->getResultArray();
    }

    public function savePatient(array $input, int $branch, int $user): int
    {
        $values = [
            'name' => PharmacyInput::text($input, 'name', 180, true),
            'phone' => PharmacyInput::text($input, 'phone', 40),
            'date_of_birth' => PharmacyInput::date($input, 'date_of_birth'),
            'allergies' => PharmacyInput::text($input, 'allergies', 5000),
        ];
        if ($values['date_of_birth'] !== null && $values['date_of_birth'] > date('Y-m-d')) throw new InvalidArgumentException('Date of birth cannot be in the future.');
        $existingId = empty($input['id']) ? null : PharmacyInput::id($input['id']);
        return FinancialTransaction::run(function ($db) use ($values, $branch, $user, $existingId) {
            if ($existingId !== null) {
                if (!$db->query('SELECT id FROM pharmacy_patients WHERE id=? AND branch_id=? FOR UPDATE', [$existingId, $branch])->getRowArray()) throw new InvalidArgumentException('Patient not found in this location.');
                $db->table('pharmacy_patients')->where('id', $existingId)->where('branch_id', $branch)->update($values);
                AuditService::log('patient.update', 'pharmacy', 'pharmacy_patients', $existingId);
                return $existingId;
            }
            $db->table('pharmacy_patients')->insert($values + ['branch_id' => $branch, 'created_by' => $user]);
            $id = (int) $db->insertID();
            // Do not duplicate patient medical details into general-purpose audit snapshots.
            AuditService::log('patient.create', 'pharmacy', 'pharmacy_patients', $id);
            return $id;
        });
    }

    public function prescriptions(int $branch, bool $includeAllergies = false): array
    {
        return db_connect()->table('pharmacy_prescriptions r')->select('r.*, p.name AS patient_name'.($includeAllergies?',p.allergies AS patient_allergies':''))
            ->join('pharmacy_patients p', 'p.id=r.patient_id AND p.branch_id=r.branch_id')
            ->where('r.branch_id', $branch)->orderBy('r.id', 'DESC')->limit(200)->get()->getResultArray();
    }

    public function prescription(int $id, int $branch): array
    {
        $record = db_connect()->table('pharmacy_prescriptions r')->select('r.*, p.name AS patient_name')
            ->join('pharmacy_patients p', 'p.id=r.patient_id AND p.branch_id=r.branch_id')
            ->where('r.id', $id)->where('r.branch_id', $branch)->get()->getRowArray();
        if (!$record) throw new InvalidArgumentException('Prescription not found in this branch.');
        $record['items'] = db_connect()->table('pharmacy_prescription_items i')->select('i.*, i.medicine_name AS name')
            ->where('i.prescription_id', $id)->orderBy('i.id')->get()->getResultArray();
        $record['documents']=(new PrescriptionDocumentService())->listing($id,$branch);
        return $record;
    }

    public function recordPrescription(array $input, int $branch, int $user): int
    {
        $patient = PharmacyInput::id($input['patient_id'] ?? null);
        $values = [
            'prescriber' => PharmacyInput::text($input, 'prescriber', 180, true),
            'reference' => PharmacyInput::text($input, 'reference', 100),
            'issued_on' => PharmacyInput::date($input, 'issued_on', true),
            'expires_on' => PharmacyInput::date($input, 'expires_on'),
        ];
        if ($values['issued_on'] > date('Y-m-d') || ($values['expires_on'] !== null && $values['expires_on'] < $values['issued_on'])) {
            throw new InvalidArgumentException('Invalid prescription date range.');
        }
        $items = $input['items'] ?? null;
        if (!is_array($items) || !array_is_list($items) || !$items || count($items) > 100) throw new InvalidArgumentException('Enter 1 to 100 prescription items.');
        $lines = [];
        foreach ($items as $item) {
            if (!is_array($item)) throw new InvalidArgumentException('Invalid prescription item.');
            $lines[] = [
                'product_id' => PharmacyInput::id($item['product_id'] ?? null),
                'quantity' => PharmacyInput::quantity($item['quantity'] ?? null),
                'directions' => PharmacyInput::text($item, 'directions', 500, true),
            ];
        }
        return FinancialTransaction::run(function ($db) use ($patient, $values, $lines, $branch, $user) {
            if (!$db->query('SELECT id FROM pharmacy_patients WHERE id=? AND branch_id=? FOR UPDATE', [$patient, $branch])->getRowArray()) {
                throw new InvalidArgumentException('Patient not found in this branch.');
            }
            $snapshots = [];
            $clinicalColumns=$db->fieldExists('active_ingredients','pharmacy_medicines')?',m.route_of_administration,m.active_ingredients,m.clinical_verified':'';
            foreach ($lines as $line) {
                $medicine = $db->table('pharmacy_medicines m')->select('p.name AS medicine_name, m.strength, m.dosage_form, p.unit'.$clinicalColumns)->join('products p', 'p.id=m.product_id')->where('m.product_id', $line['product_id'])->where('p.deleted_at', null)->where('p.is_active', 1)->get()->getRowArray();
                if (!$medicine) {
                    throw new InvalidArgumentException('Select an active medicine.');
                }
                if (isset($medicine['clinical_verified']) && !$medicine['clinical_verified']) throw new InvalidArgumentException('Review this medicine clinical profile before prescribing or dispensing.');
                unset($medicine['clinical_verified']);
                $snapshots[] = $line + $medicine;
            }
            $db->table('pharmacy_prescriptions')->insert($values + ['branch_id' => $branch, 'patient_id' => $patient, 'created_by' => $user]);
            $id = (int) $db->insertID();
            foreach ($snapshots as $line) $db->table('pharmacy_prescription_items')->insert($line + ['prescription_id' => $id]);
            AuditService::log('prescription.record', 'pharmacy', 'pharmacy_prescriptions', $id);
            return $id;
        });
    }
}
