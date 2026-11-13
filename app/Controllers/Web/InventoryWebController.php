<?php

namespace App\Controllers\Web;

use App\Controllers\BaseController;
use App\Libraries\AuthContext;
use App\Models\ProductModel;
use App\Models\StockMovementModel;
use App\Services\AccountingService;
use App\Services\Web\InventoryCatalogService;
use Config\Database;

class InventoryWebController extends BaseController
{
    private function branchId(): int
    {
        $user = session()->get('user');
        return (int) ($user['branch_id'] ?? 1);
    }

    /**
     * Medicines & Catalog Grid
     */
    public function products(): string
    {
        $this->authorizeInventory('products.view');
        $catalogService = new InventoryCatalogService();
        return view('web/inventory/products', [
            'title' => 'Medicines & Catalog — Mudnaan Pharmacy',
            'catalog' => $catalogService->products($this->branchId(), $this->request->getGet()),
            'categories' => $catalogService->categories(),
            'sellingGroups' => (new \App\Services\Web\SellingOptionsService())->all(),
            'initialCategoryId' => (int) $this->request->getGet('category_id'),
        ]);
    }

    public function categories(): string
    {
        $this->authorizeInventory('products.view');
        return view('web/inventory/categories', [
            'title' => 'Categories — Mudnaan Pharmacy',
            'categories' => (new InventoryCatalogService())->categories(),
        ]);
    }

    public function categoryData(): \CodeIgniter\HTTP\ResponseInterface
    {
        $this->authorizeInventory('products.view');
        return $this->response->setJSON(['success' => true, 'data' => (new InventoryCatalogService())->categories()]);
    }

    public function productData(): \CodeIgniter\HTTP\ResponseInterface
    {
        $this->authorizeInventory('products.view');
        return $this->catalogResponse(fn () => (new InventoryCatalogService())->products($this->branchId(), $this->request->getGet()));
    }

    public function createCategory(): \CodeIgniter\HTTP\ResponseInterface
    {
        $this->authorizeInventory('products.create');
        return $this->catalogResponse(fn () => (new InventoryCatalogService())->createCategory($this->request->getJSON(true) ?? []));
    }

    public function updateCategory(int $categoryId): \CodeIgniter\HTTP\ResponseInterface
    {
        $this->authorizeInventory('products.edit');
        return $this->catalogResponse(fn () => (new InventoryCatalogService())->updateCategory($categoryId, $this->request->getJSON(true) ?? []));
    }

    public function deleteCategory(int $categoryId): \CodeIgniter\HTTP\ResponseInterface
    {
        $this->authorizeInventory('products.delete');
        return $this->catalogResponse(fn () => (new InventoryCatalogService())->deleteCategory($categoryId));
    }

    public function saveProduct(): \CodeIgniter\HTTP\ResponseInterface
    {
        $input = $this->request->getJSON(true) ?? [];
        $this->authorizeInventory(empty($input['id']) ? 'products.create' : 'products.edit');
        return $this->catalogResponse(fn () => (new InventoryCatalogService())->saveProduct($input));
    }

    public function sellingOptions(): string
    {
        $this->authorizeInventory('products.view');
        return view('web/inventory/selling_options',['title'=>'Selling Options','groups'=>(new \App\Services\Web\SellingOptionsService())->all()]);
    }
    public function saveSellingOptions(): \CodeIgniter\HTTP\ResponseInterface
    {
        $this->authorizeInventory('products.edit');
        return $this->catalogResponse(fn() => (new \App\Services\Web\SellingOptionsService())->save($this->request->getJSON(true) ?? []));
    }

    private function authorizeInventory(string $permission): void
    {
        if (!AuthContext::can($permission)) {
            throw new \CodeIgniter\HTTP\Exceptions\HTTPException('You do not have access to this inventory action.', 403);
        }
    }

    private function catalogResponse(callable $operation): \CodeIgniter\HTTP\ResponseInterface
    {
        try {
            return $this->response->setJSON(['success' => true, 'data' => $operation()]);
        } catch (\InvalidArgumentException | \RuntimeException $exception) {
            if ($exception instanceof \RuntimeException && $exception->getMessage() !== 'PERIOD_CLOSED') throw $exception;
            return $this->response->setStatusCode(422)->setJSON(['success' => false, 'message' => $exception->getMessage() === 'PERIOD_CLOSED' ? 'The financial period is closed. No product or opening stock was saved.' : $exception->getMessage()]);
        }
    }

    public function openPackage(): \CodeIgniter\HTTP\ResponseInterface
    {
        $this->authorizeInventory('stock.adjust');
        try {
            return $this->response->setJSON(['success' => true, 'data' => (new \App\Services\PackageInventoryService())->open($this->request->getJSON(true) ?? [], $this->branchId(), (int) AuthContext::userId())]);
        } catch (\InvalidArgumentException | \App\Services\PackageStockException $error) {
            return $this->response->setStatusCode(422)->setJSON(['success' => false, 'message' => $error->getMessage()]);
        }
    }

