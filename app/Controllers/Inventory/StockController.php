<?php

namespace App\Controllers\Inventory;

use App\Controllers\BaseApiController;
use App\Libraries\AuthContext;
use App\Models\ProductModel;
use App\Models\StockMovementModel;
use App\Services\InventoryService;
use CodeIgniter\HTTP\ResponseInterface;

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
                    'low_stock'    => (int) $summaryRow['low_stock'],
                    'out_of_stock' => (int) $summaryRow['out_of_stock'],
                ],
                'items' => $result['items'],
            ],
            200,
            $this->paginationMeta($page, $perPage, $result['total']),
        );
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

        $productModel = new ProductModel();

        if ($productModel->find($productId) === null) {
            return $this->respondNotFound('Product');
        }

        $branchId  = $this->branchId();
        $inventory = new InventoryService();

        if ($qtyChange < 0 && $inventory->currentQuantity($branchId, $productId) + $qtyChange < 0) {
            return $this->respondError(
                'INSUFFICIENT_STOCK',
                'The adjustment would make the stock negative.',
                422,
            );
        }

        $inventory->recordMovement(
            $branchId,
            $productId,
            $qtyChange,
            'adjustment',
            'adjustment',
            null,
            AuthContext::userId(),
        );

        return $this->respondData(
            $productModel->findWithStock($productId, $branchId),
            201,
        );
    }
}
