<?php
namespace App\Services\Web;

use App\Libraries\AuthContext;
use App\Models\SupplierModel;
use App\Services\AuditService;
use App\Services\FinancialTransaction;
use App\Services\ProductIdentifierService;
use InvalidArgumentException;

/** Create-only imports. All rows pass preflight before the transaction makes its first write. */
final class WorkbookImportService
{
    public const PERMISSIONS = ['products' => 'products.create', 'suppliers' => 'suppliers.manage', 'customers' => 'customers.manage'];
    public const PRODUCT_HEADERS = ['name', 'category', 'unit', 'sell_price', 'reorder_level', 'stock_type', 'barcode', 'brand', 'package_unit', 'package_size', 'package_price', 'default_sale_mode', 'quantity_group', 'opening_sealed', 'opening_quantity', 'opening_unit_cost', 'sealed_barcode'];
    public const CONTACT_HEADERS = ['name', 'phone', 'email', 'address'];

    public function authorize(string $kind): void
    {
        if (!isset(self::PERMISSIONS[$kind])) throw new InvalidArgumentException('Unknown import type.');
        if (!AuthContext::can(self::PERMISSIONS[$kind])) throw new \CodeIgniter\HTTP\Exceptions\HTTPException('You do not have permission to import these records.', 403);
    }

    public function process(string $kind, string $path, bool $commit = false): array
    {
        $this->authorize($kind);
        $rows = (new ImportWorkbookReader())->read($path, $kind === 'products' ? self::PRODUCT_HEADERS : self::CONTACT_HEADERS, ['barcode', 'sealed_barcode']);
        if (!$commit) return $this->validateRows($kind, $rows);

        $db = db_connect();
        if ($kind === 'products') {
            return ProductIdentifierService::withLock($db, fn () => $this->commit($kind, $rows));
        }
        return $this->commit($kind, $rows);
    }

    private function commit(string $kind, array $rows): array
    {
        $db = db_connect();
        // Serialize web imports for this tenant. Re-check references and duplicates after obtaining the lock.
        $lock = 'workbook-import-' . substr(hash('sha256', (string)$db->database), 0, 40);
        if ((int)$db->query('SELECT GET_LOCK(?, 10) acquired', [$lock])->getRow('acquired') !== 1) throw new InvalidArgumentException('Another import is running. Please try again shortly.');
        try {
            return FinancialTransaction::run(function () use ($kind, $rows, $db) {
                $result = $this->validateRows($kind, $rows);
                if (!$result['valid']) return $result;
                foreach ($result['records'] as $rowNumber => $record) {
                    try {
                        if ($kind === 'products') (new InventoryCatalogService())->saveProduct($record);
                        elseif ($kind === 'customers') (new CustomerDetailsService())->save($record, null);
                        else {
                            $model = new SupplierModel();
                            $id = $model->insert($record);
                            if (!$id) throw new \RuntimeException('Supplier could not be saved.');
                            AuditService::log('supplier.create', 'suppliers', 'suppliers', (int)$id, after: $record);
                        }
                        if (!$db->transStatus()) throw new \RuntimeException('Record could not be saved.');
                    } catch (\Throwable $error) {
                        log_message('error', "Workbook import $kind row $rowNumber: " . $error->getMessage());
                        throw new InvalidArgumentException("Row $rowNumber could not be saved. The entire import was rolled back. Check opening stock accounting settings if applicable, then validate again.", 0, $error);
                    }
                }
                unset($result['records']);
                $result['imported'] = count($rows);
                return $result;
            });
        } finally { $db->query('SELECT RELEASE_LOCK(?)', [$lock]); }
    }

