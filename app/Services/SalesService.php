<?php

namespace App\Services;

use App\Libraries\Uuid;
use App\Models\ProductModel;
use App\Models\SaleModel;
use InvalidArgumentException;
use RuntimeException;

/**
 * The "Complete Sale" flow — all-or-nothing inside one DB transaction:
 *   1. validate items and stock
 *   2. save the sale header + line items + payments
 *   3. write -qty stock ledger rows
 *   4. auto-post the journal entry (Premium tenants only)
 *
 * Totals are always recomputed on the server from the line items;
 * client-sent totals are never trusted.
 */
class SalesService
{
    public function __construct(
        private readonly SaleModel $sales = new SaleModel(),
        private readonly ProductModel $products = new ProductModel(),
        private readonly InventoryService $inventory = new InventoryService(),
        private readonly AccountingService $accounting = new AccountingService(),
    ) {
    }

    /**
     * @param array $data {customer_id?, uuid?, discount?, sold_at?,
     *                     items: [{product_id, quantity, unit_price?, discount?}],
     *                     payments: [{method, amount, reference?}]}
     *
     * @throws InvalidArgumentException with a machine-readable code as message
     */
    public function createSale(array $data, int $branchId, int $userId, string $plan): array
    {
        $itemsInput = $data['items'] ?? [];

        if (! is_array($itemsInput) || $itemsInput === []) {
            throw new InvalidArgumentException('EMPTY_SALE');
        }

        $uuid = $data['uuid'] ?? Uuid::v4();

        // Offline-sync safety: the same device-generated uuid can never
        // create two sales. Return the existing one instead of failing.
        $existing = $this->sales->where('uuid', $uuid)->first();

        if ($existing !== null) {
            return $this->sales->findDetailed((int) $existing['id'], $branchId);
        }

        [$lineRows, $subtotal, $taxTotal, $costOfGoods] = $this->buildLines($itemsInput, $branchId);

        $saleDiscount = round((float) ($data['discount'] ?? 0), 2);
        $grandTotal   = round($subtotal + $taxTotal - $saleDiscount, 2);

        $payments  = $this->normalizePayments($data['payments'] ?? [], $grandTotal);
        $paidTotal = round(array_sum(array_column($payments, 'amount')), 2);

        if ($paidTotal < $grandTotal && empty($data['customer_id'])) {
            // Credit needs someone to owe the money.
            throw new InvalidArgumentException('CREDIT_REQUIRES_CUSTOMER');
        }

        $database = db_connect();
        $database->transException(true)->transStart();

        $saleId = (int) $this->sales->insert([
            'uuid'        => $uuid,
            'branch_id'   => $branchId,
            'user_id'     => $userId,
            'customer_id' => $data['customer_id'] ?? null,
            'subtotal'    => $subtotal,
            'discount'    => $saleDiscount,
            'tax_total'   => $taxTotal,
            'grand_total' => $grandTotal,
            'paid_total'  => min($paidTotal, $grandTotal),
            'status'      => $paidTotal >= $grandTotal ? 'completed' : 'partial',
            'sold_at'     => $data['sold_at'] ?? date('Y-m-d H:i:s'),
        ]);

        $invoiceNo = 'INV-' . str_pad((string) $saleId, 5, '0', STR_PAD_LEFT);
        $this->sales->update($saleId, ['invoice_no' => $invoiceNo]);

        foreach ($lineRows as $index => $lineRow) {
            $lineRows[$index]['sale_id'] = $saleId;
        }

        $database->table('sale_items')->insertBatch($lineRows);

        foreach ($payments as $payment) {
            $database->table('payments')->insert([
                'sale_id'   => $saleId,
                'method'    => $payment['method'],
                'amount'    => $payment['amount'],
                'reference' => $payment['reference'],
            ]);
        }

        foreach ($lineRows as $lineRow) {
            $this->inventory->recordMovement(
                $branchId,
                (int) $lineRow['product_id'],
                -$lineRow['quantity'],
                'sale',
                'sale',
                $saleId,
                $userId,
            );
        }

        // Credit portion goes onto the customer's account (- = owes us).
        $unpaid = round($grandTotal - min($paidTotal, $grandTotal), 2);

        if ($unpaid > 0) {
            $database->table('customers')
                ->where('id', (int) $data['customer_id'])
                ->set('balance', 'balance - ' . $unpaid, false)
                ->update();
        }

        if ($plan === 'premium') {
            $sale = $this->sales->find($saleId);
            $this->accounting->postSale($sale, $costOfGoods, $branchId, $userId);
        }

        $database->transComplete();

        return $this->sales->findDetailed($saleId, $branchId);
    }

