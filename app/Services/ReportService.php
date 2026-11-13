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
            "SELECT COALESCE(SUM(COALESCE(si.cost_total, si.quantity * si.stock_factor * p.cost_price)), 0) AS cost
             FROM sale_items si
             JOIN sales s ON s.id = si.sale_id
             JOIN products p ON p.id = si.product_id
             WHERE s.branch_id = ? AND s.status IN ('completed','partial')
               AND s.sold_at >= ? AND s.sold_at < ? + INTERVAL 1 DAY",
            [$branchId, $today, $today],
        )->getRowArray();

        $reorderStock = PackageInventoryService::reorderExpression('p','sl',$branchId);
        $stockAlerts = $database->query(
            "SELECT SUM(CASE WHEN sl.quantity <= 0 THEN 1 ELSE 0 END) AS out_of_stock,
                    SUM(CASE WHEN ($reorderStock) <= p.reorder_level THEN 1 ELSE 0 END) AS low_stock
             FROM stock_levels sl
             JOIN products p ON p.id = sl.product_id AND p.deleted_at IS NULL
             WHERE sl.branch_id = ?",
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
            "SELECT COALESCE(SUM(COALESCE(si.cost_total, si.quantity * si.stock_factor * p.cost_price)), 0) AS cost
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

    // ================================================================
    //  SALES REPORTS
    // ================================================================

    /** Sales grouped by customer over a period. */
    public function salesByCustomer(int $branchId, string $from, string $to): array
    {
        return $this->groupedSales($branchId, $from, $to,
            "COALESCE(c.name, 'Walk-in Customer') AS label",
            'LEFT JOIN customers c ON c.id = s.customer_id',
            'label');
    }

    /** Sales grouped by product (also drives most/least sold). */
    public function salesByProduct(int $branchId, string $from, string $to, string $order = 'DESC'): array
    {
        $rows = db_connect()->query(
            "SELECT si.description AS label,
                    SUM(si.quantity) AS quantity, SUM(si.line_total) AS total
             FROM sale_items si
             JOIN sales s ON s.id = si.sale_id
             WHERE s.branch_id = ? AND s.status IN ('completed','partial')
               AND s.sold_at >= ? AND s.sold_at < ? + INTERVAL 1 DAY
             GROUP BY si.product_id, si.description
             ORDER BY quantity " . ($order === 'ASC' ? 'ASC' : 'DESC'),
            [$branchId, $from, $to],
        )->getResultArray();

        foreach ($rows as &$row) {
            $row['quantity'] = (float) $row['quantity'];
            $row['total']    = (float) $row['total'];
        }

        return ['period' => ['from' => $from, 'to' => $to], 'rows' => $rows];
    }

    /** Sales grouped by category over a period. */
    public function salesByCategory(int $branchId, string $from, string $to): array
    {
        $rows = db_connect()->query(
            "SELECT COALESCE(cat.name, 'Uncategorized') AS label,
                    SUM(si.quantity) AS quantity, SUM(si.line_total) AS total
             FROM sale_items si
             JOIN sales s ON s.id = si.sale_id
             LEFT JOIN products p ON p.id = si.product_id
             LEFT JOIN categories cat ON cat.id = p.category_id
             WHERE s.branch_id = ? AND s.status IN ('completed','partial')
               AND s.sold_at >= ? AND s.sold_at < ? + INTERVAL 1 DAY
             GROUP BY cat.name
             ORDER BY total DESC",
            [$branchId, $from, $to],
        )->getResultArray();

        foreach ($rows as &$row) {
            $row['quantity'] = (float) $row['quantity'];
            $row['total']    = (float) $row['total'];
        }

        return ['period' => ['from' => $from, 'to' => $to], 'rows' => $rows];
    }

    // ================================================================
    //  INVENTORY REPORTS
    // ================================================================

    /** Stock valuation: every product's on-hand qty × cost + sell value. */
    public function stockValuation(int $branchId): array
    {
        $rows = db_connect()->query(
            "SELECT p.name, sl.quantity,
                    p.cost_price, p.sell_price,
                    (sl.quantity * p.cost_price) AS cost_value,
                    (sl.quantity * p.sell_price) AS retail_value
             FROM stock_levels sl
             JOIN products p ON p.id = sl.product_id AND p.deleted_at IS NULL
             WHERE sl.branch_id = ?
             ORDER BY cost_value DESC",
            [$branchId],
        )->getResultArray();

        $totalCost = 0.0;
        $totalRetail = 0.0;
        foreach ($rows as &$row) {
            $row['quantity']     = (float) $row['quantity'];
            $row['cost_price']   = (float) $row['cost_price'];
            $row['sell_price']   = (float) $row['sell_price'];
            $row['cost_value']   = round((float) $row['cost_value'], 2);
            $row['retail_value'] = round((float) $row['retail_value'], 2);
            $totalCost   += $row['cost_value'];
            $totalRetail += $row['retail_value'];
        }

        return [
            'rows'         => $rows,
            'total_cost'   => round($totalCost, 2),
            'total_retail' => round($totalRetail, 2),
        ];
    }

    /** Stock movement ledger over a period. */
    public function stockMovement(int $branchId, string $from, string $to): array
    {
        $rows = db_connect()->query(
            "SELECT m.created_at, p.name AS product, m.qty_change, m.reason,
                    m.ref_type, u.name AS user_name,
                    ABS(m.qty_change) * p.cost_price AS cost_value
             FROM stock_movements m
             JOIN products p ON p.id = m.product_id
             LEFT JOIN users u ON u.id = m.user_id
             WHERE m.branch_id = ? AND m.created_at >= ? AND m.created_at < ? + INTERVAL 1 DAY
             ORDER BY m.id DESC",
            [$branchId, $from, $to],
        )->getResultArray();

        foreach ($rows as &$row) {
            $row['qty_change'] = (float) $row['qty_change'];
            $row['cost_value'] = round((float) $row['cost_value'], 2);
        }

        return ['period' => ['from' => $from, 'to' => $to], 'rows' => $rows];
    }

    // ================================================================
    //  CUSTOMER / SUPPLIER REPORTS
    // ================================================================

    /** Customer balances (owed positive). Optionally filtered by name/phone. */
    public function customerBalances(?string $q = null): array
    {
        $sql    = "SELECT name, phone, -balance AS owed
             FROM customers WHERE deleted_at IS NULL";
        $params = [];

        if ($q !== null && $q !== '') {
            $sql   .= ' AND (name LIKE ? OR phone LIKE ?)';
            $like   = '%' . $q . '%';
            $params = [$like, $like];
        }

        $rows = db_connect()->query($sql . ' ORDER BY owed DESC', $params)->getResultArray();

        foreach ($rows as &$row) {
            $row['owed'] = round((float) $row['owed'], 2);
        }

        return ['rows' => $rows];
    }

    /** Aging of unpaid customer invoices bucketed by age. */
    public function customerAging(int $branchId, ?string $q = null): array
    {
        return $this->aging('customer_invoices', 'customer_id', 'customers', 'invoice_date', $branchId, $q);
    }

    /** Top customers by sales value over a period. */
    public function topCustomers(int $branchId, string $from, string $to, ?string $q = null): array
    {
        return $this->groupedSales($branchId, $from, $to,
            "COALESCE(c.name, 'Walk-in Customer') AS label",
            'LEFT JOIN customers c ON c.id = s.customer_id',
            'label', limit: 10, searchColumn: 'c.name', q: $q);
    }

    /**
     * Supplier balances (payable positive). Optionally filtered by
     * name/phone.
     *
     * Computed as billed minus paid — NOT as a sum of each bill's own
     * (total - paid_total) — because a payment can be recorded
     * on-account (no bill_id) without ever reducing a specific bill's
     * paid_total; billed-minus-paid stays correct either way. Mirrors
     * SupplierModel::withBalances() so this report and the supplier
     * payment picker never disagree.
     */
    public function supplierBalances(int $branchId, ?string $q = null): array
    {
        $params       = [$branchId, $branchId];
        $searchClause = '';

        if ($q !== null && $q !== '') {
            $searchClause = ' AND (s.name LIKE ? OR s.phone LIKE ?)';
            $like         = '%' . $q . '%';
            $params[]     = $like;
            $params[]     = $like;
        }

        $rows = db_connect()->query(
            "SELECT s.name, s.phone,
                    COALESCE(billed.total, 0) - COALESCE(paid.total, 0) AS payable
             FROM suppliers s
             LEFT JOIN (
                 SELECT supplier_id, SUM(total + refunded_total) AS total FROM supplier_bills
                 WHERE branch_id = ? AND deleted_at IS NULL AND status != 'cancelled'
                 GROUP BY supplier_id
             ) billed ON billed.supplier_id = s.id
             LEFT JOIN (
                 SELECT supplier_id, SUM(amount) AS total FROM supplier_payments
                 WHERE branch_id = ? AND status = 'active'
                 GROUP BY supplier_id
             ) paid ON paid.supplier_id = s.id
             WHERE s.deleted_at IS NULL$searchClause
             HAVING payable <> 0
             ORDER BY payable DESC",
            $params,
        )->getResultArray();

        foreach ($rows as &$row) {
            $row['payable'] = round((float) $row['payable'], 2);
        }

        return ['rows' => $rows];
    }

    /** Aging of unpaid supplier bills bucketed by age. */
    public function supplierAging(int $branchId, ?string $q = null): array
    {
        return $this->aging('supplier_bills', 'supplier_id', 'suppliers', 'bill_date', $branchId, $q);
    }

    /** Top suppliers by purchase value over a period. */
    public function topSuppliers(int $branchId, string $from, string $to, ?string $q = null): array
    {
        $params       = [$branchId, $from, $to];
        $searchClause = '';

        if ($q !== null && $q !== '') {
            $searchClause = ' AND sup.name LIKE ?';
            $params[]     = '%' . $q . '%';
        }

        $rows = db_connect()->query(
            "SELECT sup.name AS label, COALESCE(SUM(p.total), 0) AS total
             FROM purchases p
             JOIN suppliers sup ON sup.id = p.supplier_id
             WHERE p.branch_id = ? AND p.status != 'cancelled'
               AND p.purchased_at >= ? AND p.purchased_at < ? + INTERVAL 1 DAY$searchClause
             GROUP BY sup.id, sup.name
             ORDER BY total DESC
             LIMIT 10",
            $params,
        )->getResultArray();

        foreach ($rows as &$row) {
            $row['total'] = (float) $row['total'];
        }

        return ['period' => ['from' => $from, 'to' => $to], 'rows' => $rows];
    }

    private function groupedSales(
        int $branchId,
        string $from,
        string $to,
        string $labelExpr,
        string $join,
        string $groupBy,
        int $limit = 0,
        ?string $searchColumn = null,
        ?string $q = null,
    ): array {
        $limitClause  = $limit > 0 ? ' LIMIT ' . $limit : '';
        $params       = [$branchId, $from, $to];
        $searchClause = '';

        if ($searchColumn !== null && $q !== null && $q !== '') {
            $searchClause = " AND $searchColumn LIKE ?";
            $params[]     = '%' . $q . '%';
        }

        $rows = db_connect()->query(
            "SELECT $labelExpr, COUNT(*) AS transactions,
                    COALESCE(SUM(s.grand_total), 0) AS total
             FROM sales s
             $join
             WHERE s.branch_id = ? AND s.status IN ('completed','partial')
               AND s.sold_at >= ? AND s.sold_at < ? + INTERVAL 1 DAY$searchClause
             GROUP BY $groupBy
             ORDER BY total DESC" . $limitClause,
            $params,
        )->getResultArray();

        foreach ($rows as &$row) {
            $row['transactions'] = (int) $row['transactions'];
            $row['total']        = (float) $row['total'];
        }

        return ['period' => ['from' => $from, 'to' => $to], 'rows' => $rows];
    }

    /**
     * Shared aging bucketer for receivable/payable documents.
     */
    private function aging(
        string $table,
        string $partyKey,
        string $partyTable,
        string $dateCol,
        int $branchId,
        ?string $q = null,
    ): array {
        $params       = [$branchId];
        $searchClause = '';

        if ($q !== null && $q !== '') {
            $searchClause = ' AND party.name LIKE ?';
            $params[]     = '%' . $q . '%';
        }

        $rows = db_connect()->query(
            "SELECT party.name AS label,
                    SUM(CASE WHEN DATEDIFF(CURDATE(), d.$dateCol) <= 30 THEN d.total - d.paid_total ELSE 0 END) AS b0,
                    SUM(CASE WHEN DATEDIFF(CURDATE(), d.$dateCol) BETWEEN 31 AND 60 THEN d.total - d.paid_total ELSE 0 END) AS b30,
                    SUM(CASE WHEN DATEDIFF(CURDATE(), d.$dateCol) BETWEEN 61 AND 90 THEN d.total - d.paid_total ELSE 0 END) AS b60,
                    SUM(CASE WHEN DATEDIFF(CURDATE(), d.$dateCol) > 90 THEN d.total - d.paid_total ELSE 0 END) AS b90,
                    SUM(d.total - d.paid_total) AS total
             FROM $table d
             JOIN $partyTable party ON party.id = d.$partyKey
             WHERE d.branch_id = ? AND d.deleted_at IS NULL
               AND d.status IN ('unpaid','partial')$searchClause
             GROUP BY party.id, party.name
             HAVING total > 0
             ORDER BY total DESC",
            $params,
        )->getResultArray();

        foreach ($rows as &$row) {
            foreach (['b0', 'b30', 'b60', 'b90', 'total'] as $key) {
                $row[$key] = round((float) $row[$key], 2);
            }
        }

        return ['buckets' => ['0-30', '31-60', '61-90', '90+'], 'rows' => $rows];
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
