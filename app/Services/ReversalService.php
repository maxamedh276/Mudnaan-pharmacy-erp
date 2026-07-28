<?php

namespace App\Services;

use InvalidArgumentException;

/**
 * VOID / REVERSAL workflows. Financial documents are never edited in
 * place or deleted — instead they are reversed: dependencies are
 * checked, a mirror journal entry undoes the accounting, stock and
 * balances are put back, the original is flagged, and the whole thing
 * is recorded in the audit log. Everything runs in one DB transaction.
 *
 * Error messages are machine-readable codes the controller maps to
 * friendly text.
 */
class ReversalService
{
    public function __construct(
        private readonly AccountingService $accounting = new AccountingService(),
        private readonly InventoryService $inventory = new InventoryService(),
    ) {
    }

    /**
     * Voids a purchase after proving nothing depends on it:
     *   - its stock hasn't been sold (reversing wouldn't go negative),
     *   - its bill has no payments,
     *   - it isn't in a closed period.
     */
    public function voidPurchase(int $purchaseId, int $branchId, string $reason, int $userId, string $plan): array
    {
        $database = db_connect();

        $purchase = $database->table('purchases')
            ->where('id', $purchaseId)->where('branch_id', $branchId)
            ->get()->getRowArray();

        if ($purchase === null) {
            throw new InvalidArgumentException('PURCHASE_NOT_FOUND');
        }
        if ($purchase['status'] === 'cancelled') {
            throw new InvalidArgumentException('ALREADY_CANCELLED');
        }
        $this->assertPeriodOpen(substr((string) $purchase['purchased_at'], 0, 10));

        // Dependency: the stock this purchase added must still be on hand.
        $items = $database->table('purchase_items')
            ->where('purchase_id', $purchaseId)->get()->getResultArray();

        foreach ($items as $item) {
            $onHand = $this->inventory->currentQuantity($branchId, (int) $item['product_id']);
            if ($onHand < (float) $item['quantity']) {
                throw new InvalidArgumentException('ITEMS_SOLD');
            }
        }

        // Dependency: no payments against the auto-created bill.
        $bill = $database->table('supplier_bills')
            ->where('purchase_id', $purchaseId)->where('deleted_at', null)
            ->get()->getRowArray();

        if ($bill !== null) {
            $paidByDocuments = (float) ($database->table('supplier_payments')
                ->selectSum('amount')
                ->where('bill_id', $bill['id'])->where('status', 'active')
                ->get()->getRow()->amount ?? 0);

            if ($paidByDocuments > 0) {
                throw new InvalidArgumentException('HAS_PAYMENTS');
            }
        }

        $database->transException(true)->transStart();

        // Put the stock back (negative movements).
        foreach ($items as $item) {
            $this->inventory->recordMovement(
                $branchId,
                (int) $item['product_id'],
                -(float) $item['quantity'],
                'adjustment',
                'purchase_reversal',
                $purchaseId,
                $userId,
            );
        }

        if ($plan === 'premium') {
            $this->accounting->reverseSource('purchase', $purchaseId, $branchId, $userId);
        }

        if ($bill !== null) {
            $database->table('supplier_bills')->where('id', $bill['id'])
                ->update(['status' => 'cancelled']);
        }

        $database->table('purchases')->where('id', $purchaseId)
            ->update(['status' => 'cancelled']);

        $database->transComplete();

        AuditService::log(
            'purchase.void',
            'purchases',
            'purchases',
            $purchaseId,
            before: ['status' => $purchase['status'], 'total' => $purchase['total']],
            after: ['status' => 'cancelled'],
            details: ['reason' => $reason],
        );

        return ['id' => $purchaseId, 'status' => 'cancelled'];
    }

    /**
     * Cancels a supplier payment: reverses its journal entry, restores
     * the bill's outstanding balance, marks it cancelled.
     */
    public function voidSupplierPayment(int $paymentId, int $branchId, string $reason, int $userId, string $plan): array
    {
        $database = db_connect();

        $payment = $database->table('supplier_payments')
            ->where('id', $paymentId)->where('branch_id', $branchId)
            ->get()->getRowArray();

        if ($payment === null) {
            throw new InvalidArgumentException('PAYMENT_NOT_FOUND');
        }
        if ($payment['status'] === 'cancelled') {
            throw new InvalidArgumentException('ALREADY_CANCELLED');
        }
        $this->assertPeriodOpen(substr((string) $payment['paid_at'], 0, 10));

        $database->transException(true)->transStart();

        if ($plan === 'premium') {
            $this->accounting->reverseSource('supplier_payment', $paymentId, $branchId, $userId);
        }

        // Restore the bill it settled.
        if (! empty($payment['bill_id'])) {
            $this->rollBackBill((int) $payment['bill_id'], (float) $payment['amount']);
        }

        $database->table('supplier_payments')->where('id', $paymentId)->update([
            'status'      => 'cancelled',
            'voided_at'   => date('Y-m-d H:i:s'),
            'voided_by'   => $userId,
            'void_reason' => $reason,
        ]);

        $database->transComplete();

        AuditService::log(
            'supplier_payment.void',
            'payables',
            'supplier_payments',
            $paymentId,
            before: ['status' => 'active', 'amount' => $payment['amount']],
            after: ['status' => 'cancelled'],
            details: ['reason' => $reason],
        );

        return ['id' => $paymentId, 'status' => 'cancelled'];
    }

