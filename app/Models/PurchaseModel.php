<?php

namespace App\Models;

use CodeIgniter\Model;

class PurchaseModel extends Model
{
    protected $table         = 'purchases';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $updatedField  = '';        // table has no updated_at column
    protected $allowedFields = [
        'branch_id', 'supplier_id', 'user_id', 'reference_no',
        'total', 'status', 'method', 'bank_account_id', 'purchased_at',
    ];

    public function listPurchases(int $branchId, array $filters, int $page, int $perPage): array
    {
        $builder = $this->db->table($this->table)
            ->select('purchases.id, purchases.reference_no, purchases.supplier_id,
                      suppliers.name AS supplier_name, users.name AS recorded_by,
                      purchases.total, purchases.status, purchases.purchased_at,
                      COALESCE(qty.ordered, 0) AS quantity_ordered,
                      COALESCE(qty.received, 0) AS quantity_received')
            ->join('suppliers', 'suppliers.id = purchases.supplier_id', 'left')
            ->join('users', 'users.id = purchases.user_id', 'left')
            ->join(
                '(SELECT purchase_id, SUM(quantity) AS ordered, SUM(quantity_received) AS received
                  FROM purchase_items GROUP BY purchase_id) qty',
                'qty.purchase_id = purchases.id',
                'left',
            )
            ->where('purchases.branch_id', $branchId);

        if (! empty($filters['status'])) {
            $builder->where('purchases.status', $filters['status']);
        }

        if (! empty($filters['supplier_id'])) {
            $builder->where('purchases.supplier_id', (int) $filters['supplier_id']);
        }

        $total = $builder->countAllResults(false);
        $rows  = $builder->orderBy('purchases.id', 'DESC')
            ->limit($perPage, ($page - 1) * $perPage)
            ->get()
            ->getResultArray();

        foreach ($rows as &$row) {
            $row['id']                = (int) $row['id'];
            $row['supplier_id']       = $row['supplier_id'] === null ? null : (int) $row['supplier_id'];
            $row['total']             = (float) $row['total'];
            $row['quantity_ordered']  = (float) $row['quantity_ordered'];
            $row['quantity_received'] = (float) $row['quantity_received'];
        }

        return ['items' => $rows, 'total' => $total];
    }

    public function findDetailed(int $purchaseId, int $branchId): ?array
    {
        $purchase = $this->db->table($this->table)
            ->select('purchases.*, suppliers.name AS supplier_name, users.name AS recorded_by')
            ->join('suppliers', 'suppliers.id = purchases.supplier_id', 'left')
            ->join('users', 'users.id = purchases.user_id', 'left')
            ->where('purchases.id', $purchaseId)
            ->where('purchases.branch_id', $branchId)
            ->get()
            ->getRowArray();

        if ($purchase === null) {
            return null;
        }

        $purchase['id']    = (int) $purchase['id'];
        $purchase['total'] = (float) $purchase['total'];

        $purchase['items'] = array_map(static function (array $item): array {
            $item['id']                = (int) $item['id'];
            $item['product_id']        = (int) $item['product_id'];
            $item['quantity']          = (float) $item['quantity'];
            $item['quantity_received'] = (float) $item['quantity_received'];
            $item['quantity_remaining'] = round($item['quantity'] - $item['quantity_received'], 3);
            $item['unit_cost']         = (float) $item['unit_cost'];
            $item['line_total']        = (float) $item['line_total'];

            return $item;
        }, $this->db->table('purchase_items')
            ->select('purchase_items.id, purchase_items.product_id,
                      products.name AS product_name, purchase_items.quantity,
                      purchase_items.quantity_received,
                      purchase_items.unit_cost, purchase_items.line_total')
            ->join('products', 'products.id = purchase_items.product_id')
            ->where('purchase_items.purchase_id', $purchaseId)
            ->get()
            ->getResultArray());

        $purchase['quantity_ordered']  = array_sum(array_column($purchase['items'], 'quantity'));
        $purchase['quantity_received'] = array_sum(array_column($purchase['items'], 'quantity_received'));

        return $purchase;
    }
}
