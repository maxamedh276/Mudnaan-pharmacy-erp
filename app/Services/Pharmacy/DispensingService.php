<?php
namespace App\Services\Pharmacy;

use App\Libraries\AuthContext;
use InvalidArgumentException;

/** Runs inside the existing sale transaction; no second stock or accounting engine. */
final class DispensingService
{
    public function validate(array $data, array $lines, int $branch): void
    {
        $db = db_connect();
        if (!$db->tableExists('pharmacy_dispensings')) return;
        $requested = [];
        foreach ($lines as $line) {
            $medicine = $db->table('pharmacy_medicines')->where('product_id', $line['product_id'])->get()->getRowArray();
            if (!$medicine) continue;
            if (isset($medicine['clinical_verified']) && !$medicine['clinical_verified']) throw new InvalidArgumentException('This medicine is awaiting authorised clinical-profile review.');
            if ($medicine['prescription_required'] || isset($data['prescription_id'])) {
                $requested[(int)$line['product_id']] = round(($requested[(int)$line['product_id']] ?? 0) + $line['quantity'] * $line['stock_factor'], 3);
            }
        }
        if (!$requested) {
            if (isset($data['prescription_id'])) throw new InvalidArgumentException('The sale contains no prescribed medicine.');
            return;
        }
        if (!AuthContext::can('pharmacy.dispense')) throw new InvalidArgumentException('A pharmacist with dispensing permission is required.');
        if (!isset($data['prescription_id'])) throw new InvalidArgumentException('Select an approved prescription for this prescription-only medicine.');
        $id = PharmacyInput::id($data['prescription_id'] ?? null);
        $rx = $db->query('SELECT * FROM pharmacy_prescriptions WHERE id=? AND branch_id=? FOR UPDATE', [$id, $branch])->getRowArray();
        if (!$rx || !in_array($rx['status'], ['approved', 'partial'], true) || ($rx['expires_on'] !== null && $rx['expires_on'] < date('Y-m-d'))) {
            throw new InvalidArgumentException('An approved, unexpired prescription is required.');
        }
        $prescribed = $db->table('pharmacy_prescription_items')->select('product_id,SUM(quantity) AS quantity')->where('prescription_id', $id)->groupBy('product_id')->get()->getResultArray();
        $limits = array_column($prescribed, 'quantity', 'product_id');
        $used = $this->used($id);
        foreach ($requested as $product => $quantity) {
            if (!isset($limits[$product]) || round($quantity + ($used[$product] ?? 0), 3) > (float)$limits[$product]) throw new InvalidArgumentException('The requested quantity exceeds the remaining prescription quantity.');
        }
    }

    private function used(int $prescription): array
    {
        $rows = db_connect()->table('pharmacy_dispensings d')->select('i.product_id,SUM(i.quantity*i.stock_factor) AS quantity')
            ->join('sale_items i', 'i.sale_id=d.sale_id')->where('d.prescription_id', $prescription)->groupBy('i.product_id')->get()->getResultArray();
        return array_column($rows, 'quantity', 'product_id');
    }

    public function record(array $data, int $sale, int $branch, int $user): void
    {
        if (!isset($data['prescription_id'])) return;
        $db = db_connect();
        if (!$db->tableExists('pharmacy_dispensings')) throw new InvalidArgumentException('Pharmacy dispensing has not been installed.');
        $id = PharmacyInput::id($data['prescription_id']);
        $rx = $db->table('pharmacy_prescriptions')->where('id', $id)->where('branch_id', $branch)->get()->getRowArray();
        if (!$rx || !AuthContext::can('pharmacy.dispense') || !in_array($rx['status'], ['approved', 'partial'], true)) throw new InvalidArgumentException('Invalid dispensing prescription.');
        $db->table('pharmacy_dispensings')->insert(['sale_id' => $sale, 'prescription_id' => $id, 'user_id' => $user]);
        $used = $this->used($id);
        $remaining = false;
        foreach ($db->table('pharmacy_prescription_items')->select('product_id,SUM(quantity) AS quantity')->where('prescription_id', $id)->groupBy('product_id')->get()->getResultArray() as $line) {
            if ((float)($used[$line['product_id']] ?? 0) < (float)$line['quantity']) $remaining = true;
        }
        $db->table('pharmacy_prescriptions')->where('id', $id)->update(['status' => $remaining ? 'partial' : 'dispensed']);
    }
}
