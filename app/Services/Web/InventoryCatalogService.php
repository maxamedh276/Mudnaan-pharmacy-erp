<?php

namespace App\Services\Web;

use App\Models\CategoryModel;
use App\Models\ProductModel;
use App\Services\ProductIdentifierService;
use InvalidArgumentException;

/** Web catalog queries and form rules, separate from the existing mobile controllers. */
class InventoryCatalogService
{
    public function categories(): array
    {
        $categories = db_connect()->table('categories c')->select('c.*, COUNT(p.id) AS product_count')
            ->join('products p', 'p.category_id = c.id AND p.deleted_at IS NULL', 'left')
            ->where('c.deleted_at', null)->groupBy('c.id')->get()->getResultArray();
        return (new CategoryHierarchy())->flatten($categories);
    }

    public function createCategory(array $input): array
    {
        return \App\Services\FinancialTransaction::run(fn () => $this->insertCategory($input));
    }

    private function insertCategory(array $input): array
    {
        $name = trim((string) ($input['name'] ?? ''));
        $parentId = !empty($input['parent_id']) ? (int) $input['parent_id'] : null;
        if ($name === '' || mb_strlen($name) > 120) throw new InvalidArgumentException('Enter a category name of up to 120 characters.');
        if ($parentId) $this->lockCategory($parentId);
        $hierarchy = array_column($this->categories(), null, 'id');
        if ($parentId && empty($hierarchy[$parentId]['selectable'])) throw new InvalidArgumentException('Select an active parent category with a valid hierarchy.');
        if ((new CategoryModel())->where('parent_id', $parentId)->where('name', $name)->countAllResults() > 0) throw new InvalidArgumentException('This category already exists under the selected parent.');
        $categoryModel = new CategoryModel();
        $categoryId = (int) $categoryModel->insert(['name' => $name, 'parent_id' => $parentId, 'is_active' => 1]);
        return $categoryModel->find($categoryId);
    }

