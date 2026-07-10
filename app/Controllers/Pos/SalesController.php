<?php

namespace App\Controllers\Pos;

use App\Controllers\BaseApiController;
use App\Libraries\AuthContext;
use App\Models\SaleModel;
use App\Services\SalesService;
use CodeIgniter\HTTP\ResponseInterface;
use InvalidArgumentException;
use RuntimeException;

class SalesController extends BaseApiController
{
    /**
     * Error codes SalesService throws => HTTP status + human message.
     * The app translates by code (mobile_app_structure_provider.md §9).
     */
    private const SALE_ERRORS = [
        'EMPTY_SALE'               => [422, 'The sale has no items.'],
        'INVALID_SALE_ITEM'        => [422, 'A sale item is missing its product or quantity.'],
        'PRODUCT_NOT_FOUND'        => [422, 'One of the products no longer exists.'],
        'INSUFFICIENT_STOCK'       => [422, 'Not enough stock for one of the items.'],
        'CREDIT_REQUIRES_CUSTOMER' => [422, 'Select a customer before selling on credit.'],
        'INVALID_PAYMENT_METHOD'   => [422, 'Unknown payment method.'],
    ];

    /**
     * GET /api/v1/sales?status=&customer_id=&q=&date_from=&date_to=
     */
    public function index(): ResponseInterface
    {
        if ($denied = $this->requirePermission('sales.view')) {
            return $denied;
        }

        ['page' => $page, 'perPage' => $perPage] = $this->paginationParams();

        $filters = [
            'status'      => $this->request->getGet('status'),
            'customer_id' => $this->request->getGet('customer_id'),
            'q'           => $this->request->getGet('q'),
            'date_from'   => $this->request->getGet('date_from'),
            'date_to'     => $this->request->getGet('date_to'),
        ];

        $result = (new SaleModel())->listSales($this->branchId(), $filters, $page, $perPage);

        return $this->respondData(
            $result['items'],
            200,
            $this->paginationMeta($page, $perPage, $result['total']),
        );
    }

    public function show(int $saleId): ResponseInterface
    {
        if ($denied = $this->requirePermission('sales.view')) {
            return $denied;
        }

        $sale = (new SaleModel())->findDetailed($saleId, $this->branchId());

        return $sale === null
            ? $this->respondNotFound('Sale')
            : $this->respondData($sale);
    }

    /**
     * POST /api/v1/sales — "Complete Sale" at the POS.
     */
    public function create(): ResponseInterface
    {
        if ($denied = $this->requirePermission('sales.create')) {
            return $denied;
        }

        try {
            $sale = (new SalesService())->createSale(
                $this->body(),
                $this->branchId(),
                (int) AuthContext::userId(),
                (string) AuthContext::plan(),
            );
        } catch (InvalidArgumentException | RuntimeException $exception) {
            $code = $exception->getMessage();

            if (isset(self::SALE_ERRORS[$code])) {
                [$status, $message] = self::SALE_ERRORS[$code];

                return $this->respondError($code, $message, $status);
            }

            throw $exception;
        }

        return $this->respondData($sale, 201);
    }
}
