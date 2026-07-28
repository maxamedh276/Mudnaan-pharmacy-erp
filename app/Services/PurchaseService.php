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
     * @param array $data {supplier_id?, reference_no?, paid_amount?, method?,
     *                     bank_account_id?, items: [{product_id, quantity, unit_cost?}]}
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

        $method = $data['method'] ?? 'cash';

        if (! in_array($method, ['cash', 'card', 'mobile_money', 'bank', 'cheque'], true)) {
            throw new InvalidArgumentException('INVALID_PAYMENT_METHOD');
        }

        $bankAccountId = isset($data['bank_account_id']) && (int) $data['bank_account_id'] > 0
            ? (int) $data['bank_account_id']
            : null;

        $database = db_connect();
        $database->transException(true)->transStart();

        $purchaseId = (int) $this->purchases->insert([
            'branch_id'       => $branchId,
            'supplier_id'     => $data['supplier_id'] ?? null,
            'user_id'         => $userId,
            'reference_no'    => $data['reference_no'] ?? null,
            'total'           => $total,
            'status'          => 'received',
            'method'          => $method,
            'bank_account_id' => $bankAccountId,
            'purchased_at'    => $data['purchased_at'] ?? date('Y-m-d H:i:s'),
        ]);

        foreach ($itemRows as $index => $itemRow) {
            $itemRows[$index]['purchase_id'] = $purchaseId;
        }

        $database->table('purchase_items')->insertBatch($itemRows);

        foreach ($itemRows as $itemRow) {
            $productId = (int) $itemRow['product_id'];

            // WEIGHTED-AVERAGE COST: fold the receipt into the product's
            // cost so stock valuation (qty × cost) and the Inventory
            // account (which rises by qty × unit_cost) stay identical.
            $this->reaverageCost($branchId, $productId, $itemRow['quantity'], (float) $itemRow['unit_cost']);

            $this->inventory->recordMovement(
                $branchId,
                $productId,
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

    /**
     * Updates a product's cost_price to the new weighted average after
     * receiving `qty` units at `unitCost`.
     */
    private function reaverageCost(int $branchId, int $productId, float $qty, float $unitCost): void
    {
        $onHand  = $this->inventory->currentQuantity($branchId, $productId);
        $oldCost = (float) ($this->products->find($productId)['cost_price'] ?? 0);
        $newQty  = $onHand + $qty;

        $newCost = $newQty > 0
            ? round(($onHand * $oldCost + $qty * $unitCost) / $newQty, 2)
            : round($unitCost, 2);

        $this->products->update($productId, ['cost_price' => $newCost]);
    }
}
