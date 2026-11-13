<?php

namespace App\Services\Web;

use App\Services\ChartOfAccountsService;
use InvalidArgumentException;

/** Allocates account codes on the server without changing the mobile account API. */
class AccountCreationService
{
    public function create(array $input): array
    {
        $database = db_connect();
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 150) throw new InvalidArgumentException('Enter an account name of up to 150 characters.');
        $groupId = (int) ($input['account_group_id'] ?? 0);
        $parentId = !empty($input['parent_id']) ? (int) $input['parent_id'] : null;
        $group = $database->query('SELECT g.*, t.code AS type_code FROM account_groups g JOIN account_types t ON t.id = g.account_type_id WHERE g.id = ? AND g.is_active = 1', [$groupId])->getRowArray();
        if (!$group) {
            throw new InvalidArgumentException('Select a valid account type.');
        }
        $database->transException(true)->transBegin();
        try {
            $accounts = $database->query('SELECT id, code, account_group_id, deleted_at FROM accounts ORDER BY id FOR UPDATE')->getResultArray();
            $accountsById = array_column($accounts, null, 'id');
            $parent = $parentId ? ($accountsById[$parentId] ?? null) : null;
            if ($parentId && (!$parent || $parent['deleted_at'] !== null || (int) $parent['account_group_id'] !== $groupId)) {
                throw new InvalidArgumentException('Select a parent from the same account type.');
            }
            [$firstCode, $lastCode] = match ($group['type_code']) {
                'ASSET' => [1000, 1999], 'LIABILITY' => [2000, 2999],
                'EQUITY' => [3000, 3999], 'INCOME' => [4000, 4999], default => [5000, 9999],
            };
            if ($group['code'] === 'FA') $firstCode = 1500;
            if ($group['code'] === 'OPEX') $firstCode = 6000;
            if ($parent && ctype_digit((string) $parent['code'])) $firstCode = max($firstCode, (int) $parent['code'] + 1);
            $usedCodes = array_fill_keys(array_column($accounts, 'code'), true);
            $nextCode = $firstCode;
            while (isset($usedCodes[(string) $nextCode]) && $nextCode <= $lastCode) $nextCode++;
            if ($nextCode > $lastCode) throw new InvalidArgumentException('There are no available account codes in this group.');
            $account = (new ChartOfAccountsService())->create([
                'code' => (string) $nextCode, 'name' => $name,
                'account_group_id' => $groupId, 'parent_id' => $parentId,
                'description' => $input['description'] ?? null, 'is_postable' => 1,
            ]);
            $database->transCommit();
            return $account;
        } catch (\Throwable $exception) {
            $database->transRollback();
            throw $exception;
        }
    }
}
