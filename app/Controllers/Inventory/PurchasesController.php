<?php

namespace App\Controllers\Inventory;

use App\Controllers\BaseApiController;
use App\Libraries\AuthContext;
use App\Models\PurchaseModel;
use App\Services\PurchaseReceiptService;
use App\Services\PurchaseService;
use App\Services\ReversalService;
use CodeIgniter\HTTP\ResponseInterface;
use InvalidArgumentException;

class PurchasesController extends BaseApiController
{
    private const PURCHASE_ERRORS = [
        'EMPTY_PURCHASE'        => 'The purchase has no items.',
        'INVALID_PURCHASE_ITEM' => 'A purchase item is missing its product or quantity.',
        'PRODUCT_NOT_FOUND'     => 'One of the products no longer exists.',
    ];

    private const RECEIPT_ERRORS = [
        'PURCHASE_NOT_FOUND'      => 'That purchase order does not exist.',
        'PURCHASE_NOT_RECEIVABLE' => 'This purchase order is cancelled or already fully received.',
        'EMPTY_RECEIPT'           => 'The receipt has no items.',
        'INVALID_RECEIPT_ITEM'    => 'A receipt item is missing its purchase line or quantity.',
        'OVER_RECEIPT'            => 'That quantity is more than what remains on the order.',
    ];

    public function index(): ResponseInterface
    {
        if ($denied = $this->requirePermission('purchases.view')) {
            return $denied;
        }

        ['page' => $page, 'perPage' => $perPage] = $this->paginationParams();

        $filters = [
            'status'      => $this->request->getGet('status'),
            'supplier_id' => $this->request->getGet('supplier_id'),
        ];

        $result = (new PurchaseModel())->listPurchases($this->branchId(), $filters, $page, $perPage);

        return $this->respondData(
            $result['items'],
            200,
            $this->paginationMeta($page, $perPage, $result['total']),
        );
    }

    public function show(int $purchaseId): ResponseInterface
    {
        if ($denied = $this->requirePermission('purchases.view')) {
            return $denied;
        }

        $purchase = (new PurchaseModel())->findDetailed($purchaseId, $this->branchId());

        return $purchase === null
            ? $this->respondNotFound('Purchase')
            : $this->respondData($purchase);
    }

    /**
     * POST /purchases — creates a purchase ORDER only: supplier, items,
     * price. Nothing is received yet (status 'pending'); no stock or GL
     * impact happens until goods are receipted — see receive().
     */
    public function create(): ResponseInterface
    {
        if ($denied = $this->requirePermission('purchases.create')) {
            return $denied;
        }

        try {
            $purchase = (new PurchaseService())->createPurchase(
                $this->body(),
                $this->branchId(),
                (int) AuthContext::userId(),
            );
        } catch (InvalidArgumentException $exception) {
            $code = $exception->getMessage();

            if (isset(self::PURCHASE_ERRORS[$code])) {
                return $this->respondError($code, self::PURCHASE_ERRORS[$code], 422);
            }

            throw $exception;
        }

        return $this->respondData($purchase, 201);
    }

    /**
     * GET /purchases/{id}/receipts — every goods-in batch recorded
     * against this purchase order so far.
     */
    public function receipts(int $purchaseId): ResponseInterface
    {
        if ($denied = $this->requirePermission('purchases.view')) {
            return $denied;
        }

        return $this->respondData(
            (new PurchaseReceiptService())->listReceipts($purchaseId, $this->branchId()),
        );
    }

    /**
     * POST /purchases/{id}/receipts  Body: {received_at?, notes?,
     *   items: [{purchase_item_id, quantity}]}
     *
     * Records goods actually arriving — the only action that moves
     * stock, posts the journal and grows the supplier's payable. Can be
     * called more than once per purchase order for partial receiving.
     */
    public function receive(int $purchaseId): ResponseInterface
    {
        if ($denied = $this->requirePermission('purchases.create')) {
            return $denied;
        }

        try {
            $receipt = (new PurchaseReceiptService())->createReceipt(
                $purchaseId,
                $this->body(),
                $this->branchId(),
                (int) AuthContext::userId(),
                (string) AuthContext::plan(),
            );
        } catch (InvalidArgumentException $exception) {
            $code = $exception->getMessage();

            return $this->respondError($code, self::RECEIPT_ERRORS[$code] ?? 'Cannot record this receipt.', 422);
        }

        return $this->respondData($receipt, 201);
    }

    /**
     * POST /purchases/{id}/void  Body: { reason? }
     * Only a still-pending order (nothing received yet) can be voided —
     * see ReversalService::voidPurchase for why.
     */
    private const VOID_ERRORS = [
        'PURCHASE_NOT_FOUND' => 'That purchase does not exist.',
        'ALREADY_CANCELLED'  => 'That purchase is already cancelled.',
        'ALREADY_RECEIVING'  => 'Goods have already been received against this order, so it can no longer be cancelled.',
    ];

    public function void(int $purchaseId): ResponseInterface
    {
        if ($denied = $this->requirePermission('purchases.create')) {
            return $denied;
        }

        try {
            $result = (new ReversalService())->voidPurchase(
                $purchaseId,
                $this->branchId(),
                (string) ($this->body()['reason'] ?? ''),
                (int) AuthContext::userId(),
            );
        } catch (InvalidArgumentException $exception) {
            $code = $exception->getMessage();

            return $this->respondError($code, self::VOID_ERRORS[$code] ?? 'Cannot void this purchase.', 422);
        }

        return $this->respondData($result);
    }
}