    public function adjustStock(): \CodeIgniter\HTTP\ResponseInterface
    {
        $this->authorizeInventory('stock.adjust');
        try {
            $result = (new \App\Services\Web\StockAdjustmentService())->save($this->request->getJSON(true) ?? [], $this->branchId(), (int) AuthContext::userId(), (string) AuthContext::plan());
            return $this->response->setJSON(['success' => true, 'data' => $result]);
        } catch (\InvalidArgumentException | \RuntimeException $exception) {
            $message = match ($exception->getMessage()) {
                'INSUFFICIENT_STOCK' => 'The adjustment would make stock negative. Check the current quantity.',
                'PERIOD_CLOSED' => 'The current financial period is closed. No stock was changed.',
                'PRODUCT_NOT_FOUND' => 'Product not found.',
                default => ($exception instanceof \InvalidArgumentException || $exception instanceof \App\Services\PackageStockException) ? $exception->getMessage() : 'The adjustment could not be saved. No stock was changed.',
            };
            return $this->response->setStatusCode(422)->setJSON(['success' => false, 'message' => $message]);
        }
    }

    /**
     * Stock Levels & Valuation
     */
    public function stock(): string
    {
        $branchId = $this->branchId();
        $db = Database::connect();

        $reorderStock = \App\Services\PackageInventoryService::reorderExpression('p','sl',$branchId);
        $summaryRow = $db->query(
            "SELECT COALESCE(SUM(sl.quantity), 0) AS total_units,
                    COALESCE(SUM(sl.quantity * p.cost_price), 0) AS stock_value,
                    SUM(CASE WHEN sl.quantity <= 0 THEN 1 ELSE 0 END) AS out_of_stock,
                    SUM(CASE WHEN ($reorderStock) <= p.reorder_level THEN 1 ELSE 0 END) AS low_stock
             FROM stock_levels sl
             JOIN products p ON p.id = sl.product_id AND p.deleted_at IS NULL
             WHERE sl.branch_id = ?",
            [$branchId]
        )->getRowArray() ?? [
            'total_units' => 0,
            'stock_value' => 0,
            'out_of_stock' => 0,
            'low_stock' => 0
        ];

        // Fetch products with stock
        $productsRes = (new ProductModel())->listWithStock($branchId, [], 1, 1000);

        // Book value for premium tenants
        $bookValue = null;
        if (session()->get('plan') === 'premium') {
            $row = $db->query(
                "SELECT COALESCE(SUM(l.debit - l.credit), 0) AS balance
                 FROM journal_entry_lines l
                 JOIN journal_entries e ON e.id = l.journal_entry_id AND e.status = 'posted'
                 JOIN accounts a ON a.id = l.account_id
                 WHERE a.code = ? AND l.branch_id = ?",
                [AccountingService::INVENTORY, $branchId]
            )->getRowArray();
            $bookValue = round((float) ($row['balance'] ?? 0), 2);
        }

        return view('web/inventory/stock', [
            'title'      => 'Stock Levels & Valuation — Mudnaan Pharmacy',
            'summary'    => [
                'total_units'  => (float) $summaryRow['total_units'],
                'stock_value'  => (float) $summaryRow['stock_value'],
                'book_value'   => $bookValue,
                'low_stock'    => (int) $summaryRow['low_stock'],
                'out_of_stock' => (int) $summaryRow['out_of_stock'],
            ],
            'products'   => $productsRes['items'],
            'packageHistory' => $db->table('package_movements m')->select('m.*, p.name, p.package_unit, p.unit, u.name AS opened_by')->join('products p', 'p.id=m.product_id')->join('users u', 'u.id=m.user_id', 'left')->where('m.branch_id', $branchId)->where('m.reason', 'open')->orderBy('m.id', 'DESC')->limit(50)->get()->getResultArray(),
            'user'       => session()->get('user') ?? [],
            'tenant'     => session()->get('tenant') ?? [],
            'plan'       => session()->get('plan') ?? 'premium',
        ]);
    }

    /**
     * Stock Adjustments & Movements Ledger
     */
    public function adjustments(): string
    {
        $branchId = $this->branchId();
        $movementsRes = (new StockMovementModel())->history($branchId, null, 1, 1000);
        $movementIds = array_column($movementsRes['items'], 'id');
        $notesByMovement = (new \App\Services\Web\StockAdjustmentService())->notesForMovements($branchId, $movementIds);
        $details = $movementIds ? db_connect()->table('web_stock_adjustment_details')->whereIn('movement_id',$movementIds)->where('branch_id',$branchId)->get()->getResultArray() : [];
        $details = array_column($details,null,'movement_id');
        foreach ($movementsRes['items'] as &$movement) { $movement['note'] = $notesByMovement[$movement['id']] ?? null; $movement['adjustment_detail'] = $details[$movement['id']] ?? null; }
        unset($movement);
        $productsRes = (new ProductModel())->listWithStock($branchId, [], 1, 1000);

        return view('web/inventory/adjustments', [
            'title'     => 'Stock Movements & Adjustments — Mudnaan Pharmacy',
            'movements' => $movementsRes['items'],
            'products'  => $productsRes['items'],
            'user'      => session()->get('user') ?? [],
            'tenant'    => session()->get('tenant') ?? [],
            'plan'      => session()->get('plan') ?? 'premium',
        ]);
    }
}
