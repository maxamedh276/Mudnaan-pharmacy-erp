<?php

namespace App\Services;

use App\Libraries\Uuid;
use App\Models\ProductModel;
use InvalidArgumentException;
use RuntimeException;

/**
 * ACCOUNTS RECEIVABLE — customer invoices, the receipts that settle
 * them, and customer deposits (advances / store credit).
 *
 * Invoices are born two ways:
 *  1. AUTOMATICALLY from a POS sale that wasn't fully paid
 *     (SalesService calls createFromSale; no posting, no stock — the
 *     sale already did both).
 *  2. STANDALONE, issued directly. Product lines check + move stock
 *     and post COGS exactly like a sale; service lines don't.
 *     Posting: DEBIT AR / CREDIT Revenue (+ Tax) (+ COGS/Inventory).
 *
 * Receipts post DEBIT Cash-or-Bank / CREDIT AR and raise the
 * customer's balance (less owed). Deposits post DEBIT Cash-or-Bank /
 * CREDIT Customer Deposits (a liability) and raise the balance too.
 */
class ReceivablesService
{
    public function __construct(
        private readonly ProductModel $products = new ProductModel(),
        private readonly InventoryService $inventory = new InventoryService(),
        private readonly AccountingService $accounting = new AccountingService(),
    ) {
    }

    // ----------------------------------------------------------------
    //  READS
    // ----------------------------------------------------------------

