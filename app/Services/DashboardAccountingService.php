<?php

namespace App\Services;

/** Read-only, branch-scoped accounting cards. Cash balances are never treated as income. */
class DashboardAccountingService
{
    public function get(int $branch, string $from, string $to, array $access): array
    {
        $documents = new DashboardBalanceService();
        $invoices = $access['receivables'] ? $documents->invoices($branch, $to) : [];
        $bills = $access['payables'] ? $documents->bills($branch, $to) : [];
        $openInvoices = array_values(array_filter($invoices, static fn($r) => $r['due'] > 0.005));
        $openBills = array_values(array_filter($bills, static fn($r) => $r['due'] > 0.005));
        $result = ['receivables' => round(array_sum(array_column($invoices, 'due')), 2), 'payables' => round(array_sum(array_column($bills, 'due')), 2), 'invoice_count' => count($openInvoices), 'bill_count' => count($openBills), 'overdue_bills' => count(array_filter($openBills, static fn($r) => $r['overdue'])), 'invoices' => array_slice($openInvoices, 0, 5), 'bills' => array_slice($openBills, 0, 5)];
        $ageing = ['Current' => 0.0, '1–30 days' => 0.0, '31–60 days' => 0.0, '60+ days' => 0.0];
        foreach ($openInvoices as $invoice) {
            $bucket = $invoice['age'] === 0 ? 'Current' : ($invoice['age'] <= 30 ? '1–30 days' : ($invoice['age'] <= 60 ? '31–60 days' : '60+ days'));
            $ageing[$bucket] += $invoice['due'];
        }
        $result['ageing'] = $ageing;
        if (!$access['ledger']) return $result;
        $db = db_connect();
        $accounts = $db->query("SELECT a.id,a.code,a.name,t.code type,COALESCE(b.closing,0) closing,COALESCE(b.activity,0) activity FROM accounts a JOIN account_groups g ON g.id=a.account_group_id JOIN account_types t ON t.id=g.account_type_id LEFT JOIN (SELECT l.account_id,SUM(l.debit-l.credit) closing,SUM(CASE WHEN e.entry_date>=? THEN l.debit-l.credit ELSE 0 END) activity FROM journal_entry_lines l JOIN journal_entries e ON e.id=l.journal_entry_id WHERE e.branch_id=? AND e.entry_date<=? AND e.status IN ('posted','void') GROUP BY l.account_id) b ON b.account_id=a.id ORDER BY a.code", [$from, $branch, $to])->getResultArray();
        $types = ['ASSET' => 0.0, 'LIABILITY' => 0.0, 'EQUITY' => 0.0, 'INCOME' => 0.0, 'EXPENSE' => 0.0];
        $closingTypes = $types;
        $cash = $debits = $credits = 0.0;
        $expenses = [];
        $moneyIds = array_map('intval', (new ReceiptCashAccountService())->moneyAccountIds());
        $codes = [];
        foreach ($accounts as $account) {
            $closing = round((float)$account['closing'], 2);
            $activity = round((float)$account['activity'], 2);
            $normal = in_array($account['type'], ['ASSET', 'EXPENSE'], true) ? 1 : -1;
            $types[$account['type']] += $normal * $activity;
            $closingTypes[$account['type']] += $normal * $closing;
            $codes[$account['code']] = $normal * $closing;
            $debits += max(0, $closing);
            $credits += max(0, -$closing);
            if (in_array((int)$account['id'], $moneyIds, true)) $cash += $closing;
            if ($account['type'] === 'EXPENSE' && abs($activity) > 0.005) $expenses[$account['name']] = $activity;
        }
        $flow = [];
        for ($day = new \DateTimeImmutable($from); $day->format('Y-m-d') <= $to; $day = $day->modify('+1 day')) $flow[$day->format('Y-m-d')] = ['label' => $day->format('d M'), 'in' => 0.0, 'out' => 0.0];
        $movement = 0.0;
        if ($moneyIds) {
            $ids = implode(',', $moneyIds);
            $rows = $db->query("SELECT e.id,e.entry_date,SUM(l.debit-l.credit) value FROM journal_entries e JOIN journal_entry_lines l ON l.journal_entry_id=e.id WHERE e.branch_id=? AND e.status IN ('posted','void') AND e.entry_date BETWEEN ? AND ? AND l.account_id IN ($ids) GROUP BY e.id,e.entry_date", [$branch, $from, $to])->getResultArray();
            foreach ($rows as $row) {
                $value = round((float)$row['value'], 2);
                $movement += $value;
                $flow[$row['entry_date']][$value >= 0 ? 'in' : 'out'] += abs($value);
            }
        }
        $entries = $db->query("SELECT entry_date date,entry_no reference,description,total_debit debit,total_credit credit FROM journal_entries WHERE branch_id=? AND entry_date BETWEEN ? AND ? AND status IN ('posted','void') ORDER BY entry_date DESC,id DESC LIMIT 5", [$branch, $from, $to])->getResultArray();
        $entryCount = $db->table('journal_entries')->where('branch_id', $branch)->where('entry_date <=', $to)->whereIn('status', ['posted', 'void'])->countAllResults();
        $period = $db->table('financial_periods')->select('name,status')->where('start_date <=', $to)->where('end_date >=', $to)->orderBy('id', 'DESC')->get()->getRowArray();
        $result += ['cash' => round($cash, 2), 'income' => round($types['INCOME'], 2), 'expenses' => round($types['EXPENSE'], 2), 'profit' => round($types['INCOME'] - $types['EXPENSE'], 2), 'assets' => round($closingTypes['ASSET'], 2), 'liabilities' => round($closingTypes['LIABILITY'], 2), 'equity' => round($closingTypes['EQUITY'] + $closingTypes['INCOME'] - $closingTypes['EXPENSE'], 2), 'inventory' => round($codes[AccountingService::INVENTORY] ?? 0, 2), 'ar_ledger' => round($codes[AccountingService::RECEIVABLE] ?? 0, 2), 'ap_ledger' => round($codes[AccountingService::PAYABLE] ?? 0, 2), 'debits' => round($debits, 2), 'credits' => round($credits, 2), 'difference' => round($debits - $credits, 2), 'has_entries' => $entryCount > 0, 'expense_mix' => $expenses, 'flow' => array_values($flow), 'opening' => round($cash - $movement, 2), 'movement' => round($movement, 2), 'entries' => $entries, 'period' => $period];
        return $result;
    }
}
