<?php

namespace App\Services;

use App\Models\SaleModel;
use App\Models\SettingModel;
use Exception;
use Mike42\Escpos\PrintConnectors\DummyPrintConnector;
use Mike42\Escpos\PrintConnectors\NetworkPrintConnector;
use Mike42\Escpos\PrintConnectors\WindowsPrintConnector;
use Mike42\Escpos\PrintConnectors\FilePrintConnector;
use Mike42\Escpos\Printer;
use Throwable;

/**
 * Direct ESC/POS Thermal Printing Service using mike42/escpos-php.
 * 
 * Supports:
 *   - Windows USB / Shared Printer (WindowsPrintConnector)
 *   - Network / Wi-Fi Thermal Printer (NetworkPrintConnector)
 *   - Cash Drawer Kick (pulse)
 *   - Hardware Paper Cutter (cut)
 *   - 80mm (48 cols) and 58mm (32 cols) roll widths
 *   - QR Code & Barcode printing
 *   - Dummy / Binary stream for mobile apps & web fallback
 */
class ThermalPrintService
{
    private SettingModel $settings;
    private SaleModel $sales;

    public function __construct(private readonly ?\Closure $connectorFactory = null, private readonly bool $browserPrint = false)
    {
        $this->settings = new SettingModel();
        $this->sales = new SaleModel();
    }

    /**
     * Get thermal printer configuration from settings
     */
    public function getConfig(): array
    {
        $all = $this->settings->allAssoc();
        $branding=(new ReceiptBrandingService())->get();

        // Managed profiles are authoritative, even if a legacy settings screen changes its cached fields.
        if(array_key_exists('receipt_printers',$all)){
            $active=array_values(array_filter((new PrinterManagementService())->list(),static fn($printer)=>!empty($printer['active'])));
            $all['thermal_printer_enabled']=$active?'1':'0';
            $all['thermal_auto_cut']='1';
            if($active){$all['thermal_printer_type']=$active[0]['type'];$all['thermal_printer_name']=$active[0]['name'];$all['thermal_printer_ip']=$active[0]['ip'];$all['thermal_printer_port']=$active[0]['port'];}
        }
        return [
            'engine' => $all['thermal_print_engine']??'erp',
            'enabled'     => (bool) ($all['thermal_printer_enabled'] ?? '1'),
            'type'        => $all['thermal_printer_type'] ?? 'windows', // windows, network, dummy, file
            'name'        => $all['thermal_printer_name'] ?? 'POS80',
            'ip'          => $all['thermal_printer_ip'] ?? '192.168.1.100',
            'port'        => (int) ($all['thermal_printer_port'] ?? 9100),
            'paper_width' => (int) ($all['thermal_paper_width'] ?? 80), // 80mm or 58mm
            'auto_drawer' => (bool) ($all['thermal_auto_drawer'] ?? '1'),
            'auto_cut'    => (bool) ($all['thermal_auto_cut'] ?? '1'),
            'receipt_style' => $all['receipt_style'] ?? 'classic',
            'store_name'  => $branding['store_name'],
            'address'     => $branding['address'],
            'phone'       => $branding['phone'],
            'header'      => $branding['header'],
            'footer'      => $branding['footer'],
        ];
    }

    /**
     * Build appropriate PrintConnector based on configuration
     */
    private function getConnector(array $config)
    {
        if(!$config['enabled'])throw new \RuntimeException('Printing is disabled. Activate a printer in Settings.');
        if($this->browserPrint)return new DummyPrintConnector();
        if($this->connectorFactory)return ($this->connectorFactory)($config);
        $type = strtolower($config['type'] ?? 'windows');

        if ($type === 'network') {
            return new NetworkPrintConnector($config['ip'], $config['port'], 3); // 3 sec timeout
        }

        if ($type === 'windows') {
            // In Windows, connect to shared printer name (e.g. "POS80" or "ReceiptPrinter")
            return new WindowsPrintConnector($config['name']);
        }

        if ($type === 'file' && !empty($config['name'])) {
            return new FilePrintConnector($config['name']);
        }

        // Default or dummy fallback (memory buffer)
        return new DummyPrintConnector();
    }

