<?php

namespace App\Controllers\Reports;

use App\Controllers\BaseApiController;
use App\Services\FinancialReportService;
use App\Services\ReportService;
use CodeIgniter\HTTP\ResponseInterface;

class ReportsController extends BaseApiController
{
    /** @return array{0:string,1:string} [from, to] */
    private function range(): array
    {
        return [
            $this->request->getGet('date_from') ?: date('Y-m-01'),
            $this->request->getGet('date_to') ?: date('Y-m-d'),
        ];
    }

    private function asOf(): string
    {
        return $this->request->getGet('date_to') ?: date('Y-m-d');
    }

    /**
     * GET /api/v1/reports/dashboard — the home screen numbers (all plans).
     */
    public function dashboard(): ResponseInterface
    {
        if ($denied = $this->requirePermission('reports.basic')) {
            return $denied;
        }

        return $this->respondData((new ReportService())->dashboard($this->branchId()));
    }

    /**
     * GET /api/v1/reports/sales?date_from=&date_to= — Premium.
     */
    public function sales(): ResponseInterface
    {
        if ($denied = $this->requirePermission('reports.advanced')) {
            return $denied;
        }

        $dateFrom = $this->request->getGet('date_from') ?: date('Y-m-01');
        $dateTo   = $this->request->getGet('date_to') ?: date('Y-m-d');

        return $this->respondData(
            (new ReportService())->salesReport($this->branchId(), $dateFrom, $dateTo),
        );
    }

    /**
     * GET /api/v1/reports/inventory — Premium.
     */
    public function inventory(): ResponseInterface
    {
        if ($denied = $this->requirePermission('reports.advanced')) {
            return $denied;
        }

        return $this->respondData((new ReportService())->inventoryReport($this->branchId()));
    }

    // ---- FINANCIAL STATEMENTS (Premium) ----------------------------

    public function trialBalance(): ResponseInterface
    {
        if ($denied = $this->requirePermission('reports.advanced')) {
            return $denied;
        }

        return $this->respondData((new FinancialReportService())->trialBalance($this->asOf()));
    }

    public function incomeStatement(): ResponseInterface
    {
        if ($denied = $this->requirePermission('reports.advanced')) {
            return $denied;
        }

        [$from, $to] = $this->range();

        return $this->respondData((new FinancialReportService())->incomeStatement($from, $to));
    }

    public function balanceSheet(): ResponseInterface
    {
        if ($denied = $this->requirePermission('reports.advanced')) {
            return $denied;
        }

        return $this->respondData((new FinancialReportService())->balanceSheet($this->asOf()));
    }

    public function cashFlow(): ResponseInterface
    {
        if ($denied = $this->requirePermission('reports.advanced')) {
            return $denied;
        }

        [$from, $to] = $this->range();

        return $this->respondData((new FinancialReportService())->cashFlow($from, $to));
    }

    public function generalLedger(): ResponseInterface
    {
        if ($denied = $this->requirePermission('reports.advanced')) {
            return $denied;
        }

        [$from, $to] = $this->range();

        return $this->respondData((new FinancialReportService())->generalLedger($from, $to));
    }

    public function accountLedger(int $accountId): ResponseInterface
    {
        if ($denied = $this->requirePermission('reports.advanced')) {
            return $denied;
        }

        [$from, $to] = $this->range();
        $ledger = (new FinancialReportService())->accountLedger($accountId, $from, $to);

        return $ledger === []
            ? $this->respondNotFound('Account')
            : $this->respondData($ledger);
    }

    // ---- SALES REPORTS (Premium) -----------------------------------

    public function salesByCustomer(): ResponseInterface
    {
        if ($denied = $this->requirePermission('reports.advanced')) {
            return $denied;
        }

        [$from, $to] = $this->range();

        return $this->respondData((new ReportService())->salesByCustomer($this->branchId(), $from, $to));
    }

    public function salesByProduct(): ResponseInterface
    {
        if ($denied = $this->requirePermission('reports.advanced')) {
            return $denied;
        }

        [$from, $to] = $this->range();

        return $this->respondData((new ReportService())->salesByProduct($this->branchId(), $from, $to));
    }

    public function salesByCategory(): ResponseInterface
    {
        if ($denied = $this->requirePermission('reports.advanced')) {
            return $denied;
        }

        [$from, $to] = $this->range();

        return $this->respondData((new ReportService())->salesByCategory($this->branchId(), $from, $to));
    }

    public function mostSold(): ResponseInterface
    {
        if ($denied = $this->requirePermission('reports.advanced')) {
            return $denied;
        }

        [$from, $to] = $this->range();

        return $this->respondData((new ReportService())->salesByProduct($this->branchId(), $from, $to, 'DESC'));
    }

    public function leastSold(): ResponseInterface
    {
        if ($denied = $this->requirePermission('reports.advanced')) {
            return $denied;
        }

        [$from, $to] = $this->range();

        return $this->respondData((new ReportService())->salesByProduct($this->branchId(), $from, $to, 'ASC'));
    }

    // ---- INVENTORY REPORTS (Premium) -------------------------------

    public function stockValuation(): ResponseInterface
    {
        if ($denied = $this->requirePermission('reports.advanced')) {
            return $denied;
        }

        return $this->respondData((new ReportService())->stockValuation($this->branchId()));
    }

    public function stockMovement(): ResponseInterface
    {
        if ($denied = $this->requirePermission('reports.advanced')) {
            return $denied;
        }

        [$from, $to] = $this->range();

        return $this->respondData((new ReportService())->stockMovement($this->branchId(), $from, $to));
    }

    // ---- CUSTOMER / SUPPLIER REPORTS (Premium) ---------------------

    public function customerBalances(): ResponseInterface
    {
        if ($denied = $this->requirePermission('reports.advanced')) {
            return $denied;
        }

        return $this->respondData((new ReportService())->customerBalances());
    }

    public function customerAging(): ResponseInterface
    {
        if ($denied = $this->requirePermission('reports.advanced')) {
            return $denied;
        }

        return $this->respondData((new ReportService())->customerAging($this->branchId()));
    }

    public function topCustomers(): ResponseInterface
    {
        if ($denied = $this->requirePermission('reports.advanced')) {
            return $denied;
        }

        [$from, $to] = $this->range();

        return $this->respondData((new ReportService())->topCustomers($this->branchId(), $from, $to));
    }

    public function supplierBalances(): ResponseInterface
    {
        if ($denied = $this->requirePermission('reports.advanced')) {
            return $denied;
        }

        return $this->respondData((new ReportService())->supplierBalances($this->branchId()));
    }

    public function supplierAging(): ResponseInterface
    {
        if ($denied = $this->requirePermission('reports.advanced')) {
            return $denied;
        }

        return $this->respondData((new ReportService())->supplierAging($this->branchId()));
    }

    public function topSuppliers(): ResponseInterface
    {
        if ($denied = $this->requirePermission('reports.advanced')) {
            return $denied;
        }

        [$from, $to] = $this->range();

        return $this->respondData((new ReportService())->topSuppliers($this->branchId(), $from, $to));
    }
}
