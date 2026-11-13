<?php

namespace App\Services;

use InvalidArgumentException;

/** Deterministic cumulative rounding: partial returns can never exceed a line's original value. */
final class ReturnCalculation
{
    /** Apportion integer cents by weight, assigning rounding residue deterministically. */
    public static function allocate(int $cents, array $weights): array
    {
        $sum = array_sum($weights);
        $result = array_fill_keys(array_keys($weights), 0);
        if ($sum <= 0 || $cents === 0) return $result;
        $used = 0;
        $fractions = [];
        foreach ($weights as $id => $weight) {
            $exact = $cents * $weight / $sum;
            $result[$id] = (int) floor($exact);
            $used += $result[$id];
            $fractions[$id] = $exact - $result[$id];
        }
        arsort($fractions, SORT_NUMERIC);
        foreach (array_keys($fractions) as $id) {
            if ($used >= $cents) break;
            $result[$id]++;
            $used++;
        }
        return $result;
    }

    public static function quantity(mixed $value): int
    {
        if (!is_scalar($value) || !is_numeric($value) || !is_finite((float) $value) || (float) $value <= 0 || (float) $value > 999999999.999 || abs((float) $value - round((float) $value, 3)) > 0.0000001) {
            throw new InvalidArgumentException('Enter positive quantities with at most three decimal places.');
        }
        return (int) round((float) $value * 1000);
    }

    public static function portion(int $value, int $originalQty, int $returnedQty, int $newQty): int
    {
        if ($originalQty <= 0 || $returnedQty + $newQty > $originalQty) {
            throw new InvalidArgumentException('Return quantity exceeds the quantity still eligible for return.');
        }
        return (int) round($value * ($returnedQty + $newQty) / $originalQty) - (int) round($value * $returnedQty / $originalQty);
    }
}
