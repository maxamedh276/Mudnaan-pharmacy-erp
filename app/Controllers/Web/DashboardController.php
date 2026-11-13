<?php
namespace App\Controllers\Web;

use App\Controllers\BaseController;
use App\Libraries\AuthContext;
use App\Models\SaleModel;
use App\Services\MenuPermissionService;
use App\Services\RoleDashboardService;
use CodeIgniter\HTTP\Exceptions\HTTPException;
use CodeIgniter\HTTP\ResponseInterface;
use InvalidArgumentException;

class DashboardController extends BaseController
{
    private function authorize(): void
    {
        $user = AuthContext::$user;
        // Menu access permits the dashboard shell. Each card still checks its own
        // action permissions, so newly created roles get setup guidance, not a 403.
        if (!$user) throw new HTTPException('You do not have access to this dashboard.', 403);
        $menus = (new MenuPermissionService())->getUserMenuKeys((int)$user->id, (int)($user->role_id ?? 0));
        if (!in_array('dashboard', $menus, true)) throw new HTTPException('You do not have access to this dashboard.', 403);
    }

    public function index(): string
    {
        $this->authorize();
        $service = new RoleDashboardService();
        $notice = '';
        try { $dashboard = $service->get($this->request->getGet('range')); }
        catch (InvalidArgumentException $error) { $notice = $error->getMessage(); $dashboard = $service->get(); }
        return view('web/dashboard', ['title' => ucfirst($dashboard['profile']) . ' Dashboard', 'dashboard' => $dashboard, 'compactDashboard' => true, 'dashboardNotice' => $notice]);
    }

    public function data(): ResponseInterface
    {
        $this->authorize();
        try {
            $dashboard = (new RoleDashboardService())->get($this->request->getGet('range'));
            return $this->response->setJSON(['success' => true, 'data' => ['html' => view('web/dashboard/panels', ['dashboard' => $dashboard]), 'profile' => $dashboard['profile'], 'range' => $dashboard['range'], 'from' => $dashboard['from'], 'to' => $dashboard['to']]]);
        } catch (InvalidArgumentException $error) {
            return $this->response->setStatusCode(422)->setJSON(['success' => false, 'message' => $error->getMessage()]);
        } catch (\Throwable $error) {
            log_message('error', 'Dashboard refresh failed: ' . $error->getMessage());
            return $this->response->setStatusCode(503)->setJSON(['success' => false, 'message' => 'The dashboard could not be refreshed. Please try again.']);
        }
    }

    public function order(int $id): ResponseInterface
    {
        $this->authorize();
        $profile = (new RoleDashboardService())->profile();
        if ($profile === 'accountant' || (!AuthContext::can('sales.view') && !AuthContext::can('sales.create'))) throw new HTTPException('You do not have access to order previews.', 403);
        $branch = (int)(AuthContext::$user->branch_id ?? 0);
        $query = db_connect()->table('sales')->where('id', $id)->where('branch_id', $branch);
        if ($profile === 'cashier') $query->where('user_id', (int)AuthContext::userId());
        if (!$branch || !$query->countAllResults()) return $this->response->setStatusCode(404)->setJSON(['success' => false, 'message' => 'Order not found.']);
        $sale = (new SaleModel())->findDetailed($id, $branch);
        $data = ['reference' => $sale['invoice_no'] ?: '#' . $id, 'customer' => $sale['customer_name'] ?: 'Walk-in customer', 'date' => $sale['sold_at'], 'original_total' => $sale['grand_total'], 'returned' => $sale['returned_total'], 'refunds' => (float)$sale['refunded_total'], 'net_total' => $sale['net_total'], 'settled' => $sale['paid_total'], 'due' => max(0, round($sale['net_total'] - $sale['paid_total'], 2)), 'items' => array_map(static fn($item) => ['description' => $item['description'], 'quantity' => $item['quantity'], 'unit_price' => $item['unit_price'], 'total' => $item['line_total']], $sale['items'])];
        return $this->response->setJSON(['success' => true, 'data' => $data]);
    }
}
