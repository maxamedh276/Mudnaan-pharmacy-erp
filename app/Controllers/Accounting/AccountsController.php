<?php

namespace App\Controllers\Accounting;

use App\Controllers\BaseApiController;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Chart of Accounts (Premium) — the accounts screen: every account with
 * its group, type and current balance from the journal.
 */
class AccountsController extends BaseApiController
{
    public function index(): ResponseInterface
    {
        if ($denied = $this->requirePermission('accounting.view')) {
            return $denied;
        }

        $accounts = db_connect()->query(
            "SELECT a.id, a.code, a.name, a.is_postable, a.is_active,
                    g.name AS group_name, t.code AS type_code, t.name AS type_name,
                    t.normal_balance,
                    COALESCE(SUM(l.debit), 0)  AS total_debit,
                    COALESCE(SUM(l.credit), 0) AS total_credit
             FROM accounts a
             JOIN account_groups g ON g.id = a.account_group_id
             JOIN account_types t  ON t.id = g.account_type_id
             LEFT JOIN journal_entry_lines l ON l.account_id = a.id
             WHERE a.deleted_at IS NULL AND a.is_active = 1
             GROUP BY a.id, a.code, a.name, a.is_postable, a.is_active,
                      g.name, t.code, t.name, t.normal_balance, t.sort_order
             ORDER BY t.sort_order, a.code",
        )->getResultArray();

        foreach ($accounts as &$account) {
            $debit  = (float) $account['total_debit'];
            $credit = (float) $account['total_credit'];

            // Balance shown on the side that grows the account.
            $account['balance'] = $account['normal_balance'] === 'debit'
                ? round($debit - $credit, 2)
                : round($credit - $debit, 2);

            $account['id']           = (int) $account['id'];
            $account['is_postable']  = (bool) $account['is_postable'];
            $account['is_active']    = (bool) $account['is_active'];
            $account['total_debit']  = $debit;
            $account['total_credit'] = $credit;
        }

        return $this->respondData($accounts);
    }
}
