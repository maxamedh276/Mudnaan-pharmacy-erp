<?php

namespace App\Controllers\Payables;

use App\Controllers\BaseApiController;
use App\Libraries\AuthContext;
use App\Services\PayablesService;
use CodeIgniter\HTTP\ResponseInterface;
use InvalidArgumentException;
use RuntimeException;

class SupplierBillsController extends BaseApiController
{
    private const BILL_ERRORS = [
        'SUPPLIER_REQUIRED'  => 'A supplier is required.',
        'EMPTY_BILL'         => 'The bill has no lines.',
        'INVALID_BILL_ITEM'  => 'A bill line is missing its description, quantity or cost.',
        'PERIOD_CLOSED'      => 'That date falls in a closed financial period.',
    ];

    public function index(): ResponseInterface
    {
        if ($denied = $this->requirePermission('bills.view')) {
            return $denied;
        }

        ['page' => $page, 'perPage' => $perPage] = $this->paginationParams();

        $filters = [
            'status'      => $this->request->getGet('status'),
            'supplier_id' => $this->request->getGet('supplier_id'),
            'q'           => $this->request->getGet('q'),
        ];

        $result = (new PayablesService())->listBills($this->branchId(), $filters, $page, $perPage);

        return $this->respondData(
            $result['items'],
            200,
            $this->paginationMeta($page, $perPage, $result['total']),
        );
    }

    public function show(int $billId): ResponseInterface
    {
        if ($denied = $this->requirePermission('bills.view')) {
            return $denied;
        }

        $bill = (new PayablesService())->findBill($billId, $this->branchId());

        return $bill === null
            ? $this->respondNotFound('Bill')
            : $this->respondData($bill);
    }

    public function create(): ResponseInterface
    {
        if ($denied = $this->requirePermission('bills.manage')) {
            return $denied;
        }

        try {
            $bill = (new PayablesService())->createBill(
                $this->body(),
                $this->branchId(),
                (int) AuthContext::userId(),
                (string) AuthContext::plan(),
            );
        } catch (InvalidArgumentException | RuntimeException $exception) {
            $code = $exception->getMessage();

            if (isset(self::BILL_ERRORS[$code])) {
                return $this->respondError($code, self::BILL_ERRORS[$code], 422);
            }

            throw $exception;
        }

        return $this->respondData($bill, 201);
    }
}
