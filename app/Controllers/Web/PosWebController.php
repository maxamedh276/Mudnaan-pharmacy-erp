<?php

namespace App\Controllers\Web;

use App\Controllers\BaseController;
use App\Models\CategoryModel;
use App\Models\CustomerModel;
use App\Models\ProductModel;
use App\Models\SaleModel;
use App\Models\SettingModel;
use Config\Database;

class PosWebController extends BaseController
{
    private function branchId(): int
    {
        $user = session()->get('user');
        return (int) ($user['branch_id'] ?? 1);
    }

    public function index(): string
    {
        if (!\App\Libraries\AuthContext::can('sales.create')) throw new \CodeIgniter\HTTP\Exceptions\HTTPException('You do not have access to this page.',403);
        $branchId = $this->branchId();
        
        // 1. Categories
        $categories = (new CategoryModel())->where('is_active', 1)->orderBy('name', 'ASC')->findAll();

        // 2. Products with stock levels
        $productModel = new ProductModel();
        $productsRes = $productModel->listWithStock($branchId, [], 1, 1000);
        $products = $productsRes['items'];
        foreach ($products as &$product) { $product['stock_split'] = (new \App\Services\PackageInventoryService())->balance($branchId, $product); $product['quantity_shortcuts'] = (new \App\Services\Web\SellingOptionsService())->forProduct($product); }
        unset($product);

        // 3. Customers for customer selector
        $customers = (new \App\Services\CustomerCreditService())->withStatuses((new CustomerModel())->where('deleted_at', null)->orderBy('name', 'ASC')->findAll());

        // 4. Today's orders count and recent sales
        $todayStr = date('Y-m-d');
        $saleModel = new SaleModel();
        $todaySalesRes = $saleModel->listSales($branchId, [
            'date_from' => $todayStr,
            'date_to'   => $todayStr
        ], 1, 100);

        // 5. Settings / Store brand
        $settings = (new SettingModel())->allAssoc();
        $settings['thermal_printer_enabled']=(new \App\Services\ThermalPrintService())->getConfig()['enabled']?'1':'0';

        // 6. POS Payment Methods (linked to Chart of Accounts)
        $posPaymentMethods = (new \App\Models\PosPaymentMethodModel())->getActiveMethods();

        return view('web/pos/index', [
            'title'       => 'Point of Sale (POS) — Mudnaan Pharmacy',
            'categories'  => $categories,
            'products'    => $products,
            'customers'   => $customers,
            'canManageCredit' => \App\Services\CustomerCreditService::canManage(),
            'todaySales'  => $todaySalesRes['items'],
            'ordersCount' => $todaySalesRes['total'],
            'settings'    => $settings,
            'posPaymentMethods' => $posPaymentMethods,
            'activeTax' => (new \App\Services\TaxRateService())->active(),
            'productTaxRates' => array_column($products,'tax_rate','id'),
            'user'        => session()->get('user') ?? [],
            'tenant'      => session()->get('tenant') ?? [],
            'plan'        => session()->get('plan') ?? 'premium',
        ]);
    }

    public function scan(): \CodeIgniter\HTTP\ResponseInterface
    {
        if (!\App\Libraries\AuthContext::can('sales.create')) throw new \CodeIgniter\HTTP\Exceptions\HTTPException('You do not have access to POS.', 403);
        try {
            $code = $this->request->getGet('code');
            if (!is_string($code)) throw new \InvalidArgumentException('Scan a valid product barcode or SKU.');
            return $this->response->setHeader('Cache-Control', 'no-store')->setJSON(['success'=>true,'data'=>(new \App\Services\Web\PosScanService())->find($code, $this->branchId())]);
        } catch (\InvalidArgumentException $error) {
            return $this->response->setStatusCode(422)->setJSON(['success'=>false,'message'=>$error->getMessage()]);
        }
    }

    public function stockData(): \CodeIgniter\HTTP\ResponseInterface
    {
        if (!\App\Libraries\AuthContext::can('sales.create')) throw new \CodeIgniter\HTTP\Exceptions\HTTPException('You do not have access to POS.', 403);
        $products = (new ProductModel())->listWithStock($this->branchId(), [], 1, 1000)['items'];
        $service = new \App\Services\PackageInventoryService();
        foreach ($products as &$product) { $product['stock_split'] = $service->balance($this->branchId(), $product); $product['quantity_shortcuts'] = (new \App\Services\Web\SellingOptionsService())->forProduct($product); }
        return $this->response->setJSON(['success' => true, 'data' => $products]);
    }

    /** Web checkout retains the current cart when tax configuration changed in another tab. */
    public function checkout(): \CodeIgniter\HTTP\ResponseInterface
    {
        $auth = \App\Libraries\AuthContext::class;
        if (!$auth::can('sales.create')) throw new \CodeIgniter\HTTP\Exceptions\HTTPException('You do not have access to checkout.',403);
        try {
            $body = $this->request->getJSON(true);
            if (!is_array($body) || !array_key_exists('tax_id',$body)) throw new \InvalidArgumentException('INVALID_SALE_ITEM');
            $sale = (new \App\Services\SalesService())->createSale($body,(int)$auth::$user->branch_id,(int)$auth::userId(),(string)$auth::plan());
            // Printing configuration must never turn a committed sale into a failed checkout.
            $autoPrint = null;
            try { $autoPrint = (new \App\Services\ThermalPrintService())->getConfig()['enabled']; }
            catch (\Throwable) { log_message('error','QZ_PRINT checkout_printer_configuration_failed'); }
            return $this->response->setJSON(['success'=>true,'data'=>$sale,'autoPrint'=>$autoPrint]);
        } catch (\App\Services\PackageStockException $error) {
            return $this->response->setStatusCode(422)->setJSON(['success' => false, 'code' => 'PACKAGE_STOCK_SHORTAGE', 'message' => $error->getMessage(), 'stock' => $error->stock]);
        } catch (\App\Services\CustomerCreditLimitException $error) {
            return $this->response->setStatusCode(422)->setJSON(['success' => false, 'code' => 'CREDIT_LIMIT_EXCEEDED',
                'message' => 'This order exceeds the customer’s available credit. Increase the paid amount, collect an outstanding receipt, or ask an administrator to review the limit. No order was saved.',
                'creditStatus' => $error->creditStatus]);
        } catch (\App\Services\TaxChangedException $error) {
            return $this->response->setStatusCode(409)->setJSON(['success'=>false,'message'=>$error->getMessage(),'activeTax'=>$error->activeTax]);
        } catch (\InvalidArgumentException | \RuntimeException $error) {
            $message = match($error->getMessage()) {
                'PERIOD_CLOSED'=>'The sale date is in a closed financial period. Ask an administrator to review Financial Periods. No order was saved; your cart is unchanged.',
                'EMPTY_SALE'=>'Add items to the order first.',
                'INVALID_SALE_ITEM'=>'Enter valid quantities, prices, discount and shipping.',
                'PRODUCT_NOT_FOUND'=>'A product is no longer available.',
                'INSUFFICIENT_STOCK'=>'Not enough stock for one of the items.',
                'CREDIT_REQUIRES_CUSTOMER'=>'Select a customer before selling on credit.',
                'CUSTOMER_REQUIRED'=>'The selected customer is no longer available. Select another customer.',
                'INVALID_PAYMENT_METHOD'=>'Select an active payment method.',
                default=>null,
            };
            if ($message === null) throw $error;
            return $this->response->setStatusCode($error->getMessage()==='PERIOD_CLOSED'?409:422)->setJSON(['success'=>false,'code'=>$error->getMessage(),'message'=>$message]);
        }
    }
}
