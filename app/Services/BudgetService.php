<?php

namespace App\Services;

use InvalidArgumentException;

/**
 * BUDGET VS ACTUAL — planned amounts per GL account for a date range,
 * compared against real journal activity in that range.
 *
 * "Actual" is measured on the account's natural side: expenses count
 * debits minus credits, income counts credits minus debits, so both
 * compare positively against their budget.
 */
class BudgetService
{
    public function list(int $branchId, int $page, int $perPage): array
    {
        $builder = db_connect()->table('budgets')
            ->select('id, name, start_date, end_date, status, notes')
            ->where('branch_id', $branchId)
            ->where('deleted_at', null);

        $total = $builder->countAllResults(false);
        $rows  = $builder->orderBy('start_date', 'DESC')
            ->limit($perPage, ($page - 1) * $perPage)
            ->get()
            ->getResultArray();

        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
        }

        return ['items' => $rows, 'total' => $total];
    }

    /**
     * @param array $data {name, start_date, end_date, notes?,
     *                     lines: [{account_id, amount}]}
     */
    public function create(array $data, int $branchId, int $userId): array
    {
        $name      = trim((string) ($data['name'] ?? ''));
        $startDate = (string) ($data['start_date'] ?? '');
        $endDate   = (string) ($data['end_date'] ?? '');
        $lines     = $data['lines'] ?? [];

        if ($name === '' || $startDate === '' || $endDate === '' || $startDate > $endDate) {
            throw new InvalidArgumentException('INVALID_BUDGET');
        }

        if (! is_array($lines) || $lines === []) {
            throw new InvalidArgumentException('EMPTY_BUDGET');
        }

        $database = db_connect();

        $lineRows = [];
        $seen     = [];

        foreach ($lines as $line) {
            $accountId = (int) ($line['account_id'] ?? 0);
            $amount    = round((float) ($line['amount'] ?? 0), 2);

            if ($accountId <= 0 || $amount < 0 || isset($seen[$accountId])) {
                throw new InvalidArgumentException('INVALID_BUDGET_LINE');
            }

            $exists = $database->table('accounts')
                ->where('id', $accountId)->where('deleted_at', null)
                ->countAllResults();

            if ($exists === 0) {
                throw new InvalidArgumentException('ACCOUNT_NOT_FOUND');
            }

            $seen[$accountId] = true;
            $lineRows[]       = ['account_id' => $accountId, 'amount' => $amount];
        }

        $database->transException(true)->transStart();

        $database->table('budgets')->insert([
            'branch_id'  => $branchId,
            'name'       => $name,
            'start_date' => $startDate,
            'end_date'   => $endDate,
            'notes'      => $data['notes'] ?? null,
            'created_by' => $userId,
        ]);

        $budgetId = (int) $database->insertID();

        foreach ($lineRows as $index => $lineRow) {
            $lineRows[$index]['budget_id'] = $budgetId;
        }

        $database->table('budget_lines')->insertBatch($lineRows);

        $database->transComplete();

        AuditService::log('budget.create', 'accounting', 'budgets', $budgetId, details: ['lines' => count($lineRows)]);

        return $this->report($budgetId, $branchId);
    }

    /**
     * The Budget vs Actual report: every budgeted account with its
     * planned amount, actual journal activity, variance and usage %.
     */
    public function report(int $budgetId, int $branchId): array
    {
        $database = db_connect();

        $budget = $database->table('budgets')
            ->where('id', $budgetId)
            ->where('branch_id', $branchId)
            ->where('deleted_at', null)
            ->get()
            ->getRowArray();

        if ($budget === null) {
            throw new InvalidArgumentException('BUDGET_NOT_FOUND');
        }

        $budget['id'] = (int) $budget['id'];

        $lines = $database->query(
            "SELECT bl.account_id, a.code AS account_code, a.name AS account_name,
                    t.normal_balance, bl.amount AS budgeted,
                    COALESCE(SUM(l.debit), 0)  AS actual_debit,
                    COALESCE(SUM(l.credit), 0) AS actual_credit
             FROM budget_lines bl
             JOIN accounts a        ON a.id = bl.account_id
             JOIN account_groups g  ON g.id = a.account_group_id
             JOIN account_types t   ON t.id = g.account_type_id
             LEFT JOIN journal_entry_lines l ON l.account_id = bl.account_id
                  AND l.journal_entry_id IN (
                      SELECT id FROM journal_entries
                      WHERE entry_date BETWEEN ? AND ? AND status = 'posted'
                  )
             WHERE bl.budget_id = ?
             GROUP BY bl.account_id, a.code, a.name, t.normal_balance, bl.amount
             ORDER BY a.code",
            [$budget['start_date'], $budget['end_date'], $budgetId],
        )->getResultArray();

        $totalBudgeted = 0.0;
        $totalActual   = 0.0;

        foreach ($lines as &$line) {
            $debit  = (float) $line['actual_debit'];
            $credit = (float) $line['actual_credit'];
            $actual = $line['normal_balance'] === 'debit'
                ? round($debit - $credit, 2)
                : round($credit - $debit, 2);

            $budgeted = (float) $line['budgeted'];

            $line['account_id'] = (int) $line['account_id'];
            $line['budgeted']   = $budgeted;
            $line['actual']     = $actual;
            $line['variance']   = round($budgeted - $actual, 2);
            $line['used_pct']   = $budgeted > 0 ? round($actual / $budgeted * 100, 1) : null;
            unset($line['actual_debit'], $line['actual_credit']);

            $totalBudgeted += $budgeted;
            $totalActual   += $actual;
        }

        $budget['totals'] = [
            'budgeted' => round($totalBudgeted, 2),
            'actual'   => round($totalActual, 2),
            'variance' => round($totalBudgeted - $totalActual, 2),
        ];
        $budget['lines'] = $lines;

        return $budget;
    }
}
