<?php

namespace App\Services;

use InvalidArgumentException;

/**
 * Chart of Accounts management. Enforces the rules an accountant
 * expects: unique codes and names, a valid parent/group, and that an
 * account carrying journal history can never be deleted — only
 * deactivated. Nothing here posts to the ledger.
 */
class ChartOfAccountsService
{
    /** The account groups, with their type, for building create forms. */
    public function groups(): array
    {
        $rows = db_connect()->query(
            "SELECT g.id, g.code, g.name, t.code AS type_code, t.name AS type_name
             FROM account_groups g
             JOIN account_types t ON t.id = g.account_type_id
             WHERE g.is_active = 1
             ORDER BY t.sort_order, g.code",
        )->getResultArray();

        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
        }

        return $rows;
    }

    public function types(): array
    {
        $rows = db_connect()->table('account_types')
            ->orderBy('sort_order')->get()->getResultArray();

        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
        }

        return $rows;
    }

    /**
     * List all accounts with their group, type, and live balance from journal entry lines.
     */
    public function list(bool $includeInactive = false): array
    {
        $accounts = db_connect()->query(
            "SELECT a.id, a.code, a.name, a.description, a.is_postable, a.is_active, a.is_system,
                    a.parent_id, a.account_group_id, g.name AS group_name, g.code AS group_code,
                    t.code AS type_code, t.name AS type_name, t.normal_balance,
                    COALESCE(SUM(l.debit), 0)  AS total_debit,
                    COALESCE(SUM(l.credit), 0) AS total_credit
             FROM accounts a
             JOIN account_groups g ON g.id = a.account_group_id
             JOIN account_types t  ON t.id = g.account_type_id
             LEFT JOIN journal_entry_lines l ON l.account_id = a.id
             WHERE a.deleted_at IS NULL" . ($includeInactive ? '' : ' AND a.is_active = 1') . "
             GROUP BY a.id, a.code, a.name, a.description, a.is_postable, a.is_active, a.is_system,
                      a.parent_id, a.account_group_id, g.name, g.code, t.code, t.name, t.normal_balance, t.sort_order
             ORDER BY t.sort_order, a.code",
        )->getResultArray();

        foreach ($accounts as &$account) {
            $debit  = (float) $account['total_debit'];
            $credit = (float) $account['total_credit'];

            $account['balance'] = $account['normal_balance'] === 'debit'
                ? round($debit - $credit, 2)
                : round($credit - $debit, 2);

            $account['id']           = (int) $account['id'];
            $account['parent_id']    = $account['parent_id'] === null ? null : (int) $account['parent_id'];
            $account['is_postable']  = (bool) $account['is_postable'];
            $account['is_active']    = (bool) $account['is_active'];
            $account['is_system']    = (bool) $account['is_system'];
            $account['total_debit']  = $debit;
            $account['total_credit'] = $credit;
        }

        return $accounts;
    }

    /**
     * @param array $data {code, name, account_group_id, parent_id?,
     *                     is_postable?, description?}
     */
    public function create(array $data): array
    {
        $code = trim((string) ($data['code'] ?? ''));
        $name = trim((string) ($data['name'] ?? ''));
        $groupId = (int) ($data['account_group_id'] ?? 0);

        if ($code === '' || $name === '') {
            throw new InvalidArgumentException('CODE_AND_NAME_REQUIRED');
        }

        $database = db_connect();

        if ($groupId <= 0 ||
            $database->table('account_groups')->where('id', $groupId)->countAllResults() === 0) {
            throw new InvalidArgumentException('GROUP_NOT_FOUND');
        }

        $this->assertUniqueCode($code);
        $this->assertUniqueName($name);

        $parentId = isset($data['parent_id']) && $data['parent_id'] !== null
            ? (int) $data['parent_id']
            : null;

        if ($parentId !== null &&
            $database->table('accounts')->where('id', $parentId)->where('deleted_at', null)->countAllResults() === 0) {
            throw new InvalidArgumentException('PARENT_NOT_FOUND');
        }

        $database->table('accounts')->insert([
            'account_group_id' => $groupId,
            'parent_id'        => $parentId,
            'code'             => $code,
            'name'             => $name,
            'description'      => $data['description'] ?? null,
            'is_postable'      => (int) ($data['is_postable'] ?? 1),
            'is_system'        => 0,
            'is_active'        => 1,
        ]);

        $accountId = (int) $database->insertID();

        AuditService::log('account.create', 'accounting', 'accounts', $accountId, after: [
            'code' => $code, 'name' => $name,
        ]);

        return $this->find($accountId);
    }

    public function update(int $accountId, array $data): array
    {
        $database = db_connect();
        $account  = $database->table('accounts')
            ->where('id', $accountId)->where('deleted_at', null)
            ->get()->getRowArray();

        if ($account === null) {
            throw new InvalidArgumentException('ACCOUNT_NOT_FOUND');
        }

        $fields = [];

        if (isset($data['code'])) {
            $code = trim((string) $data['code']);
            if ($code !== $account['code']) {
                $this->assertUniqueCode($code, $accountId);
                $fields['code'] = $code;
            }
        }

        if (isset($data['name'])) {
            $name = trim((string) $data['name']);
            if ($name !== $account['name']) {
                $this->assertUniqueName($name, $accountId);
                $fields['name'] = $name;
            }
        }

        foreach (['description', 'account_group_id', 'parent_id', 'is_postable'] as $key) {
            if (array_key_exists($key, $data)) {
                $fields[$key] = $data[$key];
            }
        }

        if ($fields !== []) {
            $database->table('accounts')->where('id', $accountId)->update($fields);
            AuditService::log('account.update', 'accounting', 'accounts', $accountId,
                before: ['code' => $account['code'], 'name' => $account['name']],
                after: $fields);
        }

        return $this->find($accountId);
    }

    /**
     * Deactivate (soft) — the accountant's alternative to deletion.
     */
    public function setActive(int $accountId, bool $active): array
    {
        $database = db_connect();
        $account  = $database->table('accounts')
            ->where('id', $accountId)->where('deleted_at', null)
            ->get()->getRowArray();

        if ($account === null) {
            throw new InvalidArgumentException('ACCOUNT_NOT_FOUND');
        }

        if ((int) $account['is_system'] === 1 && ! $active) {
            throw new InvalidArgumentException('SYSTEM_ACCOUNT');
        }

        $database->table('accounts')->where('id', $accountId)
            ->update(['is_active' => $active ? 1 : 0]);

        AuditService::log('account.' . ($active ? 'activate' : 'deactivate'),
            'accounting', 'accounts', $accountId);

        return $this->find($accountId);
    }

    /**
     * True delete is only allowed for a non-system account that has
     * never been posted to; otherwise the caller must deactivate.
     */
    public function delete(int $accountId): void
    {
        $database = db_connect();
        $account  = $database->table('accounts')
            ->where('id', $accountId)->where('deleted_at', null)
            ->get()->getRowArray();

        if ($account === null) {
            throw new InvalidArgumentException('ACCOUNT_NOT_FOUND');
        }

        if ((int) $account['is_system'] === 1) {
            throw new InvalidArgumentException('SYSTEM_ACCOUNT');
        }

        $hasLines = $database->table('journal_entry_lines')
            ->where('account_id', $accountId)->countAllResults() > 0;

        if ($hasLines) {
            throw new InvalidArgumentException('ACCOUNT_HAS_ENTRIES');
        }

        $hasChildren = $database->table('accounts')
            ->where('parent_id', $accountId)->where('deleted_at', null)->countAllResults() > 0;

        if ($hasChildren) {
            throw new InvalidArgumentException('ACCOUNT_HAS_CHILDREN');
        }

        $database->table('accounts')->where('id', $accountId)
            ->update(['deleted_at' => date('Y-m-d H:i:s')]);

        AuditService::log('account.delete', 'accounting', 'accounts', $accountId,
            before: ['code' => $account['code'], 'name' => $account['name']]);
    }

    private function assertUniqueCode(string $code, ?int $exceptId = null): void
    {
        $builder = db_connect()->table('accounts')
            ->where('code', $code)->where('deleted_at', null);

        if ($exceptId !== null) {
            $builder->where('id !=', $exceptId);
        }

        if ($builder->countAllResults() > 0) {
            throw new InvalidArgumentException('DUPLICATE_CODE');
        }
    }

    private function assertUniqueName(string $name, ?int $exceptId = null): void
    {
        $builder = db_connect()->table('accounts')
            ->where('name', $name)->where('deleted_at', null);

        if ($exceptId !== null) {
            $builder->where('id !=', $exceptId);
        }

        if ($builder->countAllResults() > 0) {
            throw new InvalidArgumentException('DUPLICATE_NAME');
        }
    }

    private function find(int $accountId): array
    {
        $row = db_connect()->query(
            "SELECT a.id, a.code, a.name, a.description, a.is_postable, a.is_active,
                    a.is_system, a.parent_id, g.name AS group_name, t.name AS type_name
             FROM accounts a
             JOIN account_groups g ON g.id = a.account_group_id
             JOIN account_types t  ON t.id = g.account_type_id
             WHERE a.id = ?",
            [$accountId],
        )->getRowArray();

        $row['id']          = (int) $row['id'];
        $row['parent_id']   = $row['parent_id'] === null ? null : (int) $row['parent_id'];
        $row['is_postable'] = (bool) $row['is_postable'];
        $row['is_active']   = (bool) $row['is_active'];
        $row['is_system']   = (bool) $row['is_system'];

        return $row;
    }
}
