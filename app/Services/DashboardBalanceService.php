<?php

namespace App\Services;

/** Reconstruct document balances at the selected closing date, including returns and later voids. */
class DashboardBalanceService
{
    public function invoices(int $branch, string $to, ?int $cashier = null): array
    {
        $db = db_connect();
        $scope = $cashier === null ? '' : ' AND s.user_id=?';
        $args = [$branch, $to];
        if ($cashier !== null) $args[] = $cashier;
        $rows = $db->query("SELECT i.*,c.name party FROM customer_invoices i LEFT JOIN sales s ON s.id=i.sale_id LEFT JOIN customers c ON c.id=i.customer_id WHERE i.branch_id=? AND i.invoice_date<=? AND i.deleted_at IS NULL AND i.status<>'cancelled'$scope", $args)->getResultArray();
        $args = [$branch, $branch];
        if ($cashier !== null) $args[] = $cashier;
        $payments = $db->query("SELECT i.id document_id,r.received_at event_at,r.status,r.voided_at,CASE WHEN a.id IS NULL THEN r.amount+r.discount ELSE a.amount+a.discount END amount FROM customer_receipts r LEFT JOIN customer_receipt_allocations a ON a.receipt_id=r.id JOIN customer_invoices i ON i.id=COALESCE(a.invoice_id,r.invoice_id) LEFT JOIN sales s ON s.id=i.sale_id WHERE r.branch_id=? AND i.branch_id=?$scope", $args)->getResultArray();
        return $this->balances($rows, $payments, $this->returns($branch, 'sale', $to), $to);
    }

    public function bills(int $branch, string $to): array
    {
        $db = db_connect();
        $rows = $db->query("SELECT b.*,s.name party FROM supplier_bills b LEFT JOIN suppliers s ON s.id=b.supplier_id WHERE b.branch_id=? AND b.bill_date<=? AND b.deleted_at IS NULL AND b.status<>'cancelled'", [$branch, $to])->getResultArray();
        $later = array_column($db->query('SELECT r.purchase_id,SUM(i.line_total) amount FROM purchase_receipts r JOIN purchase_receipt_items i ON i.receipt_id=r.id WHERE r.branch_id=? AND r.received_at>? GROUP BY r.purchase_id', [$branch, $to . ' 23:59:59'])->getResultArray(), 'amount', 'purchase_id');
        foreach ($rows as &$row) $row['total'] = round((float)$row['total'] - (float)($later[$row['purchase_id']] ?? 0), 2);
        unset($row);
        $payments = $db->query('SELECT bill_id document_id,paid_at event_at,status,voided_at,amount FROM supplier_payments WHERE branch_id=? AND bill_id IS NOT NULL', [$branch])->getResultArray();
        return $this->balances($rows, $payments, $this->returns($branch, 'purchase', $to), $to);
    }

    private function returns(int $branch, string $kind, string $to): array
    {
        $rows = db_connect()->query('SELECT document_id,SUM(total) total,SUM(refund+discount_reversal) settled FROM document_returns WHERE branch_id=? AND kind=? AND returned_at<=? AND document_id IS NOT NULL GROUP BY document_id', [$branch, $kind, $to . ' 23:59:59'])->getResultArray();
        return array_column($rows, null, 'document_id');
    }

    private function balances(array $rows, array $payments, array $returns, string $to): array
    {
        $current = $effective = [];
        $cutoff = $to . ' 23:59:59';
        foreach ($payments as $payment) {
            $id = $payment['document_id'];
            if ($payment['status'] === 'active') $current[$id] = ($current[$id] ?? 0) + (float)$payment['amount'];
            if ($payment['event_at'] <= $cutoff && ($payment['status'] === 'active' || !empty($payment['voided_at']) && $payment['voided_at'] > $cutoff)) $effective[$id] = ($effective[$id] ?? 0) + (float)$payment['amount'];
        }
        foreach ($rows as &$row) {
            $id = $row['id'];
            $row['total'] = round((float)$row['total'] + (float)$row['returned_total'] - (float)($returns[$id]['total'] ?? 0), 2);
            $initial = (float)$row['paid_total'] + (float)$row['refunded_total'] + (float)$row['return_discount_total'] - ($current[$id] ?? 0);
            $row['settled'] = round($initial + ($effective[$id] ?? 0) - (float)($returns[$id]['settled'] ?? 0), 2);
            $row['due'] = round($row['total'] - $row['settled'], 2);
            $dueDate = $row['due_date'] ?: ($row['invoice_date'] ?? $row['bill_date']);
            $row['age'] = max(0, (int)(new \DateTimeImmutable($dueDate))->diff(new \DateTimeImmutable($to))->format('%r%a'));
            // Without a due date, an invoice's date anchors ageing but does not invent an overdue term.
            $row['overdue'] = $row['due'] > 0.005 && !empty($row['due_date']) && $row['due_date'] < $to;
            $row['payment_status'] = $row['overdue'] ? 'Overdue' : ($row['due'] <= 0.005 ? 'Paid' : ($row['settled'] > 0.005 ? 'Partial' : 'Unpaid'));
        }
        unset($row);
        usort($rows, static fn($a, $b) => [$b['overdue'], $b['due']] <=> [$a['overdue'], $a['due']]);
        return $rows;
    }
}
