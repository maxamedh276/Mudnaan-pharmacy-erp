<?php
namespace App\Services\Pharmacy;

use App\Libraries\AuthContext;
use InvalidArgumentException;

final class PharmacyDiscountService
{
    public function validate(array $line,array $unit): array
    {
        $raw=$line['discount']??0;
        if ($raw==='') $raw=0;
        if (!is_numeric($raw)||!is_finite((float)$raw)||(float)$raw<0||abs((float)$raw-round((float)$raw,2))>0.000001) throw new InvalidArgumentException('Enter a non-negative line discount with up to two decimals.');
        $discount=round((float)$raw,2);
        if ($discount===0.0) return ['discount'=>0];
        if (!AuthContext::can('pharmacy.discounts.apply')) throw new InvalidArgumentException('Discounts require pharmacy discount permission.');
        $quantity=PharmacyInput::quantity($line['quantity']??null);
        if ($discount>round($quantity*(float)$unit['sell_price'],2)) throw new InvalidArgumentException('The discount exceeds this medicine line amount.');
        return ['discount'=>$discount,'pharmacy_discount_reason'=>PharmacyInput::text($line,'pharmacy_discount_reason',300,true)];
    }
}
