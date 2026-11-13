<?php

namespace App\Services;

use App\Services\Web\ChartOfAccountsPresenter;
/** Read-only financial statement hierarchy; every amount is an account's own posted balance. */
class ReportAccountService
{
    public function build(string $type, int $branch, string $from, string $to, string $search): array
    {
        $closing = $this->balances($branch, '1000-01-01', $to);
        $period = $type === 'income-statement' ? $this->balances($branch, $from, $to) : $closing;
        $totals = ['ASSET' => 0.0, 'LIABILITY' => 0.0, 'EQUITY' => 0.0, 'INCOME' => 0.0, 'EXPENSE' => 0.0];
        foreach ($period as $account) {
            $totals[$account['type_code']] += $account['amount'];
        }
        // Inactive groups may still carry historical balances and must not disappear.
        $groups = db_connect()->query('SELECT g.id,g.code,g.name,t.code type_code FROM account_groups g JOIN account_types t ON t.id=g.account_type_id ORDER BY t.sort_order,g.code')->getResultArray();
        $kinds = array_column(db_connect()->query('SELECT account_id,CONVERT(type USING utf8mb4) COLLATE utf8mb4_unicode_ci AS type FROM bank_accounts UNION SELECT account_id,CONVERT(type USING utf8mb4) COLLATE utf8mb4_unicode_ci AS type FROM pos_payment_methods')->getResultArray(), 'type', 'account_id');
        $sections = [];
        if ($type === 'income-statement') {
            $money = (new ReceiptCashAccountService())->moneyAccountIds();
            $cash = array_values(array_filter($closing, static fn($a) => in_array($a['id'], $money, true)));
            $sections[] = $this->section('cash', 'Cash & payment account balances', 'Closing balances at ' . $to, $cash, $groups, $kinds, 'ASSET');
            $sections[] = $this->section('income', 'Income accounts', 'Income earned from ' . $from . ' through ' . $to, $period, $groups, $kinds, 'INCOME');
            $sections[] = $this->section('expenses', 'Expense accounts', 'Expenses from ' . $from . ' through ' . $to, $period, $groups, $kinds, 'EXPENSE');
            $summary = ['Income' => $totals['INCOME'], 'Expenses' => $totals['EXPENSE'], 'Net profit' => $totals['INCOME'] - $totals['EXPENSE']];
            $notice = 'Cash and payment balances are closing asset balances, shown separately from income. Negative expense balances are credits or reversals and reduce expenses.';
        } else {
            $sections[] = $this->section('assets', 'Assets', 'Resources owned by the business', $closing, $groups, $kinds, 'ASSET');
            $sections[] = $this->section('liabilities', 'Liabilities', 'Amounts owed to others', $closing, $groups, $kinds, 'LIABILITY');
            $equity = $this->section('equity', 'Equity', 'Capital, retained earnings and net income', $closing, $groups, $kinds, 'EQUITY');
            $earnings = round($totals['INCOME'] - $totals['EXPENSE'], 2);
            $equity['groups'][] = ['label' => 'Accumulated earnings', 'categories' => [['label' => 'Net income', 'rows' => [['', 'Net income through ' . $to, $earnings]]]], 'total' => $earnings];
            $equity['total'] = round($equity['total'] + $earnings, 2);
            $sections[] = $equity;
            $summary = ['Assets' => $totals['ASSET'], 'Liabilities' => $totals['LIABILITY'], 'Equity including earnings' => $equity['total'], 'Difference' => round($totals['ASSET'] - $totals['LIABILITY'] - $equity['total'], 2)];
            $notice = 'Closing balances through ' . $to . '. Equity includes accumulated net income through this date. Account rows contain direct posted balances; parent and child balances are not counted twice.';
        }
        $rows = [];
        foreach ($sections as &$section) {
            $visible = [];
            foreach ($section['groups'] as $group) {
                $categories = [];
                foreach ($group['categories'] as $category) {
                    $category['rows'] = array_values(array_filter($category['rows'], static fn($row) => $search === '' || str_contains(mb_strtolower(implode(' ', [$section['label'], $group['label'], $category['label'], ...$row])), mb_strtolower($search))));
                    if ($category['rows']) {
                        foreach ($category['rows'] as $row) {
                            $rows[] = [$section['label'], $group['label'], $category['label'], ...$row];
                        }
                        $categories[] = $category;
                    }
                }
                if ($categories) {
                    $group['categories'] = $categories;
                    $visible[] = $group;
                }
            }
            $section['groups'] = $visible;
        }
        unset($section);
        if ($search !== '') {
            $notice .= ' Search filters account rows; section totals retain the full statement scope.';
        }
        return ['layout' => $type, 'columns' => ['Section', 'Account group', 'Account category', 'Code', 'Account', 'Balance (USD)'], 'rows' => $rows, 'summary' => array_map(static fn($n) => round($n, 2), $summary), 'notice' => $notice, 'sections' => $sections, 'netIncome' => round($totals['INCOME'] - $totals['EXPENSE'], 2)];
    }
    private function balances(int $branch, string $from, string $to): array
    {
        $rows = db_connect()->query("SELECT a.id,a.code,a.name,a.parent_id,a.account_group_id,a.is_active,a.is_postable,a.deleted_at,\n            t.code type_code,COALESCE(b.debit,0) debit,COALESCE(b.credit,0) credit\n            FROM accounts a JOIN account_groups g ON g.id=a.account_group_id JOIN account_types t ON t.id=g.account_type_id\n            LEFT JOIN (SELECT l.account_id,SUM(l.debit) debit,SUM(l.credit) credit FROM journal_entry_lines l\n                JOIN journal_entries e ON e.id=l.journal_entry_id WHERE e.branch_id=? AND e.status IN ('posted','void')\n                AND e.entry_date BETWEEN ? AND ? GROUP BY l.account_id) b ON b.account_id=a.id ORDER BY a.code", [$branch, $from, $to])->getResultArray();
        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
            $row['parent_id'] = $row['parent_id'] === null ? null : (int) $row['parent_id'];
            $net = round((float) $row['debit'] - (float) $row['credit'], 2);
            $row['amount'] = $net === 0.0 ? 0.0 : (in_array($row['type_code'], ['ASSET', 'EXPENSE'], true) ? $net : -$net);
        }
        unset($row);
        return $rows;
    }
    private function section(string $key, string $label, string $subtitle, array $accounts, array $groups, array $kinds, string $type): array
    {
        $categories = (new ChartOfAccountsPresenter())->categories($accounts, $groups, $kinds, $type);
        $result = [];
        $total = 0;
        foreach ($categories as $category) {
            $rows = [];
            foreach ($category['rows'] as $account) {
                if ((!$account['is_active'] || $account['deleted_at'] !== null || !$account['is_postable']) && abs($account['amount']) < 0.001) {
                    continue;
                }
                $rows[] = [$account['code'], $account['name'], round($account['amount'], 2)];
            }
            if (!$rows) {
                continue;
            }
            $id = (int) $category['group']['id'];
            $amount = array_sum(array_column($rows, 2));
            $result[$id] ??= ['label' => $category['group']['name'], 'categories' => [], 'total' => 0.0];
            $result[$id]['categories'][] = ['label' => $category['label'], 'rows' => $rows];
            $result[$id]['total'] += $amount;
            $total += $amount;
        }
        foreach ($result as &$group) {
            $group['total'] = round($group['total'], 2);
        }
        return ['key' => $key, 'label' => $label, 'subtitle' => $subtitle, 'groups' => array_values($result), 'total' => round($total, 2)];
    }
}
