<?php
namespace App\Services\Web;

use App\Models\ProductModel;
use App\Models\StockMovementModel;
use App\Services\InventoryService;
use InvalidArgumentException;

/** Exact movement-to-reason linkage and retry protection for the web forms. */
class StockAdjustmentService
{
    public function notesForMovements(int $branchId, array $movementIds): array
    {
        if (!$movementIds) return [];
        $database = db_connect();
        $details = $database->table('web_stock_adjustment_details')->where('branch_id', $branchId)->whereIn('movement_id', $movementIds)->get()->getResultArray();
        $notes = array_column($details, 'note', 'movement_id');
        // Legacy notes were audited against product IDs, rather than movement IDs.
        // Accept only a unique match in both directions. Normalize the old PHP/DB clock offset.
        $placeholders = implode(',', array_fill(0, count($movementIds), '?'));
        $matches = $database->query("SELECT m.id AS movement_id, a.id AS audit_id, a.created_at AS audit_created, m.product_id, m.user_id, m.qty_change,
                JSON_UNQUOTE(JSON_EXTRACT(a.after_values, '$.note')) AS note
            FROM stock_movements m JOIN audit_log a ON a.action = 'stock.adjust'
                AND a.entity = 'stock_movements' AND a.entity_id = m.product_id
                AND a.user_id = m.user_id
                AND (a.created_at = m.created_at OR a.created_at = TIMESTAMPADD(SECOND, TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), NOW()), m.created_at))
            WHERE m.branch_id = ? AND m.id IN ($placeholders) AND m.reason = 'adjustment'
                AND JSON_VALID(a.after_values)
                AND JSON_EXTRACT(a.after_values, '$.product_id') IS NULL
                AND CAST(JSON_UNQUOTE(JSON_EXTRACT(a.after_values, '$.qty_change')) AS DECIMAL(12,3)) = m.qty_change", array_merge([$branchId], $movementIds))->getResultArray();
        $matches = array_values(array_filter($matches, function(array $match) use($database): bool {
            return $database->query("SELECT id FROM stock_movements WHERE id != ? AND product_id = ? AND user_id = ? AND reason = 'adjustment' AND qty_change = ? AND (created_at = ? OR TIMESTAMPADD(SECOND,TIMESTAMPDIFF(SECOND,UTC_TIMESTAMP(),NOW()),created_at) = ?) LIMIT 1",[$match['movement_id'],$match['product_id'],$match['user_id'],$match['qty_change'],$match['audit_created'],$match['audit_created']])->getRowArray() === null;
        }));
        $byMovement = [];
        $auditCounts = array_count_values(array_column($matches, 'audit_id'));
        foreach ($matches as $match) $byMovement[$match['movement_id']][] = $match;
        foreach ($byMovement as $movementId => $candidates) {
            if (isset($notes[$movementId]) || count($candidates) !== 1) continue;
            $match = $candidates[0];
            if ($auditCounts[$match['audit_id']] === 1 && $match['note'] !== null && $match['note'] !== 'null' && $match['note'] !== '') $notes[$movementId] = $match['note'];
        }
        return $notes;
    }

    public function save(array $input, int $branchId, int $userId, string $plan): array
    {
        $productId = (int) ($input['product_id'] ?? 0);
        $quantity = $input['qty_change'] ?? null;
        $note = trim((string) ($input['note'] ?? ''));
        $requestKey = (string) ($input['request_key'] ?? '');
        if (!is_numeric($quantity) || !is_finite((float) $quantity) || abs((float) $quantity) > 999999999.999 || round((float) $quantity, 3) == 0 || abs((float) $quantity - round((float) $quantity, 3)) > 0.0000001) throw new InvalidArgumentException('Enter a non-zero quantity with up to three decimal places.');
        if ($note === '' || mb_strlen($note) > 500) throw new InvalidArgumentException('Enter an adjustment reason of up to 500 characters.');
        if (!preg_match('/^[a-f0-9-]{36}$/i', $requestKey)) throw new InvalidArgumentException('Reopen the adjustment form and try again.');
        $quantity = round((float) $quantity, 3);
        $mode = $input['stock_mode'] ?? 'loose';
        if (!in_array($mode,['loose','sealed'],true)) throw new InvalidArgumentException('Choose the stock to adjust.');
        $database = db_connect();
        $database->transException(true)->transBegin();
        try {
            // Serialize web corrections for this product, including the first stock-in.
            $product = $database->query('SELECT * FROM products WHERE id = ? AND deleted_at IS NULL FOR UPDATE', [$productId])->getRowArray();
            if (!$product) throw new InvalidArgumentException('Product not found.');
            $sealed = $mode === 'sealed';
            if ($sealed && (empty($product['package_unit']) || floor(abs($quantity)) != abs($quantity))) throw new InvalidArgumentException('Adjust sealed stock using whole packages.');
            $selectedQuantity = $quantity;
            $quantity = round($quantity * ($sealed ? (float)$product['package_size'] : 1),3);
            if (abs($quantity) > 999999999.999) throw new InvalidArgumentException('The converted adjustment is too large.');
            $unitLabel = $sealed ? $product['package_unit'] : $product['unit'];
            $existing = $database->table('web_stock_adjustment_details d')->select('d.*, m.product_id, m.qty_change')
                ->join('stock_movements m', 'm.id = d.movement_id')->where('d.branch_id', $branchId)->where('d.request_key', $requestKey)->get()->getRowArray();
            if ($existing) {
                if ((int) $existing['product_id'] !== $productId || (float) $existing['qty_change'] !== $quantity || $existing['note'] !== $note || ($existing['stock_mode'] ?? 'loose') !== $mode) throw new InvalidArgumentException('This submission was already saved. Reopen the form for a different adjustment.');
                $result = ['product' => (new ProductModel())->findWithStock($productId, $branchId), 'already_saved' => true];
            } else {
                $movementModel = new StockMovementModel();
                $inventory = new InventoryService(movements: $movementModel);
                $packages = new \App\Services\PackageInventoryService();
                if ($selectedQuantity < 0) $packages->requireStock($branchId,$product,$mode,abs($selectedQuantity));
                if ($inventory->currentQuantity($branchId, $productId) + $quantity < 0) throw new InvalidArgumentException('INSUFFICIENT_STOCK');
                $value = round(abs($quantity) * (float) $product['cost_price'], 2);
                // Reuse stock posting and journal rules under a single transaction owner.
                // A failed accounting post must roll back the movement and cached quantity together.
                if ($sealed) $packages->record($branchId,$productId,(int)$selectedQuantity,(float)$product['package_size'],'adjustment',null,$userId);
                $inventory->recordMovement($branchId, $productId, $quantity, 'adjustment', $sealed ? 'sealed_adjustment' : 'adjustment', null, $userId);
                $movementId = (int) $movementModel->getInsertID();
                if ($plan === 'premium') (new \App\Services\AccountingService())->postInventoryAdjustment($branchId, $value, $quantity > 0, $productId, $userId);
                \App\Services\AuditService::log('stock.adjust', 'inventory', 'stock_movements', $movementId, after: ['product_id' => $productId, 'branch_id' => $branchId, 'qty_change' => $quantity, 'value' => $value, 'note' => $note]);
                $result = ['product' => (new ProductModel())->findWithStock($productId, $branchId), 'value' => $value];
                $database->table('web_stock_adjustment_details')->insert(['movement_id' => $movementId, 'branch_id' => $branchId, 'request_key' => $requestKey, 'note' => $note, 'stock_mode'=>$mode, 'selected_quantity'=>$selectedQuantity, 'unit_label'=>$unitLabel]);
                $result['movement_id'] = $movementId;
                $result['already_saved'] = false;
            }
            if (!$database->transStatus() || !$database->transCommit()) throw new \RuntimeException('TRANSACTION_FAILED');
            return $result;
        } catch (\Throwable $exception) {
            $database->transRollback();
            throw $exception;
        }
    }
}
