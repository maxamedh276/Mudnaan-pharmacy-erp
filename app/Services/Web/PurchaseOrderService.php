<?php

namespace App\Services\Web;

use App\Models\ProductModel;
use App\Models\PurchaseModel;
use App\Models\SupplierModel;
use App\Services\AuditService;
use DateTimeImmutable;
use InvalidArgumentException;

/** Web order editing is limited to orders with no receipts or posted inventory. */
class PurchaseOrderService
{
    public function save(array $input, int $branchId, int $userId): array
    {
        $database = db_connect();
        $purchaseId = (int) ($input['id'] ?? 0);
        $supplierId = (int) ($input['supplier_id'] ?? 0);
        $supplier = (new SupplierModel())->find($supplierId);
        if (!$supplier) throw new InvalidArgumentException('Select a valid supplier.');
        $expected = str_replace('T', ' ', trim((string) ($input['expected_received_at'] ?? '')));
        if (strlen($expected) === 16) $expected .= ':00';
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $expected);
        if (!$date || $date->format('Y-m-d H:i:s') !== $expected) throw new InvalidArgumentException('Select a valid expected receipt date and time.');
        $reference = trim((string) ($input['reference_no'] ?? ''));
        $notes = trim((string) ($input['notes'] ?? ''));
        if (mb_strlen($reference) > 80 || mb_strlen($notes) > 10000) throw new InvalidArgumentException('The reference or notes are too long.');
        if (!is_array($input['items'] ?? null) || !$input['items'] || count($input['items']) > 500) throw new InvalidArgumentException('Add between 1 and 500 order lines.');
        $items = [];
        $total = 0;
        foreach ($input['items'] as $item) {
            if (!is_array($item)) throw new InvalidArgumentException('Invalid order line.');
            $productId = (int) ($item['product_id'] ?? 0);
            if (!($product = (new ProductModel())->find($productId))) throw new InvalidArgumentException('Select a valid product for every line.');
            if (!is_numeric($item['quantity'] ?? null) || !is_numeric($item['unit_cost'] ?? null)) throw new InvalidArgumentException('Enter valid quantities and unit costs.');
            $quantity = round((float) $item['quantity'], 3);
            $cost = round((float) $item['unit_cost'], 2);
            if (!is_finite($quantity) || !is_finite($cost) || $quantity <= 0 || $quantity > 999999999.999 || $cost < 0 || $cost > 9999999999.99) throw new InvalidArgumentException('Check quantities and unit costs.');
            $lineTotal = round($quantity * $cost, 2);
            $total += $lineTotal;
            $packageLine = (new \App\Services\PackageInventoryService())->line($product, $item);
            $items[] = $packageLine + ['product_id' => $productId, 'quantity' => $quantity, 'quantity_received' => 0, 'unit_cost' => $cost, 'line_total' => $lineTotal];
        }
        if ($total > 9999999999.99) throw new InvalidArgumentException('The order total is too large.');
        $before = null;
        $isEdit = $purchaseId > 0;
        $database->transException(true)->transBegin();
        try {
            if ($purchaseId) {
                $purchase = $database->query('SELECT * FROM purchases WHERE id = ? AND branch_id = ? FOR UPDATE', [$purchaseId, $branchId])->getRowArray();
                if (!$purchase) throw new InvalidArgumentException('Purchase order not found.');
                $hasReceipts = $database->table('purchase_receipts')->where('purchase_id', $purchaseId)->countAllResults() > 0;
                $hasReceivedItems = $database->table('purchase_items')->where('purchase_id', $purchaseId)->where('quantity_received >', 0)->countAllResults() > 0;
                if ($purchase['status'] !== 'pending' || $hasReceipts || $hasReceivedItems) throw new InvalidArgumentException('Only orders without received goods can be edited.');
                if (empty($supplier['is_active']) && (int) $purchase['supplier_id'] !== $supplierId) throw new InvalidArgumentException('Select an active supplier.');
            } elseif (empty($supplier['is_active'])) {
                throw new InvalidArgumentException('Select an active supplier.');
            }
            $productIds = array_unique(array_column($items, 'product_id'));
            sort($productIds);
            foreach ($productIds as $productId) $database->query('SELECT id FROM products WHERE id=? FOR UPDATE', [$productId]);
            foreach ($items as $lineIndex => &$line) {
                $product = (new ProductModel())->find($line['product_id']);
                if (!$product || (new \App\Services\PackageInventoryService())->line($product, array_values($input['items'])[$lineIndex]) !== array_intersect_key($line, array_flip(['stock_mode','stock_factor','unit_label']))) throw new InvalidArgumentException('Product units changed. Reopen the purchase order.');
            }
            unset($line);
            if ($isEdit) $before = (new PurchaseModel())->findDetailed($purchaseId, $branchId);
            $fields = ['supplier_id' => $supplierId, 'reference_no' => $reference ?: null, 'notes' => $notes ?: null, 'expected_received_at' => $expected, 'total' => round($total, 2)];
            if ($purchaseId) {
                $database->table('purchases')->where('id', $purchaseId)->update($fields);
                $database->table('purchase_items')->where('purchase_id', $purchaseId)->delete();
            } else {
                $database->table('purchases')->insert($fields + ['branch_id' => $branchId, 'user_id' => $userId, 'status' => 'pending', 'purchased_at' => date('Y-m-d H:i:s')]);
                $purchaseId = (int) $database->insertID();
            }
            foreach ($items as &$item) $item['purchase_id'] = $purchaseId;
            unset($item);
            $database->table('purchase_items')->insertBatch($items);
            if (!$database->transStatus() || !$database->transCommit()) throw new \RuntimeException('TRANSACTION_FAILED');
        } catch (\Throwable $exception) {
            $database->transRollback();
            throw $exception;
        }
        $after = (new PurchaseModel())->findDetailed($purchaseId, $branchId);
        AuditService::log($isEdit ? 'purchase.update' : 'purchase.create', 'purchases', 'purchases', $purchaseId, before: $before, after: $after);
        return $after;
    }
}
