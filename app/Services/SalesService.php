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

        // A client uuid must be a proper 36-char UUID; anything else is
        // ignored (generate our own) so a malformed value can never
        // truncate into the CHAR(36) column and break idempotency.
        $uuid = $data['uuid'] ?? null;
        if ($uuid === null || ! preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', (string) $uuid)) {
            $uuid = Uuid::v4();
        }

        // Offline-sync safety: the same device-generated uuid can never
        // create two sales. Return the existing one instead of failing.
        $existing = $this->sales->where('uuid', $uuid)->first();

        if ($existing !== null && (int) $existing['branch_id'] !== $branchId) {
            throw new InvalidArgumentException('INVALID_SALE_ITEM');
        }
        if ($existing !== null) {
            return $this->sales->findDetailed((int) $existing['id'], $branchId);
        }

        $database = db_connect();
        try {
            if (! $database->transException(true)->transBegin()) {
                throw new \RuntimeException('TRANSACTION_FAILED');
            }
            $customer = null;
            if (isset($data['customer_id'])) {
                $customerId = filter_var($data['customer_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if (!$customerId) throw new InvalidArgumentException('CREDIT_REQUIRES_CUSTOMER');
                // Same lock order as collections/returns: customer, then products.
                $customer = (new CustomerCreditService())->lockCustomer($customerId);
                // A retry may have waited for the original sale to commit.
                $duplicate = $database->query('SELECT id FROM sales WHERE uuid=? AND branch_id=? FOR UPDATE', [$uuid, $branchId])->getRowArray();
                if ($duplicate) {
                    if (!$database->transStatus() || !$database->transCommit()) throw new RuntimeException('TRANSACTION_FAILED');
                    return $this->sales->findDetailed((int)$duplicate['id'], $branchId);
                }
            }
            // Lock products in ID order before reading their stock/cost.
            $productIds = array_unique(array_map(static fn ($item) => (int) ($item['product_id'] ?? 0), $itemsInput));
            sort($productIds);
            foreach ($productIds as $productId) {
                $database->query('SELECT id FROM products WHERE id = ? FOR UPDATE', [$productId]);
            }
            $activeTax = (new TaxRateService())->active();
            if (array_key_exists('tax_id', $data)) {
                $expectedTax = $data['tax_id'];
                if ($expectedTax !== null && (!is_scalar($expectedTax) || !ctype_digit((string)$expectedTax))) throw new InvalidArgumentException('INVALID_SALE_ITEM');
                if ((int)$expectedTax !== (int)($activeTax['id'] ?? 0)) throw new TaxChangedException($activeTax);
            }
            [$lineRows, $subtotal, $taxTotal, $costOfGoods] = $this->buildLines($itemsInput, $branchId, $activeTax);
            (new \App\Services\Pharmacy\DispensingService())->validate($data, $lineRows, $branchId);

            $saleDiscount = round((float) ($data['discount'] ?? 0), 2);
            $saleShipping = round((float) ($data['shipping'] ?? 0), 2);
            $grandTotal   = round($subtotal + $taxTotal - $saleDiscount + $saleShipping, 2);
            if (!is_numeric($data['discount']??0) || !is_numeric($data['shipping']??0) || !is_finite($saleDiscount) || !is_finite($saleShipping) || $saleDiscount<0 || $saleShipping<0 || $grandTotal<=0 || $grandTotal>999999999999.99 || !is_finite($grandTotal)) throw new InvalidArgumentException('INVALID_SALE_ITEM');

            if (!is_array($data['payments']??[])) throw new InvalidArgumentException('INVALID_PAYMENT_METHOD');
            $payments  = $this->normalizePayments($data['payments'] ?? [], $grandTotal);
            $paidTotal = round(array_sum(array_column($payments, 'amount')), 2);

            if ($paidTotal < $grandTotal && empty($data['customer_id'])) {
                // Credit needs someone to owe the money.
                throw new InvalidArgumentException('CREDIT_REQUIRES_CUSTOMER');
            }

            if ($customer !== null) (new CustomerCreditService())->assertAvailable($customer, max(0, $grandTotal - $paidTotal));

            $saleId = $this->persistSale(
                $database, $uuid, $data, $branchId, $userId, $plan,
                $lineRows, $subtotal, $taxTotal, $costOfGoods, $saleDiscount, $saleShipping,
                $grandTotal, $payments, $paidTotal,
            );

            if (! $database->transStatus() || ! $database->transCommit()) {
                throw new RuntimeException('TRANSACTION_FAILED');
            }
        } catch (\Throwable $exception) {
            $database->transRollback();
            // Concurrent double-submit of the same uuid: the losing
            // request returns the sale the winner just created.
            $winner = $this->sales->where('uuid', $uuid)->first();
            if ($exception instanceof \CodeIgniter\Database\Exceptions\DatabaseException && $winner !== null && (int) $winner['branch_id'] === $branchId) {
                return $this->sales->findDetailed((int) $winner['id'], $branchId);
            }
            throw $exception;
        }

        return $this->sales->findDetailed($saleId, $branchId);
    }

    /**
     * The transactional body of a sale: header + items + payments +
     * stock ledger + customer balance/invoice + accounting posting.
     */
    private function persistSale(
        $database,
        string $uuid,
        array $data,
        int $branchId,
        int $userId,
        string $plan,
        array $lineRows,
        float $subtotal,
        float $taxTotal,
        float $costOfGoods,
        float $saleDiscount,
        float $saleShipping,
        float $grandTotal,
        array $payments,
        float $paidTotal,
    ): int {
        $saleId = (int) $this->sales->insert([
            'uuid'        => $uuid,
            'branch_id'   => $branchId,
            'user_id'     => $userId,
            'customer_id' => $data['customer_id'] ?? null,
            'subtotal'    => $subtotal,
            'discount'    => $saleDiscount,
            'shipping'    => $saleShipping,
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
                'sale_id'         => $saleId,
                'method'          => $payment['method'],
                'account_id'      => $payment['account_id'] ?? null,
                'bank_account_id' => $payment['bank_account_id'] ?? null,
                'amount'          => $payment['amount'],
                'reference'       => $payment['reference'],
            ]);
        }

        foreach ($lineRows as $lineRow) {
            if ($lineRow['stock_mode'] === 'sealed') {
                (new PackageInventoryService())->record($branchId, (int)$lineRow['product_id'], -(int)$lineRow['quantity'], (float)$lineRow['stock_factor'], 'sale', $saleId, $userId);
            }
            $this->inventory->recordMovement(
                $branchId,
                (int) $lineRow['product_id'],
                -round($lineRow['quantity'] * $lineRow['stock_factor'], 3),
                'sale',
                'sale',
                $saleId,
                $userId,
                null,
                $lineRow['pharmacy_pack_state'] ?? 'any',
            );
        }

        // Credit portion goes onto the customer's account (- = owes us)
        // and becomes a receivable document (customer invoice).
        $unpaid = round($grandTotal - min($paidTotal, $grandTotal), 2);
        $sale   = $this->sales->find($saleId);

        if ($unpaid > 0) {
            $database->table('customers')
                ->where('id', (int) $data['customer_id'])
                ->set('balance', 'balance - ' . $unpaid, false)
                ->update();

            (new ReceivablesService())->createFromSale($sale, $userId);
        }

        if ($plan === 'premium') {
            $this->accounting->postSale($sale, $payments, $costOfGoods, $branchId, $userId);
        }

        (new \App\Services\Pharmacy\DispensingService())->record($data, $saleId, $branchId, $userId);

        return $saleId;
    }

    /**
     * Validates every line against the catalog and current stock, and
     * snapshots name/price so old receipts survive future price changes.
     *
     * @return array{0: list<array>, 1: float, 2: float, 3: float}
     *         [line rows, subtotal, tax total, cost of goods]
     */
    private function buildLines(array $itemsInput, int $branchId, ?array $activeTax = null): array
    {
        $lineRows    = [];
        $subtotal    = 0.0;
        $taxTotal    = 0.0;
        $costOfGoods = 0.0;
        $requested = [];

        foreach ($itemsInput as $item) {
            $productId = (int) ($item['product_id'] ?? 0);
            $quantity  = (float) ($item['quantity'] ?? 0);

            if ($productId <= 0 || !is_numeric($item['quantity']??null) || !is_finite($quantity) || $quantity <= 0 || $quantity>999999999.999 || abs($quantity-round($quantity,3))>0.0000001) {
                throw new InvalidArgumentException('INVALID_SALE_ITEM');
            }

            $product = $this->products->findWithStock($productId, $branchId);

            if ($product === null || ! $product['is_active']) {
                throw new InvalidArgumentException('PRODUCT_NOT_FOUND');
            }

            $packageService = new PackageInventoryService();
            $packageLine = $packageService->line($product, $item);
            // Sale-only snapshot: procurement keeps its existing packaging contract.
            if (db_connect()->tableExists('pharmacy_medicine_units') && db_connect()->fieldExists('pharmacy_pack_state','pharmacy_medicine_units')) {
                $unitQuery=db_connect()->table('pharmacy_medicine_units')->where('product_id',$productId);
                if (isset($item['medicine_unit_id'])) $unitQuery->where('id',$item['medicine_unit_id']);
                else $unitQuery->where('name',$product['unit'])->where('factor',1);
                $pharmacyUnit=$unitQuery->get()->getRowArray();
                if ($pharmacyUnit) $packageLine['pharmacy_pack_state']=$pharmacyUnit['pharmacy_pack_state'];
            }
            if (db_connect()->fieldExists('pharmacy_medicine_snapshot','sale_items')) {
                $profile=db_connect()->table('pharmacy_medicines')->select('generic_name,strength,dosage_form,route_of_administration,active_ingredients')->where('product_id',$productId)->get()->getRowArray();
                if ($profile) {$profile['active_ingredients']=json_decode($profile['active_ingredients']??'[]',true)??[];$packageLine['pharmacy_medicine_snapshot']=json_encode($profile,JSON_THROW_ON_ERROR);}
            }
            $mode = $packageLine['stock_mode'];
            $poolKey = $productId . ':' . $mode;
            $requested[$poolKey] = round(($requested[$poolKey] ?? 0) + $quantity, 3);
            $packageService->requireStock($branchId, $product, $mode, $requested[$poolKey]);
            $baseQuantity = round($quantity * $packageLine['stock_factor'], 3);
            $requested[$productId] = round(($requested[$productId] ?? 0) + $baseQuantity, 3);
            if ($product['quantity'] < $requested[$productId]) {
                throw new RuntimeException('INSUFFICIENT_STOCK');
            }

            $defaultPrice = $mode === 'sealed' ? $product['package_price'] : $product['sell_price'];
            if (isset($item['medicine_unit_id'])) $defaultPrice = (new \App\Services\Pharmacy\MedicineUnitService())->resolve($productId, $item['medicine_unit_id'])['sell_price'];
            $unitPrice    = round((float) ($item['unit_price'] ?? $defaultPrice), 2);
            $lineDiscount = round((float) ($item['discount'] ?? 0), 2);
            if (!is_numeric($item['unit_price']??$defaultPrice) || !is_numeric($item['discount']??0) || !is_finite($unitPrice)||!is_finite($lineDiscount)||$unitPrice<0||$lineDiscount<0||$lineDiscount>$quantity*$unitPrice) throw new InvalidArgumentException('INVALID_SALE_ITEM');
            if (!empty($item['pharmacy_discount_reason'])) $packageLine['pharmacy_discount_reason']=\App\Services\Pharmacy\PharmacyInput::text($item,'pharmacy_discount_reason',300,true);
            $lineNet      = round($quantity * $unitPrice - $lineDiscount, 2);
            $taxRate      = $activeTax === null ? (float)$product['tax_rate'] : (float)$activeTax['percentage'];
            $lineTax      = round($lineNet * $taxRate / 100, 2);

            $subtotal    += $lineNet;
            $taxTotal    += $lineTax;
            $costOfGoods += round($baseQuantity * $product['cost_price'], 2);

            $lineRows[] = $packageLine + [
                'product_id' => $productId,
                'batch_id'   => $item['batch_id'] ?? null,
                'serial_id'  => $item['serial_id'] ?? null,
                'description'=> $product['name'] . ' (' . $packageLine['unit_label'] . ')',
                'quantity'   => $quantity,
                'unit_price' => $unitPrice,
                'discount'   => $lineDiscount,
                'tax_rate'   => $taxRate,
                'line_total' => round($lineNet + $lineTax, 2),
                'cost_total' => round($baseQuantity * (float)$product['cost_price'], 2),
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
            if (!is_array($payment)) throw new InvalidArgumentException('INVALID_PAYMENT_METHOD');
            $method = $payment['method'] ?? 'cash';
            $amount = round((float) ($payment['amount'] ?? 0), 2);

            if (! in_array($method, $validMethods, true)) {
                throw new InvalidArgumentException('INVALID_PAYMENT_METHOD');
            }

            if (!is_numeric($payment['amount']??0)||!is_finite($amount)||$amount<0||$amount>999999999999.99) throw new InvalidArgumentException('INVALID_PAYMENT_METHOD');
            // 'credit' rows carry no money — the unpaid balance goes to
            // the customer's account instead.
            if ($method === 'credit' || $amount <= 0) {
                continue;
            }

            $payments[] = [
                'method'          => $method,
                'amount'          => $amount,
                'account_id'      => isset($payment['account_id']) && (int) $payment['account_id'] > 0
                    ? (int) $payment['account_id']
                    : null,
                'bank_account_id' => isset($payment['bank_account_id']) && (int) $payment['bank_account_id'] > 0
                    ? (int) $payment['bank_account_id']
                    : null,
                'reference'       => $payment['reference'] ?? null,
            ];
        }

        return $payments;
    }
}
