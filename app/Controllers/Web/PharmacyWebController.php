<?php

namespace App\Controllers\Web;

use App\Controllers\BaseController;
use App\Libraries\AuthContext;
use App\Services\Pharmacy\PharmacyService;
use App\Services\Pharmacy\PharmacyHttpException as HTTPException;
use InvalidArgumentException;

final class PharmacyWebController extends BaseController
{
    private function authorize(string $permission): void
    {
        if (!AuthContext::can($permission)) throw new HTTPException('You do not have access to this pharmacy action.', 403);
    }

    private function branch(): int
    {
        $id = (int) (AuthContext::$user->branch_id ?? 0);
        if ($id < 1) throw new HTTPException('Select a branch before using the pharmacy workspace.', 403);
        return $id;
    }

    public function index(string $section = 'overview'): string
    {
        if ($section === 'storage') {
            $this->authorize('pharmacy.view');
            return view('web/pharmacy/storage', (new \App\Services\Pharmacy\StorageMonitoringService())->data($this->branch()) + ['title'=>'Storage & Cold Chain']);
        }
        if ($section === 'opened-packs') {
            $this->authorize('pharmacy.view');
            return view('web/pharmacy/opened_packs',(new \App\Services\Pharmacy\OpenedPackService())->data($this->branch())+['title'=>'Opened Packs & Use-by']);
        }
        if ($section === 'imports') {
            $this->authorize('pharmacy.medicines.manage');
            return view('web/pharmacy/imports',['title'=>'Medicine Import']);
        }
        if ($section === 'cash-shifts') {
            $this->authorize('sales.create');
            $manager=\App\Libraries\AdministrativeAccess::isFixedRole(AuthContext::$user->role_name??'');
            return view('web/pharmacy/cash_shifts',(new \App\Services\Pharmacy\CashShiftService())->data($this->branch(),(int)AuthContext::userId(),$manager)+['title'=>'Cash Shifts','accounting'=>AuthContext::plan()==='premium']);
        }
        if ($section === 'counts') {
            $this->authorize('pharmacy.view');
            return view('web/pharmacy/counts',['title'=>'Physical Stock Counts','counts'=>(new \App\Services\Pharmacy\StockCountService())->listing($this->branch()),'medicines'=>(new PharmacyService())->medicines()]);
        }
        if (in_array($section, ['overview', 'stock', 'dispense', 'reports'], true)) return $this->operations($section);
        $permission = match ($section) {
            'medicines' => 'pharmacy.view',
            'patients' => 'pharmacy.patients.view',
            'prescriptions' => 'pharmacy.prescriptions.view',
            default => throw new HTTPException('Page not found.', 404),
        };
        $this->authorize($permission);
        $service = new PharmacyService();
        $ready = $service->ready();
        $search = $this->request->getGet('q');
        $search = is_string($search) ? mb_substr(trim($search), 0, 180) : '';
        $rows = [];
        $products = $patients = $medicines = $categories = [];
        if ($ready) {
            $branch = $this->branch();
            $rows = match ($section) {
                'medicines' => $service->medicines($search),
                'patients' => $service->patients($branch, $search),
                'prescriptions' => $service->prescriptions($branch),
            };
            if ($section === 'medicines' && AuthContext::can('pharmacy.medicines.manage')) {
                $products = db_connect()->table('products')->select('id, name, unit')->where('deleted_at', null)->where('is_active', 1)->orderBy('name')->limit(1000)->get()->getResultArray();
                $categories = db_connect()->table('categories')->select('id,name')->where('deleted_at', null)->where('is_active', 1)->orderBy('name')->get()->getResultArray();
            }
            if ($section === 'prescriptions' && AuthContext::can('pharmacy.prescriptions.manage') && AuthContext::can('pharmacy.patients.view')) {
                $patients = $service->patients($branch);
                $medicines = $service->medicines();
            }
        }
        return view('web/pharmacy/workspace', compact('section', 'ready', 'rows', 'products', 'patients', 'medicines', 'categories', 'search') + ['title' => lang('Pharmacy.title')]);
    }

