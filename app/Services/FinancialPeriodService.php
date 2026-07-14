<?php

namespace App\Services;

use InvalidArgumentException;

/**
 * Accounting months that can be CLOSED. Once a period is closed,
 * AccountingService refuses any journal entry dated inside it, which
 * locks that month's books. Reopening is allowed (Owner-level) so
 * corrections stay possible.
 */
class FinancialPeriodService
{
    public function list(int $page, int $perPage): array
    {
        $builder = db_connect()->table('financial_periods')
            ->select('financial_periods.*, users.name AS closed_by_name')
            ->join('users', 'users.id = financial_periods.closed_by', 'left');

        $total = $builder->countAllResults(false);
        $rows  = $builder->orderBy('start_date', 'DESC')
            ->limit($perPage, ($page - 1) * $perPage)
            ->get()
            ->getResultArray();

        foreach ($rows as &$row) {
            $row['id']        = (int) $row['id'];
            $row['closed_by'] = $row['closed_by'] === null ? null : (int) $row['closed_by'];
        }

        return ['items' => $rows, 'total' => $total];
    }

    /**
     * @param array $data {name, start_date, end_date}
     */
    public function create(array $data): array
    {
        $name      = trim((string) ($data['name'] ?? ''));
        $startDate = (string) ($data['start_date'] ?? '');
        $endDate   = (string) ($data['end_date'] ?? '');

        if ($name === '' || ! $this->isDate($startDate) || ! $this->isDate($endDate)) {
            throw new InvalidArgumentException('INVALID_PERIOD');
        }

        if ($startDate > $endDate) {
            throw new InvalidArgumentException('INVALID_PERIOD');
        }

        $database = db_connect();

        // Periods must not overlap — each date belongs to one month's books.
        $overlaps = $database->table('financial_periods')
            ->where('start_date <=', $endDate)
            ->where('end_date >=', $startDate)
            ->countAllResults();

        if ($overlaps > 0) {
            throw new InvalidArgumentException('PERIOD_OVERLAP');
        }

        $database->table('financial_periods')->insert([
            'name'       => $name,
            'start_date' => $startDate,
            'end_date'   => $endDate,
        ]);

        $periodId = (int) $database->insertID();

        AuditService::log('financial_period.create', 'accounting', 'financial_periods', $periodId, details: [
            'range' => "$startDate..$endDate",
        ]);

        return $this->find($periodId);
    }

    public function close(int $periodId, int $userId): array
    {
        return $this->setStatus($periodId, 'closed', $userId);
    }

    public function reopen(int $periodId, int $userId): array
    {
        return $this->setStatus($periodId, 'open', $userId);
    }

    private function setStatus(int $periodId, string $status, int $userId): array
    {
        $database = db_connect();
        $period   = $database->table('financial_periods')->where('id', $periodId)->get()->getRowArray();

        if ($period === null) {
            throw new InvalidArgumentException('PERIOD_NOT_FOUND');
        }

        if ($period['status'] === $status) {
            throw new InvalidArgumentException('PERIOD_ALREADY_' . strtoupper($status));
        }

        $database->table('financial_periods')->where('id', $periodId)->update([
            'status'    => $status,
            'closed_by' => $status === 'closed' ? $userId : null,
            'closed_at' => $status === 'closed' ? date('Y-m-d H:i:s') : null,
        ]);

        AuditService::log("financial_period.$status", 'accounting', 'financial_periods', $periodId);

        return $this->find($periodId);
    }

    private function find(int $periodId): array
    {
        $period       = db_connect()->table('financial_periods')->where('id', $periodId)->get()->getRowArray();
        $period['id'] = (int) $period['id'];

        return $period;
    }

    private function isDate(string $value): bool
    {
        return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) && strtotime($value) !== false;
    }
}
