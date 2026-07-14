<?php

namespace App\Controllers\Inventory;

use App\Controllers\BaseApiController;
use App\Libraries\AuthContext;
use App\Models\PurchaseModel;
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
                (string) AuthContext::plan(),
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
     * POST /purchases/{id}/void  Body: { reason? }
     * Reverses a purchase (stock + accounting) after dependency checks.
     * Purchases are never edited or deleted — only voided.
     */
    private const VOID_ERRORS = [
        'PURCHASE_NOT_FOUND' => 'That purchase does not exist.',
        'ALREADY_CANCELLED'  => 'That purchase is already cancelled.',
        'PERIOD_CLOSED'      => 'The purchase falls in a closed financial period.',
        'ITEMS_SOLD'         => 'Some of the received stock has already been sold, so this purchase cannot be reversed.',
        'HAS_PAYMENTS'       => 'A supplier payment is linked to this purchase; cancel the payment first.',
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
                (string) AuthContext::plan(),
            );
        } catch (InvalidArgumentException $exception) {
            $code = $exception->getMessage();

            return $this->respondError($code, self::VOID_ERRORS[$code] ?? 'Cannot void this purchase.', 422);
        }

        return $this->respondData($result);
    }
}