    public function save(string $section)
    {
        $permission = match ($section) {
            'medicines' => 'pharmacy.medicines.manage',
            'patients' => 'pharmacy.patients.manage',
            'prescriptions' => 'pharmacy.prescriptions.manage',
            'stock', 'batch-status' => 'pharmacy.stock.manage',
            'approve' => 'pharmacy.prescriptions.approve',
            'checkout' => 'sales.create',
            'definitions' => 'pharmacy.medicines.manage',
            'hold', 'discard-hold' => 'sales.create',
            'care-note' => 'pharmacy.patients.manage',
            'storage-location', 'temperature' => 'pharmacy.stock.manage',
            'open-pack' => 'pharmacy.stock.manage',
            'import-preview', 'import-commit' => 'pharmacy.medicines.manage',
            'cash-shift-open', 'cash-shift-close' => 'sales.create',
            'count-start', 'count-save', 'count-cancel' => 'pharmacy.stock.manage',
            'count-approve' => 'pharmacy.stock.approve',
            default => throw new HTTPException('Page not found.', 404),
        };
        $this->authorize($permission);
        // Double-submit token plus same-origin policy for the new JSON write routes.
        $expected = session()->get('pharmacy_csrf');
        $supplied = $this->request->getHeaderLine('X-Pharmacy-CSRF');
        if (!is_string($expected) || !hash_equals($expected, $supplied)) throw new HTTPException('Invalid request token. Reload the page.', 403);
        $service = new PharmacyService();
        if (!$service->ready()) return $this->response->setStatusCode(503)->setJSON(['success' => false, 'message' => lang('Pharmacy.setupRequired')]);
        try {
            $input = $this->request->getJSON(true);
            if (!is_array($input)) throw new InvalidArgumentException('Invalid request.');
            $branch = $this->branch();
            $user = (int) AuthContext::userId();
            if ($section === 'import-preview') {
                $csv=\App\Services\Pharmacy\PharmacyInput::text($input,'csv',2*1024*1024,true);
                return $this->response->setJSON(['success'=>true,'data'=>(new \App\Services\Pharmacy\PharmacyImportService())->preview($csv,$branch)]);
            }
            $id = match ($section) {
                'medicines' => $service->saveMedicine($input),
                'patients' => $service->savePatient($input, $branch, $user),
                'prescriptions' => $service->recordPrescription($input, $branch, $user),
                'stock' => (new \App\Services\Pharmacy\PharmacyOperationsService())->stock($input, $branch, $user, AuthContext::plan() ?? 'basic'),
                'batch-status' => (new \App\Services\Pharmacy\PharmacyOperationsService())->changeStatus($input, $branch, $user),
                'approve' => (new \App\Services\Pharmacy\PharmacyOperationsService())->approve($input, $branch),
                'checkout' => $this->checkout($input, $branch, $user),
                'definitions' => (new \App\Services\Pharmacy\PharmacyDefinitionService())->save($input, $user),
                'hold' => (new \App\Services\Pharmacy\HeldSaleService())->save($input, $branch, $user),
                'discard-hold' => (new \App\Services\Pharmacy\HeldSaleService())->discard($input, $branch, $user),
                'care-note' => (new \App\Services\Pharmacy\PatientCareService())->addNote($input, $branch, $user),
                'storage-location' => (new \App\Services\Pharmacy\StorageMonitoringService())->location($input, $branch, $user),
                'temperature' => (new \App\Services\Pharmacy\StorageMonitoringService())->reading($input, $branch, $user),
                'open-pack' => (new \App\Services\Pharmacy\OpenedPackService())->open($input, $branch, $user),
                'import-commit' => (new \App\Services\Pharmacy\PharmacyImportService())->commit(\App\Services\Pharmacy\PharmacyInput::text($input,'csv',2*1024*1024,true),$branch,$user,AuthContext::plan()??'basic'),
                'cash-shift-open' => (new \App\Services\Pharmacy\CashShiftService())->open($input,$branch,$user),
                'cash-shift-close' => (new \App\Services\Pharmacy\CashShiftService())->close($input,$branch,$user),
                'count-start' => (new \App\Services\Pharmacy\StockCountService())->start($input,$branch,$user),
                'count-save' => (new \App\Services\Pharmacy\StockCountService())->save($input,$branch,$user),
                'count-approve', 'count-cancel' => (new \App\Services\Pharmacy\StockCountService())->finish($input,$branch,$user,AuthContext::plan()??'basic',$section==='count-approve'),
            };
            return $this->response->setJSON(['success' => true, 'id' => $id, 'message' => lang('Pharmacy.saved')]);
        } catch (InvalidArgumentException $error) {
            $message=match($error->getMessage()) {
                'CREDIT_REQUIRES_CUSTOMER'=>lang('Pharmacy.checkoutCustomerRequired'),
                'CREDIT_LIMIT_EXCEEDED'=>lang('Pharmacy.checkoutCreditLimit'),
                'INVALID_PAYMENT_METHOD'=>lang('Pharmacy.checkoutPaymentInvalid'),
                'PRODUCT_NOT_FOUND'=>lang('Pharmacy.checkoutMedicineUnavailable'),
                'INVALID_SALE_ITEM'=>lang('Pharmacy.checkoutLineInvalid'),
                default=>$error->getMessage(),
            };
            return $this->response->setStatusCode(422)->setJSON(['success' => false, 'message' => $message]);
        } catch (\RuntimeException $error) {
            $message = match ($error->getMessage()) {
                'INSUFFICIENT_STOCK' => lang('Pharmacy.insufficientStock'),
                'PERIOD_CLOSED' => lang('Pharmacy.periodClosed'),
                default => null,
            };
            if ($message === null) throw $error;
            return $this->response->setStatusCode(409)->setJSON(['success' => false, 'message' => $message]);
        }
    }

