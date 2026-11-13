<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;
define('FCPATH', dirname(__DIR__).DIRECTORY_SEPARATOR);
define('ENVIRONMENT', 'testing');
require FCPATH.'app/Config/Paths.php';
$paths = new Config\Paths();
require $paths->systemDirectory.'/Boot.php';
CodeIgniter\Boot::bootConsole($paths);

use App\Services\ThermalPrintService;
use Mike42\Escpos\Printer;
use Mike42\Escpos\PrintConnectors\DummyPrintConnector;

function check(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
    echo "PASS: $message\n";
}
$service = (new ReflectionClass(ThermalPrintService::class))->newInstanceWithoutConstructor();
$rows = new ReflectionMethod($service, 'itemRows');
$twoCols = new ReflectionMethod($service, 'formatTwoCols');
foreach ([32,42] as $width) {
    foreach ([['iPhone 15 128GB','2','$750.00','$1,500.00'],['Nivea Soft Cream 200ml long product name','2.5','$493.82','$1,234.56'],['Very large quantity','123456789','$999,999.99','$999,999,999.99']] as [$name,$qty,$price,$total]) {
        $text = $rows->invoke($service,$name,$qty,$price,$total,$width);
        foreach (explode("\n",rtrim($text,"\n")) as $line) check(mb_strwidth($line,'UTF-8') <= $width, "$width-column item row cannot wrap at the printer edge.");
        check(substr_count($text,$total)===1 && str_contains($text,$qty) && str_contains($text,$price), 'Quantity, stored unit price and full line total are retained.');
        $nameWidth = $width-max(3,strlen($qty))-max(7,strlen($price))-max(8,strlen($total))-3;
        $nameLines = explode("\n",rtrim($text,"\n"));
        $printedName = $nameWidth >= 6 ? implode('',array_map(static fn($line)=>substr($line,0,$nameWidth),$nameLines)) : explode('Qty:',$text)[0];
        check(preg_replace('/\s+/','',$printedName)===preg_replace('/\s+/','',$name),'Long item descriptions are preserved across continuation lines.');
    }
    $metadata = $twoCols->invoke($service,'Customer: A very long customer name','A long right-side value',$width);
    foreach (explode("\n",rtrim($metadata,"\n")) as $line) check(mb_strwidth($line,'UTF-8') <= $width,'Metadata lines stay within paper columns.');
}
$build = new ReflectionMethod($service,'buildReceipt');
$sale=['id'=>1,'invoice_no'=>'QA-ONLY','sold_at'=>'2026-11-05 12:00:00','subtotal'=>25,'grand_total'=>25,'paid_total'=>25,'discount'=>0,
    'items'=>[['description'=>'Test product','quantity'=>'5.000','unit_price'=>5,'line_total'=>25]]];
foreach ([58,80] as $paperWidth) {
    $connector = new DummyPrintConnector(); $printer = new Printer($connector);
    $config=['paper_width'=>$paperWidth,'store_name'=>'Maareeye Shop','address'=>'','phone'=>'','header'=>'','footer'=>"\nThank you for your visit!\n\n",'auto_drawer'=>false,'auto_cut'=>false];
    $build->invoke($service,$printer,$sale,$config);
    $data=$connector->getData(); $printer->close();
    check(!str_contains($data,'Qty x Price') && str_contains($data,'Qty') && str_contains($data,'Price') && str_contains($data,'$5.00') && str_contains($data,'$25.00'),'Receipt has distinct Qty, Price and Total columns using persisted values.');
    check(str_contains($data,"\x1bG\x01\x1d!\x11Maareeye Shop\n\x1d!\x00"),'Shop title is double-struck, proportionally enlarged, and immediately reset.');
    check(!str_contains($data,"\x1d(k"),'Receipt QR commands are disabled.');
    check(str_contains($data,"Powered by\nwww.mudnaantechnology.com\n"),'Website attribution remains on both paper widths.');
    check(!str_contains($data,"\x1bd\x01"),'No additional paper-feed line is added before the footer.');
    check(str_contains($data,"Thank you for your visit!\n\x1bE\x01Powered by"),'Empty configured footer lines do not create gaps before the attribution.');
}
