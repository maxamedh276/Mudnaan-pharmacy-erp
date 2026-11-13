<?php
namespace App\Services\Pharmacy;

use InvalidArgumentException;

/** Web checkout accepts only configured active methods, never client-supplied ledger IDs. */
final class PharmacyPaymentService
{
    public function resolve(array $input): array
    {
        $entries = array_key_exists('payment_entries', $input) ? $input['payment_entries'] : [['method_id'=>$input['payment_method_id'] ?? null,'amount'=>$input['paid'] ?? null]];
        if (!is_array($entries) || !$entries || count($entries) > 10) throw new InvalidArgumentException('Enter 1 to 10 payments.');
        $payments = [];
        foreach ($entries as $entry) {
            if (!is_array($entry)) throw new InvalidArgumentException('Invalid payment row.');
            $id = PharmacyInput::id($entry['method_id'] ?? null);
            $method = db_connect()->table('pos_payment_methods')->where('id',$id)->where('is_active',1)->get()->getRowArray();
            if (!$method || empty($method['account_id'])) throw new InvalidArgumentException('Select an active payment method linked to a money account.');
            $amount = $entry['amount'] ?? null;
            if (!is_numeric($amount) || !is_finite((float)$amount) || (float)$amount < 0 || (float)$amount > 9999999999.99 || abs((float)$amount-round((float)$amount,2))>0.000001) throw new InvalidArgumentException('Enter payment amounts with up to two decimal places.');
            $payments[] = ['method'=>$method['type'],'account_id'=>(int)$method['account_id'],'amount'=>round((float)$amount,2),'reference'=>PharmacyInput::text($entry,'reference',100)];
        }
        return $payments;
    }
}