    private function checkout(array $input, int $branch, int $user): int
    {
        if (empty($input['uuid']) || !is_string($input['uuid']) || !preg_match('/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/iD', $input['uuid'])) throw new InvalidArgumentException('A checkout request identifier is required.');
        if (!is_array($input['items'] ?? null) || !$input['items'] || count($input['items']) > 100) throw new InvalidArgumentException('Enter 1 to 100 medicine lines.');
        $clean = [];
        foreach ($input['items'] as $line) {
            if (!is_array($line)) throw new InvalidArgumentException('Invalid medicine line.');
            $product = \App\Services\Pharmacy\PharmacyInput::id($line['product_id'] ?? null);
            $unit = (new \App\Services\Pharmacy\MedicineUnitService())->resolve($product, $line['medicine_unit_id'] ?? null);
            $clean[] = ['product_id' => $product, 'medicine_unit_id' => (int)$unit['id'], 'quantity' => $line['quantity'] ?? null] + (new \App\Services\Pharmacy\PharmacyDiscountService())->validate($line,$unit);
        }
        $input['items'] = $clean;
        $input['discount'] = $input['shipping'] = 0;
        unset($input['sold_at']);
        $input['payments'] = (new \App\Services\Pharmacy\PharmacyPaymentService())->resolve($input);
        $sale = (new \App\Services\SalesService())->createSale($input, $branch, $user, AuthContext::plan() ?? 'basic');
        return (int)$sale['id'];
    }

    public function heldSales()
    {
        $this->authorize('sales.create');
        return $this->response->setHeader('Cache-Control','private, no-store')->setJSON(['success'=>true,'data'=>(new \App\Services\Pharmacy\HeldSaleService())->listing($this->branch(), (int)AuthContext::userId())]);
    }

    public function storageData()
    {
        $this->authorize('pharmacy.view');
        return $this->response->setHeader('Cache-Control','private, no-store')->setJSON(['success'=>true,'data'=>(new \App\Services\Pharmacy\StorageMonitoringService())->data($this->branch())]);
    }

