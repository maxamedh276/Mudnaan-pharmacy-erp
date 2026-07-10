<?php

namespace App\Services;

/**
 * Read-only aggregate queries behind the dashboard and report screens.
 * Basic reports serve every plan; advanced ones sit behind plan:premium.
 *
 * Profit here = revenue (net of tax) minus cost of goods, where cost
 * comes from the product's current cost_price. Premium tenants get the
 * exact figure from the journal (COGS account); this approximation is
 * what Basic plans see.
 */
class ReportService
{
    /**
     * The home dashboard numbers: today's sales/profit, stock alerts,
     * the last sales and a 7-day sales curve.
     */
    public function dashboard(int $branchId): array
    {
        $database = db_connect();
        $today    = date('Y-m-d');

        $salesToday = $database->query(
            "SELECT COALESCE(SUM(grand_total), 0) AS total,
                    COALESCE(SUM(grand_total - tax_total), 0) AS net,
                    COUNT(*) AS transactions
             FROM sales
             WHERE branch_id = ? AND status IN ('completed','partial')
               AND sold_at >= ? AND sold_at < ? + INTERVAL 1 DAY",
            [$branchId, $today, $today],
        )->getRowArray();

        $costToday = $database->query(
            "SELECT COALESCE(SUM(si.quantity * p.cost_price), 0) AS cost
             FROM sale_items si
             JOIN sales s ON s.id = si.sale_id
             JOIN products p ON p.id = si.product_id
             WHERE s.branch_id = ? AND s.status IN ('completed','partial')
               AND s.sold_at >= ? AND s.sold_at < ? + INTERVAL 1 DAY",
            [$branchId, $today, $today],
        )->getRowArray();

        $stockAlerts = $database->query(
            'SELECT SUM(CASE WHEN sl.quantity <= 0 THEN 1 ELSE 0 END) AS out_of_stock,
                    SUM(CASE WHEN sl.quantity > 0 AND sl.quantity <= p.reorder_level THEN 1 ELSE 0 END) AS low_stock
             FROM stock_levels sl
             JOIN products p ON p.id = sl.product_id AND p.deleted_at IS NULL
             WHERE sl.branch_id = ?',
            [$branchId],
        )->getRowArray();

        $pendingPurchases = $database->table('purchases')
            ->where('branch_id', $branchId)
            ->where('status', 'pending')
            ->countAllResults();

        $recentSales = $database->query(
            "SELECT s.id, s.invoice_no, s.grand_total, s.status, s.sold_at,
                    COALESCE(c.name, 'Walk-in Customer') AS customer_name,
                    (SELECT method FROM payments WHERE sale_id = s.id LIMIT 1) AS payment_method
             FROM sales s
             LEFT JOIN customers c ON c.id = s.customer_id
             WHERE s.branch_id = ?
             ORDER BY s.id DESC
             LIMIT 10",
            [$branchId],
        )->getResultArray();

        foreach ($recentSales as &$sale) {
            $sale['id']          = (int) $sale['id'];
            $sale['grand_total'] = (float) $sale['grand_total'];
        }

        return [
            'today' => [
                'total_sales'  => (float) $salesToday['total'],
                'net_profit'   => round((float) $salesToday['net'] - (float) $costToday['cost'], 2),
                'transactions' => (int) $salesToday['transactions'],
            ],
            'stock' => [
                'low_stock'    => (int) ($stockAlerts['low_stock'] ?? 0),
                'out_of_stock' => (int) ($stockAlerts['out_of_stock'] ?? 0),
            ],
            'pending_purchases' => $pendingPurchases,
            'recent_sales'      => $recentSales,
            'sales_by_day'      => $this->salesByDay($branchId, date('Y-m-d', strtotime('-6 days')), $today),
        ];
    }

