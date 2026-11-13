<?php
namespace App\Services;

use InvalidArgumentException;

/** All callers that mutate packages hold the product row lock until commit. */
class PackageInventoryService
{
    public static function reorderExpression(string $productAlias, string $stockAlias, int $branch): string
    {
        return "CASE WHEN {$productAlias}.package_unit IS NOT NULL AND {$productAlias}.package_unit <> '' THEN COALESCE((SELECT SUM(pm.package_change) FROM package_movements pm WHERE pm.branch_id=" . (int)$branch . " AND pm.product_id={$productAlias}.id),0) ELSE COALESCE({$stockAlias}.quantity,0) END";
    }

    public function balance(int $branch, array $product): array
    {
        $sealed = (int) db_connect()->query(
            'SELECT COALESCE(SUM(package_change), 0) AS sealed FROM package_movements WHERE branch_id=? AND product_id=? FOR UPDATE',
            [$branch, $product['id']],
        )->getRow('sealed');
        $total = (new InventoryService())->currentQuantity($branch, (int)$product['id']);
        return ['sealed' => $sealed, 'opened' => round($total - $sealed * (float)($product['package_size'] ?? 0), 3),
            'total' => $total, 'unit' => $product['unit'], 'package_unit' => $product['package_unit'] ?? null];
    }

    public function line(array $product, array $input): array
    {
        if (isset($input['medicine_unit_id'])) {
            if (!empty($product['package_unit'])) throw new InvalidArgumentException('Reconcile legacy sealed packages before using pharmacy selling units.');
            $unit = (new \App\Services\Pharmacy\MedicineUnitService())->resolve((int)$product['id'], $input['medicine_unit_id']);
            \App\Services\Pharmacy\MedicineUnitService::baseQuantity($unit, $input['quantity'] ?? null);
            return ['stock_mode' => 'loose', 'stock_factor' => (float)$unit['factor'], 'unit_label' => $unit['name']];
        }
        $mode = $input['stock_mode'] ?? 'loose';
        if (!in_array($mode, ['loose', 'sealed'], true)) throw new InvalidArgumentException('INVALID_SALE_ITEM');
        $sealed = $mode === 'sealed';
        if ($sealed && empty($product['package_unit'])) throw new InvalidArgumentException('This product has no sealed package configured.');
        $qty = $input['quantity'] ?? null;
        if (!is_numeric($qty) || !is_finite((float)$qty) || (float)$qty <= 0 || (float)$qty > 999999999.999 || abs((float)$qty - round((float)$qty, 3)) > 0.0000001 || ($sealed && floor((float)$qty) != (float)$qty)) {
            throw new InvalidArgumentException('Enter a positive quantity; sealed packages must be whole numbers.');
        }
        $factor = $sealed ? (float)$product['package_size'] : 1.0;
        if (round((float)$qty * $factor, 3) > 999999999.999) throw new InvalidArgumentException('The converted quantity is too large.');
        return ['stock_mode' => $mode, 'stock_factor' => $factor, 'unit_label' => $sealed ? $product['package_unit'] : $product['unit']];
    }

    public function requireStock(int $branch, array $product, string $mode, float $quantity): void
    {
        if (empty($product['package_unit'])) return;
        $stock = $this->balance($branch, $product);
        $available = $mode === 'sealed' ? $stock['sealed'] : $stock['opened'];
        if (round($quantity, 3) <= $available) return;
        $unit = $mode === 'sealed' ? $product['package_unit'] : $product['unit'];
        $message = $mode === 'sealed'
            ? "{$product['name']}: only {$available} sealed {$unit} available."
            : "{$product['name']}: opened stock is {$available} {$unit}; requested {$quantity} {$unit}. Open a new {$product['package_unit']} from Inventory > Stock. / Fadlan stock-ga ka fur {$product['package_unit']} cusub.";
        throw new PackageStockException($stock + ['product_id' => (int)$product['id'], 'requested' => $quantity, 'mode' => $mode], $message);
    }

    public function record(int $branch, int $product, int $change, float $size, string $reason, ?int $ref, int $user, ?string $key = null): void
    {
        db_connect()->table('package_movements')->insert(['branch_id' => $branch, 'product_id' => $product,
            'package_change' => $change, 'package_size' => $size, 'reason' => $reason, 'ref_id' => $ref, 'user_id' => $user, 'request_key' => $key]);
    }

    public function open(array $input, int $branch, int $user): array
    {
        $id = (int)($input['product_id'] ?? 0);
        $qty = $input['quantity'] ?? null;
        $key = $input['request_key'] ?? '';
        if (!is_numeric($qty) || !is_finite((float)$qty) || (float)$qty < 1 || (float)$qty > 999999999 || floor((float)$qty) != (float)$qty) throw new InvalidArgumentException('Enter a whole number of packages to open.');
        if (!is_string($key) || !preg_match('/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/iD', $key)) throw new InvalidArgumentException('Reopen the form and try again.');
        return FinancialTransaction::run(function ($db) use ($id, $qty, $key, $branch, $user) {
            $product = $db->query('SELECT * FROM products WHERE id=? AND deleted_at IS NULL FOR UPDATE', [$id])->getRowArray();
            if (!$product || empty($product['package_unit'])) throw new InvalidArgumentException('Select a product with sealed packages.');
            $existing = $db->table('package_movements')->where('branch_id', $branch)->where('request_key', $key)->get()->getRowArray();
            if ($existing) {
                if ((int)$existing['product_id'] !== $id || (int)$existing['package_change'] !== -(int)$qty || $existing['reason'] !== 'open') throw new InvalidArgumentException('This request was already used. Reopen the form.');
            } else {
                $this->requireStock($branch, $product, 'sealed', (float)$qty);
                // Opening changes the split only: it must never change stock value or post a journal.
                $this->record($branch, $id, -(int)$qty, (float)$product['package_size'], 'open', null, $user, $key);
                AuditService::log('stock.open_package', 'inventory', 'products', $id, after: ['opened_packages' => (int)$qty, 'base_quantity' => round((float)$qty * (float)$product['package_size'], 3), 'branch_id' => $branch]);
            }
            return $this->balance($branch, $product);
        });
    }
}