    /**
     * Print a complete sale receipt to the configured thermal printer
     * 
     * @return array{success: bool, message: string, printer_type: string, data?: string, error?: string}
     */
    public function printSale(int $saleId, int $branchId): array
    {
        $config = $this->getConfig();
        $sale = $this->sales->findDetailed($saleId, $branchId);

        if (! $sale) {
            return [
                'success' => false,
                'error'   => 'Sale transaction not found.',
                'printer_type' => $config['type'],
            ];
        }

        $connector = null;
        $isDummy = false;

        try {
            $connector = $this->getConnector($config);
            $isDummy = ($connector instanceof DummyPrintConnector);
            $printer = new Printer($connector);

            $this->buildReceipt($printer, $sale, $config);

            $rawBytes = '';
            if ($isDummy) {
                $rawBytes = base64_encode($connector->getData());
            }

            $printer->close();

            return [
                'success'      => true,
                'message'      => $isDummy 
                    ? 'ESC/POS raw binary stream generated.'
                    : "Receipt successfully sent to thermal printer [{$config['name']}].",
                'printer_type' => $config['type'],
                'data'         => $rawBytes,
            ];
        } catch (Throwable $e) {
            return [
                'success'      => false,
                'error'        => 'Thermal printer error: ' . $e->getMessage(),
                'printer_type' => $config['type'],
                'fallback'     => true, // triggers screen thermal modal fallback
            ];
        }
    }

    /** Customer collection printing uses persisted allocation amounts, never browser totals. */
    public function printCustomerReceipt(int $receiptId,int $branchId): array
    {
        $db=db_connect();$receipt=$db->table('customer_receipts r')->select('r.*,c.name customer_name')->join('customers c','c.id=r.customer_id')->where('r.id',$receiptId)->where('r.branch_id',$branchId)->get()->getRowArray();
        if(!$receipt)return ['success'=>false,'error'=>'Receipt not found.'];
        $config=$this->getConfig();
        try{
            $connector=$this->getConnector($config);$printer=new Printer($connector);
            $printer->setJustification(Printer::JUSTIFY_CENTER);$this->printStoreName($printer,$config);
            if($config['header']!=='')$printer->text($config['header']."\n");
            $printer->text(($receipt['status']==='cancelled'?'VOID RECEIPT ':'CUSTOMER RECEIPT ').$receipt['receipt_no']."\n");$printer->text($config['address']."\n");$printer->text('Tel: '.$config['phone']."\n");$printer->text($receipt['received_at']."\n");$printer->setJustification(Printer::JUSTIFY_LEFT);$printer->text($receipt['customer_name']."\n");
            $rows=$db->table('customer_receipt_allocations a')->select('a.*,i.invoice_no')->join('customer_invoices i','i.id=a.invoice_id')->where('a.receipt_id',$receiptId)->get()->getResultArray();
            if(!$rows&&$receipt['invoice_id'])$rows=[['invoice_no'=>$db->table('customer_invoices')->where('id',$receipt['invoice_id'])->get()->getRow('invoice_no'),'amount'=>$receipt['amount'],'discount'=>$receipt['discount']]];
            $width=$this->receiptColumns($config);
            foreach($rows as $row)$printer->text($this->formatTwoCols($row['invoice_no'],number_format((float)$row['amount']+(float)$row['discount'],2),$width));
            $printer->text($this->formatTwoCols('Received',number_format((float)$receipt['amount'],2),$width));$printer->text($this->formatTwoCols('Discount',number_format((float)$receipt['discount'],2),$width));
            $this->printBrandFooter($printer, $config);$printer->feed(3);$printer->cut();
            $bytes=$connector instanceof DummyPrintConnector?base64_encode($connector->getData()):'';$printer->close();
            return ['success'=>true,'data'=>$bytes,'message'=>'Receipt sent to the printer with automatic cut.'];
        }catch(Throwable $error){return ['success'=>false,'error'=>'Printing failed: '.$error->getMessage().'. Check the printer before retrying.'];}
    }

