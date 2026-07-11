<?php

namespace App\Controllers\Receivables;

use App\Controllers\BaseApiController;
use App\Libraries\AuthContext;
use App\Services\ReceivablesService;
use CodeIgniter\HTTP\ResponseInterface;
use InvalidArgumentException;
use RuntimeException;

class DepositsController extends BaseApiController
{
    private const DEPOSIT_ERRORS = [
        'CUSTOMER_REQUIRED'      => 'A customer is required.',
        'INVALID_AMOUNT'         => 'The amount must be more than zero.',
        'INVALID_PAYMENT_METHOD' => 'Unknown payment method.',
        'DEPOSIT_NOT_FOUND'      => 'That deposit does not exist.',
        'DEPOSIT_NOT_ACTIVE'     => 'That deposit was already used or refunded.',
        'NOTHING_TO_REFUND'      => 'The deposit has no unused amount left.',
        'BANK_ACCOUNT_NOT_FOUND' => 'That bank account does not exist.',
        'PERIOD_CLOSED'          => 'That date falls in a closed financial period.',
    ];

    public function index(): ResponseInterface
    {
        if ($denied = $this->requirePermission('invoices.view')) {
            return $denied;
        }

        ['page' => $page, 'perPage' => $perPage] = $this->paginationParams();

        $filters = [
            'status'      => $this->request->getGet('status'),
            'customer_id' => $this->request->getGet('customer_id'),
        ];

        $result = (new ReceivablesService())->listDeposits($this->branchId(), $filters, $page, $perPage);

        return $this->respondData(
            $result['items'],
            200,
            $this->paginationMeta($page, $perPage, $result['total']),
        );
    }

    public function create(): ResponseInterface
    {
        if ($denied = $this->requirePermission('invoices.manage')) {
            return $denied;
        }

        try {
            $deposit = (new ReceivablesService())->recordDeposit(
                $this->body(),
                $this->branchId(),
                (int) AuthContext::userId(),
                (string) AuthContext::plan(),
            );
        } catch (InvalidArgumentException | RuntimeException $exception) {
            return $this->depositError($exception);
        }

        return $this->respondData($deposit, 201);
    }

    public function refund(int $depositId): ResponseInterface
    {
        if ($denied = $this->requirePermission('invoices.manage')) {
            return $denied;
        }

        try {
            $deposit = (new ReceivablesService())->refundDeposit(
                $depositId,
                $this->branchId(),
                (int) AuthContext::userId(),
                (string) AuthContext::plan(),
            );
        } catch (InvalidArgumentException | RuntimeException $exception) {
            return $this->depositError($exception);
        }

        return $this->respondData($deposit);
    }

    private function depositError(InvalidArgumentException|RuntimeException $exception): ResponseInterface
    {
        $code = $exception->getMessage();

        if (isset(self::DEPOSIT_ERRORS[$code])) {
            return $this->respondError($code, self::DEPOSIT_ERRORS[$code], 422);
        }

        throw $exception;
    }
}
