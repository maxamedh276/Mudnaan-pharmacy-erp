<?php

namespace App\Controllers\Payables;

use App\Controllers\BaseApiController;
use App\Libraries\AuthContext;
use App\Services\PayablesService;
use App\Services\ReversalService;
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

    private const VOID_ERRORS = [
        'PAYMENT_NOT_FOUND' => 'That payment does not exist.',
        'ALREADY_CANCELLED' => 'That payment is already cancelled.',
        'PERIOD_CLOSED'     => 'The payment falls in a closed financial period and cannot be changed.',
    ];

    /**
     * POST /supplier-payments/{id}/void  Body: { reason? }
     * Reverses the payment's accounting and restores the bill balance.
     */
    public function void(int $paymentId): ResponseInterface
    {
        if ($denied = $this->requirePermission('bills.manage')) {
            return $denied;
        }

        try {
            $result = (new ReversalService())->voidSupplierPayment(
                $paymentId,
                $this->branchId(),
                (string) ($this->body()['reason'] ?? ''),
                (int) AuthContext::userId(),
                (string) AuthContext::plan(),
            );
        } catch (InvalidArgumentException $exception) {
            $code = $exception->getMessage();

            return $this->respondError($code, self::VOID_ERRORS[$code] ?? 'Cannot void this payment.', 422);
        }

        return $this->respondData($result);
    }
}
