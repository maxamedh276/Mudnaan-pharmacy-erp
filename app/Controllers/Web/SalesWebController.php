<?php

namespace App\Controllers\Web;

use App\Controllers\BaseController;
use App\Models\SaleModel;
use Config\Database;

class SalesWebController extends BaseController
{
    private function branchId(): int
    {
        $user = session()->get('user');
        return (int) ($user['branch_id'] ?? 1);
    }

    public function index(): string
    {
        if (!\App\Libraries\AuthContext::can('sales.view')) throw new \CodeIgniter\HTTP\Exceptions\HTTPException('You do not have access to this page.',403);
        $branchId = $this->branchId();
        $saleModel = new SaleModel();
        $res = $saleModel->listSales($branchId, [], 1, 1000);
        $sales = $res['items'];

        $totalRevenue = 0.0;
        $totalPaid = 0.0;
        $unpaidCredit = 0.0;

        foreach ($sales as $s) {
            $grand = (float) $s['net_total'];
            $paid = (float) $s['paid_total'];
            $totalRevenue += $grand;
            $totalPaid += $paid;
            if ($paid < $grand) {
                $unpaidCredit += ($grand - $paid);
            }
        }

        return view('web/sales/index', [
            'title'        => 'Sales Management & Receipts — Mudnaan Pharmacy',
            'sales'        => $sales,
            'totalRevenue' => $totalRevenue,
            'totalPaid'    => $totalPaid,
            'unpaidCredit' => $unpaidCredit,
            'user'         => session()->get('user') ?? [],
            'tenant'       => session()->get('tenant') ?? [],
            'plan'         => session()->get('plan') ?? 'premium',
        ]);
    }
}
