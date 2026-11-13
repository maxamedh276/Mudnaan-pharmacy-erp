<?php

namespace App\Models;

use CodeIgniter\Model;

class SaleModel extends Model
{
    protected $table         = 'sales';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = [
        'uuid', 'branch_id', 'user_id', 'customer_id', 'invoice_no',
        'subtotal', 'discount', 'shipping', 'tax_total', 'grand_total', 'paid_total',
        'status', 'sync_status', 'sold_at',
    ];

    /**
     * Sales list for the Sales/Invoices screens.
     * Filters: status, customer_id, q (invoice no), date_from, date_to.
     */
    public function listSales(int $branchId, array $filters, int $page, int $perPage): array
    {
        $builder = $this->db->table($this->table)
            ->select('sales.id, sales.uuid, sales.invoice_no, sales.customer_id,
                      customers.name AS customer_name, users.name AS cashier_name,
                      sales.subtotal, sales.discount, sales.shipping, sales.tax_total,
                      sales.grand_total, sales.returned_total, sales.refunded_total, sales.return_discount_total, sales.paid_total, sales.status, sales.sold_at')
            ->join('customers', 'customers.id = sales.customer_id', 'left')
            ->join('users', 'users.id = sales.user_id', 'left')
            ->where('sales.branch_id', $branchId);

        if (! empty($filters['status'])) {
            $builder->where('sales.status', $filters['status']);
        }

        if (! empty($filters['customer_id'])) {
            $builder->where('sales.customer_id', (int) $filters['customer_id']);
        }

        if (! empty($filters['q'])) {
            $builder->like('sales.invoice_no', $filters['q']);
        }

        if (! empty($filters['date_from'])) {
            $builder->where('sales.sold_at >=', $filters['date_from'] . ' 00:00:00');
        }

        if (! empty($filters['date_to'])) {
            $builder->where('sales.sold_at <=', $filters['date_to'] . ' 23:59:59');
        }

        $total = $builder->countAllResults(false);
        $rows  = $builder->orderBy('sales.id', 'DESC')
            ->limit($perPage, ($page - 1) * $perPage)
            ->get()
            ->getResultArray();

        return ['items' => array_map([self::class, 'castHeader'], $rows), 'total' => $total];
    }

    /**
     * One sale with its line items and payments — the receipt shape.
     */
    public function findDetailed(int $saleId, int $branchId): ?array
    {
        $sale = $this->db->table($this->table)
            ->select('sales.*, customers.name AS customer_name, users.name AS cashier_name')
            ->join('customers', 'customers.id = sales.customer_id', 'left')
            ->join('users', 'users.id = sales.user_id', 'left')
            ->where('sales.id', $saleId)
            ->where('sales.branch_id', $branchId)
            ->get()
            ->getRowArray();

        if ($sale === null) {
            return null;
        }

        $sale = self::castHeader($sale);

        $sale['items'] = array_map(static function (array $item): array {
            $item['id']         = (int) $item['id'];
            $item['product_id'] = (int) $item['product_id'];
            $item['quantity']   = (float) $item['quantity'];
            $item['unit_price'] = (float) $item['unit_price'];
            $item['discount']   = (float) $item['discount'];
            $item['tax_rate']   = (float) $item['tax_rate'];
            $item['line_total'] = (float) $item['line_total'];

            return $item;
        }, $this->db->table('sale_items')
            ->select('id, product_id, description, quantity, unit_price, discount, tax_rate, line_total, stock_factor, stock_mode, unit_label')
            ->where('sale_id', $saleId)
            ->get()
            ->getResultArray());

        $sale['payments'] = array_map(static function (array $payment): array {
            $payment['id']     = (int) $payment['id'];
            $payment['amount'] = (float) $payment['amount'];

            return $payment;
        }, $this->db->table('payments')
            ->select('id, method, amount, reference, paid_at')
            ->where('sale_id', $saleId)
            ->get()
            ->getResultArray());

        return $sale;
    }

    private static function castHeader(array $sale): array
    {
        $sale['id']          = (int) $sale['id'];
        $sale['customer_id'] = $sale['customer_id'] === null ? null : (int) $sale['customer_id'];
        $sale['subtotal']    = (float) $sale['subtotal'];
        $sale['discount']    = (float) $sale['discount'];
        $sale['tax_total']   = (float) $sale['tax_total'];
        $sale['grand_total'] = (float) $sale['grand_total'];
        $sale['paid_total']  = (float) $sale['paid_total'];
        $sale['returned_total'] = (float)($sale['returned_total'] ?? 0);
        $sale['net_total'] = round($sale['grand_total'] - $sale['returned_total'], 2);

        return $sale;
    }
}
