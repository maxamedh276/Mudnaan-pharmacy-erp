<?php

namespace App\Services;

/**
 * Builds account statements — one chronological ledger of every
 * financial event for a customer or supplier, with a running balance
 * and summary totals.
 *
 * BALANCE CONVENTION (customer): a sale/invoice is a DEBIT (they owe
 * more), a receipt/deposit a CREDIT (they owe less). Running balance
 * > 0 = customer owes us; < 0 = customer is in credit. Suppliers are
 * the mirror image (a bill is a debit we owe, a payment a credit).
 *
 * MUTATION-SAFE: documents whose paid_total changes over time (invoices,
 * bills) contribute only their fixed total as a debit; the money paid
 * against them is recovered as separate credit rows, so nothing is ever
 * double-counted no matter how many payments arrive later.
 */
class StatementService
{
    public function customerStatement(int $customerId, int $branchId, array $filters): array
    {
        $database = db_connect();

        $customer = $database->table('customers')
            ->where('id', $customerId)->where('deleted_at', null)
            ->get()->getRowArray();

        if ($customer === null) {
            return [];
        }

        // Cash sales (no invoice): net-zero informational rows.
        $sales = $database->query(
            "SELECT s.id, s.invoice_no AS ref, s.sold_at AS date,
                    s.grand_total AS debit, s.grand_total AS credit, 'sale' AS type, s.status
             FROM sales s
             WHERE s.branch_id = ? AND s.customer_id = ? AND s.status != 'refunded'
               AND NOT EXISTS (SELECT 1 FROM customer_invoices ci WHERE ci.sale_id = s.id)",
            [$branchId, $customerId],
        )->getResultArray();

        // Invoices (credit sales + standalone): debit = total, credit =
        // the part paid at creation (paid_total minus documented receipts).
        $invoices = $database->query(
            "SELECT ci.id, ci.invoice_no AS ref, ci.invoice_date AS date, ci.total AS debit,
                    (ci.paid_total - COALESCE(
                        (SELECT SUM(r.amount) FROM customer_receipts r
                         WHERE r.invoice_id = ci.id AND r.status = 'active'), 0)) AS credit,
                    'invoice' AS type, ci.status
             FROM customer_invoices ci
             WHERE ci.branch_id = ? AND ci.customer_id = ?
               AND ci.deleted_at IS NULL AND ci.status != 'cancelled'",
            [$branchId, $customerId],
        )->getResultArray();

        $receipts = $database->query(
            "SELECT id, receipt_no AS ref, received_at AS date, 0 AS debit,
                    amount AS credit, 'receipt' AS type, status
             FROM customer_receipts
             WHERE branch_id = ? AND customer_id = ? AND status = 'active'",
            [$branchId, $customerId],
        )->getResultArray();

        $deposits = $database->query(
            "SELECT id, deposit_no AS ref, deposited_at AS date, 0 AS debit,
                    amount AS credit, 'deposit' AS type, status
             FROM customer_deposits
             WHERE branch_id = ? AND customer_id = ? AND status != 'refunded'",
            [$branchId, $customerId],
        )->getResultArray();

        return $this->assemble(
            $customer['name'],
            array_merge($sales, $invoices, $receipts, $deposits),
            $filters,
        );
    }

    public function supplierStatement(int $supplierId, int $branchId, array $filters): array
    {
        $database = db_connect();

        $supplier = $database->table('suppliers')
            ->where('id', $supplierId)->where('deleted_at', null)
            ->get()->getRowArray();

        if ($supplier === null) {
            return [];
        }

        // Fully-paid purchases (no bill): net-zero informational rows.
        $purchases = $database->query(
            "SELECT p.id, COALESCE(p.reference_no, CONCAT('PO-', p.id)) AS ref,
                    p.purchased_at AS date, p.total AS debit, p.total AS credit,
                    'purchase' AS type, p.status
             FROM purchases p
             WHERE p.branch_id = ? AND p.supplier_id = ?
               AND NOT EXISTS (SELECT 1 FROM supplier_bills b WHERE b.purchase_id = p.id)",
            [$branchId, $supplierId],
        )->getResultArray();

        $bills = $database->query(
            "SELECT b.id, b.bill_no AS ref, b.bill_date AS date, b.total AS debit,
                    (b.paid_total - COALESCE(
                        (SELECT SUM(sp.amount) FROM supplier_payments sp
                         WHERE sp.bill_id = b.id AND sp.status = 'active'), 0)) AS credit,
                    'bill' AS type, b.status
             FROM supplier_bills b
             WHERE b.branch_id = ? AND b.supplier_id = ?
               AND b.deleted_at IS NULL AND b.status != 'cancelled'",
            [$branchId, $supplierId],
        )->getResultArray();

        $payments = $database->query(
            "SELECT id, payment_no AS ref, paid_at AS date, 0 AS debit,
                    amount AS credit, 'payment' AS type, status
             FROM supplier_payments
             WHERE branch_id = ? AND supplier_id = ? AND status = 'active'",
            [$branchId, $supplierId],
        )->getResultArray();

        return $this->assemble(
            $supplier['name'],
            array_merge($purchases, $bills, $payments),
            $filters,
        );
    }

    /**
     * Sorts events chronologically, computes the running balance over
     * ALL of them, then applies the display filters and summary.
     *
     * @param array $filters {date_from?, date_to?, type?, q?}
     */
    private function assemble(string $partyName, array $rows, array $filters): array
    {
        usort($rows, static function (array $a, array $b): int {
            return [$a['date'], (int) $a['id']] <=> [$b['date'], (int) $b['id']];
        });

        $running     = 0.0;
        $totalDebit  = 0.0;
        $totalCredit = 0.0;
        $entries     = [];

        $dateFrom = $filters['date_from'] ?? null;
        $dateTo   = $filters['date_to'] ?? null;
        $type     = $filters['type'] ?? null;
        $search   = isset($filters['q']) ? mb_strtolower(trim((string) $filters['q'])) : '';

        foreach ($rows as $row) {
            $debit  = (float) $row['debit'];
            $credit = (float) $row['credit'];
            $running += $debit - $credit;   // running balance spans every event

            $day = substr((string) $row['date'], 0, 10);

            if ($dateFrom !== null && $dateFrom !== '' && $day < $dateFrom) {
                continue;
            }
            if ($dateTo !== null && $dateTo !== '' && $day > $dateTo) {
                continue;
            }
            if ($type !== null && $type !== '' && $row['type'] !== $type) {
                continue;
            }
            if ($search !== '' && ! str_contains(mb_strtolower((string) $row['ref']), $search)) {
                continue;
            }

            $totalDebit  += $debit;
            $totalCredit += $credit;

            $entries[] = [
                'id'      => (int) $row['id'],
                'type'    => $row['type'],
                'ref'     => $row['ref'],
                'date'    => $day,
                'debit'   => round($debit, 2),
                'credit'  => round($credit, 2),
                'status'  => $row['status'],
                'balance' => round($running, 2),
            ];
        }

        $entries = array_reverse($entries);   // newest first for display

        return [
            'party'   => $partyName,
            'summary' => [
                'total_debit'     => round($totalDebit, 2),
                'total_credit'    => round($totalCredit, 2),
                'closing_balance' => round($running, 2),
            ],
            'entries' => $entries,
        ];
    }
}
