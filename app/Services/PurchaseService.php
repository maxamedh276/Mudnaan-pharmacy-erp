<?php

namespace App\Services;

use App\Models\ProductModel;
use App\Models\PurchaseModel;
use InvalidArgumentException;

/**
 * Goods received from a supplier — the flow that ADDS stock.
 * One transaction: purchase header + items + positive stock ledger
 * rows + accounting auto-posting (Premium).
 */
class PurchaseService
{
    public function __construct(
        private readonly PurchaseModel $purchases = new PurchaseModel(),
        private readonly ProductModel $products = new ProductModel(),
        private readonly InventoryService $inventory = new InventoryService(),
        private readonly AccountingService $accounting = new AccountingService(),
    ) {
    }

    /**
     * @param array $data {supplier_id?, reference_no?, paid_amount?,
     *                     items: [{product_id, quantity, unit_cost?}]}
     */
    public function createPurchase(array $data, int $branchId, int $userId, string $plan): array
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
                'product_id' => $productId,
                'batch_id'   => $item['batch_id'] ?? null,
                'quantity'   => $quantity,
                'unit_cost'  => $unitCost,
                'line_total' => $lineTotal,
            ];
        }

        $total = round($total, 2);
        // Unless told otherwise, the purchase is paid in full on receipt.
        $paidAmount = round((float) ($data['paid_amount'] ?? $total), 2);

        $database = db_connect();
        $database->transException(true)->transStart();

        $purchaseId = (int) $this->purchases->insert([
            'branch_id'    => $branchId,
            'supplier_id'  => $data['supplier_id'] ?? null,
            'user_id'      => $userId,
            'reference_no' => $data['reference_no'] ?? null,
            'total'        => $total,
            'status'       => 'received',
            'purchased_at' => $data['purchased_at'] ?? date('Y-m-d H:i:s'),
        ]);

        foreach ($itemRows as $index => $itemRow) {
            $itemRows[$index]['purchase_id'] = $purchaseId;
        }

        $database->table('purchase_items')->insertBatch($itemRows);

        foreach ($itemRows as $itemRow) {
            $this->inventory->recordMovement(
                $branchId,
                (int) $itemRow['product_id'],
                $itemRow['quantity'],
                'purchase',
                'purchase',
                $purchaseId,
                $userId,
            );
        }

        $purchase = $this->purchases->find($purchaseId);

        if ($plan === 'premium') {
            $this->accounting->postPurchase($purchase, min($paidAmount, $total), $branchId, $userId);
        }

        // The unpaid part becomes a payable document so the Supplier
        // Bills screen tracks it. Posting already happened above.
        if ($paidAmount < $total && ! empty($purchase['supplier_id'])) {
            (new PayablesService())->createFromPurchase($purchase, $paidAmount, $userId);
        }

        $database->transComplete();

        return $this->purchases->findDetailed($purchaseId, $branchId);
    }
}
