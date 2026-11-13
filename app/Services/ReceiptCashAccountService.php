<?php

namespace App\Services;

use App\Services\Web\ChartOfAccountsPresenter;

/** Uses the Chart of Accounts categories for receipt and payment routing. */
class ReceiptCashAccountService
{
    public function list(): array
    {
        return array_values(array_filter($this->settlementAccounts(), static fn ($account) => $account['method'] === 'cash'));
    }

    public function settlementAccounts(): array
    {
        $chart = new ChartOfAccountsService();
        $kinds = array_column(db_connect()->query(
            'SELECT account_id, CONVERT(type USING utf8mb4) COLLATE utf8mb4_unicode_ci AS type FROM bank_accounts WHERE deleted_at IS NULL UNION SELECT account_id, CONVERT(type USING utf8mb4) COLLATE utf8mb4_unicode_ci AS type FROM pos_payment_methods',
        )->getResultArray(), 'type', 'account_id');
        $categories = (new ChartOfAccountsPresenter())->categories($chart->list(true), $chart->groups(), $kinds, 'ASSET');
        $accounts = [];
        foreach ($categories as $category) {
            if (!in_array($category['label'], ['Cash', 'Bank & Mobile Money'], true)) {
                continue;
            }
            foreach ($category['rows'] as $account) {
                if (!$account['is_active'] || !$account['is_postable']) {
                    continue;
                }
                $account['method'] = $category['label'] === 'Cash'
                    ? 'cash'
                    : (($kinds[$account['id']] ?? null) === 'mobile_money' ? 'mobile_money' : 'bank');
                $accounts[] = $account;
            }
        }
        return $accounts;
    }

    /** Validate the selected ledger account inside the posting transaction. */
    public function lockSettlementMethod(int $accountId): string
    {
        $account = db_connect()->query(
            'SELECT id FROM accounts WHERE id = ? AND is_active = 1 AND is_postable = 1 AND deleted_at IS NULL FOR UPDATE',
            [$accountId],
        )->getRowArray();
        $methods = $account ? array_column($this->settlementAccounts(), 'method', 'id') : [];
        return $methods[$accountId] ?? throw new \InvalidArgumentException('INVALID_CASH_ACCOUNT');
    }
    public function moneyAccountIds(): array
    {
        $rows=db_connect()->table('accounts')->select('id,code,parent_id')->get()->getResultArray();$ids=[];
        foreach($rows as $r)if(in_array($r['code'],['1000','1010']))$ids[]=(int)$r['id'];
        foreach(db_connect()->query('SELECT account_id FROM bank_accounts UNION SELECT account_id FROM pos_payment_methods')->getResultArray() as $r)$ids[]=(int)$r['account_id'];
        do{$before=count($ids);foreach($rows as $r)if(in_array((int)$r['parent_id'],$ids,true)&&!in_array((int)$r['id'],$ids,true))$ids[]=(int)$r['id'];}while(count($ids)>$before);
        return array_unique($ids);
    }
}