    public function listInvoices(int $branchId, array $filters, int $page, int $perPage): array
    {
        $builder = db_connect()->table('customer_invoices')
            ->select('customer_invoices.id, customer_invoices.invoice_no,
                      customer_invoices.customer_id, customers.name AS customer_name,
                      customer_invoices.sale_id, customer_invoices.invoice_date,
                      customer_invoices.due_date, customer_invoices.total,
                      customer_invoices.paid_total, customer_invoices.status')
            ->join('customers', 'customers.id = customer_invoices.customer_id')
            ->where('customer_invoices.branch_id', $branchId)
            ->where('customer_invoices.deleted_at', null);

        if (! empty($filters['status'])) {
            $builder->where('customer_invoices.status', $filters['status']);
        }

        if (! empty($filters['customer_id'])) {
            $builder->where('customer_invoices.customer_id', (int) $filters['customer_id']);
        }

        if (! empty($filters['q'])) {
            $builder->like('customer_invoices.invoice_no', $filters['q']);
        }

        $total = $builder->countAllResults(false);
        $rows  = $builder->orderBy('customer_invoices.id', 'DESC')
            ->limit($perPage, ($page - 1) * $perPage)
            ->get()
            ->getResultArray();

        return ['items' => array_map([self::class, 'castInvoiceRow'], $rows), 'total' => $total];
    }

    public function findInvoice(int $invoiceId, int $branchId): ?array
    {
        $database = db_connect();

        $invoice = $database->table('customer_invoices')
            ->select('customer_invoices.*, customers.name AS customer_name')
            ->join('customers', 'customers.id = customer_invoices.customer_id')
            ->where('customer_invoices.id', $invoiceId)
            ->where('customer_invoices.branch_id', $branchId)
            ->where('customer_invoices.deleted_at', null)
            ->get()
            ->getRowArray();

        if ($invoice === null) {
            return null;
        }

        $invoice = self::castInvoiceRow($invoice);

        $invoice['items'] = array_map(static function (array $item): array {
            $item['id']         = (int) $item['id'];
            $item['product_id'] = $item['product_id'] === null ? null : (int) $item['product_id'];
            $item['quantity']   = (float) $item['quantity'];
            $item['unit_price'] = (float) $item['unit_price'];
            $item['discount']   = (float) $item['discount'];
            $item['tax_rate']   = (float) $item['tax_rate'];
            $item['line_total'] = (float) $item['line_total'];

            return $item;
        }, $database->table('customer_invoice_items')
            ->select('id, product_id, description, quantity, unit_price, discount, tax_rate, line_total')
            ->where('invoice_id', $invoiceId)
            ->get()
            ->getResultArray());

        $invoice['receipts'] = array_map(static function (array $receipt): array {
            $receipt['id']     = (int) $receipt['id'];
            $receipt['amount'] = (float) $receipt['amount'];

            return $receipt;
        }, $database->table('customer_receipts')
            ->select('id, receipt_no, method, amount, reference, received_at')
            ->where('invoice_id', $invoiceId)
            ->get()
            ->getResultArray());

        return $invoice;
    }

    public function listReceipts(int $branchId, array $filters, int $page, int $perPage): array
    {
        $builder = db_connect()->table('customer_receipts')
            ->select('customer_receipts.id, customer_receipts.receipt_no,
                      customer_receipts.customer_id, customers.name AS customer_name,
                      customer_receipts.invoice_id, customer_invoices.invoice_no,
                      customer_receipts.method, customer_receipts.amount,
                      customer_receipts.reference, customer_receipts.status,
                      customer_receipts.received_at')
            ->join('customers', 'customers.id = customer_receipts.customer_id')
            ->join('customer_invoices', 'customer_invoices.id = customer_receipts.invoice_id', 'left')
            ->where('customer_receipts.branch_id', $branchId);

        if (! empty($filters['customer_id'])) {
            $builder->where('customer_receipts.customer_id', (int) $filters['customer_id']);
        }

        $total = $builder->countAllResults(false);
        $rows  = $builder->orderBy('customer_receipts.id', 'DESC')
            ->limit($perPage, ($page - 1) * $perPage)
            ->get()
            ->getResultArray();

        foreach ($rows as &$row) {
            $row['id']         = (int) $row['id'];
            $row['invoice_id'] = $row['invoice_id'] === null ? null : (int) $row['invoice_id'];
            $row['amount']     = (float) $row['amount'];
        }

        return ['items' => $rows, 'total' => $total];
    }

    public function listDeposits(int $branchId, array $filters, int $page, int $perPage): array
    {
        $builder = db_connect()->table('customer_deposits')
            ->select('customer_deposits.id, customer_deposits.deposit_no,
                      customer_deposits.customer_id, customers.name AS customer_name,
                      customer_deposits.method, customer_deposits.amount,
                      customer_deposits.used_amount, customer_deposits.status,
                      customer_deposits.deposited_at')
            ->join('customers', 'customers.id = customer_deposits.customer_id')
            ->where('customer_deposits.branch_id', $branchId);

        if (! empty($filters['status'])) {
            $builder->where('customer_deposits.status', $filters['status']);
        }

        if (! empty($filters['customer_id'])) {
            $builder->where('customer_deposits.customer_id', (int) $filters['customer_id']);
        }

        $total = $builder->countAllResults(false);
        $rows  = $builder->orderBy('customer_deposits.id', 'DESC')
            ->limit($perPage, ($page - 1) * $perPage)
            ->get()
            ->getResultArray();

        foreach ($rows as &$row) {
            $row['id']          = (int) $row['id'];
            $row['amount']      = (float) $row['amount'];
            $row['used_amount'] = (float) $row['used_amount'];
            $row['available']   = round($row['amount'] - $row['used_amount'], 2);
        }

        return ['items' => $rows, 'total' => $total];
    }

    // ----------------------------------------------------------------
    //  WRITES
    // ----------------------------------------------------------------

    /**
     * Standalone invoice. Product lines validate + move stock and
     * carry COGS; free-text lines are services.
     *
     * @param array $data {customer_id, invoice_date?, due_date?, discount?, notes?,
     *                     items: [{product_id?, description?, quantity, unit_price?, discount?}]}
     */
    public function createInvoice(array $data, int $branchId, int $userId, string $plan): array
    {
        $database = db_connect();
        if (! $database->transException(true)->transBegin()) {
            throw new \RuntimeException('TRANSACTION_FAILED');
        }
        try {
            $customerId = (int) ($data['customer_id'] ?? 0);
            $itemsInput = $data['items'] ?? [];

            if ($customerId <= 0) {
                throw new InvalidArgumentException('CUSTOMER_REQUIRED');
            }

            $creditCustomer = (new CustomerCreditService())->lockCustomer($customerId);

            if (! is_array($itemsInput) || $itemsInput === []) {
                throw new InvalidArgumentException('EMPTY_INVOICE');
            }

            $itemRows    = [];
            $subtotal    = 0.0;
            $taxTotal    = 0.0;
            $costOfGoods = 0.0;

            $productIds = array_unique(array_map(static fn ($item) => (int) ($item['product_id'] ?? 0), $itemsInput));
            sort($productIds);
            foreach ($productIds as $productId) {
                $database->query('SELECT id FROM products WHERE id = ? FOR UPDATE', [$productId]);
            }
            $requested = [];
            foreach ($itemsInput as $item) {
                $quantity  = (float) ($item['quantity'] ?? 0);
                $productId = isset($item['product_id']) ? (int) $item['product_id'] : null;

                if (!is_numeric($item['quantity']??null) || !is_finite($quantity) || $quantity <= 0 || abs($quantity-round($quantity,3))>0.0000001) {
                    throw new InvalidArgumentException('INVALID_INVOICE_ITEM');
                }

                $description = trim((string) ($item['description'] ?? ''));
                $unitPrice   = isset($item['unit_price']) ? round((float) $item['unit_price'], 2) : null;
                $taxRate     = 0.0;

                if ($productId !== null) {
                    $product = $this->products->findWithStock($productId, $branchId);

                    if ($product === null || ! $product['is_active']) {
                        throw new InvalidArgumentException('PRODUCT_NOT_FOUND');
                    }

                    $requested[$productId] = ($requested[$productId] ?? 0) + $quantity;
                    if ($product['quantity'] < $requested[$productId]) {
                        throw new RuntimeException('INSUFFICIENT_STOCK');
                    }

                    $description  = $description !== '' ? $description : $product['name'];
                    $unitPrice  ??= (float) $product['sell_price'];
                    $taxRate      = (float) $product['tax_rate'];
                    $costOfGoods += round($quantity * $product['cost_price'], 2);
                }

                if ($description === '' || $unitPrice === null || $unitPrice < 0 || !is_finite($unitPrice)) {
                    throw new InvalidArgumentException('INVALID_INVOICE_ITEM');
                }

                $lineDiscount = round((float) ($item['discount'] ?? 0), 2);
                if (!is_finite($lineDiscount)||$lineDiscount<0||$lineDiscount>$quantity*$unitPrice) throw new InvalidArgumentException('INVALID_INVOICE_ITEM');
                $lineNet      = round($quantity * $unitPrice - $lineDiscount, 2);
                $lineTax      = round($lineNet * $taxRate / 100, 2);

                $subtotal += $lineNet;
                $taxTotal += $lineTax;

                $itemRows[] = [
                    'product_id'  => $productId,
                    'description' => $description,
                    'quantity'    => $quantity,
                    'unit_price'  => $unitPrice,
                    'discount'    => $lineDiscount,
                    'tax_rate'    => $taxRate,
                    'line_total'  => round($lineNet + $lineTax, 2),
                ];
            }

            $discount = round((float) ($data['discount'] ?? 0), 2);
            $total    = round($subtotal + $taxTotal - $discount, 2);
            if (!is_finite($discount)||$discount<0||$total<0) throw new InvalidArgumentException('INVALID_INVOICE_ITEM');

            (new CustomerCreditService())->assertAvailable($creditCustomer, $total);

            $database->table('customer_invoices')->insert([
                'uuid'         => $data['uuid'] ?? Uuid::v4(),
                'branch_id'    => $branchId,
                'customer_id'  => $customerId,
                'invoice_date' => $data['invoice_date'] ?? date('Y-m-d'),
                'due_date'     => $data['due_date'] ?? null,
                'subtotal'     => round($subtotal, 2),
                'discount'     => $discount,
                'tax_total'    => round($taxTotal, 2),
                'total'        => $total,
                'status'       => 'unpaid',
                'notes'        => $data['notes'] ?? null,
                'created_by'   => $userId,
            ]);

            $invoiceId = (int) $database->insertID();
            $invoiceNo = 'CINV-' . str_pad((string) $invoiceId, 5, '0', STR_PAD_LEFT);
            $database->table('customer_invoices')->where('id', $invoiceId)
                ->update(['invoice_no' => $invoiceNo]);

            foreach ($itemRows as $index => $itemRow) {
                $itemRows[$index]['invoice_id'] = $invoiceId;
            }

            $database->table('customer_invoice_items')->insertBatch($itemRows);

            foreach ($itemRows as $itemRow) {
                if ($itemRow['product_id'] !== null) {
                    $this->inventory->recordMovement(
                        $branchId,
                        (int) $itemRow['product_id'],
                        -$itemRow['quantity'],
                        'sale',
                        'customer_invoice',
                        $invoiceId,
                        $userId,
                    );
                }
            }

            // The customer now owes the invoice total.
            $database->table('customers')->where('id', $customerId)
                ->set('balance', 'balance - ' . $total, false)->update();

            if ($plan === 'premium') {
                $invoice = $database->table('customer_invoices')->where('id', $invoiceId)->get()->getRowArray();
                $this->accounting->postCustomerInvoice($invoice, $costOfGoods, $userId);
            }
            if (! $database->transStatus() || ! $database->transCommit()) {
                throw new \RuntimeException('TRANSACTION_FAILED');
            }
        } catch (\Throwable $exception) {
            $database->transRollback();
            throw $exception;
        }

        AuditService::log('customer_invoice.create', 'receivables', 'customer_invoices', $invoiceId, details: ['total' => $total]);

        return $this->findInvoice($invoiceId, $branchId);
    }

    /**
     * Called by SalesService inside its transaction: mirrors the unpaid
     * part of a POS sale as a receivable document. NO posting, NO stock.
     */
    public function createFromSale(array $sale, int $userId): int
    {
        $database = db_connect();

        $database->table('customer_invoices')->insert([
            'uuid'         => Uuid::v4(),
            'branch_id'    => $sale['branch_id'],
            'customer_id'  => $sale['customer_id'],
            'sale_id'      => $sale['id'],
            'invoice_no'   => $sale['invoice_no'],
            'invoice_date' => substr((string) $sale['sold_at'], 0, 10),
            'subtotal'     => $sale['subtotal'],
            'discount'     => $sale['discount'],
            'tax_total'    => $sale['tax_total'],
            'total'        => $sale['grand_total'],
            'paid_total'   => $sale['paid_total'],
            'status'       => $sale['paid_total'] > 0 ? 'partial' : 'unpaid',
            'notes'        => 'Auto-created from sale ' . $sale['invoice_no'],
            'created_by'   => $userId,
        ]);

        return (int) $database->insertID();
    }

    /**
     * @param array $data {customer_id?, invoice_id?, amount, method?,
     *                     bank_account_id?, reference?, notes?}
     */
    public function recordReceipt(array $data, int $branchId, int $userId, string $plan, bool $allocated = false): array
    {
        foreach(['invoice_id'=>'INVOICE_NOT_FOUND','customer_id'=>'CUSTOMER_REQUIRED','account_id'=>'INVALID_CASH_ACCOUNT','bank_account_id'=>'BANK_ACCOUNT_NOT_FOUND'] as $key=>$error) {
            if(isset($data[$key]) && $data[$key]!=='' && !filter_var($data[$key],FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])) throw new InvalidArgumentException($error);
        }
        foreach(['reference'=>120,'notes'=>255] as $key=>$max) if(isset($data[$key])&&(!is_string($data[$key])||mb_strlen($data[$key])>$max)) throw new InvalidArgumentException('INVALID_RECEIPT_DETAILS');
        $amount = self::receiptMoney($data['amount'] ?? 0);
        $discount = self::receiptMoney($data['discount'] ?? 0);
        if ($amount <= 0 || $discount < 0) {
            throw new InvalidArgumentException('INVALID_AMOUNT');
        }
        $method = $data['method'] ?? 'cash';
        if (! in_array($method, ['cash', 'card', 'mobile_money', 'bank', 'cheque'], true)) {
            throw new InvalidArgumentException('INVALID_PAYMENT_METHOD');
        }
        $uuid = $data['uuid'] ?? Uuid::v4();
        if (! is_string($uuid) || ! preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i', $uuid)) {
            throw new InvalidArgumentException('INVALID_UUID');
        }
        $invoiceId = ! empty($data['invoice_id']) ? (int) $data['invoice_id'] : null;
        $bankId = ! empty($data['bank_account_id']) ? (int) $data['bank_account_id'] : null;
        $accountId = ! empty($data['account_id']) ? (int) $data['account_id'] : null;
        $settled = round($amount + $discount, 2);

        return FinancialTransaction::run(function ($database) use ($data, $branchId, $userId, $plan, $amount, $discount, $method, $uuid, $invoiceId, $bankId, $accountId, $settled, $allocated): array {
            // Lock the customer first in both collection and reversal. This also
            // serializes collections against different invoices for one customer.
            $invoice = $invoiceId ? $database->table('customer_invoices')
                ->where('id', $invoiceId)->where('branch_id', $branchId)->where('deleted_at', null)
                ->get()->getRowArray() : null;
            if ($invoiceId && $invoice === null) {
                throw new InvalidArgumentException('INVOICE_NOT_FOUND');
            }
            $customerId = (int) ($data['customer_id'] ?? $invoice['customer_id'] ?? 0);
            if ($customerId <= 0 || ($invoice && $customerId !== (int) $invoice['customer_id'])) {
                throw new InvalidArgumentException('CUSTOMER_REQUIRED');
            }
            $customer = $database->query('SELECT * FROM customers WHERE id = ? AND deleted_at IS NULL FOR UPDATE', [$customerId])->getRowArray();
            if ($customer === null) {
                throw new InvalidArgumentException('CUSTOMER_REQUIRED');
            }

            // Check retries before checking the remaining due: a successful
            // request may have fully settled this invoice already.
            $existing = $database->query('SELECT * FROM customer_receipts WHERE uuid = ? FOR UPDATE', [$uuid])->getRowArray();
            if ($existing !== null) {
                if ((int) $existing['branch_id'] !== $branchId || (int) $existing['customer_id'] !== $customerId
                    || (int) $existing['invoice_id'] !== (int) $invoiceId || (int) $existing['bank_account_id'] !== (int) $bankId
                    || (int) ($existing['account_id'] ?? 0) !== (int) $accountId
                    || (float) $existing['amount'] !== $amount || (float) $existing['discount'] !== $discount
                    || ($accountId === null && $existing['method'] !== $method) || (string) $existing['reference'] !== (string) ($data['reference'] ?? '')
                    || (string) $existing['notes'] !== (string) ($data['notes'] ?? '')) {
                    throw new InvalidArgumentException('DUPLICATE_REQUEST');
                }
                return $this->receiptResult($existing, $customer['name']);
            }

            if ($invoiceId) {
                $invoice = $database->query('SELECT * FROM customer_invoices WHERE id = ? AND branch_id = ? AND deleted_at IS NULL FOR UPDATE', [$invoiceId, $branchId])->getRowArray();
                if ($invoice === null || (int) $invoice['customer_id'] !== $customerId) {
                    throw new InvalidArgumentException('INVOICE_NOT_FOUND');
                }
                if ($invoice['status'] === 'cancelled') {
                    throw new InvalidArgumentException('INVOICE_CANCELLED');
                }
                if ($settled > round((float) $invoice['total'] - (float) $invoice['paid_total'], 2)) {
                    throw new InvalidArgumentException('OVERPAYMENT');
                }
            } elseif (!$allocated && $discount > 0 && $settled > max(0, round(-(float) $customer['balance'], 2))) {
                throw new InvalidArgumentException('OVERPAYMENT');
            }

            if ($accountId !== null) {
                if ($bankId !== null) {
                    throw new InvalidArgumentException('INVALID_CASH_ACCOUNT');
                }
                $method = (new ReceiptCashAccountService())->lockSettlementMethod($accountId);
            }
            if ($bankId !== null && ! $database->table('bank_accounts')->where('id', $bankId)->where('is_active', 1)->where('deleted_at', null)->countAllResults()) {
                throw new InvalidArgumentException('BANK_ACCOUNT_NOT_FOUND');
            }
            // Period restrictions apply to Basic tenants as well as Premium.
            $today = date('Y-m-d');
            if ($database->table('financial_periods')->where('status', 'closed')->where('start_date <=', $today)->where('end_date >=', $today)->countAllResults()) {
                throw new InvalidArgumentException('PERIOD_CLOSED');
            }
            $database->table('customer_receipts')->insert([
                'uuid' => $uuid, 'branch_id' => $branchId, 'customer_id' => $customerId,
                'invoice_id' => $invoiceId, 'method' => $method, 'bank_account_id' => $bankId,
                ...($accountId !== null ? ['account_id' => $accountId] : []),
                'amount' => $amount, 'discount' => $discount, 'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null, 'created_by' => $userId,
                'received_at' => date('Y-m-d H:i:s'),
            ]);
            $receiptId = (int) $database->insertID();
            $database->table('customer_receipts')->where('id', $receiptId)
                ->update(['receipt_no' => 'RCV-' . str_pad((string) $receiptId, 5, '0', STR_PAD_LEFT)]);
            if ($invoice !== null) {
                $newPaid = round((float) $invoice['paid_total'] + $settled, 2);
                $database->table('customer_invoices')->where('id', $invoiceId)->update([
                    'paid_total' => $newPaid, 'status' => $newPaid >= (float) $invoice['total'] ? 'paid' : 'partial',
                ]);
                if (! empty($invoice['sale_id'])) {
                    $database->table('sales')->where('id', (int) $invoice['sale_id'])->where('branch_id', $branchId)->update([
                        'paid_total' => $newPaid, 'status' => $newPaid >= (float) $invoice['total'] ? 'completed' : 'partial',
                    ]);
                }
            }
            $database->table('customers')->where('id', $customerId)->set('balance', 'balance + ' . $settled, false)->update();
            $receipt = $database->table('customer_receipts')->where('id', $receiptId)->get()->getRowArray();
            if ($plan === 'premium') {
                $this->accounting->postCustomerReceipt($receipt, $userId);
            }
            AuditService::log('customer_receipt.create', 'receivables', 'customer_receipts', $receiptId, details: ['amount' => $amount, 'discount' => $discount, 'invoice_id' => $invoiceId]);
            return $this->receiptResult($receipt, $customer['name']);
        });
    }

    private static function receiptMoney(mixed $value): float
    {
        if (! is_numeric($value) || ! is_finite((float) $value)) {
            throw new InvalidArgumentException('INVALID_AMOUNT');
        }
        return round((float) $value, 2);
    }

    private function receiptResult(array $receipt, string $customerName): array
    {
        $receipt['id'] = (int) $receipt['id'];
        $receipt['amount'] = (float) $receipt['amount'];
        $receipt['discount'] = (float) $receipt['discount'];
        $receipt['customer_name'] = $customerName;
        return $receipt;
    }

    /**
     * @param array $data {customer_id, amount, method?, bank_account_id?, reference?, notes?}
     */
    public function recordDeposit(array $data, int $branchId, int $userId, string $plan): array
    {
        $customerId = (int) ($data['customer_id'] ?? 0);
        $amount     = round((float) ($data['amount'] ?? 0), 2);

        if ($customerId <= 0) {
            throw new InvalidArgumentException('CUSTOMER_REQUIRED');
        }

        if (!is_numeric($data['amount']??null) || !is_finite($amount) || $amount <= 0) {
            throw new InvalidArgumentException('INVALID_AMOUNT');
        }

        $method = $data['method'] ?? 'cash';

        if (! in_array($method, ['cash', 'card', 'mobile_money', 'bank', 'cheque'], true)) {
            throw new InvalidArgumentException('INVALID_PAYMENT_METHOD');
        }

        $database = db_connect();
        if (! $database->transException(true)->transBegin()) {
            throw new \RuntimeException('TRANSACTION_FAILED');
        }
        try {
            if (!$database->query('SELECT id FROM customers WHERE id=? AND deleted_at IS NULL FOR UPDATE',[$customerId])->getRowArray()) throw new InvalidArgumentException('CUSTOMER_REQUIRED');
            $database->table('customer_deposits')->insert([
                'uuid'            => $data['uuid'] ?? Uuid::v4(),
                'branch_id'       => $branchId,
                'customer_id'     => $customerId,
                'method'          => $method,
                'bank_account_id' => $data['bank_account_id'] ?? null,
                'amount'          => $amount,
                'reference'       => $data['reference'] ?? null,
                'notes'           => $data['notes'] ?? null,
                'created_by'      => $userId,
            ]);

            $depositId = (int) $database->insertID();
            $database->table('customer_deposits')->where('id', $depositId)
                ->update(['deposit_no' => 'DEP-' . str_pad((string) $depositId, 5, '0', STR_PAD_LEFT)]);

            // An advance = store credit on the customer's account.
            $database->table('customers')->where('id', $customerId)
                ->set('balance', 'balance + ' . $amount, false)->update();

            if ($plan === 'premium') {
                $deposit = $database->table('customer_deposits')->where('id', $depositId)->get()->getRowArray();
                $this->accounting->postCustomerDeposit($deposit, $userId);
            }
            if (! $database->transStatus() || ! $database->transCommit()) {
                throw new \RuntimeException('TRANSACTION_FAILED');
            }
        } catch (\Throwable $exception) {
            $database->transRollback();
            throw $exception;
        }

        AuditService::log('customer_deposit.create', 'receivables', 'customer_deposits', $depositId, details: ['amount' => $amount]);

        $deposit = $database->table('customer_deposits')
            ->select('customer_deposits.*, customers.name AS customer_name')
            ->join('customers', 'customers.id = customer_deposits.customer_id')
            ->where('customer_deposits.id', $depositId)
            ->get()
            ->getRowArray();

        $deposit['id']          = (int) $deposit['id'];
        $deposit['amount']      = (float) $deposit['amount'];
        $deposit['used_amount'] = (float) $deposit['used_amount'];

        return $deposit;
    }

    /**
     * Pays the UNUSED part of a deposit back to the customer.
     */
    public function refundDeposit(int $depositId, int $branchId, int $userId, string $plan): array
    {
        $database = db_connect();

        if (! $database->transException(true)->transBegin()) {
            throw new \RuntimeException('TRANSACTION_FAILED');
        }
        try {
            $deposit = $database->query('SELECT * FROM customer_deposits WHERE id = ? AND branch_id = ? FOR UPDATE', [$depositId, $branchId])->getRowArray();

            if ($deposit === null) {
                throw new InvalidArgumentException('DEPOSIT_NOT_FOUND');
            }

            if ($deposit['status'] !== 'active') {
                throw new InvalidArgumentException('DEPOSIT_NOT_ACTIVE');
            }

            $refundable = round($deposit['amount'] - $deposit['used_amount'], 2);

            if ($refundable <= 0) {
                throw new InvalidArgumentException('NOTHING_TO_REFUND');
            }

            $database->table('customer_deposits')->where('id', $depositId)
                ->update(['status' => 'refunded']);

            $database->table('customers')->where('id', (int) $deposit['customer_id'])
                ->set('balance', 'balance - ' . $refundable, false)->update();

            if ($plan === 'premium') {
                $refundRow           = $deposit;
                $refundRow['amount'] = $refundable;
                $this->accounting->postCustomerDeposit($refundRow, $userId, refund: true);
            }
            if (! $database->transStatus() || ! $database->transCommit()) {
                throw new \RuntimeException('TRANSACTION_FAILED');
            }
        } catch (\Throwable $exception) {
            $database->transRollback();
            throw $exception;
        }

        AuditService::log('customer_deposit.refund', 'receivables', 'customer_deposits', $depositId, details: ['amount' => $refundable]);

        $deposit = $database->table('customer_deposits')->where('id', $depositId)->get()->getRowArray();
        $deposit['id']          = (int) $deposit['id'];
        $deposit['amount']      = (float) $deposit['amount'];
        $deposit['used_amount'] = (float) $deposit['used_amount'];

        return $deposit;
    }

    private static function castInvoiceRow(array $invoice): array
    {
        $invoice['id']          = (int) $invoice['id'];
        $invoice['customer_id'] = (int) $invoice['customer_id'];
        $invoice['sale_id']     = $invoice['sale_id'] === null ? null : (int) $invoice['sale_id'];
        $invoice['total']       = (float) $invoice['total'];
        $invoice['paid_total']  = (float) $invoice['paid_total'];
        $invoice['balance']     = round($invoice['total'] - $invoice['paid_total'], 2);

        return $invoice;
    }
}
