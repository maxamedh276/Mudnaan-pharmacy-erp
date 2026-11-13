<?php

namespace App\Services;

use InvalidArgumentException;
use CodeIgniter\Exceptions\HTTPExceptionInterface;

final class CustomerCreditLimitException extends InvalidArgumentException implements HTTPExceptionInterface
{
    public function __construct(public readonly array $creditStatus)
    {
        // Shared API callers can use the framework's 422 fallback without changes
        // to their controllers; a normal credit refusal must never become a 500.
        parent::__construct('CREDIT_LIMIT_EXCEEDED', 422);
    }
}
