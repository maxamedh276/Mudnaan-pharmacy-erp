<?php

namespace App\Controllers\Accounting;

use App\Controllers\BaseApiController;
use App\Libraries\AuthContext;
use App\Services\FinancialPeriodService;
use CodeIgniter\HTTP\ResponseInterface;
use InvalidArgumentException;

class FinancialPeriodsController extends BaseApiController
{
    private const PERIOD_ERRORS = [
        'INVALID_PERIOD'        => 'The period needs a name and a valid date range.',
        'PERIOD_OVERLAP'        => 'That date range overlaps an existing period.',
        'PERIOD_NOT_FOUND'      => 'That period does not exist.',
        'PERIOD_ALREADY_CLOSED' => 'That period is already closed.',
        'PERIOD_ALREADY_OPEN'   => 'That period is already open.',
    ];

    public function index(): ResponseInterface
    {
        if ($denied = $this->requirePermission('accounting.view')) {
            return $denied;
        }

        ['page' => $page, 'perPage' => $perPage] = $this->paginationParams();

        $result = (new FinancialPeriodService())->list($page, $perPage);

        return $this->respondData(
            $result['items'],
            200,
            $this->paginationMeta($page, $perPage, $result['total']),
        );
    }

    public function create(): ResponseInterface
    {
        if ($denied = $this->requirePermission('periods.manage')) {
            return $denied;
        }

        return $this->handle(fn () => (new FinancialPeriodService())->create($this->body()), 201);
    }

    public function close(int $periodId): ResponseInterface
    {
        if ($denied = $this->requirePermission('periods.manage')) {
            return $denied;
        }

        return $this->handle(
            fn () => (new FinancialPeriodService())->close($periodId, (int) AuthContext::userId()),
        );
    }

    public function reopen(int $periodId): ResponseInterface
    {
        if ($denied = $this->requirePermission('periods.manage')) {
            return $denied;
        }

        return $this->handle(
            fn () => (new FinancialPeriodService())->reopen($periodId, (int) AuthContext::userId()),
        );
    }

    private function handle(callable $operation, int $successStatus = 200): ResponseInterface
    {
        try {
            return $this->respondData($operation(), $successStatus);
        } catch (InvalidArgumentException $exception) {
            $code = $exception->getMessage();

            if (isset(self::PERIOD_ERRORS[$code])) {
                return $this->respondError($code, self::PERIOD_ERRORS[$code], 422);
            }

            throw $exception;
        }
    }
}
