<?php

namespace App\Services;

use App\Libraries\Uuid;
use App\Models\ProductModel;
use InvalidArgumentException;

/**
 * GOODS RECEIVING — the only place a purchase order's stock and books
 * actually move.
 *
 * PurchaseService::createPurchase() books nothing: a purchase order just
 * records what was ordered, at status 'pending'. A receipt records what
 * physically arrived — possibly less than ordered, possibly one of
 * several partial batches — and is what moves the stock ledger, grows
 * the supplier's payable (via PayablesService::growBillFromReceipt), and
 * posts the journal entry (Premium). The purchase's status rolls forward
 * from the sum of quantity_received across its lines: pending ->
 * partially_received -> received.
 */
class PurchaseReceiptService
{
    public function __construct(
        private readonly InventoryService $inventory = new InventoryService(),
        private readonly AccountingService $accounting = new AccountingService(),
        private readonly PayablesService $payables = new PayablesService(),
        private readonly ProductModel $products = new ProductModel(),
    ) {
    }

    /**
     * @param array $data {received_at?, notes?,
     *                     items: [{purchase_item_id, quantity}]}
     */
    public function createReceipt(int $purchaseId, array $data, int $branchId, int $userId, string $plan): array
    {
        $database = db_connect();

        if (! $database->transException(true)->transBegin()) {
            throw new \RuntimeException('TRANSACTION_FAILED');
        }
        try {
            $purchase = $database->query('SELECT * FROM purchases WHERE id = ? AND branch_id = ? FOR UPDATE', [$purchaseId, $branchId])->getRowArray();

            if ($purchase === null) {
                throw new InvalidArgumentException('PURCHASE_NOT_FOUND');
            }

            if (! in_array($purchase['status'], ['pending', 'partially_received'], true)) {
                throw new InvalidArgumentException('PURCHASE_NOT_RECEIVABLE');
            }

            $itemsInput = $data['items'] ?? [];

            if (! is_array($itemsInput) || $itemsInput === []) {
                throw new InvalidArgumentException('EMPTY_RECEIPT');
            }

            $purchaseItemsById = [];

            foreach ($database->table('purchase_items')->where('purchase_id', $purchaseId)->get()->getResultArray() as $row) {
                $purchaseItemsById[(int) $row['id']] = $row;
            }

            $productIds = array_unique(array_column($purchaseItemsById, 'product_id'));
            sort($productIds);
            foreach ($productIds as $productId) {
                $database->query('SELECT id FROM products WHERE id = ? FOR UPDATE', [$productId]);
            }
            $receiptItemRows = [];
            $value           = 0.0;
            $seenLines = [];

            foreach ($itemsInput as $item) {
                $purchaseItemId = (int) ($item['purchase_item_id'] ?? 0);
                $quantity       = round((float) ($item['quantity'] ?? 0), 3);

                if (!is_numeric($item['quantity'] ?? null) || !is_finite($quantity) || $quantity <= 0 || abs((float)$item['quantity'] - $quantity) > 0.0000001 || ! isset($purchaseItemsById[$purchaseItemId])) {
                    throw new InvalidArgumentException('INVALID_RECEIPT_ITEM');
                }

                if (isset($seenLines[$purchaseItemId])) {
                    throw new InvalidArgumentException('INVALID_RECEIPT_ITEM');
                }
                $seenLines[$purchaseItemId] = true;
                $line      = $purchaseItemsById[$purchaseItemId];
                $packageService = new PackageInventoryService();
                if (($line['stock_mode'] ?? 'loose') === 'sealed' && floor($quantity) != $quantity) throw new InvalidArgumentException('INVALID_RECEIPT_ITEM');
                $remaining = round((float) $line['quantity'] - (float) $line['quantity_received'], 3);

                if ($quantity > $remaining) {
                    throw new InvalidArgumentException('OVER_RECEIPT');
                }

                $unitCost  = (float) $line['unit_cost'];
                $lineTotal = round($quantity * $unitCost, 2);
                $value    += $lineTotal;

                $batchFields = $database->fieldExists('batch_id', 'purchase_receipt_items') ? ['batch_id' => null] : [];
                $pharmacy = new \App\Services\Pharmacy\MedicineBatchService();
                if ($pharmacy->isMedicine((int)$line['product_id'])) {
                    $batchFields['batch_id'] = $pharmacy->create($branchId, (int)$line['product_id'], $item);
                }
                $receiptItemRows[] = $batchFields + [
                    'purchase_item_id' => $purchaseItemId,
                    'product_id'       => (int) $line['product_id'],
                    'quantity'         => $quantity,
                    'unit_cost'        => $unitCost,
                    'line_total'       => $lineTotal,
                ];
            }

            $database->table('purchase_receipts')->insert([
                'uuid'        => $data['uuid'] ?? Uuid::v4(),
                'branch_id'   => $branchId,
                'purchase_id' => $purchaseId,
                'received_at' => $data['received_at'] ?? date('Y-m-d H:i:s'),
                'notes'       => $data['notes'] ?? null,
                'created_by'  => $userId,
            ]);

            $receiptId = (int) $database->insertID();
            $database->table('purchase_receipts')->where('id', $receiptId)
                ->update(['receipt_no' => 'GRN-' . str_pad((string) $receiptId, 5, '0', STR_PAD_LEFT)]);

            foreach ($receiptItemRows as $index => $row) {
                $receiptItemRows[$index]['receipt_id'] = $receiptId;
            }

            $database->table('purchase_receipt_items')->insertBatch($receiptItemRows);

            foreach ($receiptItemRows as $row) {
                $productId = (int) $row['product_id'];
                $quantity  = (float) $row['quantity'];
                $sourceLine = $purchaseItemsById[(int)$row['purchase_item_id']];
                $factor = (float)($sourceLine['stock_factor'] ?? 1);
                $baseQuantity = round($quantity * $factor, 3);
                if (($sourceLine['stock_mode'] ?? 'loose') === 'sealed') {
                    $packageService->record($branchId, $productId, (int)$quantity, $factor, 'purchase', $receiptId, $userId);
                }

                // Weighted-average cost — folded in per receipt, at exactly
                // the value that's actually landing in stock right now.
                $this->reaverageCost($branchId, $productId, $baseQuantity, (float)$row['unit_cost'] / $factor);

                $this->inventory->recordMovement(
                    $branchId,
                    $productId,
                    $baseQuantity,
                    'purchase',
                    'purchase_receipt',
                    $receiptId,
                    $userId,
                    isset($row['batch_id']) ? (int)$row['batch_id'] : null,
                );

                $database->table('purchase_items')->where('id', $row['purchase_item_id'])
                    ->set('quantity_received', 'quantity_received + ' . $quantity, false)
                    ->update();
            }

            // Roll the PO's status forward from the lines' running totals.
            $totals = $database->table('purchase_items')
                ->select('SUM(quantity) AS ordered, SUM(quantity_received) AS received')
                ->where('purchase_id', $purchaseId)
                ->get()
                ->getRowArray();

            $ordered  = round((float) $totals['ordered'], 3);
            $received = round((float) $totals['received'], 3);
            $status   = $received >= $ordered ? 'received' : ($received > 0 ? 'partially_received' : 'pending');

            $database->table('purchases')->where('id', $purchaseId)->update(['status' => $status]);

            $receipt = $database->table('purchase_receipts')->where('id', $receiptId)->get()->getRowArray();

            // The supplier only owes for what actually arrived.
            $this->payables->growBillFromReceipt($purchase, $receipt, $value, $userId);

            if ($plan === 'premium') {
                $this->accounting->postPurchaseReceipt($receipt, $value, $branchId, $userId);
            }
            if (! $database->transStatus() || ! $database->transCommit()) {
                throw new \RuntimeException('TRANSACTION_FAILED');
            }
        } catch (\Throwable $exception) {
            $database->transRollback();
            throw $exception;
        }

        AuditService::log('purchase_receipt.create', 'purchases', 'purchase_receipts', $receiptId, details: [
            'purchase_id' => $purchaseId, 'value' => $value, 'status' => $status,
        ]);

        return $this->findReceipt($receiptId, $branchId);
    }

