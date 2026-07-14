<?php

namespace App\Services;

use App\Models\ProductModel;
use App\Models\StockMovementModel;
use InvalidArgumentException;

/**
 * The only place stock ever changes.
 *
 * Stock is a LEDGER (see tenant_database.sql §4): every change is a
 * +/- row in stock_movements, and stock_levels is just the cached SUM.
 * Sales, purchases and manual adjustments all go through here so the
 * ledger and the cache can never drift apart. Manual adjustments also
 * post to the books (Inventory <-> Inventory Adjustment) so stock value
 * and the ledger stay reconciled.
 */
class InventoryService
{
    public function __construct(
        private readonly StockMovementModel $movements = new StockMovementModel(),
        private readonly AccountingService $accounting = new AccountingService(),
        private readonly ProductModel $products = new ProductModel(),
    ) {
    }

    /**
     * A manual stock adjustment (write-off, count correction, stock-in).
     * All-or-nothing: guards negative stock, writes the ledger row,
     * posts the journal at cost (Premium), and audits it.
     *
     * @throws InvalidArgumentException PRODUCT_NOT_FOUND | INSUFFICIENT_STOCK
     * @return array the product with its new stock level + the value moved
     */
    public function adjustStock(
        int $branchId,
        int $productId,
        float $qtyChange,
        ?string $note,
        ?int $userId,
        string $plan,
    ): array {
        $product = $this->products->find($productId);

        if ($product === null) {
            throw new InvalidArgumentException('PRODUCT_NOT_FOUND');
        }

        if ($qtyChange < 0 && $this->currentQuantity($branchId, $productId) + $qtyChange < 0) {
            throw new InvalidArgumentException('INSUFFICIENT_STOCK');
        }

        $value = round(abs($qtyChange) * (float) $product['cost_price'], 2);

        $database = db_connect();
        $database->transException(true)->transStart();

        $this->recordMovement(
            $branchId,
            $productId,
            $qtyChange,
            'adjustment',
            'adjustment',
            null,
            $userId,
        );

        if ($plan === 'premium') {
            $this->accounting->postInventoryAdjustment(
                $branchId,
                $value,
                $qtyChange > 0,
                $productId,
                $userId,
            );
        }

        $database->transComplete();

        AuditService::log(
            'stock.adjust',
            'inventory',
            'stock_movements',
            $productId,
            after: ['qty_change' => $qtyChange, 'value' => $value, 'note' => $note],
        );

        return [
            'product' => $this->products->findWithStock($productId, $branchId),
            'value'   => $value,
        ];
    }

    /**
     * Appends one ledger row and updates the cached level atomically.
     * Positive $qtyChange = stock in, negative = stock out.
     */
    public function recordMovement(
        int $branchId,
        int $productId,
        float $qtyChange,
        string $reason,
        ?string $refType = null,
        ?int $refId = null,
        ?int $userId = null,
        ?int $batchId = null,
    ): void {
        $this->movements->insert([
            'branch_id'  => $branchId,
            'product_id' => $productId,
            'batch_id'   => $batchId,
            'qty_change' => $qtyChange,
            'reason'     => $reason,
            'ref_type'   => $refType,
            'ref_id'     => $refId,
            'user_id'    => $userId,
        ]);

        db_connect()->query(
            'INSERT INTO stock_levels (branch_id, product_id, quantity)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity)',
            [$branchId, $productId, $qtyChange],
        );
    }

    public function currentQuantity(int $branchId, int $productId): float
    {
        $row = db_connect()->table('stock_levels')
            ->select('quantity')
            ->where('branch_id', $branchId)
            ->where('product_id', $productId)
            ->get()
            ->getRowArray();

        return (float) ($row['quantity'] ?? 0);
    }
}
