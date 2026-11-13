<?php

namespace App\Controllers\Pos;

use App\Controllers\BaseApiController;
use App\Libraries\AuthContext;
use App\Models\SaleModel;
use App\Services\SalesService;
use App\Services\ThermalPrintService;
use CodeIgniter\HTTP\ResponseInterface;
use InvalidArgumentException;
use RuntimeException;

class SalesController extends BaseApiController
{
    /**
     * Error codes SalesService throws => HTTP status + human message.
     * The app translates by code (mobile_app_structure_provider.md §9).
     */
    private const SALE_ERRORS = [
        'EMPTY_SALE'               => [422, 'The sale has no items.'],
        'INVALID_SALE_ITEM'        => [422, 'A sale item is missing its product or quantity.'],
        'PRODUCT_NOT_FOUND'        => [422, 'One of the products no longer exists.'],
        'INSUFFICIENT_STOCK'       => [422, 'Not enough stock for one of the items.'],
        'CREDIT_REQUIRES_CUSTOMER' => [422, 'Select a customer before selling on credit.'],
        'INVALID_PAYMENT_METHOD'   => [422, 'Unknown payment method.'],
    ];

    /**
     * GET /api/v1/sales?status=&customer_id=&q=&date_from=&date_to=
     */
    public function index(): ResponseInterface
    {
        if ($denied = $this->requirePermission('sales.view')) {
            return $denied;
        }

        ['page' => $page, 'perPage' => $perPage] = $this->paginationParams();

        $filters = [
            'status'      => $this->request->getGet('status'),
            'customer_id' => $this->request->getGet('customer_id'),
            'q'           => $this->request->getGet('q'),
            'date_from'   => $this->request->getGet('date_from'),
            'date_to'     => $this->request->getGet('date_to'),
        ];

        $result = (new SaleModel())->listSales($this->branchId(), $filters, $page, $perPage);

        return $this->respondData(
            $result['items'],
            200,
            $this->paginationMeta($page, $perPage, $result['total']),
        );
    }

    public function show(int $saleId): ResponseInterface
    {
        if ($denied = $this->requirePermission('sales.view')) {
            return $denied;
        }

        $sale = (new SaleModel())->findDetailed($saleId, $this->branchId());

        return $sale === null
            ? $this->respondNotFound('Sale')
            : $this->respondData($sale);
    }

    /**
     * POST /api/v1/sales — "Complete Sale" at the POS.
     */
    public function create(): ResponseInterface
    {
        if ($denied = $this->requirePermission('sales.create')) {
            return $denied;
        }

        try {
            $sale = (new SalesService())->createSale(
                $this->body(),
                $this->branchId(),
                (int) AuthContext::userId(),
                (string) AuthContext::plan(),
            );
        } catch (InvalidArgumentException | RuntimeException $exception) {
            $code = $exception->getMessage();

            if (isset(self::SALE_ERRORS[$code])) {
                [$status, $message] = self::SALE_ERRORS[$code];

                return $this->respondError($code, $message, $status);
            }

            throw $exception;
        }

        return $this->respondData($sale, 201);
    }

    /**
     * POST /api/v1/sales/{id}/print — Direct ESC/POS Thermal Printing via escpos-php
     */
    public function printReceipt(int $saleId): ResponseInterface
    {
        if ($denied = $this->requirePermission('sales.view')) {
            return $denied;
        }

        $service = new ThermalPrintService();
        $result = $service->printSale($saleId, $this->branchId());

        return $this->respondData($result, $result['success'] ? 200 : 400);
    }

    /**
     * GET /api/v1/sales/{id}/escpos — Raw binary ESC/POS byte stream
     */
    public function escposStream(int $saleId): ResponseInterface
    {
        if ($denied = $this->requirePermission('sales.view')) {
            return $denied;
        }

        $service = new ThermalPrintService();
        $sale = (new SaleModel())->findDetailed($saleId, $this->branchId());

        if (! $sale) {
            return $this->respondNotFound('Sale');
        }

        $config = $service->getConfig();
        $connector = new \Mike42\Escpos\PrintConnectors\DummyPrintConnector();
        $printer = new \Mike42\Escpos\Printer($connector);

        $refMethod = new \ReflectionMethod($service, 'buildReceipt');
        $refMethod->setAccessible(true);
        $refMethod->invoke($service, $printer, $sale, $config);

        $binaryData = $connector->getData();
        $printer->close();

        return $this->response
            ->setHeader('Content-Type', 'application/octet-stream')
            ->setHeader('Content-Disposition', 'inline; filename="receipt-' . $saleId . '.bin"')
            ->setBody($binaryData);
    }

    /**
     * POST /api/v1/printer/test — Send test slip to ESC/POS thermal printer
     */
    public function testPrinter(): ResponseInterface
    {
        if ($denied = $this->requirePermission('sales.view')) {
            return $denied;
        }

        $service = new ThermalPrintService();
        $result = $service->printTestSlip();

        return $this->respondData($result, $result['success'] ? 200 : 400);
    }

    /**
     * GET /api/v1/printer/settings — Get ESC/POS printer settings
     */
    public function getPrinterSettings(): ResponseInterface
    {
        if ($denied = $this->requirePermission('sales.view')) {
            return $denied;
        }

        $service = new ThermalPrintService();
        return $this->respondData($service->getConfig());
    }

    /**
     * POST /api/v1/printer/settings — Save ESC/POS printer settings
     */
    public function savePrinterSettings(): ResponseInterface
    {
        if ($denied = $this->requirePermission('sales.manage')) {
            if ($denied = $this->requirePermission('settings.manage')) {
                return $denied;
            }
        }

        $body = $this->body();
        $settingModel = new \App\Models\SettingModel();

        $keys = [
            'thermal_printer_enabled',
            'thermal_printer_type',
            'thermal_printer_name',
            'thermal_printer_ip',
            'thermal_printer_port',
            'thermal_paper_width',
            'thermal_auto_drawer',
            'thermal_auto_cut',
            'receipt_header',
            'receipt_footer',
        ];

        foreach ($keys as $key) {
            if (isset($body[$key])) {
                $settingModel->setVal($key, (string) $body[$key]);
            }
        }

        $service = new ThermalPrintService();
        return $this->respondData([
            'message'  => 'Thermal printer settings saved successfully.',
            'settings' => $service->getConfig(),
        ]);
    }
}
