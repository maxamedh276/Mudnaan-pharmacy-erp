<?php

namespace App\Controllers\Web;

use App\Controllers\BaseController;
use App\Models\CustomerModel;
use App\Libraries\AuthContext;
use App\Services\Web\CustomerStatementService;

class ClientsWebController extends BaseController
{
    public function index(): string
    {
        $this->authorize('customers.view');
        $customerModel = new CustomerModel();
        $res = $customerModel->search(null, 1, max(1, $customerModel->countAllResults()));
        $customers = (new \App\Services\CustomerCreditService())->withStatuses($res['items']);

        $totalCustomers = count($customers);
        $totalReceivable = 0.0;
        $debtorsCount = 0;

        foreach ($customers as $c) {
            $outstanding = (float) $c['outstanding'];
            if ($outstanding > 0) {
                $totalReceivable += $outstanding;
                $debtorsCount++;
            }
        }

        return view('web/clients/index', [
            'title'           => 'Customers — Mudnaan Pharmacy',
            'customers'       => $customers,
            'totalCustomers'    => $totalCustomers,
            'totalReceivable' => $totalReceivable,
            'debtorsCount'    => $debtorsCount,
            'canManageCredit' => \App\Services\CustomerCreditService::canManage(),
            'user'            => session()->get('user') ?? [],
            'tenant'          => session()->get('tenant') ?? [],
            'plan'            => session()->get('plan') ?? 'premium',
        ]);
    }

    public function save(?int $id = null): \CodeIgniter\HTTP\ResponseInterface
    {
        $this->authorize('customers.manage');
        if (!$this->request->isAJAX()) throw new \CodeIgniter\HTTP\Exceptions\HTTPException('Use the customer form to save changes.', 403);
        try {
            $data = $this->request->getJSON(true);
            if (!is_array($data)) throw new \InvalidArgumentException('Enter valid customer details.');
            $customer = (new \App\Services\Web\CustomerDetailsService())->save($data, $id);
            return $this->response->setJSON(['success' => true, 'data' => $customer]);
        } catch (\InvalidArgumentException $error) {
            return $this->response->setStatusCode(422)->setJSON(['success' => false, 'message' => $error->getMessage() === 'CUSTOMER_REQUIRED' ? 'Customer not found.' : $error->getMessage()]);
        }
    }

    /** Cashiers may view credit even when they cannot browse the customer directory. */
    public function credit(int $id): \CodeIgniter\HTTP\ResponseInterface
    {
        if (!AuthContext::can('customers.view') && !AuthContext::can('sales.create')) {
            throw new \CodeIgniter\HTTP\Exceptions\HTTPException('You do not have access to customer credit.', 403);
        }
        try {
            return $this->response->setHeader('Cache-Control', 'no-store')->setJSON(['success' => true, 'data' => (new \App\Services\CustomerCreditService())->status($id)]);
        } catch (\InvalidArgumentException) {
            return $this->response->setStatusCode(404)->setJSON(['success' => false, 'message' => 'Customer not found.']);
        }
    }
    private function authorize(string $permission): void
    {
        if (!AuthContext::can($permission)) throw new \CodeIgniter\HTTP\Exceptions\HTTPException('You do not have access to this customer action.', 403);
    }

    private function branchId(): int
    {
        return (int) (AuthContext::$user->branch_id ?? 1);
    }

    public function statement(int $customerId): string
    {
        $this->authorize('customers.view');
        return view('web/clients/statement', ['title' => 'Customer statement — Mudnaan Pharmacy', 'customerId' => $customerId, 'statement' => (new CustomerStatementService())->get($customerId, $this->branchId(), $this->request->getGet())]);
    }

    public function statementData(int $customerId): \CodeIgniter\HTTP\ResponseInterface
    {
        $this->authorize('customers.view');
        try {
            return $this->response->setJSON(['success' => true, 'data' => (new CustomerStatementService())->get($customerId, $this->branchId(), $this->request->getGet())]);
        } catch (\InvalidArgumentException $exception) {
            return $this->response->setStatusCode(422)->setJSON(['success' => false, 'message' => $exception->getMessage()]);
        }
    }

    public function deposits(): string
    {
        $this->authorize('invoices.view');
        $count = db_connect()->table('customer_deposits')->where('branch_id', $this->branchId())->countAllResults();
        $deposits = (new \App\Services\ReceivablesService())->listDeposits($this->branchId(), [], 1, max(1, $count));
        return view('web/clients/deposits', ['title' => 'Customer Deposits — Mudnaan Pharmacy', 'deposits' => $deposits['items'], 'customers' => (new CustomerModel())->orderBy('name')->findAll(), 'bankAccounts' => db_connect()->table('bank_accounts')->where('type', 'bank')->where('deleted_at', null)->where('is_active', 1)->get()->getResultArray(), 'canManage' => AuthContext::can('invoices.manage')]);
    }
}
