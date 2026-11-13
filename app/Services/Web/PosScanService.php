<?php
namespace App\Services\Web;

use App\Models\ProductModel;
use InvalidArgumentException;

final class PosScanService
{
    /** Exact identifiers only. Never guess a product from a partial name or arbitrary QR URL. */
    public function find(string $code, int $branchId): array
    {
        $code = trim($code);
        if ($code === '' || mb_strlen($code) > 80 || preg_match('/[\x00-\x1F\x7F]/', $code)) throw new InvalidArgumentException('Scan a valid product barcode or SKU.');
        $matches = db_connect()->query(
            'SELECT id, barcode = ? AS unit_match, sealed_barcode = ? AS sealed_match
             FROM products WHERE deleted_at IS NULL AND (barcode = ? OR sealed_barcode = ? OR sku = ?) LIMIT 2',
            [$code, $code, $code, $code, $code]
        )->getResultArray();
        if (!$matches) throw new InvalidArgumentException('No product matches this code. Check the product barcode or SKU.');
        if (count($matches) !== 1 || ($matches[0]['unit_match'] && $matches[0]['sealed_match'])) throw new InvalidArgumentException('This code matches multiple products or selling units. Assign a unique barcode.');
        $product = (new ProductModel())->findWithStock((int)$matches[0]['id'], $branchId);
        if (!$product || !$product['is_active']) throw new InvalidArgumentException('This product is inactive and cannot be added.');
        if ($matches[0]['sealed_match'] && empty($product['package_unit'])) throw new InvalidArgumentException('This barcode has no sealed package configured.');
        // Keep the established scan behavior for packages awaiting their new barcode.
        $product['scan_mode'] = $product['default_sale_mode'];
        if ($matches[0]['sealed_match']) {
            $product['scan_mode'] = 'sealed';
        } elseif ($matches[0]['unit_match'] && $product['sealed_barcode'] !== null) {
            $product['scan_mode'] = 'loose';
        }
        $product['stock_split'] = (new \App\Services\PackageInventoryService())->balance($branchId, $product);
        $product['quantity_shortcuts'] = (new SellingOptionsService())->forProduct($product);
        return $product;
    }
}