    public function listReceipts(int $purchaseId, int $branchId): array
    {
        $rows = db_connect()->table('purchase_receipts')
            ->where('purchase_id', $purchaseId)
            ->where('branch_id', $branchId)
            ->orderBy('id', 'DESC')
            ->get()
            ->getResultArray();

        return array_map(static function (array $row): array {
            $row['id']          = (int) $row['id'];
            $row['purchase_id'] = (int) $row['purchase_id'];

            return $row;
        }, $rows);
    }

    public function findReceipt(int $receiptId, int $branchId): ?array
    {
        $database = db_connect();

        $receipt = $database->table('purchase_receipts')
            ->where('id', $receiptId)
            ->where('branch_id', $branchId)
            ->get()
            ->getRowArray();

        if ($receipt === null) {
            return null;
        }

        $receipt['id']          = (int) $receipt['id'];
        $receipt['purchase_id'] = (int) $receipt['purchase_id'];

        $receipt['items'] = array_map(static function (array $item): array {
            $item['id']         = (int) $item['id'];
            $item['product_id'] = (int) $item['product_id'];
            $item['quantity']   = (float) $item['quantity'];
            $item['unit_cost']  = (float) $item['unit_cost'];
            $item['line_total'] = (float) $item['line_total'];

            return $item;
        }, $database->table('purchase_receipt_items')
            ->select('purchase_receipt_items.*, products.name AS product_name')
            ->join('products', 'products.id = purchase_receipt_items.product_id')
            ->where('receipt_id', $receiptId)
            ->get()
            ->getResultArray());

        return $receipt;
    }

    /**
     * Updates a product's cost_price to the new weighted average after
     * receiving `qty` units at `unitCost` — the same reasoning that used
     * to live in PurchaseService, just triggered per receipt now instead
     * of per purchase order.
     */
    private function reaverageCost(int $branchId, int $productId, float $qty, float $unitCost): void
    {
        $onHand  = $this->inventory->currentQuantity($branchId, $productId);
        $oldCost = (float) ($this->products->find($productId)['cost_price'] ?? 0);
        $newQty  = $onHand + $qty;

        $newCost = $newQty > 0
            ? round(($onHand * $oldCost + $qty * $unitCost) / $newQty, 6)
            : round($unitCost, 6);

        $this->products->update($productId, ['cost_price' => $newCost]);
    }
}
