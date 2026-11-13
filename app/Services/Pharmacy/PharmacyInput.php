<?php

namespace App\Services\Pharmacy;

use InvalidArgumentException;

/** Shared validation for browser and future API pharmacy entry points. */
final class PharmacyInput
{
    public static function text(array $data, string $key, int $max, bool $required = false): ?string
    {
        $raw = $data[$key] ?? '';
        if (!is_string($raw)) throw new InvalidArgumentException('Invalid field: ' . $key);
        $value = trim($raw);
        if (($required && $value === '') || mb_strlen($value) > $max) {
            throw new InvalidArgumentException('Invalid field: ' . $key);
        }
        return $value === '' ? null : $value;
    }

    public static function id(mixed $value): int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) throw new InvalidArgumentException('Invalid record identifier.');
        return $id;
    }

    public static function date(array $data, string $key, bool $required = false): ?string
    {
        $value = self::text($data, $key, 10, $required);
        if ($value === null) return null;
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value) throw new InvalidArgumentException('Invalid date: ' . $key);
        return $value;
    }

    public static function medicine(array $data): array
    {
        $required = $data['prescription_required'] ?? false;
        if (!in_array($required, [true, false, 0, 1, '0', '1'], true)) throw new InvalidArgumentException('Invalid prescription classification.');
        $form = self::text($data, 'dosage_form', 80, true);
        if (!in_array($form, MedicineUnitService::FORMS, true) && !in_array($form, (new PharmacyDefinitionService())->forms(), true)) throw new InvalidArgumentException('Select a registered medicine dosage form.');
        $values = [
            'generic_name' => self::text($data, 'generic_name', 180, true),
            'strength' => self::text($data, 'strength', 80, true),
            'dosage_form' => $form,
            'manufacturer' => self::text($data, 'manufacturer', 180),
            'prescription_required' => (int) (bool) $required,
            'storage_instructions' => self::text($data, 'storage_instructions', 500),
        ];
        if (array_key_exists('route_of_administration',$data)) $values['route_of_administration']=self::text($data,'route_of_administration',80);
        if (array_key_exists('active_ingredients',$data)) {
            $ingredients=$data['active_ingredients'];
            if (!is_array($ingredients)||!array_is_list($ingredients)||count($ingredients)>20) throw new InvalidArgumentException('Record up to 20 active ingredients.');
            $rows=[];
            foreach ($ingredients as $ingredient) {
                if (!is_array($ingredient)) throw new InvalidArgumentException('Invalid active ingredient.');
                $rows[]=['name'=>self::text($ingredient,'name',180,true),'strength'=>self::text($ingredient,'strength',80,true)];
            }
            $values['active_ingredients']=json_encode($rows,JSON_THROW_ON_ERROR);
        }
        return $values;
    }

    public static function quantity(mixed $raw): float
    {
        if ((!is_string($raw) && !is_int($raw) && !is_float($raw)) || !is_numeric($raw)) throw new InvalidArgumentException('Invalid quantity.');
        $quantity = (float) $raw;
        if (!is_finite($quantity) || $quantity <= 0 || $quantity > 999999999.999 || abs($quantity - round($quantity, 3)) > 0.0000001) {
            throw new InvalidArgumentException('Invalid quantity.');
        }
        return round($quantity, 3);
    }
}