    /**
     * Cancels a customer receipt: reverses its journal entry, restores
     * the invoice balance and the linked sale, and undoes the customer
     * balance movement.
     */
    public function voidCustomerReceipt(int $receiptId, int $branchId, string $reason, int $userId, string $plan): array
    {
        $database = db_connect();

        $receipt = $database->table('customer_receipts')
            ->where('id', $receiptId)->where('branch_id', $branchId)
            ->get()->getRowArray();

        if ($receipt === null) {
            throw new InvalidArgumentException('RECEIPT_NOT_FOUND');
        }
        if ($receipt['status'] === 'cancelled') {
            throw new InvalidArgumentException('ALREADY_CANCELLED');
        }
        $this->assertPeriodOpen(substr((string) $receipt['received_at'], 0, 10));

        $database->transException(true)->transStart();

        if ($plan === 'premium') {
            $this->accounting->reverseSource('customer_receipt', $receiptId, $branchId, $userId);
        }

        // The receipt cleared received + any waived discount off the due.
        $settled = round((float) $receipt['amount'] + (float) ($receipt['discount'] ?? 0), 2);

        // Restore the invoice (and its linked sale) it settled.
        if (! empty($receipt['invoice_id'])) {
            $this->rollBackInvoice((int) $receipt['invoice_id'], $settled);
        }

        // Money in had reduced what the customer owed; undo that.
        $database->table('customers')->where('id', (int) $receipt['customer_id'])
            ->set('balance', 'balance - ' . $settled, false)->update();

        $database->table('customer_receipts')->where('id', $receiptId)->update([
            'status'      => 'cancelled',
            'voided_at'   => date('Y-m-d H:i:s'),
            'voided_by'   => $userId,
            'void_reason' => $reason,
        ]);

        $database->transComplete();

        AuditService::log(
            'customer_receipt.void',
            'receivables',
            'customer_receipts',
            $receiptId,
            before: ['status' => 'active', 'amount' => $receipt['amount']],
            after: ['status' => 'cancelled'],
            details: ['reason' => $reason],
        );

        return ['id' => $receiptId, 'status' => 'cancelled'];
    }

    private function rollBackBill(int $billId, float $amount): void
    {
        $database = db_connect();
        $bill     = $database->table('supplier_bills')->where('id', $billId)->get()->getRowArray();

        if ($bill === null) {
            return;
        }

        $newPaid = max(0, round((float) $bill['paid_total'] - $amount, 2));
        $database->table('supplier_bills')->where('id', $billId)->update([
            'paid_total' => $newPaid,
            'status'     => $newPaid <= 0 ? 'unpaid' : 'partial',
        ]);
    }

    private function rollBackInvoice(int $invoiceId, float $amount): void
    {
        $database = db_connect();
        $invoice  = $database->table('customer_invoices')->where('id', $invoiceId)->get()->getRowArray();

        if ($invoice === null) {
            return;
        }

        $newPaid = max(0, round((float) $invoice['paid_total'] - $amount, 2));
        $status  = $newPaid <= 0 ? 'unpaid' : 'partial';

        $database->table('customer_invoices')->where('id', $invoiceId)->update([
            'paid_total' => $newPaid,
            'status'     => $status,
        ]);

        if (! empty($invoice['sale_id'])) {
            $database->table('sales')->where('id', (int) $invoice['sale_id'])->update([
                'paid_total' => $newPaid,
                'status'     => $newPaid >= (float) $invoice['total'] ? 'completed' : 'partial',
            ]);
        }
    }

    private function assertPeriodOpen(string $date): void
    {
        $closed = db_connect()->table('financial_periods')
            ->where('status', 'closed')
            ->where('start_date <=', $date)
            ->where('end_date >=', $date)
            ->countAllResults();

        if ($closed > 0) {
            throw new InvalidArgumentException('PERIOD_CLOSED');
        }
    }
}
