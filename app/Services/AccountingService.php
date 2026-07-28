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
    public const CASH            = '1000';
    public const BANK_DEFAULT    = '1010';
    public const RECEIVABLE      = '1100';
    public const INVENTORY       = '1200';
    public const PAYABLE         = '2000';
    public const TAX_PAYABLE     = '2100';
    public const DEPOSITS        = '2200';
    public const OWNERS_CAPITAL  = '3000';
    public const REVENUE         = '4000';
    public const SALES_DISCOUNT  = '4200';
    public const COGS            = '5000';
    public const GENERAL_EXPENSE = '6900';

    /** @var array<string, int> account code => id, cached per request */
    private array $accountIds = [];

    /**
     * A completed sale, as balanced double-entry lines:
     *   DEBIT  Cash (paid part) / Accounts Receivable (unpaid part)
     *   CREDIT Sales Revenue (net) + Tax Payable
     *   DEBIT  COGS / CREDIT Inventory (at cost)
     */
    /**
     * @param array $payments list of {method, amount, bank_account_id?} — the
     *                        paid portion is debited to each payment's chosen
     *                        money account (Cash on Hand, a till, a bank/wallet).
     */
    public function postSale(array $sale, array $payments, float $costOfGoods, int $branchId, ?int $userId): void
    {
        $unpaid = round($sale['grand_total'] - $sale['paid_total'], 2);
        $net    = round($sale['grand_total'] - $sale['tax_total'], 2);

        $lines = [
            // One debit per payment, routed to its money account. Capped at
            // the sale total so cash overpayment (change given back) never
            // inflates the books.
            ...$this->moneyDebitLines($payments, min($sale['paid_total'], $sale['grand_total'])),
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

    public const INVENTORY_ADJ = '5100';

    /**
     * A manual stock adjustment at cost value:
     *   stock UP   : DEBIT Inventory / CREDIT Inventory Adjustment (a gain)
     *   stock DOWN : DEBIT Inventory Adjustment / CREDIT Inventory (a loss)
     */
    public function postInventoryAdjustment(
        int $branchId,
        float $value,
        bool $increase,
        int $productId,
        ?int $userId,
    ): void {
        if ($value <= 0) {
            return;
        }

        $lines = $increase
            ? [
                ['code' => self::INVENTORY,     'debit'  => $value],
                ['code' => self::INVENTORY_ADJ, 'credit' => $value],
            ]
            : [
                ['code' => self::INVENTORY_ADJ, 'debit'  => $value],
                ['code' => self::INVENTORY,     'credit' => $value],
            ];

        $this->createEntry(
            branchId: $branchId,
            entryDate: date('Y-m-d'),
            description: 'Stock adjustment (product #' . $productId . ')',
            sourceType: 'stock_adjustment',
            sourceId: $productId,
            lines: $lines,
            userId: $userId,
        );
    }

    /**
     * Opening stock booked when a product is created with a quantity:
     *   DEBIT Inventory / CREDIT Owner's Capital, valued at cost.
     */
    public function postOpeningStock(int $branchId, float $value, int $productId, ?int $userId): void
    {
        if ($value <= 0) {
            return;
        }

        $this->createEntry(
            branchId: $branchId,
            entryDate: date('Y-m-d'),
            description: 'Opening stock (product #' . $productId . ')',
            sourceType: 'opening_stock',
            sourceId: $productId,
            lines: [
                ['code' => self::INVENTORY,      'debit'  => $value],
                ['code' => self::OWNERS_CAPITAL, 'credit' => $value],
            ],
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
            [
                'account_id' => $this->cashOrBankAccountId($purchase['bank_account_id'] ?? null, $purchase['method'] ?? 'cash'),
                'credit'     => $paidAmount,
            ],
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
     * A standalone supplier bill (services, utilities, rent invoices…):
     *   DEBIT General Expense / CREDIT Accounts Payable.
     * Goods flows post through purchases instead, so books and the
     * stock ledger never diverge. Purchase-linked bills post nothing —
     * the purchase already did.
     */
    public function postSupplierBill(array $bill, ?int $userId): void
    {
        $this->createEntry(
            branchId: (int) $bill['branch_id'],
            entryDate: (string) $bill['bill_date'],
            description: 'Supplier bill ' . ($bill['bill_no'] ?: '#' . $bill['id']),
            sourceType: 'supplier_bill',
            sourceId: (int) $bill['id'],
            lines: [
                ['code' => self::GENERAL_EXPENSE, 'debit'  => (float) $bill['total']],
                ['code' => self::PAYABLE,         'credit' => (float) $bill['total']],
            ],
            userId: $userId,
        );
    }

    /**
     * Money leaving for a supplier: DEBIT Accounts Payable /
     * CREDIT Cash or the chosen bank account.
     */
    public function postSupplierPayment(array $payment, ?int $userId): void
    {
        $this->createEntry(
            branchId: (int) $payment['branch_id'],
            entryDate: substr((string) $payment['paid_at'], 0, 10),
            description: 'Supplier payment ' . $payment['payment_no'],
            sourceType: 'supplier_payment',
            sourceId: (int) $payment['id'],
            lines: [
                ['code' => self::PAYABLE, 'debit' => (float) $payment['amount']],
                [
                    'account_id' => $this->cashOrBankAccountId($payment['bank_account_id'] ?? null, $payment['method']),
                    'credit'     => (float) $payment['amount'],
                ],
            ],
            userId: $userId,
        );
    }

    /**
     * A standalone customer invoice (credit sale as a document):
     *   DEBIT Accounts Receivable / CREDIT Revenue + Tax Payable,
     *   plus COGS/Inventory for any product lines.
     * Sale-linked invoices post nothing — the sale already did.
     */
    public function postCustomerInvoice(array $invoice, float $costOfGoods, ?int $userId): void
    {
        $lines = [
            ['code' => self::RECEIVABLE,  'debit'  => (float) $invoice['total']],
            ['code' => self::REVENUE,     'credit' => round($invoice['total'] - $invoice['tax_total'], 2)],
            ['code' => self::TAX_PAYABLE, 'credit' => (float) $invoice['tax_total']],
            ['code' => self::COGS,        'debit'  => $costOfGoods],
            ['code' => self::INVENTORY,   'credit' => $costOfGoods],
        ];

        $this->createEntry(
            branchId: (int) $invoice['branch_id'],
            entryDate: (string) $invoice['invoice_date'],
            description: 'Invoice ' . $invoice['invoice_no'],
            sourceType: 'customer_invoice',
            sourceId: (int) $invoice['id'],
            lines: $lines,
            userId: $userId,
        );
    }

    /**
     * Money received from a customer: DEBIT Cash/Bank (received) +
     * DEBIT Sales Discounts (any waived amount) / CREDIT AR (the full
     * settled total). A zero discount drops its line automatically.
     */
    public function postCustomerReceipt(array $receipt, ?int $userId): void
    {
        $amount   = (float) $receipt['amount'];
        $discount = round((float) ($receipt['discount'] ?? 0), 2);

        $this->createEntry(
            branchId: (int) $receipt['branch_id'],
            entryDate: substr((string) $receipt['received_at'], 0, 10),
            description: 'Receipt ' . $receipt['receipt_no'],
            sourceType: 'customer_receipt',
            sourceId: (int) $receipt['id'],
            lines: [
                [
                    'account_id' => $this->cashOrBankAccountId($receipt['bank_account_id'] ?? null, $receipt['method']),
                    'debit'      => $amount,
                ],
                ['code' => self::SALES_DISCOUNT, 'debit'  => $discount],
                ['code' => self::RECEIVABLE,     'credit' => round($amount + $discount, 2)],
            ],
            userId: $userId,
        );
    }

    /**
     * An advance from a customer is a LIABILITY until it is spent:
     *   DEBIT Cash/Bank / CREDIT Customer Deposits.
     * A refund posts the exact reverse.
     */
    public function postCustomerDeposit(array $deposit, ?int $userId, bool $refund = false): void
    {
        $moneyLine = [
            'account_id' => $this->cashOrBankAccountId($deposit['bank_account_id'] ?? null, $deposit['method']),
        ];
        $depositLine = ['code' => self::DEPOSITS];

        if ($refund) {
            $moneyLine['credit'] = (float) $deposit['amount'];
            $depositLine['debit'] = (float) $deposit['amount'];
        } else {
            $moneyLine['debit'] = (float) $deposit['amount'];
            $depositLine['credit'] = (float) $deposit['amount'];
        }

        $this->createEntry(
            branchId: (int) $deposit['branch_id'],
            entryDate: substr((string) $deposit['deposited_at'], 0, 10),
            description: ($refund ? 'Deposit refund ' : 'Deposit ') . $deposit['deposit_no'],
            sourceType: $refund ? 'deposit_refund' : 'customer_deposit',
            sourceId: (int) $deposit['id'],
            lines: [$moneyLine, $depositLine],
            userId: $userId,
        );
    }

    /**
     * Opening balance when a bank account is registered:
     *   DEBIT the bank's GL account / CREDIT Owner's Capital.
     */
    public function postBankOpening(int $branchId, int $glAccountId, float $amount, string $name, ?int $userId): void
    {
        if ($amount == 0.0) {
            return;
        }

        $this->createEntry(
            branchId: $branchId,
            entryDate: date('Y-m-d'),
            description: 'Opening balance — ' . $name,
            sourceType: 'bank_opening',
            sourceId: null,
            lines: [
                ['account_id' => $glAccountId, 'debit' => $amount],
                ['code' => self::OWNERS_CAPITAL, 'credit' => $amount],
            ],
            userId: $userId,
        );
    }

    /**
     * Splits an applied amount into one debit line per payment, each routed
     * to the money account it landed in. Any shortfall (e.g. no payment rows
     * but money was applied) falls back to Cash on Hand so the entry balances.
     *
     * @param array $payments list of {method, amount, bank_account_id?}
     * @return list<array<string,mixed>>
     */
    private function moneyDebitLines(array $payments, float $applied): array
    {
        $applied = round($applied, 2);

        if ($applied <= 0) {
            return [];
        }

        $lines     = [];
        $remaining = $applied;

        foreach ($payments as $payment) {
            if ($remaining <= 0) {
                break;
            }

            $portion = min(round((float) ($payment['amount'] ?? 0), 2), $remaining);

            if ($portion <= 0) {
                continue;
            }

            $lines[]   = [
                'account_id' => $this->cashOrBankAccountId($payment['bank_account_id'] ?? null, $payment['method']),
                'debit'      => $portion,
            ];
            $remaining = round($remaining - $portion, 2);
        }

        if ($remaining > 0) {
            $lines[] = ['code' => self::CASH, 'debit' => $remaining];
        }

        return $lines;
    }

    /**
     * Which GL account money physically hits: an explicit money account
     * (a cash till or a bank/wallet, by bank_accounts row id) wins;
     * otherwise cash goes to Cash on Hand and every electronic method
     * defaults to the built-in Bank Account.
     */
    public function cashOrBankAccountId(?int $bankAccountId, string $method): int
    {
        if ($bankAccountId !== null && $bankAccountId > 0) {
            $row = db_connect()->table('bank_accounts')
                ->select('account_id')
                ->where('id', $bankAccountId)
                ->where('deleted_at', null)
                ->get()
                ->getRowArray();

            if ($row === null) {
                throw new InvalidArgumentException('BANK_ACCOUNT_NOT_FOUND');
            }

            return (int) $row['account_id'];
        }

        return $this->accountIdByCode($method === 'cash' ? self::CASH : self::BANK_DEFAULT);
    }

    /**
     * Writes one balanced journal entry + its lines. Zero-amount lines
     * are dropped; debits must equal credits or nothing is saved.
     * Entries dated inside a CLOSED financial period are rejected.
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

        $closedPeriod = $database->table('financial_periods')
            ->where('status', 'closed')
            ->where('start_date <=', $entryDate)
            ->where('end_date >=', $entryDate)
            ->countAllResults();

        if ($closedPeriod > 0) {
            throw new RuntimeException('PERIOD_CLOSED');
        }

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
            $this->assertPostable($accountId);

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

    /**
     * Reverses every posted journal entry raised by a source document
     * by creating a mirror entry (debits and credits swapped) and
     * marking the originals 'void'. Financial rows are never deleted —
     * the original and its reversal both remain for the audit trail.
     *
     * @return int number of entries reversed
     */
    public function reverseSource(string $sourceType, int $sourceId, int $branchId, ?int $userId): int
    {
        $database = db_connect();

        $entries = $database->table('journal_entries')
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->where('status', 'posted')
            ->get()
            ->getResultArray();

        foreach ($entries as $entry) {
            $lines = $database->table('journal_entry_lines')
                ->where('journal_entry_id', $entry['id'])
                ->get()
                ->getResultArray();

            $reversedLines = array_map(static fn (array $line): array => [
                'account_id' => (int) $line['account_id'],
                // swap sides to undo the original
                'debit'      => (float) $line['credit'],
                'credit'     => (float) $line['debit'],
                'description'=> 'Reversal',
            ], $lines);

            $this->createEntry(
                branchId: $branchId,
                entryDate: date('Y-m-d'),
                description: 'Reversal of ' . ($entry['entry_no'] ?? ('#' . $entry['id'])),
                sourceType: $sourceType . '_reversal',
                sourceId: $sourceId,
                lines: $reversedLines,
                userId: $userId,
            );

            $database->table('journal_entries')
                ->where('id', $entry['id'])
                ->update(['status' => 'void']);
        }

        return count($entries);
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

    /**
     * Journal lines may only hit an account that is active and postable
     * (header/deactivated accounts are rejected before anything saves).
     */
    private function assertPostable(int $accountId): void
    {
        $row = db_connect()->table('accounts')
            ->select('is_postable, is_active')
            ->where('id', $accountId)
            ->where('deleted_at', null)
            ->get()
            ->getRowArray();

        if ($row === null || ! (int) $row['is_postable'] || ! (int) $row['is_active']) {
            throw new RuntimeException('INACTIVE_ACCOUNT');
        }
    }
}
