<?php

namespace App\Services\Web;

/** Maps the existing ledger hierarchy to the web chart's account-type accordions. */
class ChartOfAccountsPresenter
{
    private const CATEGORY_ROOTS = [
        'CA' => ['1000' => 'Cash', '1010' => 'Bank & Mobile Money', '1100' => 'Receivables', '1200' => 'Inventory'],
        'CL' => ['2000' => 'Payables', '2100' => 'Tax Payable', '2200' => 'Customer Deposits'],
        'EQ' => ['3000' => 'Capital', '3100' => 'Retained Earnings'],
        'INC' => ['4000' => 'Sales Revenue', '4100' => 'Other Income', '4200' => 'Sales Discounts'],
    ];

    public function categories(array $accounts, array $groups, array $moneyAccountKinds, string $selectedType): array
    {
        $accountsById = array_column($accounts, null, 'id');
        $accountsByCode = array_column($accounts, null, 'code');
        $categories = [];

        foreach ($groups as $group) {
            if ($group['type_code'] !== $selectedType) {
                continue;
            }
            foreach (self::CATEGORY_ROOTS[$group['code']] ?? [] as $code => $label) {
                if (isset($accountsByCode[$code])) {
                    $categories[] = ['label' => $label, 'group' => $group, 'root' => $accountsByCode[$code], 'rows' => []];
                }
            }
            $label = isset(self::CATEGORY_ROOTS[$group['code']]) ? 'Other ' . $group['name'] : $group['name'];
            $categories[] = ['label' => $label, 'group' => $group, 'root' => null, 'rows' => []];
        }

        foreach ($accounts as $account) {
            if ($account['type_code'] !== $selectedType) {
                continue;
            }
            $ancestorIds = [$account['id']];
            $parentId = $account['parent_id'];
            while ($parentId && isset($accountsById[$parentId]) && !in_array($parentId, $ancestorIds, true)) {
                $ancestorIds[] = $parentId;
                $parentId = $accountsById[$parentId]['parent_id'];
            }

            $targetIndex = null;
            $fallbackIndex = null;
            foreach ($categories as $index => $category) {
                if ((int) $category['group']['id'] !== (int) $account['account_group_id']) {
                    continue;
                }
                if ($category['root'] === null) {
                    $fallbackIndex = $index;
                } elseif (in_array($category['root']['id'], $ancestorIds, true)) {
                    $targetIndex = $index;
                    break;
                }
            }

            // Existing standalone cash/wallet accounts retain their money-account category.
            if ($targetIndex === null && isset($moneyAccountKinds[$account['id']])) {
                $rootCode = $moneyAccountKinds[$account['id']] === 'cash' ? '1000' : '1010';
                foreach ($categories as $index => $category) {
                    if (($category['root']['code'] ?? '') === $rootCode) {
                        $targetIndex = $index;
                        break;
                    }
                }
            }
            $targetIndex ??= $fallbackIndex;
            if ($targetIndex !== null) {
                $categories[$targetIndex]['rows'][] = $account;
            }
        }

        return $categories;
    }
}
