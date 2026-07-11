<?php

namespace App\Controllers\Payables;

use App\Controllers\BaseApiController;
use App\Libraries\AuthContext;
use App\Services\PayablesService;
use CodeIgniter\HTTP\ResponseInterface;
use InvalidArgumentException;
use RuntimeException;

class SupplierPaymentsController extends BaseApiController
{
    private const PAYMENT_ERRORS = [
        'INVALID_AMOUNT'         => 'The amount must be more than zero.',
        'INVALID_PAYMENT_METHOD' => 'Unknown payment method.',
        'BILL_NOT_FOUND'         => 'That bill does not exist.',
        'BILL_CANCELLED'         => 'That bill was cancelled.',
        'OVERPAYMENT'            => 'The amount is more than the bill\'s outstanding balance.',
        'SUPPLIER_REQUIRED'      => 'A supplier is required.',
        'BANK_ACCOUNT_NOT_FOUND' => 'That bank account does not exist.',
        'PERIOD_CLOSED'          => 'That date falls in a closed financial period.',
    ];

    public function index(): ResponseInterface
    {
        if ($denied = $this->requirePermission('bills.view')) {
            return $denied;
        }

        ['page' => $page, 'perPage' => $perPage] = $this->paginationParams();

        $filters = ['supplier_id' => $this->request->getGet('supplier_id')];

        $result = (new PayablesService())->listPayments($this->branchId(), $filters, $page, $perPage);

        return $this->respondData(
            $result['items'],
            200,
            $this->paginationMeta($page, $perPage, $result['total']),
        );
    }

    public function create(): ResponseInterface
    {
        if ($denied = $this->requirePermission('bills.manage')) {
            return $denied;
        }

        try {
            $payment = (new PayablesService())->recordPayment(
                $this->body(),
                $this->branchId(),
                (int) AuthContext::userId(),
                (string) AuthContext::plan(),
            );
        } catch (InvalidArgumentException | RuntimeException $exception) {
            $code = $exception->getMessage();

            if (isset(self::PAYMENT_ERRORS[$code])) {
                return $this->respondError($code, self::PAYMENT_ERRORS[$code], 422);
            }

            throw $exception;
        }

        return $this->respondData($payment, 201);
    }
}
