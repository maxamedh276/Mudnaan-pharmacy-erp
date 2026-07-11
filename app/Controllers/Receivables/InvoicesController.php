<?php

namespace App\Controllers\Receivables;

use App\Controllers\BaseApiController;
use App\Libraries\AuthContext;
use App\Services\ReceivablesService;
use CodeIgniter\HTTP\ResponseInterface;
use InvalidArgumentException;
use RuntimeException;

class InvoicesController extends BaseApiController
{
    private const INVOICE_ERRORS = [
        'CUSTOMER_REQUIRED'    => 'A customer is required.',
        'EMPTY_INVOICE'        => 'The invoice has no lines.',
        'INVALID_INVOICE_ITEM' => 'An invoice line is missing its description, quantity or price.',
        'PRODUCT_NOT_FOUND'    => 'One of the products no longer exists.',
        'INSUFFICIENT_STOCK'   => 'Not enough stock for one of the items.',
        'PERIOD_CLOSED'        => 'That date falls in a closed financial period.',
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
            'q'           => $this->request->getGet('q'),
        ];

        $result = (new ReceivablesService())->listInvoices($this->branchId(), $filters, $page, $perPage);

        return $this->respondData(
            $result['items'],
            200,
            $this->paginationMeta($page, $perPage, $result['total']),
        );
    }

    public function show(int $invoiceId): ResponseInterface
    {
        if ($denied = $this->requirePermission('invoices.view')) {
            return $denied;
        }

        $invoice = (new ReceivablesService())->findInvoice($invoiceId, $this->branchId());

        return $invoice === null
            ? $this->respondNotFound('Invoice')
            : $this->respondData($invoice);
    }

    public function create(): ResponseInterface
    {
        if ($denied = $this->requirePermission('invoices.manage')) {
            return $denied;
        }

        try {
            $invoice = (new ReceivablesService())->createInvoice(
                $this->body(),
                $this->branchId(),
                (int) AuthContext::userId(),
                (string) AuthContext::plan(),
            );
        } catch (InvalidArgumentException | RuntimeException $exception) {
            $code = $exception->getMessage();

            if (isset(self::INVOICE_ERRORS[$code])) {
                return $this->respondError($code, self::INVOICE_ERRORS[$code], 422);
            }

            throw $exception;
        }

        return $this->respondData($invoice, 201);
    }
}
