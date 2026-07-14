<?php

namespace App\Controllers\Receivables;

use App\Controllers\BaseApiController;
use App\Libraries\AuthContext;
use App\Services\ReceivablesService;
use App\Services\ReversalService;
use CodeIgniter\HTTP\ResponseInterface;
use InvalidArgumentException;
use RuntimeException;

class ReceiptsController extends BaseApiController
{
    private const RECEIPT_ERRORS = [
        'INVALID_AMOUNT'         => 'The amount must be more than zero.',
        'INVALID_PAYMENT_METHOD' => 'Unknown payment method.',
        'INVOICE_NOT_FOUND'      => 'That invoice does not exist.',
        'INVOICE_CANCELLED'      => 'That invoice was cancelled.',
        'OVERPAYMENT'            => 'The amount is more than the invoice\'s outstanding balance.',
        'CUSTOMER_REQUIRED'      => 'A customer is required.',
        'BANK_ACCOUNT_NOT_FOUND' => 'That bank account does not exist.',
        'PERIOD_CLOSED'          => 'That date falls in a closed financial period.',
    ];

    public function index(): ResponseInterface
    {
        if ($denied = $this->requirePermission('invoices.view')) {
            return $denied;
        }

        ['page' => $page, 'perPage' => $perPage] = $this->paginationParams();

        $filters = ['customer_id' => $this->request->getGet('customer_id')];

        $result = (new ReceivablesService())->listReceipts($this->branchId(), $filters, $page, $perPage);

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
            $receipt = (new ReceivablesService())->recordReceipt(
                $this->body(),
                $this->branchId(),
                (int) AuthContext::userId(),
                (string) AuthContext::plan(),
            );
        } catch (InvalidArgumentException | RuntimeException $exception) {
            $code = $exception->getMessage();

            if (isset(self::RECEIPT_ERRORS[$code])) {
                return $this->respondError($code, self::RECEIPT_ERRORS[$code], 422);
            }

            throw $exception;
        }

        return $this->respondData($receipt, 201);
    }

    private const VOID_ERRORS = [
        'RECEIPT_NOT_FOUND' => 'That receipt does not exist.',
        'ALREADY_CANCELLED' => 'That receipt is already cancelled.',
        'PERIOD_CLOSED'     => 'The receipt falls in a closed financial period and cannot be changed.',
    ];

    /**
     * POST /customer-receipts/{id}/void  Body: { reason? }
     * Reverses the receipt's accounting and restores invoice + balances.
     */
    public function void(int $receiptId): ResponseInterface
    {
        if ($denied = $this->requirePermission('invoices.manage')) {
            return $denied;
        }

        try {
            $result = (new ReversalService())->voidCustomerReceipt(
                $receiptId,
                $this->branchId(),
                (string) ($this->body()['reason'] ?? ''),
                (int) AuthContext::userId(),
                (string) AuthContext::plan(),
            );
        } catch (InvalidArgumentException $exception) {
            $code = $exception->getMessage();

            return $this->respondError($code, self::VOID_ERRORS[$code] ?? 'Cannot void this receipt.', 422);
        }

        return $this->respondData($result);
    }
}
