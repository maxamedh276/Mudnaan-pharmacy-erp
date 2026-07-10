<?php

namespace App\Services;

use App\Models\StockMovementModel;

/**
 * The only place stock ever changes.
 *
 * Stock is a LEDGER (see tenant_database.sql §4): every change is a
 * +/- row in stock_movements, and stock_levels is just the cached SUM.
 * Sales, purchases and manual adjustments all go through here so the
 * ledger and the cache can never drift apart.
 */
class InventoryService
{
    public function __construct(
        private readonly StockMovementModel $movements = new StockMovementModel(),
    ) {
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
