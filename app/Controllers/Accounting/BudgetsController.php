<?php

namespace App\Controllers\Accounting;

use App\Controllers\BaseApiController;
use App\Libraries\AuthContext;
use App\Services\BudgetService;
use CodeIgniter\HTTP\ResponseInterface;
use InvalidArgumentException;

class BudgetsController extends BaseApiController
{
    private const BUDGET_ERRORS = [
        'INVALID_BUDGET'      => 'The budget needs a name and a valid date range.',
        'EMPTY_BUDGET'        => 'The budget has no lines.',
        'INVALID_BUDGET_LINE' => 'A budget line is missing its account or amount, or repeats an account.',
        'ACCOUNT_NOT_FOUND'   => 'One of the accounts does not exist.',
        'BUDGET_NOT_FOUND'    => 'That budget does not exist.',
    ];

    public function index(): ResponseInterface
    {
        if ($denied = $this->requirePermission('budgets.view')) {
            return $denied;
        }

        ['page' => $page, 'perPage' => $perPage] = $this->paginationParams();

        $result = (new BudgetService())->list($this->branchId(), $page, $perPage);

        return $this->respondData(
            $result['items'],
            200,
            $this->paginationMeta($page, $perPage, $result['total']),
        );
    }

    /**
     * GET /budgets/{id} — the full Budget vs Actual report.
     */
    public function show(int $budgetId): ResponseInterface
    {
        if ($denied = $this->requirePermission('budgets.view')) {
            return $denied;
        }

        return $this->handle(fn () => (new BudgetService())->report($budgetId, $this->branchId()));
    }

    public function create(): ResponseInterface
    {
        if ($denied = $this->requirePermission('budgets.manage')) {
            return $denied;
        }

        return $this->handle(fn () => (new BudgetService())->create(
            $this->body(),
            $this->branchId(),
            (int) AuthContext::userId(),
        ), 201);
    }

    private function handle(callable $operation, int $successStatus = 200): ResponseInterface
    {
        try {
            return $this->respondData($operation(), $successStatus);
        } catch (InvalidArgumentException $exception) {
            $code = $exception->getMessage();

            if (isset(self::BUDGET_ERRORS[$code])) {
                return $this->respondError($code, self::BUDGET_ERRORS[$code], 422);
            }

            throw $exception;
        }
    }
}
