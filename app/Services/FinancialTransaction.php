<?php

namespace App\Services;

use RuntimeException;
use Throwable;

/** Owns a transaction boundary, including failures raised by business/accounting code. */
final class FinancialTransaction
{
    public static function run(callable $operation): mixed
    {
        $database = db_connect();
        if (! $database->transException(true)->transBegin()) {
            throw new RuntimeException('TRANSACTION_FAILED');
        }

        try {
            $result = $operation($database);
            if (! $database->transStatus() || ! $database->transCommit()) {
                throw new RuntimeException('TRANSACTION_FAILED');
            }
            return $result;
        } catch (Throwable $exception) {
            $database->transRollback();
            throw $exception;
        }
    }
}
