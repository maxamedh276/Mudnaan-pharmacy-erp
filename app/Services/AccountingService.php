<?php

namespace App\Services;

use App\Libraries\Uuid;
use InvalidArgumentException;
use RuntimeException;

/**
 * AUTO-POSTING — the bridge between the operational side (sales,
 * purchases) and the books (journal entries). See backend_structure.md §6.
 *
 * Accounts are looked up by their CODE from the seeded chart of accounts
 * (tenant_accounting.sql): 1000 Cash, 1100 Receivable, 1200 Inventory,
 * 2000 Payable, 2100 Tax Payable, 4000 Sales Revenue, 5000 COGS.
 *
 * Premium tenants only — Basic tenants skip accounting entirely.
 */
class AccountingService
{
    private const CASH        = '1000';
    private const RECEIVABLE  = '1100';
    private const INVENTORY   = '1200';
    private const PAYABLE     = '2000';
    private const TAX_PAYABLE = '2100';
    private const REVENUE     = '4000';
    private const COGS        = '5000';

    /** @var array<string, int> account code => id, cached per request */
    private array $accountIds = [];

    /**
     * A completed sale, as balanced double-entry lines:
     *   DEBIT  Cash (paid part) / Accounts Receivable (unpaid part)
     *   CREDIT Sales Revenue (net) + Tax Payable
     *   DEBIT  COGS / CREDIT Inventory (at cost)
     */
    public function postSale(array $sale, float $costOfGoods, int $branchId, ?int $userId): void
    {
        $unpaid = round($sale['grand_total'] - $sale['paid_total'], 2);
        $net    = round($sale['grand_total'] - $sale['tax_total'], 2);

        $lines = [
            ['code' => self::CASH,        'debit'  => min($sale['paid_total'], $sale['grand_total'])],
            ['code' => self::RECEIVABLE,  'debit'  => max($unpaid, 0)],
            ['code' => self::REVENUE,     'credit' => $net],
            ['code' => self::TAX_PAYABLE, 'credit' => $sale['tax_total']],
            ['code' => self::COGS,        'debit'  => $costOfGoods],
            ['code' => self::INVENTORY,   'credit' => $costOfGoods],
        ];

        $this->createEntry(
            branchId: $branchId,
            entryDate: substr((string) $sale['sold_at'], 0, 10),
            description: 'Sale ' . $sale['invoice_no'],
            sourceType: 'sale',
            sourceId: (int) $sale['id'],
            lines: $lines,
            userId: $userId,
        );
    }

    /**
     * A received purchase: DEBIT Inventory / CREDIT Cash (paid) +
     * Accounts Payable (owed).
     */
    public function postPurchase(array $purchase, float $paidAmount, int $branchId, ?int $userId): void
    {
        $owed = round($purchase['total'] - $paidAmount, 2);

        $lines = [
            ['code' => self::INVENTORY, 'debit'  => (float) $purchase['total']],
            ['code' => self::CASH,      'credit' => $paidAmount],
            ['code' => self::PAYABLE,   'credit' => max($owed, 0)],
        ];

        $this->createEntry(
            branchId: $branchId,
            entryDate: substr((string) $purchase['purchased_at'], 0, 10),
            description: 'Purchase ' . ($purchase['reference_no'] ?: '#' . $purchase['id']),
            sourceType: 'purchase',
            sourceId: (int) $purchase['id'],
            lines: $lines,
            userId: $userId,
        );
    }

    /**
     * Writes one balanced journal entry + its lines. Zero-amount lines
     * are dropped; debits must equal credits or nothing is saved.
     *
     * @param list<array{code?: string, account_id?: int, debit?: float, credit?: float, description?: string}> $lines
     */
    public function createEntry(
        int $branchId,
        string $entryDate,
        ?string $description,
        ?string $sourceType,
        ?int $sourceId,
        array $lines,
        ?int $userId = null,
    ): int {
        $database = db_connect();

        $totalDebit  = 0.0;
        $totalCredit = 0.0;
        $lineRows    = [];

        foreach ($lines as $line) {
            $debit  = round((float) ($line['debit'] ?? 0), 2);
            $credit = round((float) ($line['credit'] ?? 0), 2);

            if ($debit == 0.0 && $credit == 0.0) {
                continue;
            }

            if ($debit > 0 && $credit > 0) {
                throw new InvalidArgumentException('A journal line must be a debit OR a credit, not both.');
            }

            $accountId = $line['account_id'] ?? $this->accountIdByCode($line['code']);

            $totalDebit  += $debit;
            $totalCredit += $credit;
            $lineRows[]   = [
                'account_id'  => $accountId,
                'branch_id'   => $branchId,
                'debit'       => $debit,
                'credit'      => $credit,
                'description' => $line['description'] ?? null,
            ];
        }

        if ($lineRows === [] || round($totalDebit, 2) !== round($totalCredit, 2)) {
            throw new InvalidArgumentException(sprintf(
                'Journal entry does not balance: debits %.2f vs credits %.2f',
                $totalDebit,
                $totalCredit,
            ));
        }

        $database->table('journal_entries')->insert([
            'uuid'         => Uuid::v4(),
            'branch_id'    => $branchId,
            'entry_date'   => $entryDate,
            'description'  => $description,
            'source_type'  => $sourceType,
            'source_id'    => $sourceId,
            'total_debit'  => $totalDebit,
            'total_credit' => $totalCredit,
            'status'       => 'posted',
            'created_by'   => $userId,
        ]);

        $entryId = (int) $database->insertID();

        $database->table('journal_entries')
            ->where('id', $entryId)
            ->update(['entry_no' => 'JV-' . str_pad((string) $entryId, 5, '0', STR_PAD_LEFT)]);

        foreach ($lineRows as &$lineRow) {
            $lineRow['journal_entry_id'] = $entryId;
        }

        $database->table('journal_entry_lines')->insertBatch($lineRows);

        return $entryId;
    }

    private function accountIdByCode(string $code): int
    {
        if (! isset($this->accountIds[$code])) {
            $row = db_connect()->table('accounts')
                ->select('id')
                ->where('code', $code)
                ->where('is_postable', 1)
                ->get()
                ->getRowArray();

            if ($row === null) {
                throw new RuntimeException("Account with code {$code} not found in the chart of accounts.");
            }

            $this->accountIds[$code] = (int) $row['id'];
        }

        return $this->accountIds[$code];
    }
}
