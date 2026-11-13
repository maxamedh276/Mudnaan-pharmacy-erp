<?php

namespace App\Controllers\Web;

use App\Controllers\BaseController;
use App\Libraries\AuthContext;
use App\Services\ChartOfAccountsService;
use App\Services\FinancialPeriodService;
use App\Services\PayablesService;
use App\Services\ReceiptCashAccountService;
use App\Services\Web\ChartOfAccountsPresenter;
use App\Services\Web\PosPaymentMethodService;
use Config\Database;

class FinanceWebController extends BaseController
{
    public function index(string $section = 'accounts'): string
    {
        $section = ['accounts' => 'coa', 'banks' => 'pos-methods', 'banking' => 'pos-methods', 'pos_methods' => 'pos-methods'][$section] ?? $section;
        $titles = ['coa' => 'Chart of Accounts', 'journals' => 'Journal Entries', 'pos-methods' => 'POS Payment Methods', 'periods' => 'Financial Periods', 'bills' => 'Supplier Bills', 'payments' => 'Supplier Payments', 'invoices' => 'Customer Invoices', 'receipts' => 'Customer Receipts'];
        if (!isset($titles[$section])) {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }
        $this->authorizeSection($section);
        $db = Database::connect();
        $branchId = (int) (AuthContext::$user->branch_id ?? 1);
        $data = array_fill_keys(['bills', 'supplierPayments', 'suppliers', 'invoices', 'customerReceipts', 'customerDeposits', 'customers', 'accounts', 'accountGroups', 'accountTypes', 'journalEntries', 'bankAccounts', 'financialPeriods', 'posPaymentMethods', 'cashAndBankAccounts', 'moneyAccountKinds', 'settlementAccounts'], []);
        if (in_array($section, ['bills', 'payments', 'invoices', 'receipts'], true)) {
            $data['settlementAccounts'] = (new ReceiptCashAccountService())->settlementAccounts();
        }
        if (in_array($section, ['bills', 'payments'], true)) {
            $data['bills'] = $db->table('supplier_bills b')->select('b.*, s.name AS supplier_name')->join('suppliers s', 's.id = b.supplier_id', 'left')->where('b.branch_id', $branchId)->orderBy('b.id', 'DESC')->get()->getResultArray();
            $data['suppliers'] = $db->table('suppliers')->select('id, name, phone')->where('deleted_at', null)->get()->getResultArray();
            $data['supplierPayments'] = $db->table('supplier_payments p')
                ->select('p.*, s.name AS supplier_name, b.bill_no AS bill_number, a.name AS payment_account_name')
                ->join('suppliers s', 's.id = p.supplier_id', 'left')
                ->join('supplier_bills b', 'b.id = p.bill_id', 'left')
                ->join('accounts a', 'a.id = p.account_id', 'left')
                ->where('p.branch_id', $branchId)->orderBy('p.id', 'DESC')->get()->getResultArray();
        }
        if (in_array($section, ['invoices', 'receipts'], true)) {
            $data['customers'] = $db->table('customers')->select('id, name, phone')->where('deleted_at', null)->get()->getResultArray();
            $data['invoices'] = $db->table('customer_invoices i')->select('i.*, c.name AS customer_name')->join('customers c', 'c.id = i.customer_id', 'left')->where('i.branch_id', $branchId)->where('i.deleted_at', null)->orderBy('i.id', 'DESC')->get()->getResultArray();
            $printerSettings=(new \App\Models\SettingModel())->allAssoc();
            $data['receiptAutoPrintEnabled']=isset($printerSettings['receipt_printers'])?(new \App\Services\ThermalPrintService())->getConfig()['enabled']:(($printerSettings['thermal_printer_enabled']??'0')==='1');
            $data['customerReceipts'] = $db->table('customer_receipts r')->select('r.*, c.name AS customer_name, i.invoice_no AS invoice_number, a.name AS cash_account_name')->join('customers c', 'c.id = r.customer_id', 'left')->join('customer_invoices i', 'i.id = r.invoice_id', 'left')->join('accounts a', 'a.id = r.account_id', 'left')->where('r.branch_id', $branchId)->orderBy('r.id', 'DESC')->get()->getResultArray();
        }
        if ($section === 'coa') {
            $data = array_replace($data, $this->chartOfAccountsData());
        }
        if ($section === 'journals') {
            $data['journalEntries'] = $db->table('journal_entries')->where('branch_id', $branchId)->orderBy('id', 'DESC')->get()->getResultArray();
        }
        if ($section === 'periods') {
            $data['financialPeriods'] = (new FinancialPeriodService())->list(1, 1000)['items'];
        }
        if ($section === 'pos-methods') {
            $data['posPaymentMethods'] = (new \App\Models\PosPaymentMethodModel())->getAllWithAccounts();
            $data['cashAndBankAccounts'] = $db->query("SELECT a.id, a.code, a.name, g.name AS group_name FROM accounts a JOIN account_groups g ON g.id = a.account_group_id JOIN account_types t ON t.id = g.account_type_id WHERE t.code = 'ASSET' AND a.is_postable = 1 AND a.is_active = 1 AND a.deleted_at IS NULL ORDER BY a.code")->getResultArray();
        }
        return view('web/finance/index', $data + [
            'title' => $titles[$section] . ' — Mudnaan Pharmacy', 'pageTitle' => $titles[$section],
            'activeSection' => $section, 'user' => session()->get('user') ?? [],
            'tenant' => session()->get('tenant') ?? [], 'plan' => AuthContext::plan(),
        ]);
    }

