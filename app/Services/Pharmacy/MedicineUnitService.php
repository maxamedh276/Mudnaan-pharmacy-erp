<?php
namespace App\Services\Pharmacy;

use InvalidArgumentException;

/** Every conversion is expressed in the product's base unit, never in another pack. */
final class MedicineUnitService
{
    public const FORMS = ['Tablet', 'Capsule', 'Syrup', 'Suspension', 'Solution', 'Injection', 'Infusion', 'Cream', 'Ointment', 'Gel', 'Drops', 'Inhaler', 'Suppository', 'Powder', 'Sachet', 'Patch', 'Spray', 'Medical supply'];
    public const UNITS = ['tablet', 'capsule', 'strip', 'blister', 'box', 'bottle', 'ml', 'litre', 'ampoule', 'vial', 'tube', 'sachet', 'dose', 'piece', 'pcs', 'pack', 'gram'];

    public function units(int $product): array
    {
        return db_connect()->table('pharmacy_medicine_units')->where('product_id', $product)->orderBy('factor')->get()->getResultArray();
    }

    public function resolve(int $product, mixed $id): array
    {
        $id = PharmacyInput::id($id);
        $unit = db_connect()->table('pharmacy_medicine_units')->where('product_id', $product)->where('id', $id)->get()->getRowArray();
        if (!$unit) throw new InvalidArgumentException('Select a selling unit belonging to this medicine.');
        return $unit;
    }

    public static function baseQuantity(array $unit, mixed $quantity): float
    {
        $quantity = PharmacyInput::quantity($quantity);
        if (empty($unit['fractional']) && floor($quantity) !== $quantity) throw new InvalidArgumentException('This selling unit requires a whole quantity.');
        $factor = PharmacyInput::quantity($unit['factor'] ?? null);
        return PharmacyInput::quantity($quantity * $factor);
    }

    /** Caller holds the product lock inside the catalog transaction. */
    public function save(int $product, array $input): void
    {
        if (!$input || count($input) > 30) throw new InvalidArgumentException('Enter 1 to 30 medicine units.');
        $seen = [];
        $base = false;
        $rows = [];
        $definitions = (new PharmacyDefinitionService())->units();
        $baseName = db_connect()->table('products')->where('id', $product)->get()->getRow('unit');
        $baseDefinition = $definitions[$baseName] ?? null;
        if (!$baseDefinition) throw new InvalidArgumentException('Register the inventory base unit before configuring medicine units.');
        foreach ($input as $entry) {
            if (!is_array($entry)) throw new InvalidArgumentException('Invalid medicine unit.');
            $name = strtolower(PharmacyInput::text($entry, 'name', 30, true));
            if (!isset($definitions[$name]) || isset($seen[$name])) throw new InvalidArgumentException('Select distinct registered medicine units.');
            $seen[$name] = true;
            $factor = PharmacyInput::quantity($entry['factor'] ?? null);
            if (empty($baseDefinition['fractional']) && floor($factor) !== $factor) throw new InvalidArgumentException('Pack conversions for countable medicines must contain whole base units.');
            $family = $definitions[$name]['family'];
            if (in_array($family, ['volume','mass'], true) && $family !== $baseDefinition['family']) throw new InvalidArgumentException('Volume and mass units must match the medicine base-unit family.');
            $price = $entry['sell_price'] ?? null;
            $packState=$entry['pharmacy_pack_state']??'any';
            if (!in_array($packState,['any','sealed','opened'],true)) throw new InvalidArgumentException('Select the stock source for each selling unit.');
            if (!is_numeric($price) || !is_finite((float) $price) || (float)$price < 0 || (float)$price > 9999999999.99) throw new InvalidArgumentException('Enter a valid unit selling price.');
            $base = $base || $factor === 1.0;
            $barcode = \App\Services\ProductIdentifierService::normalizeBarcode($entry['barcode'] ?? null);
            if ($barcode !== null) {
                $conflict = db_connect()->table('products')->groupStart()->where('barcode', $barcode)->orWhere('sealed_barcode', $barcode)->groupEnd()->get()->getResultArray();
                foreach ($conflict as $row) if ((int)$row['id'] !== $product || $factor !== 1.0) throw new InvalidArgumentException('This barcode belongs to a different medicine or selling unit.');
                if (db_connect()->table('pharmacy_medicine_units')->where('barcode', $barcode)->where('product_id !=', $product)->countAllResults()) throw new InvalidArgumentException('This barcode already identifies another medicine unit.');
            }
            $row = ['product_id' => $product, 'name' => $name, 'factor' => $factor, 'sell_price' => round((float)$price, 2), 'barcode' => $barcode, 'fractional' => !empty($definitions[$name]['fractional']) ? 1 : 0];
            if (db_connect()->fieldExists('pharmacy_pack_state','pharmacy_medicine_units')) $row['pharmacy_pack_state']=$packState;
            elseif ($packState!=='any') throw new InvalidArgumentException('Apply the opened-pack migration before configuring stock sources.');
            $rows[]=$row;
        }
        if (!$base) throw new InvalidArgumentException('Include a base unit with conversion factor 1.');
        // Keep unit IDs stable: old sales already snapshot factor and label.
        foreach ($rows as $row) {
            $old = db_connect()->table('pharmacy_medicine_units')->where('product_id', $product)->where('name', $row['name'])->get()->getRowArray();
            if ($old && (float)$old['factor'] !== $row['factor']) throw new InvalidArgumentException('A saved unit conversion cannot change. Add a new medicine for a different pack size.');
            if ($old) db_connect()->table('pharmacy_medicine_units')->where('id', $old['id'])->update($row);
            else db_connect()->table('pharmacy_medicine_units')->insert($row);
        }
    }
}
