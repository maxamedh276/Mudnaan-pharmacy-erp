<?php

namespace App\Services;

use InvalidArgumentException;
/** Branch-scoped report snapshots. Every export consumes the same normalized rows. */
class WebReportService
{
    public const TITLES = ['sales' => 'Sales Report', 'inventory' => 'Inventory Valuation', 'customers' => 'Customer Report', 'suppliers' => 'Supplier Report', 'income-statement' => 'Income Statement', 'balance-sheet' => 'Balance Sheet', 'cash-flow' => 'Cash Flow', 'trial-balance' => 'Trial Balance'];
    public function build(string $type, int $branch, array $filters): array
    {
        // One consistent read snapshot keeps document totals, drilldowns and exports in agreement.
        return FinancialTransaction::run(fn() => $this->snapshot($type, $branch, $filters));
    }
    private function snapshot(string $type, int $branch, array $filters): array
    {
        if (!isset(self::TITLES[$type])) {
            throw new InvalidArgumentException('Unknown report.');
        }
        foreach (['date_from', 'date_to', 'q'] as $key) {
            if (isset($filters[$key]) && !is_string($filters[$key])) {
                throw new InvalidArgumentException('Report filters must be text values.');
            }
        }
        $from = $filters['date_from'] ?? date('Y-m-01');
        $to = $filters['date_to'] ?? date('Y-m-d');
        foreach ([$from, $to] as $date) {
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $date);
            if (!$parsed || $parsed->format('Y-m-d') !== $date) {
                throw new InvalidArgumentException('Select valid report dates.');
            }
        }
        if ($from > $to) {
            throw new InvalidArgumentException('Start date must not be after end date.');
        }
        $q = trim((string) ($filters['q'] ?? ''));
        if (mb_strlen($q) > 120) {
            throw new InvalidArgumentException('Search is too long.');
        }
        $party = null;
        if (isset($filters['party_id']) && $filters['party_id'] !== '') {
            if (!is_scalar($filters['party_id']) || !preg_match('/^\d{1,10}$/D', (string) $filters['party_id'])) {
                throw new InvalidArgumentException('Select a valid customer or supplier.');
            }
            $party = (int) $filters['party_id'];
        }
        $base = ['title' => self::TITLES[$type], 'type' => $type, 'filters' => ['date_from' => $from, 'date_to' => $to, 'q' => $q, 'party_id' => $party === null ? '' : (string) $party]];
        if (in_array($type, ['income-statement', 'balance-sheet'], true)) {
            return array_merge($base, (new ReportAccountService())->build($type, $branch, $from, $to, $q));
        }
        if (in_array($type, ['customers', 'suppliers'], true)) {
            $result = (new ReportPartyService())->build($type, $branch, $from, $to, $q, $party);
            $result['summary'] = array_map(static fn($n) => round($n, 2), $result['summary']);
            if ($q !== '') {
                $result['notice'] .= ' Search filters rows and details; summary totals retain the full scope.';
            }
            return array_merge($base, $result);
        }
        $db = db_connect();
        $summary = [];
        $notice = '';
        $rows = [];
        if ($type === 'sales') {
            $columns = ['Date', 'Order', 'Customer', 'Total', 'Settled', 'Due', 'Status'];
            $raw = $db->query("SELECT s.id,s.sold_at,s.invoice_no,COALESCE(c.name,'Walk-in') name,s.grand_total,s.paid_total,s.status FROM sales s LEFT JOIN customers c ON c.id=s.customer_id WHERE s.branch_id=? AND s.sold_at>=? AND s.sold_at<DATE_ADD(?,INTERVAL 1 DAY) AND s.status IN ('completed','partial') ORDER BY s.sold_at,s.id", [$branch, $from, $to])->getResultArray();
            $settlements = (new ReportPartyService())->saleSettlements($branch, $to);
            $returns = (new ReportPartyService())->returnAdjustments($branch, $to)['sale'];
            foreach ($raw as $r) {
                $r['grand_total'] = round((float)$r['grand_total'] - ($returns[(int)$r['id']]['total'] ?? 0), 2);
                $paid = $settlements[(int) $r['id']] ?? (float) $r['paid_total'];
                $due = round((float) $r['grand_total'] - $paid, 2);
                $rows[] = [$r['sold_at'], $r['invoice_no'], $r['name'], (float) $r['grand_total'], $paid, $due, $due > 0.001 ? $paid > 0.001 ? 'partial' : 'unpaid' : 'paid'];
            }
            $summary = ['Sales' => array_sum(array_column($rows, 3)), 'Settled' => array_sum(array_column($rows, 4)), 'Outstanding' => array_sum(array_column($rows, 5))];
            $notice = 'Sales are selected by order date. Totals include returns effective through the end date; settled and due amounts include collections, refunds and discounts through that date.';
        } elseif ($type === 'inventory') {
            $columns = ['SKU', 'Product', 'Category', 'Quantity as of date', 'Current unit cost', 'Estimated value'];
            // Current stock includes imported/opening quantities that may predate movement logging.
            // Roll back only later movements to reconstruct the requested closing quantity.
            $raw = $db->query("SELECT p.sku,p.name,COALESCE(c.name,'Uncategorized') category,COALESCE(sl.quantity,0)-COALESCE(m.after_qty,0) qty,p.cost_price FROM products p LEFT JOIN categories c ON c.id=p.category_id LEFT JOIN stock_levels sl ON sl.product_id=p.id AND sl.branch_id=? LEFT JOIN (SELECT m.product_id,SUM(m.qty_change) after_qty FROM stock_movements m WHERE m.branch_id=? AND COALESCE((SELECT s.sold_at FROM sales s WHERE m.ref_type='sale' AND s.id=m.ref_id),(SELECT pr.received_at FROM purchase_receipts pr WHERE m.ref_type='purchase_receipt' AND pr.id=m.ref_id),(SELECT ci.invoice_date FROM customer_invoices ci WHERE m.ref_type='customer_invoice' AND ci.id=m.ref_id),m.created_at)>=DATE_ADD(?,INTERVAL 1 DAY) GROUP BY m.product_id) m ON m.product_id=p.id WHERE p.deleted_at IS NULL ORDER BY p.name", [$branch, $branch, $to])->getResultArray();
            foreach ($raw as $r) {
                $rows[] = [$r['sku'], $r['name'], $r['category'], (float) $r['qty'], (float) $r['cost_price'], round((float) $r['qty'] * (float) $r['cost_price'], 2)];
            }
            $summary = ['Units' => array_sum(array_column($rows, 3)), 'Estimated value' => array_sum(array_column($rows, 5))];
            $notice = 'Closing quantities include opening stock and reverse movements after the end date. Historical valuation is estimated using current average cost. The start date does not change closing stock.';
        } elseif ($type === 'cash-flow') {
            $columns = ['Date', 'Entry', 'Description', 'Cash in', 'Cash out'];
            $ids = $this->moneyIds();
            $opening = 0;
            if ($ids) {
                $in = implode(',', array_map('intval', $ids));
                $opening = (float) $db->query("SELECT COALESCE(SUM(l.debit-l.credit),0) n FROM journal_entry_lines l JOIN journal_entries e ON e.id=l.journal_entry_id WHERE e.branch_id=? AND e.status IN ('posted','void') AND e.entry_date<? AND l.account_id IN ({$in})", [$branch, $from])->getRow('n');
                $raw = $db->query("SELECT e.entry_date,e.entry_no,e.description,SUM(l.debit-l.credit) n FROM journal_entry_lines l JOIN journal_entries e ON e.id=l.journal_entry_id WHERE e.branch_id=? AND e.status IN ('posted','void') AND e.entry_date BETWEEN ? AND ? AND l.account_id IN ({$in}) GROUP BY e.id,e.entry_date,e.entry_no,e.description HAVING ABS(n)>0.001 ORDER BY e.entry_date,e.id", [$branch, $from, $to])->getResultArray();
                foreach ($raw as $r) {
                    $rows[] = [$r['entry_date'], $r['entry_no'], $r['description'], max(0, (float) $r['n']), max(0, -(float) $r['n'])];
                }
            }
            $incoming = array_sum(array_column($rows, 3));
            $outgoing = array_sum(array_column($rows, 4));
            $summary = ['Opening cash' => $opening, 'Cash in' => $incoming, 'Cash out' => $outgoing, 'Closing cash' => $opening + $incoming - $outgoing];
            $notice = 'Cash movement statement. Transfers between cash and bank accounts are excluded from inflows and outflows.';
        } else {
            $since = '1000-01-01';
            $raw = $db->query("SELECT a.code,a.name,t.code type,SUM(l.debit) debit,SUM(l.credit) credit FROM journal_entry_lines l JOIN journal_entries e ON e.id=l.journal_entry_id JOIN accounts a ON a.id=l.account_id JOIN account_groups g ON g.id=a.account_group_id JOIN account_types t ON t.id=g.account_type_id WHERE e.branch_id=? AND e.status IN ('posted','void') AND e.entry_date BETWEEN ? AND ? GROUP BY a.id,a.code,a.name,t.code ORDER BY a.code", [$branch, $since, $to])->getResultArray();
            $debit = 0;
            $credit = 0;
            $columns = ['Code', 'Account', 'Debit', 'Credit'];
            foreach ($raw as $r) {
                $net = round((float) $r['debit'] - (float) $r['credit'], 2);
                if (abs($net) < 0.001) {
                    continue;
                }
                $rows[] = [$r['code'], $r['name'], max(0, $net), max(0, -$net)];
                $debit += max(0, $net);
                $credit += max(0, -$net);
            }
            $summary = ['Total debits' => $debit, 'Total credits' => $credit, 'Difference' => round($debit - $credit, 2)];
            $notice = 'Cumulative balances through the end date. Start date does not change closing balances.';
        }
        // Search limits displayed/exported detail; statement totals retain their full accounting scope.
        if ($q !== '') {
            $rows = array_values(array_filter($rows, static fn($r) => str_contains(mb_strtolower(implode(' ', array_map('strval', $r))), mb_strtolower($q))));
            $notice .= ' Search filters detail rows; summary totals cover the full date scope.';
        }
        return array_merge($base, ['columns' => $columns, 'rows' => $rows, 'summary' => array_map(static fn($n) => round($n, 2), $summary), 'notice' => $notice]);
    }
    private function moneyIds(): array
    {
        return (new ReceiptCashAccountService())->moneyAccountIds();
    }
}
