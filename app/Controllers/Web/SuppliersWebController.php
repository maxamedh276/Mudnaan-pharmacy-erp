<?php

namespace App\Controllers\Web;

use App\Controllers\BaseController;
use App\Models\ProductModel;
use App\Models\PurchaseModel;
use App\Models\SupplierModel;
use Config\Database;
use App\Libraries\AuthContext;
use App\Services\Web\PurchaseOrderService;

class SuppliersWebController extends BaseController
{
    private function branchId(): int
    {
        $user = session()->get('user');
        return (int) ($user['branch_id'] ?? 1);
    }

    /**
     * Supplier Directory & Outstanding Balances
     */
    public function index(): string
    {
        $this->authorizeSupplierAction('suppliers.view');
        $supplierModel = new SupplierModel();
        $res = $supplierModel->search(null, 1, 1000);
        $suppliers = $res['items'];

        $totalSuppliers = count($suppliers);
        $activeSuppliers = 0;
        $totalPayable = 0.0;

        foreach ($suppliers as $s) {
            if (!empty($s['is_active'])) {
                $activeSuppliers++;
            }
            $totalPayable += (float) ($s['balance'] ?? 0);
        }

        return view('web/suppliers/index', [
            'title'           => 'Supplier Directory & Statements — Mudnaan Pharmacy',
            'suppliers'       => $suppliers,
            'totalSuppliers'  => $totalSuppliers,
            'activeSuppliers' => $activeSuppliers,
            'totalPayable'    => $totalPayable,
            'user'            => session()->get('user') ?? [],
            'tenant'          => session()->get('tenant') ?? [],
            'plan'            => session()->get('plan') ?? 'premium',
        ]);
    }

    /**
     * Purchase Orders Management Hub
     */
    public function purchases(): string
    {
        $this->authorizeSupplierAction('purchases.view');
        $branchId = $this->branchId();
        $purchaseModel = new PurchaseModel();
        $res = $purchaseModel->listPurchases($branchId, [], 1, max(1, $purchaseModel->where('branch_id', $branchId)->countAllResults()));
        $purchases = $res['items'];

        $suppliers = (new SupplierModel())->orderBy('name', 'ASC')->findAll();
        $productsRes = (new ProductModel())->listWithStock($branchId, [], 1, max(1, (new ProductModel())->countAllResults()));
        $products = $productsRes['items'];
        if (db_connect()->tableExists('pharmacy_medicine_units')) {
            foreach ($products as &$product) $product['medicine_units'] = (new \App\Services\Pharmacy\MedicineUnitService())->units((int)$product['id']);
            unset($product);
        }

        $totalSpend = 0.0;
        $pendingCount = 0;
        $receivedCount = 0;

        foreach ($purchases as $p) {
            $totalSpend += (float) $p['total'];
            if ($p['status'] === 'pending' || $p['status'] === 'partially_received') {
                $pendingCount++;
            } elseif ($p['status'] === 'received') {
                $receivedCount++;
            }
        }

        return view('web/suppliers/purchases', [
            'title'         => 'Purchase Orders & Procurement — Mudnaan Pharmacy',
            'purchases'     => $purchases,
            'suppliers'     => $suppliers,
            'products'      => $products,
            'totalSpend'    => $totalSpend,
            'pendingCount'  => $pendingCount,
            'receivedCount' => $receivedCount,
            'user'          => session()->get('user') ?? [],
            'tenant'        => session()->get('tenant') ?? [],
            'plan'          => session()->get('plan') ?? 'premium',
        ]);
    }

    /** Enrich web statements without changing the mobile statement response. */
    public function statement(int $supplierId): \CodeIgniter\HTTP\ResponseInterface
    {
        $this->authorizeSupplierAction('suppliers.view');
        $statement = (new \App\Services\StatementService())->supplierStatement($supplierId, $this->branchId(), []);
        if (!$statement) return $this->response->setStatusCode(404)->setJSON(['success' => false, 'message' => 'Supplier not found.']);
        $bills = db_connect()->table('supplier_bills b')
            ->select('b.id, p.id AS purchase_id, p.reference_no')
            ->join('purchases p', 'p.id = b.purchase_id AND p.branch_id = b.branch_id', 'left')
            ->where('b.supplier_id', $supplierId)->where('b.branch_id', $this->branchId())
            ->where('b.deleted_at', null)->get()->getResultArray();
        $billsById = array_column($bills, null, 'id');
        foreach ($statement['entries'] as &$entry) {
            $purchase = $entry['type'] === 'bill' ? ($billsById[$entry['id']] ?? null) : null;
            if (!empty($purchase['purchase_id'])) {
                $entry['ref'] = $purchase['reference_no'] ?: 'PO-' . $purchase['purchase_id'];
                if (AuthContext::can('purchases.view')) {
                    $entry['purchase_url'] = site_url('suppliers/purchases') . '?po=' . (int) $purchase['purchase_id'];
                }
            }
        }
        unset($entry);
        return $this->response->setJSON(['success' => true, 'data' => $statement]);
    }

    public function savePurchase(): \CodeIgniter\HTTP\ResponseInterface
    {
        // The existing purchase-create permission also governs pre-receipt corrections.
        $this->authorizeSupplierAction('purchases.create');
        try {
            $purchase = (new PurchaseOrderService())->save($this->request->getJSON(true) ?? [], $this->branchId(), (int) AuthContext::userId());
            return $this->response->setJSON(['success' => true, 'data' => $purchase]);
        } catch (\InvalidArgumentException $exception) {
            return $this->response->setStatusCode(422)->setJSON(['success' => false, 'message' => $exception->getMessage()]);
        }
    }

    private function authorizeSupplierAction(string $permission): void
    {
        if (!AuthContext::can($permission)) throw new \CodeIgniter\HTTP\Exceptions\HTTPException('You do not have access to this supplier action.', 403);
    }

    /**
     * Goods Receiving Workspace
     */
    public function receiving(): string
    {
        $branchId = $this->branchId();
        $purchaseModel = new PurchaseModel();
        
        // Orders that can still receive shipments: pending or partially_received
        $pendingRes = $purchaseModel->listPurchases($branchId, ['status' => 'pending'], 1, 500);
        $partialRes = $purchaseModel->listPurchases($branchId, ['status' => 'partially_received'], 1, 500);
        $receivablePurchases = array_merge($partialRes['items'], $pendingRes['items']);

        // Recent receipts audit
        $db = Database::connect();
        $recentReceipts = $db->table('purchase_receipts')
            ->select('purchase_receipts.*, purchases.reference_no, suppliers.name as supplier_name, users.name as received_by_name')
            ->join('purchases', 'purchases.id = purchase_receipts.purchase_id', 'left')
            ->join('suppliers', 'suppliers.id = purchases.supplier_id', 'left')
            ->join('users', 'users.id = purchase_receipts.created_by', 'left')
            ->where('purchase_receipts.branch_id', $branchId)
            ->orderBy('purchase_receipts.id', 'DESC')
            ->limit(50)
            ->get()
            ->getResultArray();

        return view('web/suppliers/receiving', [
            'title'               => 'Goods Receiving & Inspection — Mudnaan Pharmacy',
            'receivablePurchases' => $receivablePurchases,
            'recentReceipts'      => $recentReceipts,
            'user'                => session()->get('user') ?? [],
            'tenant'              => session()->get('tenant') ?? [],
            'plan'                => session()->get('plan') ?? 'premium',
        ]);
    }
}
