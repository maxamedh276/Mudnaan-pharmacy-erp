<?php
namespace App\Services;
class PackageStockException extends \RuntimeException
{
    public function __construct(public readonly array $stock, string $message)
    {
        parent::__construct($message);
    }
}
