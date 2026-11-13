<?php
namespace App\Services\Web;

use App\Services\StatementService;
use InvalidArgumentException;

/** Web display filters retain running balances from the complete customer ledger. */
class CustomerStatementService
{
    public function get(int $customerId, int $branchId, array $filters): array
    {
        foreach (['date_from', 'date_to'] as $field) {
            $value = (string) ($filters[$field] ?? '');
            if ($value !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) || !checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4)))) throw new InvalidArgumentException('Select valid statement dates.');
        }
        $from = (string) ($filters['date_from'] ?? '');
        $to = (string) ($filters['date_to'] ?? '');
        if ($from && $to && $from > $to) throw new InvalidArgumentException('The start date must be before the end date.');
        $statement = (new StatementService())->customerStatement($customerId, $branchId, []);
        if (!$statement) throw new InvalidArgumentException('Customer not found.');
        $opening = 0.0;
        $closing = 0.0;
        $openingFound = false;
        $closingFound = false;
        $entries = [];
        $search = mb_strtolower(trim((string) ($filters['q'] ?? '')));
        $type = (string) ($filters['type'] ?? '');
        foreach ($statement['entries'] as $entry) {
            if (!$openingFound && $from && $entry['date'] < $from) { $opening = $entry['balance']; $openingFound = true; }
            if (!$closingFound && (!$to || $entry['date'] <= $to)) { $closing = $entry['balance']; $closingFound = true; }
            if (($from && $entry['date'] < $from) || ($to && $entry['date'] > $to) || ($type && $entry['type'] !== $type) || ($search && !str_contains(mb_strtolower((string) $entry['ref']), $search))) continue;
            $entries[] = $entry;
        }
        return ['party' => $statement['party'], 'entries' => $entries, 'filters' => ['date_from' => $from, 'date_to' => $to, 'type' => $type, 'q' => $search], 'summary' => ['opening_balance' => $opening, 'closing_balance' => $closing, 'total_debit' => array_sum(array_column($entries, 'debit')), 'total_credit' => array_sum(array_column($entries, 'credit'))]];
    }
}