    /**
     * Advanced sales report (Premium): range totals, daily curve and
     * top-selling products.
     */
    public function salesReport(int $branchId, string $dateFrom, string $dateTo): array
    {
        $database = db_connect();

        $totals = $database->query(
            "SELECT COALESCE(SUM(grand_total), 0) AS total_sales,
                    COALESCE(SUM(grand_total - tax_total), 0) AS net,
                    COALESCE(SUM(tax_total), 0) AS tax,
                    COALESCE(SUM(discount), 0) AS discount,
                    COUNT(*) AS transactions
             FROM sales
             WHERE branch_id = ? AND status IN ('completed','partial')
               AND sold_at >= ? AND sold_at < ? + INTERVAL 1 DAY",
            [$branchId, $dateFrom, $dateTo],
        )->getRowArray();

        $cost = $database->query(
            "SELECT COALESCE(SUM(si.quantity * p.cost_price), 0) AS cost
             FROM sale_items si
             JOIN sales s ON s.id = si.sale_id
             JOIN products p ON p.id = si.product_id
             WHERE s.branch_id = ? AND s.status IN ('completed','partial')
               AND s.sold_at >= ? AND s.sold_at < ? + INTERVAL 1 DAY",
            [$branchId, $dateFrom, $dateTo],
        )->getRowArray();

        $topProducts = $database->query(
            "SELECT si.product_id, si.description AS name,
                    SUM(si.quantity) AS quantity_sold,
                    SUM(si.line_total) AS revenue
             FROM sale_items si
             JOIN sales s ON s.id = si.sale_id
             WHERE s.branch_id = ? AND s.status IN ('completed','partial')
               AND s.sold_at >= ? AND s.sold_at < ? + INTERVAL 1 DAY
             GROUP BY si.product_id, si.description
             ORDER BY revenue DESC
             LIMIT 10",
            [$branchId, $dateFrom, $dateTo],
        )->getResultArray();

        foreach ($topProducts as &$product) {
            $product['product_id']    = (int) $product['product_id'];
            $product['quantity_sold'] = (float) $product['quantity_sold'];
            $product['revenue']       = (float) $product['revenue'];
        }

        return [
            'period' => ['from' => $dateFrom, 'to' => $dateTo],
            'totals' => [
                'total_sales'  => (float) $totals['total_sales'],
                'net_sales'    => (float) $totals['net'],
                'tax'          => (float) $totals['tax'],
                'discount'     => (float) $totals['discount'],
                'gross_profit' => round((float) $totals['net'] - (float) $cost['cost'], 2),
                'transactions' => (int) $totals['transactions'],
            ],
            'sales_by_day' => $this->salesByDay($branchId, $dateFrom, $dateTo),
            'top_products' => $topProducts,
        ];
    }

    /**
     * Advanced inventory report (Premium): value by category + alerts.
     */
    public function inventoryReport(int $branchId): array
    {
        $database = db_connect();

        $byCategory = $database->query(
            "SELECT COALESCE(c.name, 'Uncategorized') AS category,
                    SUM(sl.quantity) AS units,
                    SUM(sl.quantity * p.cost_price) AS value
             FROM stock_levels sl
             JOIN products p ON p.id = sl.product_id AND p.deleted_at IS NULL
             LEFT JOIN categories c ON c.id = p.category_id
             WHERE sl.branch_id = ?
             GROUP BY c.name
             ORDER BY value DESC",
            [$branchId],
        )->getResultArray();

        foreach ($byCategory as &$row) {
            $row['units'] = (float) $row['units'];
            $row['value'] = (float) $row['value'];
        }

        $lowStockItems = $database->query(
            'SELECT p.id, p.name, sl.quantity, p.reorder_level
             FROM stock_levels sl
             JOIN products p ON p.id = sl.product_id AND p.deleted_at IS NULL
             WHERE sl.branch_id = ? AND sl.quantity <= p.reorder_level
             ORDER BY sl.quantity ASC
             LIMIT 20',
            [$branchId],
        )->getResultArray();

        foreach ($lowStockItems as &$item) {
            $item['id']            = (int) $item['id'];
            $item['quantity']      = (float) $item['quantity'];
            $item['reorder_level'] = (int) $item['reorder_level'];
        }

        return [
            'total_value'     => round(array_sum(array_column($byCategory, 'value')), 2),
            'by_category'     => $byCategory,
            'low_stock_items' => $lowStockItems,
        ];
    }

    private function salesByDay(int $branchId, string $dateFrom, string $dateTo): array
    {
        $rows = db_connect()->query(
            "SELECT DATE(sold_at) AS day, COALESCE(SUM(grand_total), 0) AS total
             FROM sales
             WHERE branch_id = ? AND status IN ('completed','partial')
               AND sold_at >= ? AND sold_at < ? + INTERVAL 1 DAY
             GROUP BY DATE(sold_at)
             ORDER BY day",
            [$branchId, $dateFrom, $dateTo],
        )->getResultArray();

        foreach ($rows as &$row) {
            $row['total'] = (float) $row['total'];
        }

        return $rows;
    }
}
