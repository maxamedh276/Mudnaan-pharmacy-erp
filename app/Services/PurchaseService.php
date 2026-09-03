<?php

namespace App\Services;

use App\Models\ProductModel;
use App\Models\PurchaseModel;
use InvalidArgumentException;

/**
 * PURCHASE ORDERS — what we ordered from a supplier, not what's arrived.
 *
 * Creating a purchase order books NOTHING: no stock movement, no journal
 * entry, no supplier bill. It just records the order at status 'pending'.
 * Stock, the books and the supplier's payable only move when goods are
 * actually received — see PurchaseReceiptService, which a purchase order
 * can be sent through as many times as it takes to arrive in full.
 */
class PurchaseService
{
    public function __construct(
        private readonly PurchaseModel $purchases = new PurchaseModel(),
        private readonly ProductModel $products = new ProductModel(),
    ) {
    }

    /**
     * @param array $data {supplier_id?, reference_no?, notes?,
     *                     items: [{product_id, quantity, unit_cost?}]}
     */
    public function createPurchase(array $data, int $branchId, int $userId): array
    {
        $itemsInput = $data['items'] ?? [];

        if (! is_array($itemsInput) || $itemsInput === []) {
            throw new InvalidArgumentException('EMPTY_PURCHASE');
        }

        $itemRows = [];
        $total    = 0.0;

        foreach ($itemsInput as $item) {
            $productId = (int) ($item['product_id'] ?? 0);
            $quantity  = (float) ($item['quantity'] ?? 0);

            if ($productId <= 0 || $quantity <= 0) {
                throw new InvalidArgumentException('INVALID_PURCHASE_ITEM');
            }

            $product = $this->products->find($productId);

            if ($product === null) {
                throw new InvalidArgumentException('PRODUCT_NOT_FOUND');
            }

            $unitCost  = round((float) ($item['unit_cost'] ?? $product['cost_price']), 2);
            $lineTotal = round($quantity * $unitCost, 2);
            $total    += $lineTotal;

            $itemRows[] = [
                'product_id'        => $productId,
                'batch_id'          => $item['batch_id'] ?? null,
                'quantity'          => $quantity,
                'quantity_received' => 0,
                'unit_cost'         => $unitCost,
                'line_total'        => $lineTotal,
            ];
        }

        $total = round($total, 2);

        $database = db_connect();
        $database->transException(true)->transStart();

        $purchaseId = (int) $this->purchases->insert([
            'branch_id'    => $branchId,
            'supplier_id'  => $data['supplier_id'] ?? null,
            'user_id'      => $userId,
            'reference_no' => $data['reference_no'] ?? null,
            'total'        => $total,
            'status'       => 'pending',
            'purchased_at' => $data['purchased_at'] ?? date('Y-m-d H:i:s'),
        ]);

        foreach ($itemRows as $index => $itemRow) {
            $itemRows[$index]['purchase_id'] = $purchaseId;
        }

        $database->table('purchase_items')->insertBatch($itemRows);

        $database->transComplete();

        return $this->purchases->findDetailed($purchaseId, $branchId);
    }
}