    /**
     * Print a test slip to verify connection and cash drawer pulse
     */
    public function printTestSlip(): array
    {
        $config = $this->getConfig();
        $connector = null;
        $isDummy = false;

        try {
            $connector = $this->getConnector($config);
            $isDummy = ($connector instanceof DummyPrintConnector);
            $printer = new Printer($connector);

            $cols = $this->receiptColumns($config);

            // Center Header
            $printer->setJustification(Printer::JUSTIFY_CENTER);
            $printer->selectPrintMode(Printer::MODE_FONT_A);
            $this->printStoreName($printer, $config);
            $printer->selectPrintMode(Printer::MODE_FONT_A);
            $printer->text("THERMAL PRINTER TEST\n");
            $printer->text(date('Y-m-d H:i:s') . "\n");
            $printer->text(str_repeat('-', $cols) . "\n");

            // Info Body
            $printer->setJustification(Printer::JUSTIFY_LEFT);
            $printer->text($this->formatTwoCols("Connection Type:", strtoupper($config['type']), $cols));
            $printer->text($this->formatTwoCols("Printer Target:", ($config['type'] === 'network' ? $config['ip'] . ':' . $config['port'] : $config['name']), $cols));
            $printer->text($this->formatTwoCols("Paper Width:", $config['paper_width'] . "mm", $cols));
            $printer->text($this->formatTwoCols("Auto Cash Drawer:", $config['auto_drawer'] ? 'Enabled' : 'Disabled', $cols));
            $printer->text($this->formatTwoCols("Auto Cutter:", $config['auto_cut'] ? 'Enabled' : 'Disabled', $cols));
            $printer->text(str_repeat('-', $cols) . "\n");

            $printer->setJustification(Printer::JUSTIFY_CENTER);
            $printer->setEmphasis(true);
            $printer->text("mike42/escpos-php is active!\n");
            $printer->setEmphasis(false);
            $printer->text("Direct POS Thermal Printing is Ready.\n");

            $this->printBrandFooter($printer, $config);

            // Cash drawer kick test
            if ($config['auto_drawer']) {
                $printer->pulse();
            }

            // Cut
            $printer->feed(3);
            if ($config['auto_cut']) {
                $printer->cut();
            }

            $rawBytes = '';
            if ($isDummy) {
                $rawBytes = base64_encode($connector->getData());
            }

            $printer->close();

            return [
                'success'      => true,
                'message'      => $isDummy
                    ? 'ESC/POS test stream generated (Dummy mode).'
                    : "Test receipt printed successfully to [{$config['name']}].",
                'printer_type' => $config['type'],
                'data'         => $rawBytes,
            ];
        } catch (Throwable $e) {
            return [
                'success'      => false,
                'error'        => 'Test print failed: ' . $e->getMessage(),
                'printer_type' => $config['type'],
            ];
        }
    }

