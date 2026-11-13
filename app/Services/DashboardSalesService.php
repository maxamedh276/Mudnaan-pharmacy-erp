<?php

namespace App\Services;

/** Branch-wide sales for administrators; every cashier query is scoped to the authenticated actor. */
class DashboardSalesService
{
    public function get(int $branch, int $actor, string $from, string $to, bool $own): array
    {
        $db = db_connect();
        $methodNames = array_column($db->table('pos_payment_methods')->select('account_id,name')->get()->getResultArray(), 'name', 'account_id');
        $scope = $own ? ' AND s.user_id=?' : '';
        $args = [$branch, $from, $to . ' 23:59:59'];
        if ($own) $args[] = $actor;
        $sales = $db->query("SELECT s.id,s.invoice_no,s.sold_at,s.grand_total,s.paid_total,s.returned_total,s.refunded_total,s.return_discount_total,COALESCE(c.name,'Walk-in customer') customer FROM sales s LEFT JOIN customers c ON c.id=s.customer_id WHERE s.branch_id=? AND s.sold_at>=? AND s.sold_at<=? AND s.status IN ('completed','partial')$scope ORDER BY s.sold_at DESC,s.id DESC", $args)->getResultArray();
        $invoices = (new DashboardBalanceService())->invoices($branch, $to, $own ? $actor : null);
        $bySale = array_column(array_filter($invoices, static fn($i) => $i['sale_id'] !== null), null, 'sale_id');
        $args = [$branch, $to . ' 23:59:59'];
        if ($own) $args[] = $actor;
        $returns = $db->query("SELECT r.source_id,SUM(r.total) total,SUM(r.refund+r.discount_reversal) settled FROM document_returns r JOIN sales s ON s.id=r.source_id AND s.branch_id=r.branch_id WHERE r.branch_id=? AND r.kind='sale' AND r.returned_at<=?$scope GROUP BY r.source_id", $args)->getResultArray();
        $returns = array_column($returns, null, 'source_id');
        $daily = [];
        for ($day = new \DateTimeImmutable($from); $day->format('Y-m-d') <= $to; $day = $day->modify('+1 day')) $daily[$day->format('Y-m-d')] = ['label' => $day->format('d M'), 'sales' => 0.0, 'collections' => 0.0];
        $hourly = array_fill(0, 24, 0.0);
        $orders = [];
        $status = ['Paid' => 0, 'Partial' => 0, 'Unpaid' => 0];
        $outstanding = array_sum(array_map(static fn($invoice) => max(0, $invoice['due']), $invoices));
        $revenue = 0.0;
        foreach ($sales as $sale) {
            $id = $sale['id'];
            $total = round((float)$sale['grand_total'] - (float)($returns[$id]['total'] ?? 0), 2);
            $paid = isset($bySale[$id]) ? $bySale[$id]['settled'] : round((float)$sale['paid_total'] + (float)$sale['refunded_total'] + (float)$sale['return_discount_total'] - (float)($returns[$id]['settled'] ?? 0), 2);
            $due = max(0, round($total - $paid, 2));
            if (!isset($bySale[$id])) $outstanding += $due;
            if (substr($sale['sold_at'], 0, 10) < $from) continue;
            $state = $due <= 0.005 ? 'Paid' : ($paid > 0.005 ? 'Partial' : 'Unpaid');
            $status[$state]++;
            $revenue += $total;
            $daily[substr($sale['sold_at'], 0, 10)]['sales'] += $total;
            $hourly[(int)substr($sale['sold_at'], 11, 2)] += $total;
            $orders[] = ['id' => (int)$id, 'reference' => $sale['invoice_no'] ?: '#' . $id, 'date' => $sale['sold_at'], 'customer' => $sale['customer'], 'total' => $total, 'paid' => $paid, 'due' => $due, 'returned' => (float)($returns[$id]['total'] ?? 0), 'status' => $state];
        }
        // Initial tenders are capped per sale, excluding change. Receipt collections have
        // their own actor scope, even when collected against another cashier's original order.
        $args = [$branch, $from, $to . ' 23:59:59'];
        if ($own) $args[] = $actor;
        $tenders = $db->query("SELECT s.id,s.sold_at,s.grand_total,p.method,p.account_id,p.amount FROM sales s JOIN payments p ON p.sale_id=s.id WHERE s.branch_id=? AND s.sold_at>=? AND s.sold_at<=? AND s.status IN ('completed','partial')$scope ORDER BY s.id,p.id", $args)->getResultArray();
        $mix = $applied = [];
        foreach ($tenders as $tender) {
            $id = $tender['id'];
            $value = max(0, min((float)$tender['amount'], (float)$tender['grand_total'] - ($applied[$id] ?? 0)));
            $applied[$id] = ($applied[$id] ?? 0) + $value;
            $label = $methodNames[$tender['account_id']] ?? ucwords(str_replace('_', ' ', $tender['method']));
            $mix[$label] = ($mix[$label] ?? 0) + $value;
            $daily[substr($tender['sold_at'], 0, 10)]['collections'] += $value;
        }
        $args = [$branch, $from, $to . ' 23:59:59', $to . ' 23:59:59'];
        $receiptScope = $own ? ' AND r.created_by=?' : '';
        if ($own) $args[] = $actor;
        $receipts = $db->query("SELECT r.receipt_no reference,r.received_at date,r.amount,r.method,r.account_id,COALESCE(c.name,'Customer') customer FROM customer_receipts r LEFT JOIN customers c ON c.id=r.customer_id WHERE r.branch_id=? AND r.received_at>=? AND r.received_at<=? AND (r.status='active' OR r.voided_at>?)$receiptScope ORDER BY r.received_at DESC,r.id DESC", $args)->getResultArray();
        foreach ($receipts as &$receipt) {
            $receipt['amount'] = (float)$receipt['amount'];
            $label = $methodNames[$receipt['account_id']] ?? ucwords(str_replace('_', ' ', $receipt['method']));
            $mix[$label] = ($mix[$label] ?? 0) + $receipt['amount'];
            $daily[substr($receipt['date'], 0, 10)]['collections'] += $receipt['amount'];
        }
        unset($receipt);
        $args = [$branch, $from, $to . ' 23:59:59'];
        $refundScope = $own ? ' AND created_by=?' : '';
        if ($own) $args[] = $actor;
        $refunds = $db->query("SELECT COALESCE(SUM(refund),0) total,COUNT(*) count FROM document_returns WHERE branch_id=? AND kind='sale' AND returned_at>=? AND returned_at<=?$refundScope", $args)->getRowArray();
        $args = [$branch, $from, $to . ' 23:59:59'];
        if ($own) $args[] = $actor;
        $top = $db->query("SELECT i.product_id,i.description,SUM(i.quantity) quantity,SUM(i.line_total) total FROM sale_items i JOIN sales s ON s.id=i.sale_id WHERE s.branch_id=? AND s.sold_at>=? AND s.sold_at<=? AND s.status IN ('completed','partial')$scope GROUP BY i.product_id,i.description ORDER BY total DESC,i.description LIMIT 5", $args)->getResultArray();
        return ['revenue' => round($revenue, 2), 'collected' => round(array_sum($mix) - (float)$refunds['total'], 2), 'gross_collected' => round(array_sum($mix), 2), 'outstanding' => round($outstanding, 2), 'order_count' => count($orders), 'refunds' => (float)$refunds['total'], 'return_count' => (int)$refunds['count'], 'daily' => array_values($daily), 'hourly' => $hourly, 'payment_mix' => $mix, 'status_mix' => $status, 'orders' => array_slice($orders, 0, 12), 'receipts' => array_slice($receipts, 0, 6), 'top_items' => $top];
    }
}
