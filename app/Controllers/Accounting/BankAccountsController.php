<?php

namespace App\Controllers\Accounting;

use App\Controllers\BaseApiController;
use App\Libraries\AuthContext;
use App\Services\BankAccountService;
use CodeIgniter\HTTP\ResponseInterface;
use InvalidArgumentException;

class BankAccountsController extends BaseApiController
{
    private const BANK_ERRORS = [
        'NAME_REQUIRED'          => 'The bank account needs a name.',
        'INVALID_AMOUNT'         => 'The opening balance cannot be negative.',
        'BANK_ACCOUNT_NOT_FOUND' => 'That bank account does not exist.',
    ];

    public function index(): ResponseInterface
    {
        if ($denied = $this->requirePermission('banking.view')) {
            return $denied;
        }

        return $this->respondData((new BankAccountService())->list());
    }

    public function create(): ResponseInterface
    {
        if ($denied = $this->requirePermission('banking.manage')) {
            return $denied;
        }

        return $this->handle(fn () => (new BankAccountService())->create(
            $this->body(),
            $this->branchId(),
            (int) AuthContext::userId(),
        ), 201);
    }

    public function update(int $bankAccountId): ResponseInterface
    {
        if ($denied = $this->requirePermission('banking.manage')) {
            return $denied;
        }

        return $this->handle(
            fn () => (new BankAccountService())->update($bankAccountId, $this->body()),
        );
    }

    private function handle(callable $operation, int $successStatus = 200): ResponseInterface
    {
        try {
            return $this->respondData($operation(), $successStatus);
        } catch (InvalidArgumentException $exception) {
            $code = $exception->getMessage();

            if (isset(self::BANK_ERRORS[$code])) {
                return $this->respondError($code, self::BANK_ERRORS[$code], 422);
            }

            throw $exception;
        }
    }
}
