<?php

namespace App\Controllers\Accounting;

use App\Controllers\BaseApiController;
use App\Services\ChartOfAccountsService;
use CodeIgniter\HTTP\ResponseInterface;
use InvalidArgumentException;

/**
 * Chart of Accounts (Premium) — the accounts screen (with live
 * balances) plus full management: create, edit, (de)activate, delete.
 */
class AccountsController extends BaseApiController
{
    private const ERRORS = [
        'CODE_AND_NAME_REQUIRED' => 'Both a code and a name are required.',
        'GROUP_NOT_FOUND'        => 'Choose a valid account group.',
        'PARENT_NOT_FOUND'       => 'The parent account does not exist.',
        'DUPLICATE_CODE'         => 'An account with that code already exists.',
        'DUPLICATE_NAME'         => 'An account with that name already exists.',
        'ACCOUNT_NOT_FOUND'      => 'That account does not exist.',
        'SYSTEM_ACCOUNT'         => 'Built-in system accounts cannot be changed this way.',
        'ACCOUNT_HAS_ENTRIES'    => 'This account has journal entries; deactivate it instead of deleting.',
        'ACCOUNT_HAS_CHILDREN'   => 'This account has sub-accounts; remove them first.',
    ];

    /**
     * GET /accounts?include_inactive=1
     * Every account with its group, type and current balance.
     */
    public function index(): ResponseInterface
    {
        if ($denied = $this->requirePermission('accounting.view')) {
            return $denied;
        }

        $includeInactive = (bool) $this->request->getGet('include_inactive');

        $accounts = db_connect()->query(
            "SELECT a.id, a.code, a.name, a.is_postable, a.is_active, a.is_system,
                    a.parent_id, g.name AS group_name, t.code AS type_code,
                    t.name AS type_name, t.normal_balance,
                    COALESCE(SUM(l.debit), 0)  AS total_debit,
                    COALESCE(SUM(l.credit), 0) AS total_credit
             FROM accounts a
             JOIN account_groups g ON g.id = a.account_group_id
             JOIN account_types t  ON t.id = g.account_type_id
             LEFT JOIN journal_entry_lines l ON l.account_id = a.id
             WHERE a.deleted_at IS NULL" . ($includeInactive ? '' : ' AND a.is_active = 1') . "
             GROUP BY a.id, a.code, a.name, a.is_postable, a.is_active, a.is_system,
                      a.parent_id, g.name, t.code, t.name, t.normal_balance, t.sort_order
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

        return $this->respondData($accounts);
    }

    /**
     * GET /account-groups — groups + their type, for the create form.
     */
    public function groups(): ResponseInterface
    {
        if ($denied = $this->requirePermission('accounting.view')) {
            return $denied;
        }

        return $this->respondData([
            'types'  => (new ChartOfAccountsService())->types(),
            'groups' => (new ChartOfAccountsService())->groups(),
        ]);
    }

    public function create(): ResponseInterface
    {
        if ($denied = $this->requirePermission('accounting.manage')) {
            return $denied;
        }

        return $this->handle(
            fn () => (new ChartOfAccountsService())->create($this->body()),
            201,
        );
    }

    public function update(int $accountId): ResponseInterface
    {
        if ($denied = $this->requirePermission('accounting.manage')) {
            return $denied;
        }

        return $this->handle(
            fn () => (new ChartOfAccountsService())->update($accountId, $this->body()),
        );
    }

    public function deactivate(int $accountId): ResponseInterface
    {
        if ($denied = $this->requirePermission('accounting.manage')) {
            return $denied;
        }

        $active = (bool) ($this->body()['is_active'] ?? false);

        return $this->handle(
            fn () => (new ChartOfAccountsService())->setActive($accountId, $active),
        );
    }

    public function delete(int $accountId): ResponseInterface
    {
        if ($denied = $this->requirePermission('accounting.manage')) {
            return $denied;
        }

        return $this->handle(function () use ($accountId) {
            (new ChartOfAccountsService())->delete($accountId);

            return ['message' => 'Account deleted.'];
        });
    }

    private function handle(callable $operation, int $successStatus = 200): ResponseInterface
    {
        try {
            return $this->respondData($operation(), $successStatus);
        } catch (InvalidArgumentException $exception) {
            $code = $exception->getMessage();

            return $this->respondError($code, self::ERRORS[$code] ?? 'Invalid request.', 422);
        }
    }
}