    public function updateCategory(int $categoryId, array $input): array
    {
        return \App\Services\FinancialTransaction::run(function ($db) use ($categoryId, $input) {
            // Serialize hierarchy changes so simultaneous moves cannot create a cycle.
            $db->query('SELECT id FROM categories WHERE deleted_at IS NULL ORDER BY id FOR UPDATE')->getResultArray();
            $model = new CategoryModel();
            $existing = $model->find($categoryId);
            if (!$existing) throw new InvalidArgumentException('Category not found or already deleted.');
            $name = trim((string) ($input['name'] ?? ''));
            if ($name === '' || mb_strlen($name) > 120) throw new InvalidArgumentException('Enter a category name of up to 120 characters.');
            $parentId = $input['parent_id'] ?? null;
            if ($parentId !== null) {
                $parentId = filter_var($parentId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if ($parentId === false) throw new InvalidArgumentException('Select a valid parent category.');
            }
            $hierarchy = $this->categories();
            $parent = array_column($hierarchy, null, 'id')[$parentId ?? 0] ?? null;
            if ($parentId === $categoryId || ($parent && in_array($categoryId, array_map('intval', $parent['path_ids']), true))) {
                throw new InvalidArgumentException('A category cannot be moved under itself or one of its subcategories.');
            }
            if ($parentId !== null && empty($parent['selectable'])) throw new InvalidArgumentException('Select an active parent category with a valid hierarchy.');
            if ($model->where('parent_id', $parentId)->where('name', $name)->where('id !=', $categoryId)->countAllResults()) {
                throw new InvalidArgumentException('This category already exists under the selected parent.');
            }
            if (!$model->update($categoryId, ['name' => $name, 'parent_id' => $parentId])) throw new \RuntimeException('Category could not be updated.');
            \App\Services\AuditService::log('category.update', 'inventory', 'categories', $categoryId, before: $existing, after: ['name' => $name, 'parent_id' => $parentId]);
            return $model->find($categoryId);
        });
    }

    public function deleteCategory(int $categoryId): array
    {
        return \App\Services\FinancialTransaction::run(function ($db) use ($categoryId) {
            $this->lockCategory($categoryId);
            if ($db->table('categories')->where('parent_id', $categoryId)->where('deleted_at', null)->countAllResults()) {
                throw new InvalidArgumentException('Delete the subcategories first.');
            }
            if ($db->table('products')->where('category_id', $categoryId)->where('deleted_at', null)->countAllResults()) {
                throw new InvalidArgumentException('Move the assigned products to another category first.');
            }
            if (!(new CategoryModel())->delete($categoryId)) throw new \RuntimeException('Category could not be deleted.');
            \App\Services\AuditService::log('category.delete', 'inventory', 'categories', $categoryId);
            return ['id' => $categoryId];
        });
    }

    private function lockCategory(int $categoryId): void
    {
        if (!db_connect()->query('SELECT id FROM categories WHERE id = ? AND deleted_at IS NULL FOR UPDATE', [$categoryId])->getRowArray()) {
            throw new InvalidArgumentException('Category not found or already deleted.');
        }
    }

    public function products(int $branchId, array $filters = []): array
    {
        $hierarchy = $this->categories();
        $categoryPaths = array_column($hierarchy, 'path', 'id');
        $builder = db_connect()->table('products p')->select('p.*, COALESCE(s.quantity, 0) AS quantity')
            ->join('stock_levels s', 's.product_id = p.id AND s.branch_id = ' . $branchId, 'left')->where('p.deleted_at', null);
        $search = trim((string) ($filters['q'] ?? ''));
        if ($search !== '') $builder->groupStart()->like('p.name', $search)->orLike('p.sku', $search)->orLike('p.barcode', $search)->orLike('p.sealed_barcode', $search)->orLike('p.brand', $search)->groupEnd();
        $categoryId = (int) ($filters['category_id'] ?? 0);
        if ($categoryId > 0) {
            $descendantIds = (new CategoryHierarchy())->descendantIds($hierarchy, $categoryId);
            $builder->whereIn('p.category_id', $descendantIds ?: [-1]);
        }
        $reorderStock = \App\Services\PackageInventoryService::reorderExpression('p','s',$branchId);
        $stockFilter = (string) ($filters['stock'] ?? '');
        if ($stockFilter === 'out') $builder->where('COALESCE(s.quantity, 0) <=', 0);
        if ($stockFilter === 'low') $builder->where("($reorderStock) <= p.reorder_level",null,false);
        if ($stockFilter === 'in') $builder->where('COALESCE(s.quantity, 0) >', 0);
        foreach (['min_price' => '>=', 'max_price' => '<='] as $field => $operator) {
            if (($filters[$field] ?? '') !== '') {
                if (!is_numeric($filters[$field]) || (float) $filters[$field] < 0) throw new InvalidArgumentException('Enter valid selling-price limits.');
                $builder->where('p.sell_price ' . $operator, (float) $filters[$field]);
            }
        }
        if (($filters['min_price'] ?? '') !== '' && ($filters['max_price'] ?? '') !== '' && (float) $filters['min_price'] > (float) $filters['max_price']) throw new InvalidArgumentException('Minimum price cannot exceed maximum price.');
        $total = $builder->countAllResults(false);
        $perPage = min(100, max(1, (int) ($filters['per_page'] ?? 25)));
        $page = min(max(1, (int) ($filters['page'] ?? 1)), max(1, (int) ceil($total / $perPage)));
        $products = $builder->orderBy('p.name')->orderBy('p.id')->limit($perPage, ($page - 1) * $perPage)->get()->getResultArray();
        foreach ($products as &$product) {
            $product['id'] = (int) $product['id'];
            $product['category_id'] = $product['category_id'] === null ? null : (int) $product['category_id'];
            $product['category_path'] = $categoryPaths[$product['category_id']] ?? 'Uncategorized';
            foreach (['quantity', 'sell_price', 'cost_price', 'tax_rate', 'reorder_level'] as $field) $product[$field] = (float) $product[$field];
            $product['reorder_quantity'] = !empty($product['package_unit']) ? (new \App\Services\PackageInventoryService())->balance($branchId,$product)['sealed'] : $product['quantity'];
            $product['is_active'] = (bool) $product['is_active'];
        }
        $summary = db_connect()->query(
            "SELECT COUNT(*) AS total_products,
                    COALESCE(SUM(COALESCE(s.quantity, 0) * p.cost_price), 0) AS valuation,
                    COALESCE(SUM(COALESCE(s.quantity, 0) <= 0), 0) AS out_of_stock,
                    COALESCE(SUM(($reorderStock) <= p.reorder_level), 0) AS low_stock
             FROM products p
             LEFT JOIN stock_levels s ON s.product_id = p.id AND s.branch_id = ?
             WHERE p.deleted_at IS NULL",
            [$branchId],
        )->getRowArray();
        return ['items' => $products, 'total' => $total, 'page' => $page, 'per_page' => $perPage, 'summary' => $summary];
    }

    public function saveProduct(array $input): array
    {
        return ProductIdentifierService::withLock(db_connect(), fn () => $this->saveProductTransaction($input));
    }

    private function saveProductTransaction(array $input): array
    {
        return \App\Services\FinancialTransaction::run(function ($db) use ($input) {
            $key = $input['creation_request_key'] ?? null;
            $hash = hash('sha256', json_encode($input));
            if (empty($input['id']) && $key !== null) {
                if (!is_string($key) || !preg_match('/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/iD',$key)) throw new InvalidArgumentException('Reopen the product form and try again.');
                $existing = $db->table('products')->where('creation_request_key',$key)->get()->getRowArray();
                if ($existing) {
                    if (!hash_equals((string)$existing['creation_request_hash'],$hash)) throw new InvalidArgumentException('This request already created a different product. Reopen the form.');
                    return $existing;
                }
            }
            if (!empty($input['id']) && ((float)($input['opening_sealed'] ?? 0) != 0 || (float)($input['opening_quantity'] ?? 0) != 0)) throw new InvalidArgumentException('Opening stock is only entered when creating a product. Use Adjust stock for later corrections.');
            $product = $this->persistProduct($input);
            if (empty($input['id'])) {
                if ($key !== null) $db->table('products')->where('id',$product['id'])->update(['creation_request_key'=>$key,'creation_request_hash'=>$hash]);
                (new OpeningStockService())->create($product,$input);
            }
            return (new ProductModel())->find($product['id']);
        });
    }

    /** Pure preflight: runs the same creation rules without inserting a product. */
    public function validateNewProduct(array $input): array
    {
        unset($input['id']);
        $fields = $this->persistProduct($input, true);
        (new OpeningStockService())->create($fields, $input, true);
        return $fields;
    }

    private function persistProduct(array $input, bool $validateOnly = false): array
    {
        $productModel = new ProductModel();
        $productId = !empty($input['id']) ? (int) $input['id'] : null;
        $existingProduct = $productId ? $productModel->find($productId) : null;
        if ($productId && !$existingProduct) throw new InvalidArgumentException('Product not found.');
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 180) throw new InvalidArgumentException('Enter a product name of up to 180 characters.');
        $categoryId = (int) ($input['category_id'] ?? 0);
        if (!$validateOnly && $categoryId) $this->lockCategory($categoryId);
        $categoriesById = array_column($this->categories(), null, 'id');
        if (empty($categoriesById[$categoryId]['selectable']) && (!$existingProduct || (int) $existingProduct['category_id'] !== $categoryId)) throw new InvalidArgumentException('Select an active category.');
        foreach (['sell_price', 'reorder_level'] as $field) {
            if (!is_numeric($input[$field] ?? null) || !is_finite((float)$input[$field]) || (float) $input[$field] < 0) throw new InvalidArgumentException('Enter valid selling price and stock-alert values.');
        }
        if ((float) $input['sell_price'] > 9999999999.99 || (float) $input['reorder_level'] > 2147483647 || abs((float)$input['reorder_level'] - round((float)$input['reorder_level'],3)) > 0.0000001) throw new InvalidArgumentException('Check the price and stock alert (up to three decimals).');
        $fields = [
            'name' => $name,
            'category_id' => $categoryId,
            'barcode' => ProductIdentifierService::normalizeBarcode(array_key_exists('barcode', $input) ? $input['barcode'] : ($existingProduct['barcode'] ?? null)),
            'brand' => trim((string) ($input['brand'] ?? '')) ?: null,
            'unit' => trim((string) ($input['unit'] ?? 'pcs')),
            'sell_price' => round((float) $input['sell_price'], 2),
            'reorder_level' => round((float)$input['reorder_level'],3),
        ];
        if (mb_strlen($fields['brand'] ?? '') > 120 || $fields['unit'] === '' || mb_strlen($fields['unit']) > 30) throw new InvalidArgumentException('Check the brand and unit lengths.');
        if (isset($input['stock_type']) && !in_array($input['stock_type'], ['sealed','non_sealed'], true)) throw new InvalidArgumentException('Select Sealed or Non-Sealed stock.');
        if (($input['stock_type'] ?? '') === 'sealed' && trim((string)($input['package_unit'] ?? '')) === '') throw new InvalidArgumentException('Package name, capacity and selling price are required for sealed products.');
        if (($input['stock_type'] ?? '') === 'non_sealed') $input = array_replace($input, ['package_unit'=>'','package_size'=>null,'package_price'=>null]);
        if (array_key_exists('package_unit', $input)) {
            $packageUnit = trim((string)($input['package_unit'] ?? ''));
            $size = $input['package_size'] ?? null;
            $price = $input['package_price'] ?? null;
            if ($packageUnit !== '' && (mb_strlen($packageUnit) > 30 || !in_array($fields['unit'], ['pcs', 'kg', 'litre'], true) ||
                !is_numeric($size) || !is_finite((float)$size) || (float)$size <= 0 || (float)$size > 999999999.999 || abs((float)$size - round((float)$size, 3)) > 0.0000001 ||
                !is_numeric($price) || !is_finite((float)$price) || (float)$price < 0 || (float)$price > 9999999999.99)) {
                throw new InvalidArgumentException('Choose pieces, kg or litre, a package name, a positive capacity (up to 3 decimals), and a valid package selling price.');
            }
            if ($packageUnit !== '' && $fields['unit'] === 'pcs' && floor((float)$size) !== (float)$size) throw new InvalidArgumentException('Enter a whole number of pieces per sealed package.');
            $fields += ['package_unit' => $packageUnit ?: null, 'package_size' => $packageUnit === '' ? null : round((float)$size, 3), 'package_price' => $packageUnit === '' ? null : round((float)$price, 2)];
        }
        $effectivePackage = $fields['package_unit'] ?? ($existingProduct['package_unit'] ?? null);
        if (array_key_exists('package_unit',$fields)) $effectivePackage = $fields['package_unit'];
        $fields['sealed_barcode'] = $effectivePackage ? ProductIdentifierService::normalizeBarcode(
            array_key_exists('sealed_barcode', $input) ? $input['sealed_barcode'] : ($existingProduct['sealed_barcode'] ?? null)
        ) : null;
        if ($effectivePackage && floor((float)$fields['reorder_level']) != (float)$fields['reorder_level']) throw new InvalidArgumentException('The low-stock threshold for sealed products is a whole number of packages.');
        $mode = $input['default_sale_mode'] ?? ($existingProduct['default_sale_mode'] ?? 'loose');
        if (!in_array($mode,['loose','sealed'],true) || ($mode === 'sealed' && !$effectivePackage)) throw new InvalidArgumentException('Choose a valid default selling unit.');
        $fields['default_sale_mode'] = $mode;
        $groupId = (int)($input['selling_group_id'] ?? ($existingProduct['selling_group_id'] ?? 0));
        if ($groupId) {
            $group = db_connect()->query('SELECT * FROM selling_option_groups WHERE id=? FOR UPDATE',[$groupId])->getRowArray();
            if (!$group || !$group['is_active'] || $group['unit'] !== $fields['unit']) throw new InvalidArgumentException('Choose an active quantity group matching the base unit.');
        }
        $fields['selling_group_id'] = $groupId ?: null;
        if ($validateOnly) {
            ProductIdentifierService::assertAvailable(db_connect(), $fields);
            return $fields;
        }
        // Existing tax/status are preserved on edit; new products start active without a product tax.
        // Cost is maintained by receiving; supplier belongs to each purchase. Neither is edited here.
        if ($productId) {
            $database = db_connect();
            $database->transException(true)->transBegin();
            try {
                $locked = $database->query('SELECT * FROM products WHERE id=? AND deleted_at IS NULL FOR UPDATE', [$productId])->getRowArray();
                if (!$locked) throw new InvalidArgumentException('Product not found.');
                if ($fields['unit'] !== $locked['unit'] && $database->tableExists('pharmacy_medicines') && $database->table('pharmacy_medicines')->where('product_id', $productId)->countAllResults()) throw new InvalidArgumentException('A medicine base unit cannot change after registration. Create a new medicine for a different unit.');
                $changed = ($fields['unit'] !== $locked['unit']);
                foreach (['package_unit', 'package_size'] as $field) {
                    if (array_key_exists($field, $fields) && (string)$fields[$field] !== (string)$locked[$field]) {
                        // Numeric SQL strings contain trailing zeroes.
                        if ($field !== 'package_size' || (float)$fields[$field] !== (float)$locked[$field]) $changed = true;
                    }
                }
                if ($changed && !empty($locked['package_unit']) && (
                    $database->table('package_movements')->where('product_id', $productId)->countAllResults() ||
                    $database->table('purchase_items')->where('product_id', $productId)->where('stock_mode', 'sealed')->countAllResults())) {
                    throw new InvalidArgumentException('Package name, capacity and base unit cannot change after package transactions. Create a separate product for a different package size.');
                }
                if ($fields['unit'] !== $locked['unit'] && !empty($fields['package_unit'])) throw new InvalidArgumentException('Keep the existing base unit when enabling packages.');
                $productModel->update($productId, $fields);
                if (!$database->transStatus() || !$database->transCommit()) throw new \RuntimeException('Product could not be saved.');
            } catch (\Throwable $error) { $database->transRollback(); throw $error; }
        } else {
            do { $sku = 'SKU-' . strtoupper(bin2hex(random_bytes(8))); } while (db_connect()->table('products')->where('sku', $sku)->countAllResults() > 0);
            $productId = (int) $productModel->insert($fields + ['sku' => $sku, 'cost_price' => 0, 'supplier_id' => null, 'tax_rate' => 0, 'is_active' => 1]);
        }
        return $productModel->find($productId);
    }
}
