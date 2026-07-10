<?php

namespace App\Controllers\Reports;

use App\Controllers\BaseApiController;
use App\Services\ReportService;
use CodeIgniter\HTTP\ResponseInterface;

class ReportsController extends BaseApiController
{
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
}