    public function validateRows(string $kind, array $rows): array
    {
        $this->authorize($kind);
        $db = db_connect(); $errors = []; $records = []; $seen = [];
        $existing = $db->table($kind)->where('deleted_at', null)->get()->getResultArray();
        foreach ($existing as $item) {
            foreach ($this->duplicateKeys($kind, $item) as $key) $seen[$key] = 'an existing record';
        }
        $categories = []; $groups = [];
        if ($kind === 'products') {
            foreach ((new InventoryCatalogService())->categories() as $category) {
                if ($category['selectable']) $categories[$this->key($category['path'])][] = (int)$category['id'];
            }
            foreach ($db->table('selling_option_groups')->where('is_active', 1)->get()->getResultArray() as $group) $groups[$this->key($group['name'])][] = $group;
        }
        foreach ($rows as $number => $row) {
            try {
                if ($kind === 'products') {
                    foreach (['name', 'category', 'unit', 'sell_price', 'reorder_level', 'stock_type'] as $field) {
                        if (($row[$field] ?? '') === '') throw new InvalidArgumentException("$field is required.");
                    }
                    if (!in_array($row['unit'], ['pcs','box','pack','kg','g','litre','ml','cup'], true)) throw new InvalidArgumentException('unit must be pcs, box, pack, kg, g, litre, ml or cup.');
                    $matches = $categories[$this->key($row['category'])] ?? [];
                    if (count($matches) !== 1) throw new InvalidArgumentException('category must match one active category path. See Categories in the application.');
                    $row['category_id'] = $matches[0]; unset($row['category']);
                    $groupName = $row['quantity_group'] ?? ''; unset($row['quantity_group']);
                    if ($groupName !== '') {
                        $matching = array_values(array_filter($groups[$this->key($groupName)] ?? [], static fn ($g) => $g['unit'] === $row['unit']));
                        if (count($matching) !== 1) throw new InvalidArgumentException('quantity_group must match an active group with the same unit.');
                        $row['selling_group_id'] = (int)$matching[0]['id'];
                    }
                    if (($row['default_sale_mode'] ?? '') === '') $row['default_sale_mode'] = 'loose';
                    foreach (['opening_sealed', 'opening_quantity'] as $field) if (($row[$field] ?? '') === '') $row[$field] = 0;
                    if ($row['stock_type'] === 'non_sealed' && (($row['package_unit'] ?? '') !== '' || ($row['package_size'] ?? '') !== '' || ($row['package_price'] ?? '') !== '' || ($row['sealed_barcode'] ?? '') !== '' || (float)$row['opening_sealed'] !== 0.0)) throw new InvalidArgumentException('Leave package details empty and opening_sealed zero for non_sealed products.');
                    (new InventoryCatalogService())->validateNewProduct($row);
                } else {
                    $row = $this->contact($kind, $row);
                }
                foreach ($this->duplicateKeys($kind, $row) as $key) {
                    if (isset($seen[$key])) throw new InvalidArgumentException('Duplicate ' . explode(':', $key, 2)[0] . ': matches ' . $seen[$key] . '. Imports create new records only.');
                }
                foreach ($this->duplicateKeys($kind, $row) as $key) $seen[$key] = "row $number";
                $records[$number] = $row;
            } catch (InvalidArgumentException $error) { $errors[] = ['row' => $number, 'message' => $error->getMessage()]; }
        }
        return ['valid' => !$errors, 'count' => count($rows), 'errors' => $errors, 'records' => $records];
    }

    private function contact(string $kind, array $row): array
    {
        if ($kind === 'customers') return (new CustomerDetailsService())->validate($row);
        $fields = [];
        foreach (['name' => 150, 'phone' => 40, 'email' => 150, 'address' => 255] as $field => $limit) {
            $value = trim((string)($row[$field] ?? ''));
            if (mb_strlen($value) > $limit) throw new InvalidArgumentException("$field must be at most $limit characters.");
            $fields[$field] = $value === '' && $field !== 'name' ? null : $value;
        }
        if ($fields['name'] === '') throw new InvalidArgumentException('Supplier name is required.');
        if ($fields['email'] !== null && !filter_var($fields['email'], FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Enter a valid email address.');
        return $fields;
    }

    private function duplicateKeys(string $kind, array $row): array
    {
        $keys = ['name:' . $this->key((string)($row['name'] ?? ''))];
        foreach ($kind === 'products' ? ['barcode', 'sealed_barcode'] : ['phone', 'email'] as $field) {
            $value = $this->key((string)($row[$field] ?? ''));
            $namespace = $field === 'sealed_barcode' ? 'barcode' : $field;
            if ($value !== '') $keys[] = "$namespace:$value";
        }
        return $keys;
    }

    private function key(string $value): string { return mb_strtolower(trim($value)); }
}