    private function authorizeSection(string $section, bool $manage = false): void
    {
        $permission = match ($section) {
            'bills', 'payments' => $manage ? 'bills.manage' : 'bills.view',
            'invoices', 'receipts' => $manage ? 'invoices.manage' : 'invoices.view',
            'periods' => $manage ? 'periods.manage' : 'accounting.view',
            default => $manage ? 'accounting.manage' : 'accounting.view',
        };
        $premium = !in_array($section, ['bills', 'payments', 'invoices', 'receipts'], true);
        if (!AuthContext::can($permission) || ($premium && !AuthContext::isPremium())) {
            throw new \CodeIgniter\HTTP\Exceptions\HTTPException('You do not have access to this financial page.', 403);
        }
    }

    public function receiptInvoice(int $id): \CodeIgniter\HTTP\ResponseInterface
    {
        $this->authorizeSection('receipts');
        $invoice=(new \App\Services\ReceivablesService())->findInvoice($id,(int)AuthContext::$user->branch_id);
        if (!$invoice) return $this->response->setStatusCode(404)->setJSON(['success'=>false,'message'=>'Order not found.']);
        if ($invoice['sale_id']) $invoice['items']=(new \App\Models\SaleModel())->findDetailed((int)$invoice['sale_id'],(int)AuthContext::$user->branch_id)['items'];
        return $this->response->setJSON(['success'=>true,'data'=>$invoice]);
    }

    public function receiptDetails(int $id): \CodeIgniter\HTTP\ResponseInterface
    {
        $this->authorizeSection('receipts');
        $db=db_connect();$receipt=$db->table('customer_receipts')->where('id',$id)->where('branch_id',(int)AuthContext::$user->branch_id)->get()->getRowArray();
        if(!$receipt) return $this->response->setStatusCode(404)->setJSON(['success'=>false,'message'=>'Receipt not found.']);
        $rows=$db->table('customer_receipt_allocations a')->select('a.*,i.invoice_no')->join('customer_invoices i','i.id=a.invoice_id')->where('a.receipt_id',$id)->get()->getResultArray();
        if(!$rows && $receipt['invoice_id']) $rows=[['invoice_no'=>$db->table('customer_invoices')->where('id',$receipt['invoice_id'])->get()->getRow('invoice_no'),'amount'=>$receipt['amount'],'discount'=>$receipt['discount']]];
        if(!$rows)$rows=[['invoice_no'=>'On account','amount'=>$receipt['amount'],'discount'=>$receipt['discount']]];
        return $this->response->setJSON(['success'=>true,'data'=>$rows]);
    }

