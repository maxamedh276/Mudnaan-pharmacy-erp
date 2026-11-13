<?php

namespace App\Services;

use CodeIgniter\Exceptions\HTTPExceptionInterface;
use InvalidArgumentException;

final class ProductIdentifierException extends InvalidArgumentException implements HTTPExceptionInterface
{
    public function __construct(string $message)
    {
        parent::__construct($message, 422);
    }
}
