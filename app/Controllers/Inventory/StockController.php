<?php

namespace App\Controllers\Inventory;

use App\Controllers\BaseApiController;
use App\Libraries\AuthContext;
use App\Models\ProductModel;
use App\Models\StockMovementModel;
use App\Services\AccountingService;
use App\Services\InventoryService;
use CodeIgniter\HTTP\ResponseInterface;
use InvalidArgumentException;

class StockController extends BaseApiController
{
    /**
     * GET /api/v1/stock — the Stock Overview screen: levels per product
     * plus the headline numbers (total units, stock value, low/out counts).
     */
    public function index(): ResponseInterface
    {
        if ($denied = $this->requirePermission('stock.view')) {
            return $denied;
        }

        ['page' => $page, 'perPage' => $perPage] = $this->paginationParams();

        $filters = [
            'q'     => $this->request->getGet('q'),
            'stock' => $this->request->getGet('stock'),
        ];

        $branchId = $this->branchId();
        $result   = (new ProductModel())->listWithStock($branchId, $filters, $page, $perPage);

        $summaryRow = db_connect()->query(
            'SELECT COALESCE(SUM(sl.quantity), 0) AS total_units,
                    COALESCE(SUM(sl.quantity * p.cost_price), 0) AS stock_value,
                    SUM(CASE WHEN sl.quantity <= 0 THEN 1 ELSE 0 END) AS out_of_stock,
                    SUM(CASE WHEN sl.quantity > 0 AND sl.quantity <= p.reorder_level THEN 1 ELSE 0 END) AS low_stock
             FROM stock_levels sl
             JOIN products p ON p.id = sl.product_id AND p.deleted_at IS NULL
             WHERE sl.branch_id = ?',
            [$branchId],
        )->getRowArray();

        return $this->respondData(
            [
                'summary' => [
                    'total_units'  => (float) $summaryRow['total_units'],
                    'stock_value'  => (float) $summaryRow['stock_value'],
                    // What the Inventory account says the stock is worth on
                    // the books (Premium); should track stock_value.
                    'book_value'   => $this->inventoryBookValue($branchId),
                    'low_stock'    => (int) $summaryRow['low_stock'],
                    'out_of_stock' => (int) $summaryRow['out_of_stock'],
                ],
                'items' => $result['items'],
            ],
            200,
            $this->paginationMeta($page, $perPage, $result['total']),
        );
    }

    /** The Inventory (1200) account balance, or null for Basic tenants. */
    private function inventoryBookValue(int $branchId): ?float
    {
        if (! AuthContext::isPremium()) {
            return null;
        }

        $row = db_connect()->query(
            "SELECT COALESCE(SUM(l.debit - l.credit), 0) AS balance
             FROM journal_entry_lines l
             JOIN journal_entries e ON e.id = l.journal_entry_id AND e.status = 'posted'
             JOIN accounts a ON a.id = l.account_id
             WHERE a.code = ? AND l.branch_id = ?",
            [AccountingService::INVENTORY, $branchId],
        )->getRowArray();

        return round((float) $row['balance'], 2);
    }

    /**
     * GET /api/v1/stock/movements?product_id= — the Stock Movements screen.
     */
    public function movements(): ResponseInterface
    {
        if ($denied = $this->requirePermission('stock.view')) {
            return $denied;
        }

        ['page' => $page, 'perPage' => $perPage] = $this->paginationParams();

        $productId = $this->request->getGet('product_id');
        $result    = (new StockMovementModel())->history(
            $this->branchId(),
            $productId === null ? null : (int) $productId,
            $page,
            $perPage,
        );

        return $this->respondData(
            $result['items'],
            200,
            $this->paginationMeta($page, $perPage, $result['total']),
        );
    }

    /**
     * POST /api/v1/stock/adjustments — the Stock Adjustment screen.
     * Body: { product_id, qty_change (+in / -out), note? }
     *
     * Writes the stock ledger row AND posts the value to the books
     * (Inventory <-> Inventory Adjustment) so stock and accounting stay
     * in step. Returns the updated product plus the value moved.
     */
    public function adjust(): ResponseInterface
    {
        if ($denied = $this->requirePermission('stock.adjust')) {
            return $denied;
        }

        $body      = $this->body();
        $productId = (int) ($body['product_id'] ?? 0);
        $qtyChange = (float) ($body['qty_change'] ?? 0);

        if ($productId <= 0 || $qtyChange == 0.0) {
            return $this->respondValidationErrors([
                'product_id' => 'A product is required.',
                'qty_change' => 'A non-zero quantity change is required.',
            ]);
        }

        try {
            $result = (new InventoryService())->adjustStock(
                $this->branchId(),
                $productId,
                $qtyChange,
                $body['note'] ?? null,
                AuthContext::userId(),
                (string) AuthContext::plan(),
            );
        } catch (InvalidArgumentException $exception) {
            return match ($exception->getMessage()) {
                'PRODUCT_NOT_FOUND'  => $this->respondNotFound('Product'),
                'INSUFFICIENT_STOCK' => $this->respondError(
                    'INSUFFICIENT_STOCK',
                    'The adjustment would make the stock negative.',
                    422,
                ),
                default => $this->respondError('ADJUSTMENT_FAILED', $exception->getMessage(), 422),
            };
        }

        return $this->respondData($result, 201);
    }
}