    public function importTemplate()
    {
        $this->authorize('pharmacy.medicines.manage');
        $categories=(new \App\Services\Web\InventoryCatalogService())->categories();$category='';
        foreach ($categories as $item) if ($item['selectable']) {$category=$item['path'];break;}
        $stream=fopen('php://temp','w+');
        fputcsv($stream,\App\Services\Pharmacy\PharmacyImportService::HEADERS,',','"','');
        fputcsv($stream,['Example medicine',$category,'Example ingredient','100 mg','Tablet','tablet','tablet=1@1;strip=10@8;box=100@70','','','','0','10','0','','',''],',','"','');
        rewind($stream);$csv=stream_get_contents($stream);fclose($stream);
        return $this->response->setHeader('Content-Type','text/csv; charset=UTF-8')->setHeader('Content-Disposition','attachment; filename="pharmacy-medicines-template.csv"')->setBody($csv);
    }

    public function countDetail(int $id)
    {
        $this->authorize('pharmacy.view');
        try { return $this->response->setHeader('Cache-Control','private, no-store')->setJSON(['success'=>true,'data'=>(new \App\Services\Pharmacy\StockCountService())->detail($id,$this->branch())]); }
        catch (InvalidArgumentException $error) { return $this->response->setStatusCode(404)->setJSON(['success'=>false,'message'=>$error->getMessage()]); }
    }

    public function countList()
    {
        $this->authorize('pharmacy.view');
        return $this->response->setHeader('Cache-Control','private, no-store')->setJSON(['success'=>true,'data'=>(new \App\Services\Pharmacy\StockCountService())->listing($this->branch())]);
    }

    public function prescriptionUpload(int $id)
    {
        $this->authorize('pharmacy.prescriptions.manage');$this->authorize('pharmacy.prescriptions.view');
        $expected=session()->get('pharmacy_csrf');
        if (!is_string($expected)||!hash_equals($expected,$this->request->getHeaderLine('X-Pharmacy-CSRF'))) throw new HTTPException('Invalid request token. Reload the page.',403);
        try {
            $document=(new \App\Services\Pharmacy\PrescriptionDocumentService())->upload($id,$this->branch(),(int)AuthContext::userId(),$this->request->getFile('attachment'));
            return $this->response->setJSON(['success'=>true,'id'=>$document,'message'=>lang('Pharmacy.saved'),'data'=>(new \App\Services\Pharmacy\PrescriptionDocumentService())->listing($id,$this->branch())]);
        } catch (InvalidArgumentException $e) { return $this->response->setStatusCode(422)->setJSON(['success'=>false,'message'=>$e->getMessage()]); }
    }

    public function prescriptionDownload(int $id)
    {
        $this->authorize('pharmacy.prescriptions.view');
        try {
            $service=new \App\Services\Pharmacy\PrescriptionDocumentService();$document=$service->find($id,$this->branch());
            \App\Services\AuditService::log('prescription.document_download','pharmacy','pharmacy_prescription_documents',$id);
            return $this->response->download($service->path($document),null)->setFileName($document['original_name'])->setHeader('Content-Type',$document['mime'])->setHeader('X-Content-Type-Options','nosniff')->setHeader('Cache-Control','private, no-store');
        } catch (InvalidArgumentException $e) { throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound(); }
    }

