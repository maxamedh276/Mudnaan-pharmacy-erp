<?php

namespace App\Models;

use App\Services\ProductIdentifierException;
use App\Services\ProductIdentifierService;

class ProductModel extends FinanciallyProtectedModel
{
    protected $table          = 'products';
    protected $primaryKey     = 'id';
    protected $returnType     = 'array';
    protected $useSoftDeletes = true;
    protected $useTimestamps  = true;
    protected $deletedField   = 'deleted_at';
    protected $allowedFields  = [
        'category_id', 'supplier_id', 'sku', 'barcode', 'sealed_barcode', 'name', 'brand',
        'selling_group_id', 'default_sale_mode', 'package_unit', 'package_size', 'package_price', 'unit', 'cost_price', 'sell_price', 'tax_rate', 'reorder_level',
        'attributes', 'image_path', 'track_batches', 'track_serials', 'is_active',
    ];

    public function insert($row = null, bool $returnID = true)
    {
        $fields = $this->transformDataToArray($row, 'insert');
        return ProductIdentifierService::withLock($this->db, function () use ($fields, $returnID) {
            $fields = $this->normalizeBarcodes($fields);
            ProductIdentifierService::assertAvailable($this->db, $fields);
            return parent::insert($fields, $returnID);
        });
    }

    public function update($id = null, $row = null): bool
    {
        $fields = $this->transformDataToArray($row, 'update');
        if (!array_intersect(array_keys($fields), ProductIdentifierService::FIELDS)) return parent::update($id, $row);

        $ids = (array)$id;
        if (count($ids) !== 1 || !ctype_digit((string)reset($ids))) {
            throw new ProductIdentifierException('Update identifiers on one product at a time.');
        }
        return ProductIdentifierService::withLock($this->db, function () use ($id, $ids, $fields) {
            $fields = $this->normalizeBarcodes($fields);
            ProductIdentifierService::assertAvailable($this->db, $fields, (int)reset($ids));
            return parent::update($id, $fields);
        });
    }

    private function normalizeBarcodes(array $fields): array
    {
        foreach (['barcode', 'sealed_barcode'] as $field) {
            if (array_key_exists($field, $fields)) $fields[$field] = ProductIdentifierService::normalizeBarcode($fields[$field]);
        }
        return $fields;
    }

    /**
     * Product list with the current stock quantity of one branch,
     * category and supplier names — the shape the Products screen needs.
     *
     * Filters: q (name/sku/barcode/brand), category_id,
     *          stock = low | out (relative to reorder_level).
     */
    public function listWithStock(int $branchId, array $filters, int $page, int $perPage): array
    {
        $builder = $this->builderWithStock($branchId);

        if (! empty($filters['q'])) {
            $term = $filters['q'];
            $builder->groupStart()
                ->like('products.name', $term)
                ->orLike('products.sku', $term)
                ->orLike('products.barcode', $term)
                ->orLike('products.sealed_barcode', $term)
                ->orLike('products.brand', $term)
                ->groupEnd();
        }

        if (! empty($filters['category_id'])) {
            $builder->where('products.category_id', (int) $filters['category_id']);
        }

        $reorderStock = \App\Services\PackageInventoryService::reorderExpression('products','stock_levels',$branchId);
        if (($filters['stock'] ?? '') === 'out') {
            $builder->where('COALESCE(stock_levels.quantity, 0) <=', 0);
        } elseif (($filters['stock'] ?? '') === 'low') {
            $builder->where("($reorderStock) <= products.reorder_level",null,false);
        }

        $total = $builder->countAllResults(false);
        $rows  = $builder->orderBy('products.name', 'ASC')
            ->limit($perPage, ($page - 1) * $perPage)
            ->get()
            ->getResultArray();

        return ['items' => array_map([$this, 'castRow'], $rows), 'total' => $total];
    }

    public function findWithStock(int $productId, int $branchId): ?array
    {
        $row = $this->builderWithStock($branchId)
            ->where('products.id', $productId)
            ->get()
            ->getRowArray();

        return $row === null ? null : $this->castRow($row);
    }

    private function builderWithStock(int $branchId)
    {
        return $this->db->table($this->table)
            ->select('products.id, products.category_id, products.supplier_id,
                      products.sku, products.barcode, products.sealed_barcode, products.name, products.brand,
                      products.selling_group_id, products.default_sale_mode, products.package_unit, products.package_size, products.package_price, products.unit, products.cost_price, products.sell_price,
                      products.tax_rate, products.reorder_level, products.attributes,
                      products.image_path,
                      products.track_batches, products.track_serials, products.is_active,
                      categories.name AS category_name,
                      suppliers.name  AS supplier_name,
                      COALESCE(stock_levels.quantity, 0) AS quantity')
            ->join('categories', 'categories.id = products.category_id', 'left')
            ->join('suppliers', 'suppliers.id = products.supplier_id', 'left')
            ->join(
                'stock_levels',
                'stock_levels.product_id = products.id AND stock_levels.branch_id = ' . $branchId,
                'left',
            )
            ->where('products.deleted_at', null);
    }

    /**
     * MySQL returns every column as a string; give the app real types.
     */
    private function castRow(array $row): array
    {
        $row['id']            = (int) $row['id'];
        $row['category_id']   = $row['category_id'] === null ? null : (int) $row['category_id'];
        $row['supplier_id']   = $row['supplier_id'] === null ? null : (int) $row['supplier_id'];
        $row['cost_price']    = (float) $row['cost_price'];
        $row['sell_price']    = (float) $row['sell_price'];
        $row['tax_rate']      = (float) $row['tax_rate'];
        $row['reorder_level'] = (float) $row['reorder_level'];
        $row['track_batches'] = (bool) $row['track_batches'];
        $row['track_serials'] = (bool) $row['track_serials'];
        $row['is_active']     = (bool) $row['is_active'];
        $row['quantity']      = (float) $row['quantity'];
        $row['attributes']    = $row['attributes'] === null ? null : json_decode($row['attributes'], true);

        return $row;
    }
}
