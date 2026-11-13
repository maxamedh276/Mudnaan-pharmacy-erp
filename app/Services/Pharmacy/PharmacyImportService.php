<?php
namespace App\Services\Pharmacy;

use App\Libraries\AuthContext;
use App\Services\FinancialTransaction;
use InvalidArgumentException;

/** Create-only onboarding, with preflight and one transaction for catalog plus opening batches. */
final class PharmacyImportService
{
    public const HEADERS=['name','category','generic_name','strength','dosage_form','base_unit','selling_units','barcode','manufacturer','storage_instructions','prescription_required','reorder_level','opening_quantity','base_unit_cost','batch_no','expiry_date'];

    public function parse(string $csv): array
    {
        if ($csv==='' || strlen($csv)>2*1024*1024 || !mb_check_encoding($csv,'UTF-8')) throw new InvalidArgumentException('Choose a UTF-8 CSV up to 2 MB.');
        $stream=fopen('php://temp','w+');fwrite($stream,preg_replace('/^\xEF\xBB\xBF/','',$csv));rewind($stream);
        try {
            $headers=fgetcsv($stream,0,',','"','');
            if ($headers!==self::HEADERS) throw new InvalidArgumentException('Use the pharmacy CSV template with its original column headings.');
            $rows=[];
            while (($cells=fgetcsv($stream,0,',','"',''))!==false) {
                if ($cells===[null] || !array_filter($cells,static fn($v)=>trim((string)$v)!=='')) continue;
                if (count($cells)!==count($headers)) throw new InvalidArgumentException('CSV row '.(count($rows)+2).' has an incorrect column count.');
                $rows[]=array_combine($headers,array_map(static fn($v)=>trim((string)$v),$cells));
                if (count($rows)>1000) throw new InvalidArgumentException('Import at most 1,000 medicines at a time.');
            }
            if (!$rows) throw new InvalidArgumentException('The CSV contains no medicines.');
            return $rows;
        } finally { fclose($stream); }
    }

    private function hash(array $rows): string { return hash('sha256',json_encode($rows,JSON_THROW_ON_ERROR)); }

    private function existing(string $hash,int $branch): ?array
    {
        $db=db_connect();
        return $db->tableExists('pharmacy_imports') ? $db->table('pharmacy_imports')->where(['branch_id'=>$branch,'file_hash'=>$hash])->get()->getRowArray() : null;
    }