    public function batchTrace(int $id)
    {
        $this->authorize('pharmacy.view');$this->authorize('sales.view');
        $page=filter_var($this->request->getGet('page'),FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>10000]])?:1;
        try { return $this->response->setHeader('Cache-Control','private, no-store')->setJSON(['success'=>true,'data'=>(new \App\Services\Pharmacy\BatchTraceService())->trace($id,$this->branch(),AuthContext::can('pharmacy.patients.view'),$page)]); }
        catch (InvalidArgumentException $e) { return $this->response->setStatusCode(404)->setJSON(['success'=>false,'message'=>$e->getMessage()]); }
    }

    public function partyLookup(string $kind)
    {
        if ($kind==='patients') $this->authorize('pharmacy.patients.view');
        elseif ($kind==='customers') $this->authorize('sales.create');
        else throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        $search=$this->request->getGet('q');$search=is_string($search)?mb_substr(trim($search),0,100):'';
        if ($kind==='patients') $query=db_connect()->table('pharmacy_patients')->select('id,name,phone')->where('branch_id',$this->branch());
        else $query=db_connect()->table('customers')->select('id,name')->where('deleted_at',null);
        if ($search!=='') $query->groupStart()->like('name',$search)->orLike('phone',$search)->groupEnd();
        return $this->response->setHeader('Cache-Control','private, no-store')->setJSON(['success'=>true,'data'=>$query->orderBy('name')->limit(25)->get()->getResultArray()]);
    }

    public function availability()
    {
        $this->authorize('sales.create');
        $raw=$this->request->getGet('products');
        if (!is_string($raw)||!preg_match('/^[0-9]+(?:,[0-9]+){0,99}$/D',$raw)) return $this->response->setStatusCode(422)->setJSON(['success'=>false,'message'=>'Request 1 to 100 product identifiers.']);
        $products=[];
        try { foreach (explode(',',$raw) as $id) $products[]=\App\Services\Pharmacy\PharmacyInput::id($id); }
        catch (InvalidArgumentException $e) { return $this->response->setStatusCode(422)->setJSON(['success'=>false,'message'=>$e->getMessage()]); }
        return $this->response->setHeader('Cache-Control','private, no-store')->setJSON(['success'=>true,'data'=>(new \App\Services\Pharmacy\PharmacyAvailabilityService())->catalog($this->branch(),array_values(array_unique($products)))['medicines']]);
    }

    public function patientHistory(int $id)
    {
        $this->authorize('pharmacy.patients.view');
        $this->authorize('pharmacy.prescriptions.view');
        try {
            $data=(new \App\Services\Pharmacy\PatientCareService())->history($id,$this->branch());
            \App\Services\AuditService::log('patient.view','pharmacy','pharmacy_patients',$id);
            return $this->response->setHeader('Cache-Control','private, no-store')->setJSON(['success'=>true,'data'=>$data]);
        } catch (InvalidArgumentException $error) {
            return $this->response->setStatusCode(404)->setJSON(['success'=>false,'message'=>$error->getMessage()]);
        }
    }

    private function operations(string $section): string
    {
        $this->authorize($section === 'dispense' ? 'sales.create' : 'pharmacy.view');
        $ready = (new PharmacyService())->ready() && db_connect()->tableExists('pharmacy_batch_states');
        $medicines = $batches = $prescriptions = $payments = $customers = [];
        if ($ready) {
            $branch = $this->branch();
            $catalog=(new \App\Services\Pharmacy\PharmacyAvailabilityService())->catalog($branch);
            $medicines=$catalog['medicines'];$batches=$catalog['batches'];
            if ($section === 'dispense') {
                $medicines=array_values(array_filter($medicines,static fn($medicine)=>(bool)$medicine['is_active'] && (!isset($medicine['clinical_verified']) || (bool)$medicine['clinical_verified'])));
                $payments = (new \App\Models\PosPaymentMethodModel())->getActiveMethods();
                $customers = db_connect()->table('customers')->select('id,name')->where('deleted_at', null)->orderBy('name')->limit(1000)->get()->getResultArray();
                if (AuthContext::can('pharmacy.dispense') && AuthContext::can('pharmacy.prescriptions.view')) $prescriptions = (new PharmacyService())->prescriptions($branch,AuthContext::can('pharmacy.patients.view'));
            }
        }
        return view('web/pharmacy/operations', compact('section', 'ready', 'medicines', 'batches', 'prescriptions', 'payments', 'customers') + ['title' => lang('Pharmacy.title')]);
    }

    public function prescription(int $id)
    {
        $this->authorize('pharmacy.prescriptions.view');
        try {
            $data=(new PharmacyService())->prescription($id,$this->branch());
            \App\Services\AuditService::log('prescription.view','pharmacy','pharmacy_prescriptions',$id);
            return $this->response->setJSON(['success'=>true,'data'=>$data]);
        } catch (InvalidArgumentException $error) {
            return $this->response->setStatusCode(404)->setJSON(['success' => false, 'message' => $error->getMessage()]);
        }
    }

    public function export()
    {
        $this->authorize('pharmacy.view');
        $stream = fopen('php://temp', 'w+');
        fputcsv($stream, ['Medicine','Batch','Expiry','Status','Quantity','Unit'], ',', '"', '');
        foreach ((new \App\Services\Pharmacy\MedicineBatchService())->batches($this->branch()) as $batch) {
            $values = [$batch['name'], $batch['batch_no'], $batch['expiry_date'], $batch['status'], $batch['quantity'], $batch['unit']];
            foreach ($values as &$value) { $value = (string)$value; if (preg_match('/^[=+@\-\t\r]/', $value)) $value = "'" . $value; }
            unset($value);
            fputcsv($stream, $values, ',', '"', '');
        }
        rewind($stream); $csv = stream_get_contents($stream); fclose($stream);
        return $this->response->setHeader('Content-Type', 'text/csv; charset=UTF-8')->setHeader('Content-Disposition', 'attachment; filename="pharmacy-batch-stock.csv"')->setBody($csv);
    }

    /** Cashiers can reprint their own invoices; sales viewers can view their branch. */
    private function authorizeReceipts(): void
    {
        if (!AuthContext::can('sales.view') && !AuthContext::can('sales.create')) throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
    }

    public function receipts()
    {
        $this->authorizeReceipts();
        $query=$this->request->getGet('q');
        $query=is_string($query)?mb_substr(trim($query),0,100):'';
        $builder=db_connect()->table('sales s')
            ->select('s.id,s.invoice_no,s.sold_at,s.grand_total,s.status,c.name AS customer_name')
            ->join('customers c','c.id=s.customer_id','left')->where('s.branch_id',$this->branch());
        if (!AuthContext::can('sales.view')) $builder->where('s.user_id',AuthContext::userId());
        if ($query!=='') $builder->like('s.invoice_no',$query);
        return $this->response->setHeader('Cache-Control','private, no-store')->setJSON(['success'=>true,'data'=>$builder->orderBy('s.id','DESC')->limit(30)->get()->getResultArray()]);
    }

    public function receipt(int $saleId): string
    {
        $this->authorizeReceipts();
        $branch=$this->branch();
        $sale=(new \App\Models\SaleModel())->findDetailed($saleId,$branch);
        if (!$sale || (!AuthContext::can('sales.view') && (int)$sale['user_id']!==AuthContext::userId())) throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound('Receipt not found.');
        $identity=(new \App\Services\ReceiptBrandingService())->get($branch);
        $this->response->setHeader('Cache-Control','private, no-store');
        return view('web/pharmacy/receipt',compact('sale','identity'));
    }

    public function labels(int $saleId): string
    {
        $this->authorize('sales.create');
        $db = db_connect();
        $sale = $db->table('sales')->where('id',$saleId)->where('branch_id',$this->branch())->get()->getRowArray();
        if (!$sale) throw new HTTPException('Sale not found.',404);
        if (!AuthContext::can('sales.view') && (int)$sale['user_id']!==(int)AuthContext::userId()) throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        $dispensing = $db->table('pharmacy_dispensings')->where('sale_id',$saleId)->get()->getRowArray();
        $prescription = null;
        if ($dispensing) {
            $this->authorize('pharmacy.prescriptions.view');
            $prescription = (new PharmacyService())->prescription((int)$dispensing['prescription_id'],$this->branch());
        }
        $items = $db->table('sale_items')->where('sale_id',$saleId)->get()->getResultArray();
        return view('web/pharmacy/labels', compact('sale','items','prescription'));
    }
}