    /**
     * Validates every line against the catalog and current stock, and
     * snapshots name/price so old receipts survive future price changes.
     *
     * @return array{0: list<array>, 1: float, 2: float, 3: float}
     *         [line rows, subtotal, tax total, cost of goods]
     */
    private function buildLines(array $itemsInput, int $branchId): array
    {
        $lineRows    = [];
        $subtotal    = 0.0;
        $taxTotal    = 0.0;
        $costOfGoods = 0.0;

        foreach ($itemsInput as $item) {
            $productId = (int) ($item['product_id'] ?? 0);
            $quantity  = (float) ($item['quantity'] ?? 0);

            if ($productId <= 0 || $quantity <= 0) {
                throw new InvalidArgumentException('INVALID_SALE_ITEM');
            }

            $product = $this->products->findWithStock($productId, $branchId);

            if ($product === null || ! $product['is_active']) {
                throw new InvalidArgumentException('PRODUCT_NOT_FOUND');
            }

            if ($product['quantity'] < $quantity) {
                throw new RuntimeException('INSUFFICIENT_STOCK');
            }

            $unitPrice    = round((float) ($item['unit_price'] ?? $product['sell_price']), 2);
            $lineDiscount = round((float) ($item['discount'] ?? 0), 2);
            $lineNet      = round($quantity * $unitPrice - $lineDiscount, 2);
            $lineTax      = round($lineNet * $product['tax_rate'] / 100, 2);

            $subtotal    += $lineNet;
            $taxTotal    += $lineTax;
            $costOfGoods += round($quantity * $product['cost_price'], 2);

            $lineRows[] = [
                'product_id' => $productId,
                'batch_id'   => $item['batch_id'] ?? null,
                'serial_id'  => $item['serial_id'] ?? null,
                'description'=> $product['name'],
                'quantity'   => $quantity,
                'unit_price' => $unitPrice,
                'discount'   => $lineDiscount,
                'tax_rate'   => $product['tax_rate'],
                'line_total' => round($lineNet + $lineTax, 2),
            ];
        }

        return [$lineRows, round($subtotal, 2), round($taxTotal, 2), round($costOfGoods, 2)];
    }

    /**
     * @return list<array{method: string, amount: float, reference: ?string}>
     */
    private function normalizePayments(array $paymentsInput, float $grandTotal): array
    {
        $validMethods = ['cash', 'card', 'mobile_money', 'bank', 'credit'];
        $payments     = [];

        foreach ($paymentsInput as $payment) {
            $method = $payment['method'] ?? 'cash';
            $amount = round((float) ($payment['amount'] ?? 0), 2);

            if (! in_array($method, $validMethods, true)) {
                throw new InvalidArgumentException('INVALID_PAYMENT_METHOD');
            }

            // 'credit' rows carry no money — the unpaid balance goes to
            // the customer's account instead.
            if ($method === 'credit' || $amount <= 0) {
                continue;
            }

            $payments[] = [
                'method'    => $method,
                'amount'    => $amount,
                'reference' => $payment['reference'] ?? null,
            ];
        }

        return $payments;
    }
}
