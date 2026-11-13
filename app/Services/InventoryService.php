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
        $database = db_connect();
        if (! $database->transException(true)->transBegin()) {
            throw new \RuntimeException('TRANSACTION_FAILED');
        }
        try {
            $database->query('SELECT id FROM products WHERE id = ? FOR UPDATE', [$productId]);
            $product = $this->products->find($productId);

            if ($product === null) {
                throw new InvalidArgumentException('PRODUCT_NOT_FOUND');
            }

            if ($qtyChange < 0 && $this->currentQuantity($branchId, $productId) + $qtyChange < 0) {
                throw new InvalidArgumentException('INSUFFICIENT_STOCK');
            }

            $value = round(abs($qtyChange) * (float) $product['cost_price'], 2);

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
            if (! $database->transStatus() || ! $database->transCommit()) {
                throw new \RuntimeException('TRANSACTION_FAILED');
            }
        } catch (\Throwable $exception) {
            $database->transRollback();
            throw $exception;
        }

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
        string $pharmacyPackState = 'any',
    ): void {
        $pharmacy = new \App\Services\Pharmacy\MedicineBatchService();
        if ($pharmacy->isMedicine($productId)) {
            if ($batchId === null && $qtyChange < 0 && $refType === 'sale') {
                $batches=$pharmacy->batches($branchId,$productId);
                if ($pharmacyPackState!=='any') $batches=array_values(array_filter($batches,static fn($batch)=>$pharmacyPackState==='opened' ? !empty($batch['opened_pack_id']) : empty($batch['opened_pack_id'])));
                foreach ($pharmacy::allocate($batches, abs($qtyChange), date('Y-m-d')) as $allocation) {
                    $this->recordMovement($branchId, $productId, -$allocation['quantity'], $reason, $refType, $refId, $userId, $allocation['batch_id']);
                }
                return;
            }
            if ($batchId === null && $qtyChange < 0 && $refType === 'purchase_return') {
                foreach ($pharmacy->supplierReturnAllocations($branchId, $productId, (int)$refId, abs($qtyChange)) as $allocation) {
                    $this->recordMovement($branchId, $productId, -$allocation['quantity'], $reason, $refType, $refId, $userId, $allocation['batch_id']);
                }
                return;
            }
            if ($batchId === null && $qtyChange > 0 && $refType === 'sale_return') {
                // Returned medicines enter a segregated, non-sellable lot for inspection.
                $db = db_connect();
                $db->table('product_batches')->insert(['branch_id' => $branchId, 'product_id' => $productId, 'batch_no' => 'RETURN-' . $refId . '-' . bin2hex(random_bytes(3))]);
                $batchId = (int)$db->insertID();
                $db->table('pharmacy_batch_states')->insert(['batch_id' => $batchId, 'status' => 'quarantine', 'reason' => 'Customer return; original document return ID ' . $refId]);
            }
            if ($batchId === null && $refType !== 'pharmacy_reconcile') throw new InvalidArgumentException('Medicine stock movements require a verified batch. Use Pharmacy stock.');
            if ($batchId !== null) $pharmacy->validateMovement($branchId, $productId, $batchId, $qtyChange, $refType === 'sale');
        }
        if ($qtyChange < 0 && !in_array($refType, ['sale', 'purchase_return', 'sealed_adjustment'], true)) {
            $product = $this->products->find($productId);
            if ($product) (new PackageInventoryService())->requireStock($branchId, $product, 'loose', abs($qtyChange));
        }
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
        $row = db_connect()->query(
            'SELECT quantity FROM stock_levels WHERE branch_id=? AND product_id=? FOR UPDATE',
            [$branchId, $productId],
        )->getRowArray();

        return (float) ($row['quantity'] ?? 0);
    }
}
