<?php

namespace App\Services;

use App\Libraries\Uuid;
use InvalidArgumentException;

/**
 * ACCOUNTS PAYABLE — supplier bills and the payments that settle them.
 *
 * Two ways a bill is born:
 *  1. AUTOMATICALLY from a purchase that wasn't fully paid
 *     (PurchaseService calls createFromPurchase; no journal posting —
 *     the purchase already posted Inventory / Cash + AP).
 *  2. STANDALONE for services (rent, utilities, freight …): posts
 *     DEBIT General Expense / CREDIT Accounts Payable.
 *
 * Payments always post DEBIT AP / CREDIT Cash-or-Bank and roll the
 * bill's paid_total + status forward. Everything is transaction-safe.
 */
class PayablesService
{
    public function __construct(
        private readonly AccountingService $accounting = new AccountingService(),
    ) {
    }

    // ----------------------------------------------------------------
    //  READS
    // ----------------------------------------------------------------

    public function listBills(int $branchId, array $filters, int $page, int $perPage): array
    {
        $builder = db_connect()->table('supplier_bills')
            ->select('supplier_bills.id, supplier_bills.bill_no, supplier_bills.supplier_id,
                      suppliers.name AS supplier_name, supplier_bills.purchase_id,
                      supplier_bills.bill_date, supplier_bills.due_date,
                      supplier_bills.total, supplier_bills.paid_total, supplier_bills.status')
            ->join('suppliers', 'suppliers.id = supplier_bills.supplier_id')
            ->where('supplier_bills.branch_id', $branchId)
            ->where('supplier_bills.deleted_at', null);

        if (! empty($filters['status'])) {
            $builder->where('supplier_bills.status', $filters['status']);
        }

        if (! empty($filters['supplier_id'])) {
            $builder->where('supplier_bills.supplier_id', (int) $filters['supplier_id']);
        }

        if (! empty($filters['q'])) {
            $builder->like('supplier_bills.bill_no', $filters['q']);
        }

        $total = $builder->countAllResults(false);
        $rows  = $builder->orderBy('supplier_bills.id', 'DESC')
            ->limit($perPage, ($page - 1) * $perPage)
            ->get()
            ->getResultArray();

        return ['items' => array_map([self::class, 'castBillRow'], $rows), 'total' => $total];
    }

    public function findBill(int $billId, int $branchId): ?array
    {
        $database = db_connect();

        $bill = $database->table('supplier_bills')
            ->select('supplier_bills.*, suppliers.name AS supplier_name')
            ->join('suppliers', 'suppliers.id = supplier_bills.supplier_id')
            ->where('supplier_bills.id', $billId)
            ->where('supplier_bills.branch_id', $branchId)
            ->where('supplier_bills.deleted_at', null)
            ->get()
            ->getRowArray();

        if ($bill === null) {
            return null;
        }

        $bill = self::castBillRow($bill);

        $bill['items'] = array_map(static function (array $item): array {
            $item['id']         = (int) $item['id'];
            $item['product_id'] = $item['product_id'] === null ? null : (int) $item['product_id'];
            $item['quantity']   = (float) $item['quantity'];
            $item['unit_cost']  = (float) $item['unit_cost'];
            $item['line_total'] = (float) $item['line_total'];

            return $item;
        }, $database->table('supplier_bill_items')
            ->select('id, product_id, description, quantity, unit_cost, line_total')
            ->where('bill_id', $billId)
            ->get()
            ->getResultArray());

        $bill['payments'] = array_map(static function (array $payment): array {
            $payment['id']     = (int) $payment['id'];
            $payment['amount'] = (float) $payment['amount'];

            return $payment;
        }, $database->table('supplier_payments')
            ->select('id, payment_no, method, amount, reference, paid_at')
            ->where('bill_id', $billId)
            ->get()
            ->getResultArray());

        return $bill;
    }

    public function listPayments(int $branchId, array $filters, int $page, int $perPage): array
    {
        $builder = db_connect()->table('supplier_payments')
            ->select('supplier_payments.id, supplier_payments.payment_no,
                      supplier_payments.supplier_id, suppliers.name AS supplier_name,
                      supplier_payments.bill_id, supplier_bills.bill_no,
                      supplier_payments.method, supplier_payments.amount,
                      supplier_payments.reference, supplier_payments.status,
                      supplier_payments.paid_at')
            ->join('suppliers', 'suppliers.id = supplier_payments.supplier_id')
            ->join('supplier_bills', 'supplier_bills.id = supplier_payments.bill_id', 'left')
            ->where('supplier_payments.branch_id', $branchId);

        if (! empty($filters['supplier_id'])) {
            $builder->where('supplier_payments.supplier_id', (int) $filters['supplier_id']);
        }

        $total = $builder->countAllResults(false);
        $rows  = $builder->orderBy('supplier_payments.id', 'DESC')
            ->limit($perPage, ($page - 1) * $perPage)
            ->get()
            ->getResultArray();

        foreach ($rows as &$row) {
            $row['id']      = (int) $row['id'];
            $row['bill_id'] = $row['bill_id'] === null ? null : (int) $row['bill_id'];
            $row['amount']  = (float) $row['amount'];
        }

        return ['items' => $rows, 'total' => $total];
    }

    // ----------------------------------------------------------------
    //  WRITES
    // ----------------------------------------------------------------

    /**
     * Standalone bill. Lines may reference a product (for description
     * convenience) or be free-text service lines.
     *
     * @param array $data {supplier_id, bill_no?, bill_date?, due_date?, notes?,
     *                     items: [{description?, product_id?, quantity?, unit_cost}]}
     */
    public function createBill(array $data, int $branchId, int $userId, string $plan): array
    {
        $supplierId = (int) ($data['supplier_id'] ?? 0);
        $itemsInput = $data['items'] ?? [];

        if ($supplierId <= 0) {
            throw new InvalidArgumentException('SUPPLIER_REQUIRED');
        }

        if (! is_array($itemsInput) || $itemsInput === []) {
            throw new InvalidArgumentException('EMPTY_BILL');
        }

        $database = db_connect();

        $itemRows = [];
        $total    = 0.0;

        foreach ($itemsInput as $item) {
            $quantity = (float) ($item['quantity'] ?? 1);
            $unitCost = round((float) ($item['unit_cost'] ?? 0), 2);

            if ($quantity <= 0 || $unitCost < 0) {
                throw new InvalidArgumentException('INVALID_BILL_ITEM');
            }

            $description = trim((string) ($item['description'] ?? ''));
            $productId   = isset($item['product_id']) ? (int) $item['product_id'] : null;

            if ($description === '' && $productId !== null) {
                $product = $database->table('products')->select('name')
                    ->where('id', $productId)->get()->getRowArray();
                $description = $product['name'] ?? '';
            }

            if ($description === '') {
                throw new InvalidArgumentException('INVALID_BILL_ITEM');
            }

            $lineTotal = round($quantity * $unitCost, 2);
            $total    += $lineTotal;

            $itemRows[] = [
                'product_id'  => $productId,
                'description' => $description,
                'quantity'    => $quantity,
                'unit_cost'   => $unitCost,
                'line_total'  => $lineTotal,
            ];
        }

        $database->transException(true)->transStart();

        $database->table('supplier_bills')->insert([
            'uuid'        => $data['uuid'] ?? Uuid::v4(),
            'branch_id'   => $branchId,
            'supplier_id' => $supplierId,
            'bill_no'     => $data['bill_no'] ?? null,
            'bill_date'   => $data['bill_date'] ?? date('Y-m-d'),
            'due_date'    => $data['due_date'] ?? null,
            'subtotal'    => round($total, 2),
            'total'       => round($total, 2),
            'status'      => 'unpaid',
            'notes'       => $data['notes'] ?? null,
            'created_by'  => $userId,
        ]);

        $billId = (int) $database->insertID();

        if (empty($data['bill_no'])) {
            $database->table('supplier_bills')->where('id', $billId)
                ->update(['bill_no' => 'BILL-' . str_pad((string) $billId, 5, '0', STR_PAD_LEFT)]);
        }

        foreach ($itemRows as $index => $itemRow) {
            $itemRows[$index]['bill_id'] = $billId;
        }

        $database->table('supplier_bill_items')->insertBatch($itemRows);

        if ($plan === 'premium') {
            $bill = $database->table('supplier_bills')->where('id', $billId)->get()->getRowArray();
            $this->accounting->postSupplierBill($bill, $userId);
        }

        $database->transComplete();

        AuditService::log('supplier_bill.create', 'payables', 'supplier_bills', $billId, details: ['total' => $total]);

        return $this->findBill($billId, $branchId);
    }

    /**
     * Called by PurchaseService inside its transaction: mirrors the
     * unpaid part of a purchase as a payable document. NO posting here.
     */
    public function createFromPurchase(array $purchase, float $paidAmount, int $userId): int
    {
        $database = db_connect();

        $database->table('supplier_bills')->insert([
            'uuid'        => Uuid::v4(),
            'branch_id'   => $purchase['branch_id'],
            'supplier_id' => $purchase['supplier_id'],
            'purchase_id' => $purchase['id'],
            'bill_no'     => $purchase['reference_no'] ?: null,
            'bill_date'   => substr((string) $purchase['purchased_at'], 0, 10),
            'subtotal'    => $purchase['total'],
            'total'       => $purchase['total'],
            'paid_total'  => $paidAmount,
            'status'      => $paidAmount > 0 ? 'partial' : 'unpaid',
            'notes'       => 'Auto-created from purchase #' . $purchase['id'],
            'created_by'  => $userId,
        ]);

        $billId = (int) $database->insertID();

        if (empty($purchase['reference_no'])) {
            $database->table('supplier_bills')->where('id', $billId)
                ->update(['bill_no' => 'BILL-' . str_pad((string) $billId, 5, '0', STR_PAD_LEFT)]);
        }

        return $billId;
    }

    /**
     * @param array $data {supplier_id?, bill_id?, amount, method?,
     *                     bank_account_id?, reference?, notes?}
     * supplier_id may be omitted when bill_id is given.
     */
    public function recordPayment(array $data, int $branchId, int $userId, string $plan): array
    {
        $amount = round((float) ($data['amount'] ?? 0), 2);

        if ($amount <= 0) {
            throw new InvalidArgumentException('INVALID_AMOUNT');
        }

        $method = $data['method'] ?? 'cash';

        if (! in_array($method, ['cash', 'card', 'mobile_money', 'bank', 'cheque'], true)) {
            throw new InvalidArgumentException('INVALID_PAYMENT_METHOD');
        }

        $database = db_connect();
        $billId   = isset($data['bill_id']) ? (int) $data['bill_id'] : null;
        $bill     = null;

        if ($billId !== null) {
            $bill = $database->table('supplier_bills')
                ->where('id', $billId)
                ->where('branch_id', $branchId)
                ->where('deleted_at', null)
                ->get()
                ->getRowArray();

            if ($bill === null) {
                throw new InvalidArgumentException('BILL_NOT_FOUND');
            }

            if ($bill['status'] === 'cancelled') {
                throw new InvalidArgumentException('BILL_CANCELLED');
            }

            $outstanding = round($bill['total'] - $bill['paid_total'], 2);

            if ($amount > $outstanding) {
                throw new InvalidArgumentException('OVERPAYMENT');
            }
        }

        $supplierId = (int) ($data['supplier_id'] ?? $bill['supplier_id'] ?? 0);

        if ($supplierId <= 0) {
            throw new InvalidArgumentException('SUPPLIER_REQUIRED');
        }

        $database->transException(true)->transStart();

        $database->table('supplier_payments')->insert([
            'uuid'            => $data['uuid'] ?? Uuid::v4(),
            'branch_id'       => $branchId,
            'supplier_id'     => $supplierId,
            'bill_id'         => $billId,
            'method'          => $method,
            'bank_account_id' => $data['bank_account_id'] ?? null,
            'amount'          => $amount,
            'reference'       => $data['reference'] ?? null,
            'notes'           => $data['notes'] ?? null,
            'created_by'      => $userId,
        ]);

        $paymentId = (int) $database->insertID();
        $paymentNo = 'PAY-' . str_pad((string) $paymentId, 5, '0', STR_PAD_LEFT);
        $database->table('supplier_payments')->where('id', $paymentId)
            ->update(['payment_no' => $paymentNo]);

        if ($bill !== null) {
            $newPaid = round($bill['paid_total'] + $amount, 2);
            $database->table('supplier_bills')->where('id', $billId)->update([
                'paid_total' => $newPaid,
                'status'     => $newPaid >= (float) $bill['total'] ? 'paid' : 'partial',
            ]);
        }

        if ($plan === 'premium') {
            $payment = $database->table('supplier_payments')->where('id', $paymentId)->get()->getRowArray();
            $this->accounting->postSupplierPayment($payment, $userId);
        }

        $database->transComplete();

        AuditService::log('supplier_payment.create', 'payables', 'supplier_payments', $paymentId, details: [
            'amount' => $amount, 'bill_id' => $billId,
        ]);

        $payment = $database->table('supplier_payments')
            ->select('supplier_payments.*, suppliers.name AS supplier_name')
            ->join('suppliers', 'suppliers.id = supplier_payments.supplier_id')
            ->where('supplier_payments.id', $paymentId)
            ->get()
            ->getRowArray();

        $payment['id']     = (int) $payment['id'];
        $payment['amount'] = (float) $payment['amount'];

        return $payment;
    }

    private static function castBillRow(array $bill): array
    {
        $bill['id']          = (int) $bill['id'];
        $bill['supplier_id'] = (int) $bill['supplier_id'];
        $bill['purchase_id'] = $bill['purchase_id'] === null ? null : (int) $bill['purchase_id'];
        $bill['total']       = (float) $bill['total'];
        $bill['paid_total']  = (float) $bill['paid_total'];
        $bill['balance']     = round($bill['total'] - $bill['paid_total'], 2);

        return $bill;
    }
}
