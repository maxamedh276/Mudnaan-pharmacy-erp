<?php

namespace App\Services;

use CodeIgniter\Database\BaseConnection;

final class ProductIdentifierService
{
    public const FIELDS = ['sku', 'barcode', 'sealed_barcode'];

    public static function normalizeBarcode(mixed $value): ?string
    {
        if ($value === null) return null;
        if (!is_string($value) && !is_int($value)) throw new ProductIdentifierException('Enter a valid barcode.');
        $code = trim((string)$value);
        if (mb_strlen($code) > 80 || preg_match('/[\x00-\x1F\x7F]/', $code)) {
            throw new ProductIdentifierException('Enter a valid barcode of up to 80 characters.');
        }
        return $code === '' ? null : $code;
    }

    /** Serialize identifier writes through the outermost catalog transaction. */
    public static function withLock(BaseConnection $db, callable $operation): mixed
    {
        $lock = 'product-codes-' . substr(hash('sha256', $db->getDatabase()), 0, 40);
        if ((int)$db->query('SELECT GET_LOCK(?, 10) AS acquired', [$lock])->getRow('acquired') !== 1) {
            throw new ProductIdentifierException('Another product is being saved. Please try again shortly.');
        }
        try {
            return $operation();
        } finally {
            $db->query('SELECT RELEASE_LOCK(?)', [$lock]);
        }
    }

    public static function assertAvailable(BaseConnection $db, array $fields, ?int $id = null): void
    {
        $existing = $id === null ? [] : ($db->table('products')->select(self::FIELDS)->where('id', $id)->get()->getRowArray() ?? []);
        $codes = array_intersect_key($fields, array_flip(self::FIELDS));
        $changed = array_filter($codes, static fn ($code, $field) => $code !== ($existing[$field] ?? null), ARRAY_FILTER_USE_BOTH);
        if (!$changed) return;

        $current = $codes + $existing;
        $sealed = $current['sealed_barcode'] ?? null;
        if ($sealed !== null && (mb_strtolower($sealed) === mb_strtolower((string)($current['barcode'] ?? '')) ||
            mb_strtolower($sealed) === mb_strtolower((string)($current['sku'] ?? '')))) {
            throw new ProductIdentifierException('Use a sealed barcode different from the single-unit barcode and SKU.');
        }

        foreach ($changed as $code) {
            if ($code === null || $code === '') continue;
            $builder = $db->table('products')->where('deleted_at', null);
            if ($id !== null) $builder->where('id !=', $id);
            $match = $builder->groupStart()->where('barcode', $code)->orWhere('sealed_barcode', $code)
                ->orWhere('sku', $code)->groupEnd()->limit(1)->get()->getRowArray();
            if ($match) throw new ProductIdentifierException('This barcode or SKU is already assigned to another product.');
            if ($db->tableExists('pharmacy_medicine_units')) {
                $units = $db->table('pharmacy_medicine_units')->where('barcode', $code);
                if ($id !== null) $units->where('product_id !=', $id);
                if ($units->countAllResults()) throw new ProductIdentifierException('This barcode belongs to a medicine selling unit.');
            }
        }
    }
}
