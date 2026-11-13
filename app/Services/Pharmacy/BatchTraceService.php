<?php
namespace App\Services\Pharmacy;

use InvalidArgumentException;

/** A recall trace includes opened children of the selected original batch. */
final class BatchTraceService
{
    public function trace(int $id,int $branch,bool $patients,int $page=1): array
    {
        $all=(new MedicineBatchService())->batches($branch);$byId=array_column($all,null,'id');
        $batch=$byId[$id]??null;if (!$batch) throw new InvalidArgumentException('Batch not found.');
        $related=array_values(array_filter($all,static fn($b)=>(int)$b['id']===$id || (int)($b['source_batch_id']??0)===$id));
        $ids=array_map('intval',array_column($related,'id'));
        $query=static function()use($ids,$branch){return db_connect()->table('stock_movements m')->join('sales s','s.id=m.ref_id AND s.branch_id=m.branch_id')->where('m.branch_id',$branch)->where('m.ref_type','sale')->where('m.qty_change <',0)->whereIn('m.batch_id',$ids);};
        $total=(int)$query()->select('COUNT(DISTINCT s.id) AS total',false)->get()->getRow('total');
        $sales=$query()->select("s.id,s.invoice_no,s.sold_at,s.status,COALESCE(c.name,'Walk-in') AS customer_name,c.phone AS customer_phone,SUM(-m.qty_change) AS base_quantity")
            ->join('customers c','c.id=s.customer_id','left');
        if ($patients) $sales->select('p.name AS patient_name,p.phone AS patient_phone')->join('pharmacy_dispensings d','d.sale_id=s.id','left')->join('pharmacy_prescriptions r','r.id=d.prescription_id AND r.branch_id=s.branch_id','left')->join('pharmacy_patients p','p.id=r.patient_id AND p.branch_id=r.branch_id','left');
        $page=max(1,min(10000,$page));
        return ['batch'=>$batch,'related_lots'=>$related,'sales'=>$sales->groupBy('s.id')->orderBy('s.id','DESC')->limit(50,($page-1)*50)->get()->getResultArray(),'total'=>$total,'page'=>$page,'pages'=>max(1,(int)ceil($total/50)),'patient_access'=>$patients];
    }
}
