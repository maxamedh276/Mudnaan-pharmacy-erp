<?php

namespace App\Models;

use CodeIgniter\Model;

class StockMovementModel extends Model
{
    protected $table         = 'stock_movements';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $updatedField  = '';        // ledger rows are never updated
    protected $allowedFields = [
        'branch_id', 'product_id', 'batch_id', 'qty_change',
        'reason', 'ref_type', 'ref_id', 'user_id',
    ];

    public function history(int $branchId, ?int $productId, int $page, int $perPage): array
    {
        $builder = $this->db->table($this->table)
            ->select('stock_movements.id, stock_movements.product_id,
                      products.name AS product_name, stock_movements.qty_change,
                      stock_movements.reason, stock_movements.ref_type,
                      stock_movements.ref_id, users.name AS user_name,
                      stock_movements.created_at,
                      ABS(stock_movements.qty_change) * products.cost_price AS cost_value')
            ->join('products', 'products.id = stock_movements.product_id')
            ->join('users', 'users.id = stock_movements.user_id', 'left')
            ->where('stock_movements.branch_id', $branchId);

        if ($productId !== null) {
            $builder->where('stock_movements.product_id', $productId);
        }

        $total = $builder->countAllResults(false);
        $rows  = $builder->orderBy('stock_movements.id', 'DESC')
            ->limit($perPage, ($page - 1) * $perPage)
            ->get()
            ->getResultArray();

        foreach ($rows as &$row) {
            $row['id']         = (int) $row['id'];
            $row['product_id'] = (int) $row['product_id'];
            $row['ref_id']     = $row['ref_id'] === null ? null : (int) $row['ref_id'];
            $row['qty_change'] = (float) $row['qty_change'];
            $row['cost_value'] = round((float) $row['cost_value'], 2);
        }

        return ['items' => $rows, 'total' => $total];
    }
}
