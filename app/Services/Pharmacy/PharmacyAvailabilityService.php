<?php
namespace App\Services\Pharmacy;

/** One availability calculation for POS, reports and post-checkout refreshes. */
final class PharmacyAvailabilityService
{
    public function catalog(int $branch,?array $products=null): array
    {
        $medicines=(new PharmacyService())->medicines('',$products);
        $batches=(new MedicineBatchService())->batches($branch,null,$products);
        $physicalQuery=db_connect()->table('stock_levels')->select('product_id,quantity')->where('branch_id',$branch);
        if ($products!==null) {
            if (!$products) return ['medicines'=>[],'batches'=>[]];
            $physicalQuery->whereIn('product_id',$products);
        }
        $physical=array_column($physicalQuery->get()->getResultArray(),'quantity','product_id');
        $pools=[];
        foreach ($batches as $batch) if (MedicineBatchService::sellable($batch,date('Y-m-d'))) {
            $id=$batch['product_id'];$state=empty($batch['opened_pack_id'])?'sealed':'opened';
            $pools[$id]['any']=($pools[$id]['any']??0)+(float)$batch['quantity'];
            $pools[$id][$state]=($pools[$id][$state]??0)+(float)$batch['quantity'];
        }
        $tax=(new \App\Services\TaxRateService())->active();
        foreach ($medicines as &$medicine) {
            $id=$medicine['product_id'];$ready=(bool)$medicine['is_active']&&(!isset($medicine['clinical_verified'])||(bool)$medicine['clinical_verified']);
            $medicine['physical_quantity']=(float)($physical[$id]??0);
            $medicine['available_quantity']=$ready?round(min($pools[$id]['any']??0,max(0,$medicine['physical_quantity'])),3):0;
            $medicine['tax_rate']=$tax===null?(float)$medicine['tax_rate']:(float)$tax['percentage'];
            foreach ($medicine['units'] as &$unit) {
                $pool=$unit['pharmacy_pack_state']??'any';
                $available=$ready?max(0,min($pools[$id][$pool]??0,$medicine['physical_quantity'])):0;
                $unit['available_quantity']=(float)$unit['factor']>0 ? (empty($unit['fractional'])?floor($available/(float)$unit['factor']+0.000000001):round($available/(float)$unit['factor'],3)) : 0;
            }
            unset($unit);
        }
        unset($medicine);
        return compact('medicines','batches');
    }
}
