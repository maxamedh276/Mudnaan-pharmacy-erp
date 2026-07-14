<?php

namespace App\Services;

/**
 * The accounting statements — trial balance, income statement, balance
 * sheet, cash flow, and the ledgers. All read-only, all derived from
 * posted journal entries so they always agree with the books.
 *
 * Sign convention: a debit-normal account (assets, expenses) shows a
 * positive balance when debits exceed credits; a credit-normal account
 * (liabilities, equity, income) when credits exceed debits.
 */
class FinancialReportService
{
    /**
     * Trial balance as at a date: every account with a non-zero
     * balance placed in its debit or credit column. The two columns
     * must total to the same figure.
     */
    public function trialBalance(string $asOf): array
    {
        $rows = db_connect()->query(
            "SELECT a.code, a.name, t.normal_balance,
                    COALESCE(SUM(l.debit), 0) AS d, COALESCE(SUM(l.credit), 0) AS c
             FROM accounts a
             JOIN account_groups g ON g.id = a.account_group_id
             JOIN account_types t  ON t.id = g.account_type_id
             LEFT JOIN journal_entry_lines l ON l.account_id = a.id
                  AND l.journal_entry_id IN (
                      SELECT id FROM journal_entries WHERE status = 'posted' AND entry_date <= ?)
             WHERE a.deleted_at IS NULL
             GROUP BY a.id, a.code, a.name, t.normal_balance, t.sort_order
             ORDER BY a.code",
            [$asOf],
        )->getResultArray();

        $lines = [];
        $totalDebit = 0.0;
        $totalCredit = 0.0;

        foreach ($rows as $row) {
            $net = round((float) $row['d'] - (float) $row['c'], 2);
            if ($net === 0.0) {
                continue;
            }

            $debit  = $net > 0 ? $net : 0.0;
            $credit = $net < 0 ? -$net : 0.0;
            $totalDebit  += $debit;
            $totalCredit += $credit;

            $lines[] = [
                'code'   => $row['code'],
                'name'   => $row['name'],
                'debit'  => $debit,
                'credit' => $credit,
            ];
        }

        return [
            'as_of'  => $asOf,
            'lines'  => $lines,
            'totals' => [
                'debit'      => round($totalDebit, 2),
                'credit'     => round($totalCredit, 2),
                'in_balance' => round($totalDebit - $totalCredit, 2) === 0.0,
            ],
        ];
    }

    /**
     * Income statement (profit & loss) for a period: income and expense
     * accounts, netted to profit.
     */
    public function incomeStatement(string $from, string $to): array
    {
        $income   = $this->accountActivity('INCOME', $from, $to, 'credit');
        $expenses = $this->accountActivity('EXPENSE', $from, $to, 'debit');

        $totalIncome  = array_sum(array_column($income, 'amount'));
        $totalExpense = array_sum(array_column($expenses, 'amount'));

        return [
            'period'        => ['from' => $from, 'to' => $to],
            'income'        => $income,
            'expenses'      => $expenses,
            'total_income'  => round($totalIncome, 2),
            'total_expense' => round($totalExpense, 2),
            'net_profit'    => round($totalIncome - $totalExpense, 2),
        ];
    }

    /**
     * Balance sheet as at a date. Equity includes the net profit earned
     * up to that date (retained), so Assets = Liabilities + Equity.
     */
    public function balanceSheet(string $asOf): array
    {
        $assets      = $this->accountActivity('ASSET', null, $asOf, 'debit');
        $liabilities = $this->accountActivity('LIABILITY', null, $asOf, 'credit');
        $equity      = $this->accountActivity('EQUITY', null, $asOf, 'credit');

        $income  = array_sum(array_column($this->accountActivity('INCOME', null, $asOf, 'credit'), 'amount'));
        $expense = array_sum(array_column($this->accountActivity('EXPENSE', null, $asOf, 'debit'), 'amount'));
        $netProfit = round($income - $expense, 2);

        $totalAssets = round(array_sum(array_column($assets, 'amount')), 2);
        $totalLiabilities = round(array_sum(array_column($liabilities, 'amount')), 2);
        $totalEquity = round(array_sum(array_column($equity, 'amount')) + $netProfit, 2);

        return [
            'as_of'             => $asOf,
            'assets'            => $assets,
            'liabilities'       => $liabilities,
            'equity'            => $equity,
            'net_profit'        => $netProfit,
            'total_assets'      => $totalAssets,
            'total_liabilities' => $totalLiabilities,
            'total_equity'      => $totalEquity,
            'balanced'          => round($totalAssets - $totalLiabilities - $totalEquity, 2) === 0.0,
        ];
    }