    public function preview(string $csv,int $branch): array
    {
        $rows=$this->parse($csv);$hash=$this->hash($rows);$existing=$this->existing($hash,$branch);
        if ($existing) return ['hash'=>$hash,'already_imported'=>true,'import_id'=>(int)$existing['id'],'valid_count'=>(int)$existing['medicine_count'],'errors'=>[],'records'=>[]];
        $definitions=new PharmacyDefinitionService();$unitDefinitions=$definitions->units();
        $categories=[];
        foreach ((new \App\Services\Web\InventoryCatalogService())->categories() as $category) if ($category['selectable']) $categories[mb_strtolower($category['path'])][]=(int)$category['id'];
        $names=[];foreach (db_connect()->table('products')->select('name')->where('deleted_at',null)->get()->getResultArray() as $p) $names[mb_strtolower($p['name'])]=true;
        $barcodes=[];$errors=[];$records=[];
        foreach ($rows as $index=>$row) {
            try {
                $row['name']=PharmacyInput::text($row,'name',180,true);
                $name=mb_strtolower($row['name']);
                if (isset($names[$name])) throw new InvalidArgumentException('Medicine name already exists in the catalog or this file. Use a distinct product name for each strength/pack variant.');
                $names[$name]=true;
                $matches=$categories[mb_strtolower($row['category'])]??[];
                if (count($matches)!==1) throw new InvalidArgumentException('Category must match one active category path. Create categories before importing.');
                $row['category_id']=$matches[0];
                $profile=PharmacyInput::medicine($row);
                if (!isset($unitDefinitions[$row['base_unit']])) throw new InvalidArgumentException('Register the base unit before importing.');
                $row['units']=[];$unitNames=[];$baseFound=false;
                foreach (explode(';',$row['selling_units']) as $spec) {
                    if (!preg_match('/^([^=@~#]+)=([0-9.]+)@([0-9.]+)(?:~(any|sealed|opened))?(?:#([^#]+))?$/D',trim($spec),$parts)) throw new InvalidArgumentException('Selling units use name=factor@price, separated by semicolons. Optional: ~opened or ~sealed and #barcode.');
                    $unitName=strtolower(trim($parts[1]));$definition=$unitDefinitions[$unitName]??null;
                    if (!$definition || isset($unitNames[$unitName])) throw new InvalidArgumentException('Use distinct, registered selling units.');
                    $unitNames[$unitName]=true;$factor=PharmacyInput::quantity($parts[2]);
                    if (empty($unitDefinitions[$row['base_unit']]['fractional']) && floor($factor)!==$factor) throw new InvalidArgumentException('Countable base units require whole conversion factors.');
                    if (in_array($definition['family'],['mass','volume'],true) && $definition['family']!==$unitDefinitions[$row['base_unit']]['family']) throw new InvalidArgumentException('Volume/mass family does not match the base unit.');
                    $price=$parts[3];if (!is_numeric($price)||!is_finite((float)$price)||(float)$price<0||(float)$price>9999999999.99) throw new InvalidArgumentException('Invalid unit price.');
                    if ($factor===1.0 && $unitName!==$row['base_unit']) throw new InvalidArgumentException('The factor-1 unit must match the base unit.');
                    if ($factor===1.0) {$baseFound=true;$row['sell_price']=$price;}
                    $unit=['name'=>$unitName,'factor'=>$factor,'sell_price'=>$price,'pharmacy_pack_state'=>($parts[4]??'')?:'any','barcode'=>\App\Services\ProductIdentifierService::normalizeBarcode($parts[5]??null)];
                    $row['units'][]=$unit;
                }
                if (!$baseFound || count($row['units'])>30) throw new InvalidArgumentException('Include the base unit with factor 1; at most 30 units.');
                $row['barcode']=\App\Services\ProductIdentifierService::normalizeBarcode($row['barcode']);
                $rowBarcodes=[];foreach ($row['units'] as $u) if ($u['barcode']!==null) {
                    if (in_array($u['barcode'],$rowBarcodes,true) || ($row['barcode']===$u['barcode'] && (float)$u['factor']!==1.0)) throw new InvalidArgumentException('Each barcode must identify exactly one medicine selling unit.');
                    $rowBarcodes[]=$u['barcode'];
                }
                if ($row['barcode']!==null) $rowBarcodes[]=$row['barcode'];
                foreach (array_unique($rowBarcodes) as $barcode) {
                    if (isset($barcodes[$barcode]) || db_connect()->table('products')->groupStart()->where('barcode',$barcode)->orWhere('sealed_barcode',$barcode)->groupEnd()->countAllResults() || db_connect()->table('pharmacy_medicine_units')->where('barcode',$barcode)->countAllResults()) throw new InvalidArgumentException('Barcode already exists in the catalog or another medicine in this file.');
                    $barcodes[$barcode]=true;
                }
                $row['reorder_level']=$row['reorder_level']===''?10:$row['reorder_level'];
                if (!is_numeric($row['reorder_level']) || !is_finite((float)$row['reorder_level']) || (float)$row['reorder_level']<0 || (float)$row['reorder_level']>2147483647 || abs((float)$row['reorder_level']-round((float)$row['reorder_level'],3))>0.0000001) throw new InvalidArgumentException('Invalid low-stock threshold (up to three decimals).');
                $quantity=$row['opening_quantity']===''?0:$row['opening_quantity'];
                if (!is_numeric($quantity) || !is_finite((float)$quantity) || (float)$quantity<0) throw new InvalidArgumentException('Opening quantity must be zero or positive base units.');
                if ((float)$quantity>0) {
                    if (!AuthContext::can('pharmacy.stock.manage')) throw new InvalidArgumentException('Opening balances require stock-management permission.');
                    MedicineUnitService::baseQuantity(['factor'=>1,'fractional'=>$unitDefinitions[$row['base_unit']]['fractional']],$quantity);
                    PharmacyInput::text($row,'batch_no',80,true);$expiry=PharmacyInput::date($row,'expiry_date',true);
                    if ($expiry<date('Y-m-d')) throw new InvalidArgumentException('Opening batch expiry must not be in the past.');
                    if (!is_numeric($row['base_unit_cost']) || !is_finite((float)$row['base_unit_cost']) || (float)$row['base_unit_cost']<0 || (float)$row['base_unit_cost']>9999999999.99) throw new InvalidArgumentException('Enter the opening cost per base unit.');
                }
                $row['opening_quantity']=$quantity;$records[]=$row+$profile;
            } catch (InvalidArgumentException $e) { $errors[]=['row'=>$index+2,'medicine'=>$row['name']??'','message'=>$e->getMessage()]; }
        }
        return ['hash'=>$hash,'already_imported'=>false,'valid_count'=>count($records),'errors'=>$errors,'records'=>$records];
    }

