<?php
/** Run C:/xampp/php/php.exe tests/workbook_import_smoke.php.
 * Connection-local temporary tables isolate every write, including financial entries.
 * Retained regression tests cover real XLSX parsing, all-or-nothing preflight and rollback.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('FCPATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
define('ENVIRONMENT', 'development');
require FCPATH . 'app/Config/Paths.php';
$paths = new Config\Paths(); require $paths->systemDirectory . '/Boot.php';
CodeIgniter\Boot::bootConsole($paths);
use App\Libraries\AuthContext;
use App\Services\Web\WorkbookImportService;
use App\Services\Web\ImportWorkbookReader;
$checks = 0; $tempFiles = [];
function check(bool $value, string $message): void { global $checks; if (!$value) throw new RuntimeException($message); $checks++; echo "PASS: $message\n"; }
function rejects(callable $action, string $contains): void {
 try { $action(); } catch (Throwable $e) { check(str_contains($e->getMessage(), $contains), 'Rejected: '.$contains); return; }
 throw new RuntimeException('Expected rejection: '.$contains);
}
/** Modify copies of the delivered XLSX files to exercise actual parsing, not mocked rows. */
function workbook(string $kind, callable $edit): string {
 global $tempFiles; $path=tempnam(sys_get_temp_dir(),'erp-import-'); $tempFiles[]=$path;
 copy(FCPATH.'assets/import-templates/'.$kind.'.xlsx',$path);
 $zip=new ZipArchive(); if ($zip->open($path)!==true) throw new RuntimeException('Fixture ZIP failed.');
 $edit($zip); $zip->close(); return $path;
}
function dataFile(string $kind, array $rows): string {
 $headers=$kind==='products'?WorkbookImportService::PRODUCT_HEADERS:WorkbookImportService::CONTACT_HEADERS;
 return workbook($kind, function($zip) use($headers,$rows) {
  $xml='<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
  foreach (array_merge([$headers],$rows) as $i=>$row) {
   $number=$i+1; $xml.='<row r="'.$number.'">';
   foreach(array_values($row) as $j=>$value) {
    $column=chr(65+$j); $xml.='<c r="'.$column.$number.'" t="inlineStr"><is><t>'.htmlspecialchars((string)$value,ENT_XML1,'UTF-8').'</t></is></c>';
   }
   $xml.='</row>';
  }
  $xml.='</sheetData></worksheet>'; $zip->addFromString('xl/worksheets/sheet1.xml',$xml);
 });
}
function snapshot(): array {
 $out=[]; foreach(['products','customers','suppliers','stock_levels','stock_movements','package_movements','product_opening_stocks','journal_entries','journal_entry_lines','audit_log'] as $table) $out[$table]=db_connect()->table($table)->countAllResults(); return $out;
}
/** CLI fixtures bypass only PHP's HTTP-upload provenance check, never application validation. */
class ImportFixtureUpload extends CodeIgniter\HTTP\Files\UploadedFile {
    public function isValid(): bool { return true; }
}
class ImportFixtureRequest extends CodeIgniter\HTTP\IncomingRequest {
    public ?CodeIgniter\HTTP\Files\UploadedFile $fixture = null;
    public function getFile(string $fileID) { return $this->fixture; }
}
function uploadController(?string $path, string $action = 'validate', string $name = 'test.xlsx', bool $ajax = true): App\Controllers\Web\WorkbookImportsController {
    $request = new ImportFixtureRequest(config(Config\App::class), new CodeIgniter\HTTP\URI('http://localhost/web/imports/customers'), null, new CodeIgniter\HTTP\UserAgent());
    $request->setMethod('POST')->setGlobal('post', ['action'=>$action]);
    if ($ajax) $request->setHeader('X-Requested-With','XMLHttpRequest');
    if ($path !== null) $request->fixture = new ImportFixtureUpload($path, $name, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', filesize($path), UPLOAD_ERR_OK);
    $controller = new App\Controllers\Web\WorkbookImportsController();
    $controller->initController($request, new CodeIgniter\HTTP\Response(config(Config\App::class)), service('logger'));
    return $controller;
}
$manager = new App\Libraries\TenantManager(); $tenant = $manager->find(config(Config\Tenants::class)->default); $manager->connect($tenant); $db = db_connect();
AuthContext::$tenant = $tenant; AuthContext::$user = (object)['id'=>1,'role_name'=>'Superadmin','branch_id'=>1];
session()->set('user', (array)AuthContext::$user);
$tables = ['products','categories','sales','sale_items','payments','stock_levels','stock_movements','package_movements','customers','customer_invoices','customer_invoice_items','customer_receipts','customer_receipt_allocations','customer_deposits','journal_entries','journal_entry_lines','financial_periods','audit_log','accounts','pos_payment_methods','bank_accounts','purchases','purchase_items','purchase_receipts','purchase_receipt_items','suppliers','supplier_bills','supplier_bill_items','supplier_payments','settings','tax_rates','document_returns','document_return_items','web_stock_adjustment_details','selling_option_groups','product_opening_stocks'];
$hashes = $shadowed = [];
try {
    foreach ($tables as $table) {
        $hashes[$table] = hash('sha256', serialize($db->table($table)->get()->getResultArray()));
        $copy = in_array($table, ['accounts','pos_payment_methods'], true) ? $db->table($table)->get()->getResultArray() : [];
        $definition = array_values($db->query("SHOW CREATE TABLE `$table`")->getRowArray())[1];
        $definition = preg_replace('/^CREATE TABLE/', 'CREATE TEMPORARY TABLE', $definition);
        $definition = preg_replace('/^\s*CONSTRAINT .*\n/m', '', $definition);
        $definition = preg_replace('/,\s*\)/', "\n)", $definition);
        // Mirror the barcode migration in the isolated fixture when the local schema is older.
        if ($table === 'products' && !str_contains($definition, '`sealed_barcode`')) {
            $definition = preg_replace('/\(/', '( `sealed_barcode` VARCHAR(80) NULL,', $definition, 1);
        }
        $db->query($definition); $shadowed[] = $table;
        if ($copy) $db->table($table)->insertBatch($copy);
    }
    $db->resetDataCache();

    $db->table('categories')->insert(['name'=>'Import QA','is_active'=>1]);
    $service=new WorkbookImportService(); $reader=new ImportWorkbookReader();
    foreach(['products','customers','suppliers'] as $kind) {
        $rows=$reader->read(FCPATH.'assets/import-templates/'.$kind.'.xlsx',$kind==='products'?WorkbookImportService::PRODUCT_HEADERS:WorkbookImportService::CONTACT_HEADERS);
        check(count($rows)===($kind==='products'?3:1), "$kind delivered sample parses correctly.");
    }
    $sample=workbook('products', function($zip) {
        foreach (['xl/sharedStrings.xml','xl/worksheets/sheet1.xml'] as $part) {
            $xml=$zip->getFromName($part);
            if ($xml!==false) $zip->addFromString($part,str_replace('REPLACE WITH ACTIVE CATEGORY PATH','Import QA',$xml));
        }
    });
    check($service->process('products',$sample,true)['imported']===3,'Delivered product sample imports after replacing its category placeholder.');
    foreach (['customers','suppliers'] as $kind) check($service->process($kind,FCPATH.'assets/import-templates/'.$kind.'.xlsx',true)['imported']===1,"Delivered $kind sample imports successfully.");
    $plain=['QA Soap','Import QA','pcs','1','10','non_sealed','QA-SOAP-CODE','','','','','loose','','0','12','0.5'];
    $sealed=['QA Bariis','Import QA','kg','1.5','5','sealed','001234','','Loor','25','35','loose','','2','3.5','25','001235'];
    $oil=['QA Saliid','Import QA','litre','2','3','sealed','QA-OIL-CODE','','Jirgaan','20','38','sealed','','0','0','',''];
    $good=dataFile('products',[$plain,$sealed,$oil]);
    $noCodes=$oil; $noCodes[6]=''; $noCodes[16]='';
    $before=snapshot();
    $preview=$service->process('products',dataFile('products',[$noCodes]));
    check($preview['valid'] && snapshot()===$before,'A sealed product with neither barcode passes import validation without writes.');

    $before=snapshot(); $valid=$service->process('products',$good); if (!$valid['valid']) echo json_encode($valid['errors']).PHP_EOL;
    check($valid['valid'] && $valid['count']===3 && snapshot()===$before,'Preflight accepts ordinary, sealed kg and liquid products without any writes.');
    $duplicate=$plain; $duplicate[6]=$sealed[16];
    check(!$service->process('products',dataFile('products',[$sealed,$duplicate]))['valid'],'Import rejects a single-unit barcode already used for a sealed package in the workbook.');
    $bad=$plain; $bad[0]='';
    $broken=dataFile('products',[$plain,$sealed,$bad]);
    $result=$service->process('products',$broken,true);
    check(!$result['valid'] && $result['errors'][0]['row']===4 && snapshot()===$before,'Invalid final row rejects the entire workbook before any write.');
    $bad=$sealed; $bad[0]='Bad fractional package';$bad[13]='0.5';
    check(!$service->process('products',dataFile('products',[$bad]))['valid'],'Fractional sealed opening quantities are rejected.');
    $bad=$sealed; $bad[0]='Bad required package';$bad[8]='';
    check(!$service->process('products',dataFile('products',[$bad]))['valid'],'Sealed package details are mandatory.');
    $bad=$plain;$bad[0]='Bad unit';$bad[2]='kiloz';
    check(!$service->process('products',dataFile('products',[$bad]))['valid'],'Unknown base units are rejected.');
    $bad=$plain;$bad[0]='Bad price';$bad[3]='-1';
    check(!$service->process('products',dataFile('products',[$bad]))['valid'],'Negative prices are rejected.');
    $bad=$plain;$bad[0]='Unknown category';$bad[1]='Missing';
    check(!$service->process('products',dataFile('products',[$bad]))['valid'],'Unknown category paths are rejected.');
    foreach ([
        [3,'one dollar','Price entered as words'], [4,'-2','Negative low-stock threshold'],
        [13,'-1','Negative sealed opening quantity'], [14,'0.0001','Opening quantity with excessive decimals'],
        [15,'','Missing opening cost'], [15,'-5','Negative opening cost'],
        [9,'0','Zero package capacity'], [9,'25.0001','Package capacity with excessive decimals'],
        [10,'','Missing package selling price'], [11,'invalid','Invalid default sale mode'],
        [12,'Unknown group','Unknown quantity shortcut group'],
    ] as [$field,$value,$message]) {
        $mistake=$sealed; $mistake[0]='Human mistake '.$message; $mistake[$field]=$value;
        $state=snapshot(); $result=$service->process('products',dataFile('products',[$mistake]),true);
        check(!$result['valid'] && snapshot()===$state, "$message blocks the whole file without writes.");
    }
    $state=snapshot();
    AuthContext::$user=(object)['id'=>1,'role_name'=>'Import Clerk','branch_id'=>1]; AuthContext::$permissions=['products.create'];
    check(!$service->process('products',$good,true)['valid'] && snapshot()===$state,'Product import permission alone cannot grant opening-stock adjustment permission.');
    AuthContext::$user=(object)['id'=>1,'role_name'=>'Superadmin','branch_id'=>1]; AuthContext::$permissions=[];
    $db->table('financial_periods')->insert(['name'=>'Import closed QA','start_date'=>date('Y-m-d'),'end_date'=>date('Y-m-d'),'status'=>'closed']);
    rejects(fn()=> $service->process('products',$good,true),'entire import was rolled back');
    check(snapshot()===$before,'Accounting failure rolls back products, stock, package counts and journals together.');
    $db->table('financial_periods')->emptyTable();
    $result=$service->process('products',$good,true);
    check(($result['imported']??0)===3,'Valid workbook imports all products.');
    $product=$db->table('products')->where('name','QA Bariis')->get()->getRowArray();
    $balance=(new App\Services\PackageInventoryService())->balance(1,$product);
    check($balance['sealed']===2 && $balance['opened']===3.5 && $balance['total']===53.5,'Imported opening stock preserves sealed versus opened quantities.');
    check($product['barcode']==='001234' && $product['sealed_barcode']==='001235','Text barcodes preserve leading zeros in both fields.');
    check((float)$db->table('stock_levels')->where('product_id',$db->table('products')->where('name','QA Soap')->get()->getRow('id'))->get()->getRow('quantity')===12.0,'Ordinary product opening stock uses base units.');
    $before=snapshot();
    check(!$service->process('products',$good,true)['valid'] && snapshot()===$before,'Reimporting existing names/barcodes cannot duplicate products or stock.');
    foreach(['customers','suppliers'] as $kind) {
        $rows=[['QA '. $kind.' A','+252610000000',$kind.'@example.com','Test address'],['QA '.$kind.' B','','','']];
        $bad=$rows; $bad[1][2]='not-email'; $before=snapshot();
        $result=$service->process($kind,dataFile($kind,$bad),true);
        check(!$result['valid'] && $result['errors'][0]['row']===3 && snapshot()===$before,"$kind invalid last email blocks all rows.");
        $bad=$rows; $bad[1][0]=''; $bad[1][3]='Partial row';
        check(!$service->process($kind,dataFile($kind,$bad))['valid'],"$kind missing required name is rejected.");
        $bad=$rows; $bad[1][1]=$bad[0][1];
        check(!$service->process($kind,dataFile($kind,$bad))['valid'],"$kind duplicate phones inside workbook are rejected.");
        check($service->process($kind,dataFile($kind,$rows),true)['imported']===2,"$kind valid contacts import successfully, including blank optional fields.");
        if($kind==='customers') check((float)$db->table('customers')->selectSum('balance')->get()->getRow('balance')===0.0,'Customer imports never create debt.');
        $before=snapshot();
        check(!$service->process($kind,dataFile($kind,$rows),true)['valid'] && snapshot()===$before,"$kind duplicates against the database reject the entire file.");
    }
    $many=[]; for($i=0;$i<1000;$i++) $many[]=['QA bulk '.$i,'','', ''];
    $large=dataFile('customers',$many); $before=snapshot();
    check($service->process('customers',$large)['count']===1000 && snapshot()===$before,'1,000-row workbook validates without writes.');
    check($service->process('customers',$large,true)['imported']===1000,'1,000-row workbook imports atomically.');
    $many=[];
    for($i=0;$i<1000;$i++) { $item=$plain; $item[0]='QA bulk product '.$i; $item[6]='QA-BULK-'.$i; $item[14]='0'; $item[15]=''; $many[]=$item; }
    check($service->process('products',dataFile('products',$many),true)['imported']===1000,'1,000-product workbook imports atomically through the existing product service.');
    $formula=workbook('customers',function($zip){$xml=$zip->getFromName('xl/worksheets/sheet1.xml');$xml=preg_replace('/(<(?:x:)?c\b[^>]*r="A2"[^>]*>)/','$1<f xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">1+1</f>',$xml);$zip->addFromString('xl/worksheets/sheet1.xml',$xml);});
    rejects(fn()=> $service->process('customers',$formula),'formulas are not allowed');
    $missing=workbook('customers',function($zip){$xml=$zip->getFromName('xl/worksheets/sheet1.xml');$xml=preg_replace('/<(?:x:)?c\b[^>]*r="D1".*?<\/(?:x:)?c>/s','',$xml);$zip->addFromString('xl/worksheets/sheet1.xml',$xml);});
    rejects(fn()=> $service->process('customers',$missing),'missing headers');
    $numeric=workbook('customers', function($zip) {
        $xml=$zip->getFromName('xl/worksheets/sheet1.xml');
        $xml=preg_replace('/<(?:x:)?c\b[^>]*r="B2".*?<\/(?:x:)?c>/s','<c xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" r="B2"><v>610000001</v></c>',$xml);
        $zip->addFromString('xl/worksheets/sheet1.xml',$xml);
    });
    rejects(fn()=> $service->process('customers',$numeric),'format phone as Text');
    $unsafeXml=workbook('customers',fn($zip)=>$zip->addFromString('xl/workbook.xml','<!DOCTYPE workbook [<!ENTITY injected "bad">]><workbook/>'));
    rejects(fn()=> $service->process('customers',$unsafeXml),'unsafe workbook XML');
    $damaged=workbook('customers',fn($zip)=>$zip->addFromString('xl/workbook.xml','<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"/>'));
    rejects(fn()=> $service->process('customers',$damaged),'missing its worksheet list');
    $unknown=workbook('customers', function($zip) {
        $xml=$zip->getFromName('xl/worksheets/sheet1.xml');
        $xml=preg_replace('/<\/x:row>/','<x:c r="E1" t="inlineStr"><x:is><x:t>balance</x:t></x:is></x:c></x:row>',$xml,1);
        $zip->addFromString('xl/worksheets/sheet1.xml',$xml);
    });
    rejects(fn()=> $service->process('customers',$unknown),'unknown or duplicate header');
    $oversized=workbook('customers',fn($zip)=>$zip->addFromString('extra.txt',str_repeat('x',16777217)));
    rejects(fn()=> $service->process('customers',$oversized),'expanded size');
    $unsafe=workbook('customers',fn($zip)=>$zip->addFromString('xl/externalLinks/link1.xml','x'));
    rejects(fn()=> $service->process('customers',$unsafe),'external links');
    $empty=dataFile('customers',[['','','','']]);
    rejects(fn()=> $service->process('customers',$empty),'no records');
    $badzip=tempnam(sys_get_temp_dir(),'erp-import-');$tempFiles[]=$badzip;file_put_contents($badzip,'not an excel workbook');
    rejects(fn()=> $service->process('customers',$badzip),'not a readable XLSX');
    $httpFile=dataFile('customers',[['QA HTTP import','','','']]); $state=snapshot();
    check(uploadController(null)->upload('customers')->getStatusCode()===422, 'Missing upload returns a readable validation response.');
    check(uploadController($httpFile,'delete')->upload('customers')->getStatusCode()===422, 'Unknown upload action is rejected.');
    check(uploadController($httpFile,'validate','mistake.xls')->upload('customers')->getStatusCode()===422, 'Wrong file extension is rejected on the server.');
    check(uploadController($httpFile,'validate','test.xlsx',false)->upload('customers')->getStatusCode()===403, 'Non-AJAX upload is rejected.');
    $response=uploadController($httpFile)->upload('customers'); $payload=json_decode($response->getBody(),true);
    check($payload['data']['valid'] && !isset($payload['data']['records']) && snapshot()===$state, 'HTTP validation exposes no internal records and writes nothing.');
    $response=uploadController($httpFile,'import')->upload('customers'); $payload=json_decode($response->getBody(),true);
    check(($payload['data']['imported']??0)===1, 'HTTP import saves a valid workbook.');
    $state=snapshot(); $response=uploadController($httpFile,'import')->upload('customers'); $payload=json_decode($response->getBody(),true);
    check(($payload['data']['imported']??0)===1 && snapshot()===$state, 'Retrying the same HTTP import returns its successful result without duplicate writes.');
    $before=snapshot();AuthContext::$user=(object)['id'=>1,'role_name'=>'No import access','branch_id'=>1];
    rejects(fn()=> $service->process('products',$good),'permission');
    check(snapshot()===$before,'Unauthorized imports cannot write anything.');
} finally {
    foreach (array_reverse($shadowed) as $table) $db->query("DROP TEMPORARY TABLE IF EXISTS `$table`");
    foreach ($hashes as $table=>$hash) check(hash('sha256',serialize($db->table($table)->get()->getResultArray()))===$hash, "$table real records remain unchanged.");
    foreach ($tempFiles as $path) if (is_file($path)) unlink($path);
    AuthContext::reset();
}
echo "Completed $checks workbook import checks.\n";