    public function printCustomerReceipt(int $id): \CodeIgniter\HTTP\ResponseInterface
    {
        $this->authorizeSection('receipts');$result=(new \App\Services\ThermalPrintService())->printCustomerReceipt($id,(int)AuthContext::$user->branch_id);
        return $this->response->setStatusCode($result['success']?200:422)->setJSON(['success'=>$result['success'],'data'=>$result,'message'=>$result['error']??$result['message']]);
    }

    public function saveCustomerReceipt(): \CodeIgniter\HTTP\ResponseInterface
    {
        return $this->mutateCustomerReceipt();
    }

    public function voidCustomerReceipt(int $id): \CodeIgniter\HTTP\ResponseInterface
    {
        return $this->mutateCustomerReceipt($id);
    }

    public function saveSupplierPayment(): \CodeIgniter\HTTP\ResponseInterface
    {
        $this->authorizeSection('payments', true);
        if (!$this->request->isAJAX()) {
            throw new \CodeIgniter\HTTP\Exceptions\HTTPException('Use the supplier payment form to save payments.', 403);
        }
        $body = $this->request->getJSON(true);
        $accountId = filter_var($body['account_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $billId = filter_var($body['bill_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $uuid = $body['uuid'] ?? null;
        $reference = $body['reference'] ?? null;
        if (!$accountId || !$billId || !is_string($uuid) || !preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/iD', $uuid)
            || ($reference !== null && (!is_string($reference) || mb_strlen($reference) > 120))) {
            return $this->response->setStatusCode(422)->setJSON(['success' => false, 'message' => 'Select a bill and payment account, and enter a reference of up to 120 characters.']);
        }
        try {
            $payment = (new PayablesService())->recordPayment(
                ['bill_id' => $billId, 'uuid' => $uuid, 'amount' => $body['amount'] ?? null, 'reference' => $reference],
                (int) AuthContext::$user->branch_id, (int) AuthContext::userId(), (string) AuthContext::plan(), $accountId,
            );
            return $this->response->setJSON(['success' => true, 'data' => $payment]);
        } catch (\InvalidArgumentException $error) {
            $message = match ($error->getMessage()) {
                'INVALID_AMOUNT' => 'Enter a positive payment amount.',
                'BILL_NOT_FOUND', 'BILL_CANCELLED' => 'Select an open bill belonging to this branch.',
                'OVERPAYMENT' => 'The payment exceeds the remaining bill balance.',
                'INVALID_CASH_ACCOUNT' => 'Select an active cash, bank or mobile money account from the chart of accounts.',
                'DUPLICATE_REQUEST' => 'This request ID already belongs to a different payment.',
                'PERIOD_CLOSED' => 'This transaction falls in a closed financial period.',
                default => throw $error,
            };
            return $this->response->setStatusCode(422)->setJSON(['success' => false, 'message' => $message]);
        }
    }

    private function mutateCustomerReceipt(?int $id = null): \CodeIgniter\HTTP\ResponseInterface
    {
        $this->authorizeSection('receipts', true);
        $body = $this->request->getJSON(true) ?? [];
        if (!is_array($body)) return $this->response->setStatusCode(422)->setJSON(['success'=>false,'message'=>'Provide a receipt object.']);
        $branchId = (int) (AuthContext::$user->branch_id ?? 1);
        try {
            // Web collections always settle a specific invoice; advances use deposits.
            if ($id === null && empty($body['invoice_id']) && empty($body['customer_id'])) {
                throw new \InvalidArgumentException('INVOICE_NOT_FOUND');
            }
            if ($id === null && (!is_string($body['uuid']??null)||!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i',$body['uuid']))) {
                throw new \InvalidArgumentException('INVALID_UUID');
            }
            // A saved receipt from the previous form can still be replayed;
            // the service compares every original detail before returning it.
            if ($id === null && empty($body['account_id']) && !db_connect()->table('customer_receipts')->where('uuid', $body['uuid'])->where('branch_id', $branchId)->countAllResults()) {
                throw new \InvalidArgumentException('INVALID_CASH_ACCOUNT');
            }
            $result = $id === null
                ? (empty($body['invoice_id'])
                    ? (new \App\Services\CustomerCollectionService())->record($body, $branchId, (int) AuthContext::userId(), (string) AuthContext::plan())
                    : (new \App\Services\ReceivablesService())->recordReceipt($body, $branchId, (int) AuthContext::userId(), (string) AuthContext::plan()))
                : (new \App\Services\ReversalService())->voidCustomerReceipt($id, $branchId, trim((string) ($body['reason'] ?? '')), (int) AuthContext::userId(), (string) AuthContext::plan());
            return $this->response->setJSON(['success' => true, 'data' => $result]);
        } catch (\InvalidArgumentException | \RuntimeException $exception) {
            $message = match ($exception->getMessage()) {
                'INVALID_RECEIPT_DETAILS' => 'Enter a reference up to 120 characters and notes up to 255 characters.',
                'INVALID_AMOUNT' => 'Enter a positive amount and a valid nonnegative discount.',
                'INVALID_PAYMENT_METHOD' => 'Select a valid payment method.',
                'INVOICE_NOT_FOUND' => 'Select an invoice belonging to this branch.',
                'INVOICE_CANCELLED' => 'This invoice has been cancelled.',
                'CUSTOMER_REQUIRED' => 'The selected customer must match the invoice.',
                'INVALID_CASH_ACCOUNT' => 'Select an active cash, bank or mobile money account from the chart of accounts.',
                'BANK_ACCOUNT_NOT_FOUND' => 'Select an active bank account.',
                'INVALID_ALLOCATION' => 'Allocated amounts must match the collection total and belong to this customer’s outstanding orders.',
                'OVERPAYMENT' => 'The payment plus discount exceeds the remaining invoice balance. Refresh and check the balance.',
                'INVALID_UUID', 'DUPLICATE_REQUEST' => 'This request ID is invalid or already belongs to a different payment. Refresh before starting a new receipt.',
                'RECEIPT_NOT_FOUND' => 'This receipt was not found.',
                'INCONSISTENT_BALANCE' => 'This order’s paid balance needs reconciliation before the receipt can be voided.',
                'ALREADY_CANCELLED' => 'This receipt has already been voided.',
                'PERIOD_CLOSED' => 'This transaction falls in a closed financial period.',
                default => null,
            };
            if ($message === null) {
                throw $exception;
            }
            return $this->response->setStatusCode(422)->setJSON(['success' => false, 'error' => ['message' => $message]]);
        }
    }

    public function newJournal(): string
    {
        $this->authorizeSection('journals', true);
        return view('web/finance/journal_new', [
            'title' => 'New Journal Entry — Mudnaan Pharmacy',
            'accounts' => array_values(array_filter((new ChartOfAccountsService())->list(), static fn ($a) => $a['is_postable'])),
        ]);
    }

    public function savePeriod(): \CodeIgniter\HTTP\ResponseInterface
    {
        return $this->mutatePeriod(null, 'create');
    }

    public function changePeriod(int $id, string $action): \CodeIgniter\HTTP\ResponseInterface
    {
        if (!in_array($action, ['activate', 'close'], true)) {
            return $this->response->setStatusCode(404)->setJSON(['success' => false, 'message' => 'Unknown period action.']);
        }
        return $this->mutatePeriod($id, $action);
    }

    /** Web-only transition: serialize changes and keep at most one open period. */
    private function mutatePeriod(?int $id, string $action): \CodeIgniter\HTTP\ResponseInterface
    {
        $this->authorizeSection('periods', true);
        $db = Database::connect();
        $lock = 'web_period_' . substr(hash('sha256', $db->getDatabase()), 0, 48);
        $locked = (int) $db->query('SELECT GET_LOCK(?, 5) AS acquired', [$lock])->getRow()->acquired === 1;
        if (!$locked) {
            return $this->response->setStatusCode(409)->setJSON(['success' => false, 'message' => 'Another period change is in progress. Please retry.']);
        }
        try {
            $db->transException(true)->transBegin();
            $service = new FinancialPeriodService();
            $userId = (int) AuthContext::userId();
            if ($action !== 'create' && !$db->table('financial_periods')->where('id', $id)->countAllResults()) {
                throw new \InvalidArgumentException('PERIOD_NOT_FOUND');
            }
            if ($action !== 'close') {
                foreach ($db->table('financial_periods')->where('status', 'open')->get()->getResultArray() as $period) {
                    if ((int) $period['id'] !== $id) {
                        $service->close((int) $period['id'], $userId);
                    }
                }
            }
            if ($action === 'create') {
                $body = $this->request->getJSON(true) ?? $this->request->getPost();
                foreach (['start_date', 'end_date'] as $field) {
                    $value = (string) ($body[$field] ?? '');
                    $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
                    if (!$date || $date->format('Y-m-d') !== $value) {
                        throw new \InvalidArgumentException('INVALID_PERIOD');
                    }
                }
                $result = $service->create($body);
            } elseif ($action === 'close') {
                $result = $service->close($id, $userId);
            } else {
                $period = $db->table('financial_periods')->where('id', $id)->get()->getRowArray();
                $result = $period['status'] === 'open' ? $period : $service->reopen($id, $userId);
            }
            if (!$db->transStatus()) {
                throw new \RuntimeException('Could not save the financial period.');
            }
            $db->transCommit();
            return $this->response->setJSON(['success' => true, 'data' => $result]);
        } catch (\InvalidArgumentException $e) {
            $db->transRollback();
            $message = match ($e->getMessage()) {
                'PERIOD_OVERLAP' => 'These dates overlap an existing financial period.',
                'INVALID_PERIOD' => 'Enter a name and a valid start and end date.',
                'PERIOD_NOT_FOUND' => 'The financial period was not found.',
                default => 'The period is already in that state.',
            };
            return $this->response->setStatusCode(422)->setJSON(['success' => false, 'message' => $message]);
        } catch (\Throwable $e) {
            $db->transRollback();
            throw $e;
        } finally {
            $db->query('SELECT RELEASE_LOCK(?)', [$lock]);
        }
    }

    /** The same HTML fragment serves initial rendering and AJAX group navigation. */
    private function chartOfAccountsData(string $selectedType = 'ASSET'): array
    {
        $chartOfAccountsService = new ChartOfAccountsService();
        $accountTypes = $chartOfAccountsService->types();
        $typeCodes = array_column($accountTypes, 'code');
        if (!in_array($selectedType, $typeCodes, true)) {
            $selectedType = $typeCodes[0] ?? 'ASSET';
        }
        $accounts = $chartOfAccountsService->list();
        $accountGroups = $chartOfAccountsService->groups();
        $moneyAccountKinds = array_column(Database::connect()->query(
            'SELECT account_id, CONVERT(type USING utf8mb4) COLLATE utf8mb4_unicode_ci AS type FROM bank_accounts WHERE deleted_at IS NULL UNION SELECT account_id, CONVERT(type USING utf8mb4) COLLATE utf8mb4_unicode_ci AS type FROM pos_payment_methods',
        )->getResultArray(), 'type', 'account_id');

        $categoriesByType = [];
        foreach ($accountTypes as $accountType) {
            $categoriesByType[$accountType['code']] = (new ChartOfAccountsPresenter())->categories($accounts, $accountGroups, $moneyAccountKinds, $accountType['code']);
        }
        return [
            'accountCategoriesByType' => $categoriesByType,
            'accountTypes' => $accountTypes,
            'selectedAccountType' => $selectedType,
            'accountCategories' => $categoriesByType[$selectedType] ?? [],
        ];
    }

    public function accountGroup(string $groupCode = 'ASSET'): \CodeIgniter\HTTP\ResponseInterface
    {
        $this->authorizeSection('coa');
        $chartData = $this->chartOfAccountsData($groupCode);
        return $this->response->setJSON(['success' => true, 'data' => [
            'group' => $chartData['selectedAccountType'],
            'html' => view('web/finance/account_categories', $chartData, ['saveData' => false]),
        ]]);
    }

    public function createAccount(): \CodeIgniter\HTTP\ResponseInterface
    {
        $this->authorizeSection('coa', true);
        try {
            $account = (new \App\Services\Web\AccountCreationService())->create($this->request->getJSON(true) ?? []);
            return $this->response->setJSON(['success' => true, 'data' => $account]);
        } catch (\InvalidArgumentException $exception) {
            $message = match ($exception->getMessage()) {
                'DUPLICATE_NAME' => 'An account with this name already exists.',
                'CODE_AND_NAME_REQUIRED' => 'Enter an account name.',
                default => $exception->getMessage(),
            };
            return $this->response->setStatusCode(422)->setJSON(['success' => false, 'message' => $message]);
        }
    }

    public function paymentMethods(): \CodeIgniter\HTTP\ResponseInterface
    {
        $this->authorizeSection('pos-methods');
        return $this->response->setJSON(['success' => true, 'data' => (new \App\Models\PosPaymentMethodModel())->getAllWithAccounts()]);
    }

    public function savePosPaymentMethod(): \CodeIgniter\HTTP\ResponseInterface
    {
        $this->authorizeSection('pos-methods', true);
        try {
            $paymentMethod = (new PosPaymentMethodService())->save($this->request->getJSON(true) ?? $this->request->getPost());
            return $this->response->setJSON(['success' => true, 'data' => $paymentMethod, 'message' => 'Payment method saved.']);
        } catch (\InvalidArgumentException $exception) {
            return $this->paymentMethodValidationError($exception->getMessage());
        }
    }

    public function reorderPaymentMethods(): \CodeIgniter\HTTP\ResponseInterface
    {
        $this->authorizeSection('pos-methods', true);
        $requestBody = $this->request->getJSON(true) ?? [];
        if (!is_array($requestBody['ordered_ids'] ?? null)) {
            return $this->paymentMethodValidationError('Provide the complete payment-method order.');
        }
        try {
            (new PosPaymentMethodService())->reorder($requestBody['ordered_ids']);
            return $this->response->setJSON(['success' => true, 'message' => 'Payment order saved.']);
        } catch (\InvalidArgumentException $exception) {
            return $this->paymentMethodValidationError($exception->getMessage());
        }
    }

    private function paymentMethodValidationError(string $message): \CodeIgniter\HTTP\ResponseInterface
    {
        return $this->response->setStatusCode(422)->setJSON(['success' => false, 'message' => $message]);
    }

    public function togglePosPaymentMethod(int $id): \CodeIgniter\HTTP\ResponseInterface
    {
        $this->authorizeSection('pos-methods', true);
        $model = new \App\Models\PosPaymentMethodModel();
        $method = $model->find($id);
        if (!$method) {
            return $this->response->setJSON(['success' => false, 'message' => 'Method not found'])->setStatusCode(404);
        }

        $newStatus = (int)$method['is_active'] === 1 ? 0 : 1;
        $model->update($id, ['is_active' => $newStatus]);

        return $this->response->setJSON([
            'success'   => true,
            'is_active' => $newStatus,
            'message'   => $newStatus ? 'Payment method enabled for POS terminal' : 'Payment method hidden from POS terminal'
        ]);
    }

    public function deletePosPaymentMethod(int $id): \CodeIgniter\HTTP\ResponseInterface
    {
        $this->authorizeSection('pos-methods', true);
        $model = new \App\Models\PosPaymentMethodModel();
        $model->delete($id);
        return $this->response->setJSON(['success' => true, 'message' => 'Payment method removed']);
    }
}
