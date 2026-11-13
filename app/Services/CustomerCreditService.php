<?php

namespace App\Services;

use App\Libraries\AdministrativeAccess;
use App\Libraries\AuthContext;
use InvalidArgumentException;

/** One customer-wide credit policy shared by POS and standalone invoicing. */
final class CustomerCreditService
{
    public static function canManage(): bool
    {
        return AuthContext::$user !== null
            && AdministrativeAccess::isFixedRole(AuthContext::$user->role_name ?? '');
    }

    public static function limitAmount(mixed $value): float
    {
        // Reject negative values, exponent notation and fractions smaller than a cent.
        if ((!is_string($value) && !is_int($value) && !is_float($value))
            || !preg_match('/^(?:0|[1-9]\d{0,9})(?:\.\d{1,2})?$/D', (string)$value)) {
            throw new InvalidArgumentException('Enter a credit limit from 0 to 9999999999.99 with at most two decimal places.');
        }
        return (float)$value;
    }

    /** Must be called inside the posting transaction, before product/document locks. */
    public function lockCustomer(int $customerId): array
    {
        $customer = db_connect()->query('SELECT * FROM customers WHERE id=? AND deleted_at IS NULL FOR UPDATE', [$customerId])->getRowArray();
        if (!$customer) throw new InvalidArgumentException('CUSTOMER_REQUIRED');
        return $customer;
    }

    public function status(int $customerId): array
    {
        $customer = db_connect()->table('customers')->where('id', $customerId)->where('deleted_at', null)->get()->getRowArray();
        if (!$customer) throw new InvalidArgumentException('CUSTOMER_REQUIRED');
        return $this->statusFor($customer);
    }

    /** Bulk display query; checkout always repeats the decision under row locks. */
    public function withStatuses(array $customers): array
    {
        if (!$customers) return [];
        $balances = db_connect()->table('customer_invoices')->select('customer_id, SUM(total-paid_total) AS outstanding')
            ->whereIn('customer_id', array_column($customers, 'id'))->where('deleted_at', null)
            ->whereIn('status', ['unpaid', 'partial'])->where('total > paid_total', null, false)
            ->groupBy('customer_id')->get()->getResultArray();
        $owed = array_column($balances, 'outstanding', 'customer_id');
        foreach ($customers as &$customer) {
            $customer['credit_limit'] = (float)$customer['credit_limit'];
            $customer['outstanding'] = round((float)($owed[$customer['id']] ?? 0), 2);
            $customer['available_credit'] = max(0, round($customer['credit_limit'] - $customer['outstanding'], 2));
        }
        return $customers;
    }

    public function statusFor(array $customer, bool $locking = false): array
    {
        // Deposits are separate liabilities until applied. Never offset unused deposits
        // against this exposure, and never count linked POS sales a second time.
        $rows = db_connect()->query("SELECT total,paid_total FROM customer_invoices WHERE customer_id=? AND deleted_at IS NULL AND status IN ('unpaid','partial') AND total>paid_total" . ($locking ? ' FOR UPDATE' : ''), [(int)$customer['id']])->getResultArray();
        $owed = 0;
        foreach ($rows as $row) $owed += max(0, (int)round((float)$row['total'] * 100) - (int)round((float)$row['paid_total'] * 100));
        $limit = (int)round((float)$customer['credit_limit'] * 100);
        return ['customer_id' => (int)$customer['id'], 'credit_limit' => $limit / 100.0,
            'outstanding' => $owed / 100.0, 'available_credit' => max(0, $limit - $owed) / 100.0,
            'over_limit' => $owed > $limit];
    }

    public function assertAvailable(array $lockedCustomer, float $newDebt): void
    {
        // Fully paid orders remain valid even after an administrator lowers the limit
        // or a receipt reversal restores old debt above the current limit.
        $newCents = (int)round($newDebt * 100);
        if ($newCents <= 0) return;
        // A locking read avoids a stale REPEATABLE READ snapshot after waiting for
        // another cashier, receipt, return or limit edit to release the customer row.
        $status = $this->statusFor($lockedCustomer, true);
        if ($newCents > (int)round($status['available_credit'] * 100)) {
            throw new CustomerCreditLimitException($status);
        }
    }
}