    public function commit(string $csv,int $branch,int $user,string $plan): int
    {
        $db=db_connect();
        if (!$db->tableExists('pharmacy_imports')) throw new InvalidArgumentException('Apply the pharmacy import migration first.');
        $lock='pharmacy-import-'.substr(hash('sha256',$db->getDatabase().':'.$branch),0,40);
        if ((int)$db->query('SELECT GET_LOCK(?,10) acquired',[$lock])->getRow('acquired')!==1) throw new InvalidArgumentException('Another pharmacy import is running. Try again.');
        try {
            return FinancialTransaction::run(function($db)use($csv,$branch,$user,$plan){
                $preview=$this->preview($csv,$branch);
                if ($preview['already_imported']) return $preview['import_id'];
                if ($preview['errors']) throw new InvalidArgumentException('Correct all preview errors before importing. No records were saved.');
                foreach ($preview['records'] as $index=>$row) {
                    // Deterministic per-file request keys make transport retries safe.
                    $hex=substr(hash('sha256',$preview['hash'].':'.$branch.':'.$index),0,32);
                    $key=substr($hex,0,8).'-'.substr($hex,8,4).'-'.substr($hex,12,4).'-'.substr($hex,16,4).'-'.substr($hex,20);
                    $row['creation_request_key']=$key;$product=(new PharmacyService())->saveMedicine($row);
                    if ((float)$row['opening_quantity']>0) {
                        $base=$db->table('pharmacy_medicine_units')->where('product_id',$product)->where('name',$row['base_unit'])->get()->getRowArray();
                        (new PharmacyOperationsService())->stock(['product_id'=>$product,'medicine_unit_id'=>$base['id'],'quantity'=>$row['opening_quantity'],'unit_cost'=>$row['base_unit_cost'],'batch_no'=>$row['batch_no'],'expiry_date'=>$row['expiry_date'],'action'=>'receive','reason'=>'Pharmacy CSV opening stock','request_key'=>$key],$branch,$user,$plan);
                    }
                }
                $db->table('pharmacy_imports')->insert(['branch_id'=>$branch,'user_id'=>$user,'file_hash'=>$preview['hash'],'medicine_count'=>$preview['valid_count']]);
                $id=(int)$db->insertID();\App\Services\AuditService::log('pharmacy.import','pharmacy','pharmacy_imports',$id);return $id;
            });
        } finally { $db->query('SELECT RELEASE_LOCK(?)',[$lock]); }
    }
}