    /**
     * Render the ESC/POS receipt commands
     */
    private function buildReceipt(Printer $printer, array $sale, array $config): void
    {
        $cols = $this->receiptColumns($config);

        $printer->setLineSpacing(($config['receipt_style'] ?? 'classic') === 'compact' ? 24 : 30);

        // 1. Store Header (Centered)
        $printer->setJustification(Printer::JUSTIFY_CENTER);
        $printer->selectPrintMode(Printer::MODE_FONT_A);
        $this->printStoreName($printer, $config);
        
        $printer->selectPrintMode(Printer::MODE_FONT_A);
        if (! empty($config['address'])) {
            $printer->text($config['address'] . "\n");
        }
        if (! empty($config['phone'])) {
            $printer->text("Tel: " . $config['phone'] . "\n");
        }
        if (! empty($config['header'])) {
            $printer->text($config['header'] . "\n");
        }

        $printer->text(str_repeat('-', $cols) . "\n");

        // Center the fixed-width block when a printer exposes a wider print area.
        $printer->setDoubleStrike(true);
        $printer->setJustification(Printer::JUSTIFY_CENTER);
        $invoiceNo = $sale['invoice_no'] ?: ('#' . $sale['id']);
        $soldAt    = date('M d, Y h:i A', strtotime($sale['sold_at'] ?? 'now'));
        $customer  = $sale['customer_name'] ?? 'Walk-in Customer';
        $cashier   = $sale['cashier_name'] ?? 'Staff';

        $printer->text($this->formatTwoCols("Invoice:", $invoiceNo, $cols));
        $printer->text($this->formatTwoCols("Date:", $soldAt, $cols));
        $printer->text($this->formatTwoCols("Customer:", $customer, $cols));
        $printer->text($this->formatTwoCols("Cashier:", $cashier, $cols));
        $printer->text(str_repeat('-', $cols) . "\n");

        // All column widths include their separators and fit the actual print area.
        $printer->setEmphasis(true);
        $printer->setDoubleStrike(true);
        $printer->text($this->itemRows('Item', 'Qty', 'Price', 'Total', $cols));
        $printer->setEmphasis(false);
        $printer->text(str_repeat('-', $cols)."\n");
        foreach ($sale['items'] ?? [] as $item) {
            $quantity = (string) $item['quantity'];
            if (str_contains($quantity, '.')) $quantity = rtrim(rtrim($quantity, '0'), '.');
            $printer->text($this->itemRows($item['description'] ?? $item['product_name'] ?? 'Item',
                $quantity, '$'.number_format((float)$item['unit_price'], 2), '$'.number_format((float)$item['line_total'], 2), $cols));
        }
        $printer->text(str_repeat('-', $cols)."\n");

        // 4. Totals breakdown in normal-size printer text.
        $subtotal = number_format((float) $sale['subtotal'], 2);
        $discount = number_format((float) $sale['discount'], 2);
        $grandTotal = number_format((float) $sale['grand_total'], 2);
        $paidTotal = number_format((float) $sale['paid_total'], 2);
        $due = round((float)($sale['net_total'] ?? $sale['grand_total']) - (float)$sale['paid_total'], 2);

        $printer->text($this->formatTwoCols("Subtotal:", '$' . $subtotal, $cols));
        if ((float)$sale['discount'] > 0) {
            $printer->text($this->formatTwoCols("Discount:", '-$' . $discount, $cols));
        }
        if ((float)($sale['tax_total'] ?? 0) > 0) {
            $printer->text($this->formatTwoCols('Tax:', '$' . number_format((float)$sale['tax_total'], 2), $cols));
        }
        if ((float)($sale['shipping'] ?? 0) > 0) {
            $printer->text($this->formatTwoCols('Shipping:', '$' . number_format((float)$sale['shipping'], 2), $cols));
        }

        // Emphasize the total without stretching the glyphs.
        $printer->setDoubleStrike(true);
        $printer->setEmphasis(true);
        $printer->text($this->formatTwoCols("GRAND TOTAL:", '$' . $grandTotal, $cols));
        if ((float)($sale['returned_total'] ?? 0) > 0) {
            $printer->text($this->formatTwoCols('Returns:', '-$' . number_format((float)$sale['returned_total'], 2), $cols));
            $printer->text($this->formatTwoCols('NET TOTAL:', '$' . number_format((float)$sale['net_total'], 2), $cols));
            $printer->text($this->formatTwoCols('Refunded:', '$' . number_format((float)$sale['refunded_total'], 2), $cols));
        }
        $printer->setEmphasis(false);
        $printer->selectPrintMode(Printer::MODE_FONT_A);

        $printer->text(str_repeat('-', $cols) . "\n");

        // 5. Payment Methods Breakdown
        $printer->setEmphasis(true);
        $printer->text(str_pad("Payment Breakdown:", $cols)."\n");
        $printer->setEmphasis(false);

        $payments = $sale['payments'] ?? [];
        if (! empty($payments)) {
            foreach ($payments as $p) {
                $methodLabel = $p['reference'] ?: ucfirst($p['method']);
                $printer->text($this->formatTwoCols($methodLabel . ":", '$' . number_format((float)$p['amount'], 2), $cols));
            }
        }

        $printer->text($this->formatTwoCols("Total Paid:", '$' . $paidTotal, $cols));
        if ($due > 0.005) {
            $printer->setEmphasis(true);
            $printer->text($this->formatTwoCols("CREDIT DUE:", '$' . number_format($due, 2), $cols));
            $printer->setEmphasis(false);
        }

        $printer->text(str_repeat('-', $cols) . "\n");

        $this->printBrandFooter($printer, $config);

        // 8. Cash Drawer Kick
        if ($config['auto_drawer']) {
            $printer->pulse();
        }

        // 9. Feed & Auto-Cut
        $printer->feed(3);
        if ($config['auto_cut']) {
            $printer->cut();
        }
    }

