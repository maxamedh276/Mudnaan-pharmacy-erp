<?php

namespace App\Services;

use InvalidArgumentException;

/**
 * Bank accounts, each backed by its OWN postable GL account created
 * automatically under Current Assets (codes 1011, 1012, …). The
 * account's balance is therefore always the journal's truth — receipts
 * and payments that select a bank hit its GL account directly.
 */
class BankAccountService
{
    public function __construct(
        private readonly AccountingService $accounting = new AccountingService(),
    ) {
    }

    public function list(): array
    {
        $rows = db_connect()->query(
            "SELECT b.id, b.name, b.bank_name, b.account_number, b.currency,
                    b.opening_balance, b.is_active, b.account_id,
                    a.code AS account_code,
                    COALESCE(SUM(l.debit), 0) - COALESCE(SUM(l.credit), 0) AS balance
             FROM bank_accounts b
             JOIN accounts a ON a.id = b.account_id
             LEFT JOIN journal_entry_lines l ON l.account_id = b.account_id
             WHERE b.deleted_at IS NULL
             GROUP BY b.id, b.name, b.bank_name, b.account_number, b.currency,
                      b.opening_balance, b.is_active, b.account_id, a.code
             ORDER BY b.name",
        )->getResultArray();

        foreach ($rows as &$row) {
            $row['id']              = (int) $row['id'];
            $row['account_id']      = (int) $row['account_id'];
            $row['opening_balance'] = (float) $row['opening_balance'];
            $row['is_active']       = (bool) $row['is_active'];
            $row['balance']         = round((float) $row['balance'], 2);
        }

        return $rows;
    }

    /**
     * @param array $data {name, bank_name?, account_number?, currency?, opening_balance?}
     */
    public function create(array $data, int $branchId, int $userId): array
    {
        $name = trim((string) ($data['name'] ?? ''));

        if ($name === '') {
            throw new InvalidArgumentException('NAME_REQUIRED');
        }

        $openingBalance = round((float) ($data['opening_balance'] ?? 0), 2);

        if ($openingBalance < 0) {
            throw new InvalidArgumentException('INVALID_AMOUNT');
        }

        $database = db_connect();
        $database->transException(true)->transStart();

        // 1) A dedicated GL account under Current Assets: next 10xx code.
        $maxCode = $database->query(
            "SELECT MAX(CAST(code AS UNSIGNED)) AS max_code
             FROM accounts WHERE code REGEXP '^10[0-9][0-9]$'",
        )->getRowArray();

        $glCode = (string) (max(1010, (int) $maxCode['max_code']) + 1);

        $database->query(
            "INSERT INTO accounts (account_group_id, code, name, is_system)
             SELECT id, ?, ?, 0 FROM account_groups WHERE code = 'CA'",
            [$glCode, 'Bank — ' . $name],
        );

        $glAccountId = (int) $database->insertID();

        // 2) The bank account row itself.
        $database->table('bank_accounts')->insert([
            'account_id'      => $glAccountId,
            'name'            => $name,
            'bank_name'       => $data['bank_name'] ?? null,
            'account_number'  => $data['account_number'] ?? null,
            'currency'        => $data['currency'] ?? 'USD',
            'opening_balance' => $openingBalance,
        ]);

        $bankAccountId = (int) $database->insertID();

        // 3) The opening money, on the books.
        $this->accounting->postBankOpening($branchId, $glAccountId, $openingBalance, $name, $userId);

        $database->transComplete();

        AuditService::log('bank_account.create', 'bank_accounts', $bankAccountId, [
            'gl_code' => $glCode, 'opening' => $openingBalance,
        ]);

        return $this->findOrFail($bankAccountId);
    }

    public function update(int $bankAccountId, array $data): array
    {
        $database = db_connect();
        $existing = $database->table('bank_accounts')
            ->where('id', $bankAccountId)->where('deleted_at', null)
            ->get()->getRowArray();

        if ($existing === null) {
            throw new InvalidArgumentException('BANK_ACCOUNT_NOT_FOUND');
        }

        $fields = array_intersect_key($data, array_flip(['name', 'bank_name', 'account_number', 'currency', 'is_active']));

        if ($fields !== []) {
            $database->table('bank_accounts')->where('id', $bankAccountId)->update($fields);

            if (isset($fields['name'])) {
                $database->table('accounts')->where('id', (int) $existing['account_id'])
                    ->update(['name' => 'Bank — ' . $fields['name']]);
            }
        }

        AuditService::log('bank_account.update', 'bank_accounts', $bankAccountId);

        return $this->findOrFail($bankAccountId);
    }

    private function findOrFail(int $bankAccountId): array
    {
        foreach ($this->list() as $account) {
            if ($account['id'] === $bankAccountId) {
                return $account;
            }
        }

        throw new InvalidArgumentException('BANK_ACCOUNT_NOT_FOUND');
    }
}
