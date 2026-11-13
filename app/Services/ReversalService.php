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
    ) {
    }

    /**
     * Cancels a purchase order. Since creating a PO books nothing (no
     * stock, no GL, no bill — see PurchaseService), voiding one is only
     * ever a status flip: it's only allowed while still 'pending', i.e.
     * before any goods have been received against it. A PO with any
     * receipts already carries real stock/GL/payable impact that voiding
     * doesn't currently unwind — receive against it (or leave it as a
     * standing partial order) instead of cancelling.
     */
    public function voidPurchase(int $purchaseId, int $branchId, string $reason, int $userId): array
    {
        return FinancialTransaction::run(function ($database) use ($purchaseId, $branchId, $reason, $userId): array {
            $purchase = $database->query('SELECT * FROM purchases WHERE id = ? AND branch_id = ? FOR UPDATE', [$purchaseId, $branchId])->getRowArray();

            if ($purchase === null) {
                throw new InvalidArgumentException('PURCHASE_NOT_FOUND');
            }
            if ($purchase['status'] === 'cancelled') {
                throw new InvalidArgumentException('ALREADY_CANCELLED');
            }
            if ($purchase['status'] !== 'pending') {
                throw new InvalidArgumentException('ALREADY_RECEIVING');
            }

            $database->table('purchases')->where('id', $purchaseId)
                ->update(['status' => 'cancelled']);

            AuditService::log(
                'purchase.void',
                'purchases',
                'purchases',
                $purchaseId,
                before: ['status' => $purchase['status']],
                after: ['status' => 'cancelled'],
                details: ['reason' => $reason],
            );

            return ['id' => $purchaseId, 'status' => 'cancelled'];
        });
    }

    /**
     * Cancels a supplier payment: reverses its journal entry, restores
     * the bill's outstanding balance, marks it cancelled.
     */
    public function voidSupplierPayment(int $paymentId, int $branchId, string $reason, int $userId, string $plan): array
    {
        $database = db_connect();

        if (! $database->transException(true)->transBegin()) {
            throw new \RuntimeException('TRANSACTION_FAILED');
        }
        try {
            $payment = $database->query('SELECT * FROM supplier_payments WHERE id = ? AND branch_id = ? FOR UPDATE', [$paymentId, $branchId])->getRowArray();

            if ($payment === null) {
                throw new InvalidArgumentException('PAYMENT_NOT_FOUND');
            }
            if ($payment['status'] === 'cancelled') {
                throw new InvalidArgumentException('ALREADY_CANCELLED');
            }
            $this->assertPeriodOpen(substr((string) $payment['paid_at'], 0, 10));

            $this->accounting->reverseSource('supplier_payment', $paymentId, $branchId, $userId);

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
            if (! $database->transStatus() || ! $database->transCommit()) {
                throw new \RuntimeException('TRANSACTION_FAILED');
            }
        } catch (\Throwable $exception) {
            $database->transRollback();
            throw $exception;
        }

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
        return FinancialTransaction::run(function ($database) use ($receiptId, $branchId, $reason, $userId): array {
            $receipt = $database->table('customer_receipts')->where('id', $receiptId)->where('branch_id', $branchId)->get()->getRowArray();
            if ($receipt === null) {
                throw new InvalidArgumentException('RECEIPT_NOT_FOUND');
            }
            $database->query('SELECT id FROM customers WHERE id = ? FOR UPDATE', [(int) $receipt['customer_id']]);
            $receipt = $database->query('SELECT * FROM customer_receipts WHERE id = ? AND branch_id = ? FOR UPDATE', [$receiptId, $branchId])->getRowArray();
            if ($receipt['status'] === 'cancelled') {
                throw new InvalidArgumentException('ALREADY_CANCELLED');
            }
            $this->assertPeriodOpen(substr((string) $receipt['received_at'], 0, 10));
            // Reverse existing postings even if the tenant has since changed plan.
            $this->accounting->reverseSource('customer_receipt', $receiptId, $branchId, $userId);
            $settled = round((float) $receipt['amount'] + (float) ($receipt['discount'] ?? 0), 2);
            foreach ($database->table('customer_receipt_allocations')->where('receipt_id', $receiptId)->get()->getResultArray() as $allocation) {
                $this->rollBackInvoice((int)$allocation['invoice_id'], round((float)$allocation['amount']+(float)$allocation['discount'],2));
            }
            if (! empty($receipt['invoice_id'])) {
                $this->rollBackInvoice((int) $receipt['invoice_id'], $settled);
            }
            $database->table('customers')->where('id', (int) $receipt['customer_id'])
                ->set('balance', 'balance - ' . $settled, false)->update();
            $database->table('customer_receipts')->where('id', $receiptId)->update([
                'status' => 'cancelled', 'voided_at' => date('Y-m-d H:i:s'),
                'voided_by' => $userId, 'void_reason' => $reason,
            ]);
            AuditService::log('customer_receipt.void', 'receivables', 'customer_receipts', $receiptId,
                before: ['status' => 'active', 'amount' => $receipt['amount']],
                after: ['status' => 'cancelled'], details: ['reason' => $reason]);
            return ['id' => $receiptId, 'status' => 'cancelled'];
        });
    }

    private function rollBackBill(int $billId, float $amount): void
    {
        $database = db_connect();
        $bill     = $database->query('SELECT * FROM supplier_bills WHERE id = ? FOR UPDATE', [$billId])->getRowArray();

        if ($bill === null) throw new InvalidArgumentException('BILL_NOT_FOUND');
        if (round((float)$bill['paid_total']-$amount,2)<0) throw new InvalidArgumentException('INCONSISTENT_BALANCE');

        $newPaid = max(0, round((float) $bill['paid_total'] - $amount, 2));
        $database->table('supplier_bills')->where('id', $billId)->update([
            'paid_total' => $newPaid,
            'status'     => $newPaid <= 0 ? 'unpaid' : 'partial',
        ]);
    }

    private function rollBackInvoice(int $invoiceId, float $amount): void
    {
        $database = db_connect();
        $invoice  = $database->query('SELECT * FROM customer_invoices WHERE id = ? FOR UPDATE', [$invoiceId])->getRowArray();

        if ($invoice === null) {
            throw new InvalidArgumentException('INVOICE_NOT_FOUND');
        }

        if (round((float)$invoice['paid_total']-$amount,2)<0) throw new InvalidArgumentException('INCONSISTENT_BALANCE');
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