    /**
     * Simple cash-flow view: movement across cash + bank GL accounts
     * over the period (opening, in, out, closing).
     */
    public function cashFlow(string $from, string $to): array
    {
        $database = db_connect();

        // Cash on Hand + every bank-backed account.
        $accountIds = $database->query(
            "SELECT a.id FROM accounts a WHERE a.deleted_at IS NULL
             AND (a.code = '1000' OR a.id IN (SELECT account_id FROM bank_accounts WHERE deleted_at IS NULL))",
        )->getResultArray();

        $ids = array_column($accountIds, 'id');
        if ($ids === []) {
            return ['period' => ['from' => $from, 'to' => $to], 'opening' => 0, 'inflow' => 0, 'outflow' => 0, 'closing' => 0];
        }

        $in = implode(',', array_map('intval', $ids));

        $opening = (float) ($database->query(
            "SELECT COALESCE(SUM(l.debit - l.credit),0) v FROM journal_entry_lines l
             JOIN journal_entries e ON e.id = l.journal_entry_id
             WHERE e.status='posted' AND e.entry_date < ? AND l.account_id IN ($in)",
            [$from],
        )->getRow()->v);

        $flow = $database->query(
            "SELECT COALESCE(SUM(l.debit),0) inflow, COALESCE(SUM(l.credit),0) outflow
             FROM journal_entry_lines l
             JOIN journal_entries e ON e.id = l.journal_entry_id
             WHERE e.status='posted' AND e.entry_date BETWEEN ? AND ? AND l.account_id IN ($in)",
            [$from, $to],
        )->getRowArray();

        $inflow  = (float) $flow['inflow'];
        $outflow = (float) $flow['outflow'];

        return [
            'period'  => ['from' => $from, 'to' => $to],
            'opening' => round($opening, 2),
            'inflow'  => round($inflow, 2),
            'outflow' => round($outflow, 2),
            'closing' => round($opening + $inflow - $outflow, 2),
        ];
    }

    /**
     * General ledger: every posted line in the range, grouped by
     * account, each with a running balance.
     */
    public function generalLedger(string $from, string $to): array
    {
        $rows = db_connect()->query(
            "SELECT a.id AS account_id, a.code, a.name, t.normal_balance,
                    e.entry_no, e.entry_date, e.description, l.debit, l.credit
             FROM journal_entry_lines l
             JOIN journal_entries e ON e.id = l.journal_entry_id
             JOIN accounts a ON a.id = l.account_id
             JOIN account_groups g ON g.id = a.account_group_id
             JOIN account_types t  ON t.id = g.account_type_id
             WHERE e.status='posted' AND e.entry_date BETWEEN ? AND ?
             ORDER BY a.code, e.entry_date, e.id",
            [$from, $to],
        )->getResultArray();

        $accounts = [];
        foreach ($rows as $row) {
            $code = $row['code'];
            if (! isset($accounts[$code])) {
                $accounts[$code] = [
                    'code' => $code,
                    'name' => $row['name'],
                    'lines' => [],
                    'running' => 0.0,
                ];
            }

            $accounts[$code]['running'] += (float) $row['debit'] - (float) $row['credit'];
            $accounts[$code]['lines'][] = [
                'entry_no'    => $row['entry_no'],
                'date'        => $row['entry_date'],
                'description' => $row['description'],
                'debit'       => (float) $row['debit'],
                'credit'      => (float) $row['credit'],
                'balance'     => round($accounts[$code]['running'], 2),
            ];
        }

        return [
            'period'   => ['from' => $from, 'to' => $to],
            'accounts' => array_values(array_map(static function (array $a): array {
                unset($a['running']);
                return $a;
            }, $accounts)),
        ];
    }

