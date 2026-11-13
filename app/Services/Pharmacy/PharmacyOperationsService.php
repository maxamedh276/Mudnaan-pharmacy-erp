<?php
namespace App\Services\Pharmacy;

use App\Services\AccountingService;
use App\Services\AuditService;
use App\Services\FinancialTransaction;
use App\Services\InventoryService;
use InvalidArgumentException;

final class PharmacyOperationsService
{
    public function stock(array $input, int $branch, int $user, string $plan): int
    {
        $productId = PharmacyInput::id($input['product_id'] ?? null);
        $key = PharmacyInput::text($input, 'request_key', 36, true);
        if (!preg_match('/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/iD', $key)) throw new InvalidArgumentException('Reopen the stock form and try again.');
        $reason = PharmacyInput::text($input, 'reason', 500, true);
        $action = $input['action'] ?? '';
        if (!in_array($action, ['receive', 'dispose', 'allocate_existing'], true)) throw new InvalidArgumentException('Choose a stock operation.');
        $quantity = PharmacyInput::quantity($input['quantity'] ?? null);
        $hash = hash('sha256', json_encode($input, JSON_THROW_ON_ERROR));
        return FinancialTransaction::run(function ($db) use ($input, $productId, $branch, $user, $plan, $key, $reason, $action, $quantity, $hash) {
            $product = $db->query('SELECT * FROM products WHERE id=? AND deleted_at IS NULL FOR UPDATE', [$productId])->getRowArray();
            if (!$product || !(new MedicineBatchService())->isMedicine($productId)) throw new InvalidArgumentException('Select a medicine.');
            $existing = $db->table('pharmacy_stock_requests')->where('branch_id', $branch)->where('request_key', $key)->get()->getRowArray();
            if ($existing) {
                if (!hash_equals($existing['request_hash'], $hash)) throw new InvalidArgumentException('This request key was used for a different stock operation.');
                return (int)$existing['batch_id'];
            }
            $unit = (new MedicineUnitService())->resolve($productId, $input['medicine_unit_id'] ?? null);
            $base = MedicineUnitService::baseQuantity($unit, $quantity);
            $inventory = new InventoryService();
            $before = $inventory->currentQuantity($branch, $productId);
            if ($action === 'allocate_existing') {
                $unallocated = (float)$db->query('SELECT COALESCE(SUM(qty_change),0) AS quantity FROM stock_movements WHERE branch_id=? AND product_id=? AND batch_id IS NULL', [$branch, $productId])->getRow('quantity');
                if ($base > round($unallocated, 3)) throw new InvalidArgumentException('Quantity exceeds the unallocated legacy stock.');
            }
            if ($action === 'dispose') {
                $batch = PharmacyInput::id($input['batch_id'] ?? null);
                if ($base > $before) throw new InvalidArgumentException('Insufficient physical inventory.');
            } else $batch = (new MedicineBatchService())->create($branch, $productId, $input);
            $cost = (float)$product['cost_price'];
            if ($action === 'receive') {
                $unitCost = $input['unit_cost'] ?? null;
                if (!is_numeric($unitCost) || !is_finite((float)$unitCost) || (float)$unitCost < 0 || (float)$unitCost > 9999999999.99) throw new InvalidArgumentException('Enter a valid stock cost.');
                $cost = (float)$unitCost / (float)$unit['factor'];
                $average = $before + $base > 0 ? ($before * (float)$product['cost_price'] + $base * $cost) / ($before + $base) : $cost;
                $db->table('products')->where('id', $productId)->update(['cost_price' => round($average, 6)]);
            }
            $db->table('pharmacy_stock_requests')->insert(['branch_id' => $branch, 'request_key' => $key, 'request_hash' => $hash, 'product_id' => $productId, 'batch_id' => $batch]);
            $reference = (int)$db->insertID();
            if ($action === 'allocate_existing') $inventory->recordMovement($branch, $productId, -$base, 'adjustment', 'pharmacy_reconcile', $reference, $user);
            $inventory->recordMovement($branch, $productId, $action === 'dispose' ? -$base : $base, 'adjustment', 'pharmacy_stock', $reference, $user, $batch);
            if ($action !== 'allocate_existing' && $plan === 'premium') (new AccountingService())->postInventoryAdjustment($branch, round($base * $cost, 2), $action === 'receive', $productId, $user);
            AuditService::log('pharmacy.stock.' . $action, 'pharmacy', 'product_batches', $batch, after: ['quantity' => $base, 'reason' => $reason, 'request_id' => $reference]);
            return $batch;
        });
    }

    public function changeStatus(array $input, int $branch, int $user): int
    {
        $id = PharmacyInput::id($input['batch_id'] ?? null);
        $reason = PharmacyInput::text($input, 'reason', 500, true);
        $status = $input['status'] ?? '';
        if (!in_array($status, ['active', 'quarantine', 'recalled'], true)) throw new InvalidArgumentException('Choose a valid batch status.');
        return FinancialTransaction::run(function ($db) use ($id, $branch, $user, $reason, $status) {
            $batch = $db->table('product_batches')->where('id', $id)->where('branch_id', $branch)->get()->getRowArray();
            if (!$batch) throw new InvalidArgumentException('Batch not found in this location.');
            $db->query('SELECT id FROM products WHERE id=? FOR UPDATE', [$batch['product_id']]);
            $before = $db->table('pharmacy_batch_states')->where('batch_id', $id)->get()->getRowArray();
            if (!$before) throw new InvalidArgumentException('Batch has not been verified.');
            if ($status === 'active' && (empty($batch['expiry_date']) || $batch['expiry_date'] < date('Y-m-d') || str_starts_with((string)$batch['batch_no'], 'RETURN-'))) throw new InvalidArgumentException('Expired or customer-return batches cannot be released for sale.');
            $db->table('pharmacy_batch_states')->where('batch_id', $id)->update(['status' => $status, 'reason' => $reason, 'updated_by' => $user]);
            AuditService::log('pharmacy.batch.status', 'pharmacy', 'product_batches', $id, before: $before, after: ['status' => $status, 'reason' => $reason]);
            return $id;
        });
    }

    public function approve(array $input, int $branch): int
    {
        $id = PharmacyInput::id($input['prescription_id'] ?? null);
        return FinancialTransaction::run(function ($db) use ($id, $branch) {
            $rx = $db->query('SELECT * FROM pharmacy_prescriptions WHERE id=? AND branch_id=? FOR UPDATE', [$id, $branch])->getRowArray();
            if (!$rx || !in_array($rx['status'], ['recorded', 'approved'], true) || ($rx['expires_on'] !== null && $rx['expires_on'] < date('Y-m-d'))) throw new InvalidArgumentException('Only a recorded, unexpired prescription can be approved.');
            $db->table('pharmacy_prescriptions')->where('id', $id)->update(['status' => 'approved']);
            AuditService::log('prescription.approve', 'pharmacy', 'pharmacy_prescriptions', $id);
            return $id;
        });
    }
}