    /** Shared paper footer; QR output is temporarily disabled, attribution remains. */
    private function printBrandFooter(Printer $printer, array $config): void
    {
        $printer->setDoubleStrike(false);
        $printer->selectPrintMode(Printer::MODE_FONT_A);
        $printer->setTextSize(1, 1);
        $printer->setJustification(Printer::JUSTIFY_CENTER);
        /* QR disabled for now; keep this section to restore it later.
        try {
            $printer->qrCode('https://mudnaantechnology.com', Printer::QR_ECLEVEL_L, 4);
        } catch (Throwable) {
            // Older devices may lack native QR support; preserve the text footer.
        }
        */
        if (!empty($config['footer'])) {
            foreach (explode("\n", str_replace(['\\n', "\r"], ["\n", ''], $config['footer'])) as $line) {
                if (trim($line) !== '') $printer->text(trim($line)."\n");
            }
        }
        $printer->setEmphasis(true);
        // Two lines fit 58 mm paper without condensing or enlarging characters.
        $printer->text("Powered by\nwww.mudnaantechnology.com\n");
        $printer->setEmphasis(false);
    }

    /** 80 mm mechanisms commonly expose 42 Font-A columns, not 48. */
    private function receiptColumns(array $config): int
    {
        return (int)$config['paper_width'] === 58 ? 32 : 42;
    }

    private function printStoreName(Printer $printer, array $config): void
    {
        $printer->setEmphasis(true);
        $printer->setDoubleStrike(true);
        // Enlarge only the title proportionally. Wrap at half the normal columns.
        $printer->setTextSize(2, 2);
        foreach ($this->wrapText($config['store_name'], intdiv($this->receiptColumns($config), 2)) as $line) {
            $printer->text($line."\n");
        }
        $printer->setTextSize(1, 1);
        $printer->setEmphasis(false);
        $printer->setDoubleStrike(false);
    }

    private function wrapText(string $text, int $width): array
    {
        $text = trim(preg_replace('/[\x00-\x20\x7f]+/u', ' ', $text) ?? '');
        $lines = []; $line = '';
        foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $character) {
            if (mb_strwidth($line.$character, 'UTF-8') > $width) {
                $lines[] = rtrim($line); $line = '';
            }
            $line .= $character;
        }
        if ($line !== '' || !$lines) $lines[] = trim($line);
        return $lines;
    }

    /** Keep stored unit prices and totals intact; wrap names within their column. */
    private function itemRows(string $description, string $quantity, string $price, string $total, int $width): string
    {
        $quantityWidth = max(3, mb_strwidth($quantity, 'UTF-8'));
        $priceWidth = max(7, mb_strwidth($price, 'UTF-8'));
        $totalWidth = max(8, mb_strwidth($total, 'UTF-8'));
        $nameWidth = $width - $quantityWidth - $priceWidth - $totalWidth - 3;
        if ($nameWidth < 6) {
            // Unusually large values get labelled rows rather than clipping digits.
            return implode("\n", $this->wrapText($description, $width))."\n".
                $this->formatTwoCols('Qty:', $quantity, $width).
                $this->formatTwoCols('Price:', $price, $width).
                $this->formatTwoCols('Total:', $total, $width);
        }
        $lines = $this->wrapText($description, $nameWidth);
        $output = '';
        foreach ($lines as $index=>$line) {
            $output .= $line.str_repeat(' ', $nameWidth-mb_strwidth($line,'UTF-8')).' '.
                str_pad($index === 0 ? $quantity : '', $quantityWidth, ' ', STR_PAD_LEFT).' '.
                str_pad($index === 0 ? $price : '', $priceWidth, ' ', STR_PAD_LEFT).' '.
                str_pad($index === 0 ? $total : '', $totalWidth, ' ', STR_PAD_LEFT)."\n";
        }
        return $output;
    }

    private function formatTwoCols(string $left, string $right, int $width): string
    {
        if (mb_strwidth($right,'UTF-8') >= $width) {
            return implode("\n", $this->wrapText($left, $width))."\n".
                implode("\n", $this->wrapText($right, $width))."\n";
        }
        $leftLines = $this->wrapText($left, max(1,$width-mb_strwidth($right,'UTF-8')-1));
        $last = array_pop($leftLines);
        $output = '';
        foreach ($leftLines as $line) $output .= $line.str_repeat(' ', $width-mb_strwidth($line,'UTF-8'))."\n";
        return $output.$last.str_repeat(' ', $width-mb_strwidth($last,'UTF-8')-mb_strwidth($right,'UTF-8')).$right."\n";
    }
}