    /**
     * Account ledger: one account's movement in the range, with an
     * opening balance carried in and a running balance.
     */
    public function accountLedger(int $accountId, string $from, string $to): array
    {
        $database = db_connect();

        $account = $database->query(
            "SELECT a.code, a.name FROM accounts a WHERE a.id = ?",
            [$accountId],
        )->getRowArray();

        if ($account === null) {
            return [];
        }

        $opening = (float) ($database->query(
            "SELECT COALESCE(SUM(l.debit - l.credit),0) v FROM journal_entry_lines l
             JOIN journal_entries e ON e.id = l.journal_entry_id
             WHERE e.status='posted' AND e.entry_date < ? AND l.account_id = ?",
            [$from, $accountId],
        )->getRow()->v);

        $rows = $database->query(
            "SELECT e.entry_no, e.entry_date, e.description, l.debit, l.credit
             FROM journal_entry_lines l
             JOIN journal_entries e ON e.id = l.journal_entry_id
             WHERE e.status='posted' AND e.entry_date BETWEEN ? AND ? AND l.account_id = ?
             ORDER BY e.entry_date, e.id",
            [$from, $to, $accountId],
        )->getResultArray();

        $running = $opening;
        $lines = [];
        foreach ($rows as $row) {
            $running += (float) $row['debit'] - (float) $row['credit'];
            $lines[] = [
                'entry_no'    => $row['entry_no'],
                'date'        => $row['entry_date'],
                'description' => $row['description'],
                'debit'       => (float) $row['debit'],
                'credit'      => (float) $row['credit'],
                'balance'     => round($running, 2),
            ];
        }

        return [
            'account' => $account['code'] . ' ' . $account['name'],
            'period'  => ['from' => $from, 'to' => $to],
            'opening_balance' => round($opening, 2),
            'closing_balance' => round($running, 2),
            'lines'   => $lines,
        ];
    }

    /**
     * Per-account totals for one account type over a period, on the
     * account's natural side.
     *
     * @return list<array{code:string,name:string,amount:float}>
     */
    private function accountActivity(string $typeCode, ?string $from, string $to, string $side): array
    {
        $params = [$typeCode, $to];
        $fromClause = '';
        if ($from !== null) {
            $fromClause = ' AND e.entry_date >= ?';
            $params = [$typeCode, $to, $from];
        }

        $sign = $side === 'debit' ? 'l.debit - l.credit' : 'l.credit - l.debit';

        $rows = db_connect()->query(
            "SELECT a.code, a.name, COALESCE(SUM($sign), 0) AS amount
             FROM accounts a
             JOIN account_groups g ON g.id = a.account_group_id
             JOIN account_types t  ON t.id = g.account_type_id
             LEFT JOIN journal_entry_lines l ON l.account_id = a.id
             LEFT JOIN journal_entries e ON e.id = l.journal_entry_id
                  AND e.status = 'posted' AND e.entry_date <= ?" . $fromClause . "
             WHERE t.code = ? AND a.deleted_at IS NULL
             GROUP BY a.id, a.code, a.name, t.sort_order
             HAVING amount <> 0
             ORDER BY a.code",
            // params order: the entry_date bounds first (they appear in the JOIN), then type
            $from !== null ? [$to, $from, $typeCode] : [$to, $typeCode],
        )->getResultArray();

        return array_map(static fn (array $r): array => [
            'code'   => $r['code'],
            'name'   => $r['name'],
            'amount' => round((float) $r['amount'], 2),
        ], $rows);
    }
}
