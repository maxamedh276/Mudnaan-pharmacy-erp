<?php
namespace App\Services;
use InvalidArgumentException;

/** One receipt, with explicit per-invoice allocations, or oldest-due-first allocation. */
class CustomerCollectionService
{
    public function __construct(private readonly ReceivablesService $receivables = new ReceivablesService()) {}
    public function record(array $data, int $branchId, int $userId, string $plan): array
    {
        if (!is_string($data['uuid']??null) || !preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i',$data['uuid'])) throw new InvalidArgumentException('INVALID_UUID');
        if (!filter_var($data['customer_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])) throw new InvalidArgumentException('CUSTOMER_REQUIRED');
        if (isset($data['account_id']) && !filter_var($data['account_id'],FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])) throw new InvalidArgumentException('INVALID_CASH_ACCOUNT');
        foreach(['reference'=>120,'notes'=>255] as $key=>$max) if(isset($data[$key])&&(!is_string($data[$key])||mb_strlen($data[$key])>$max)) throw new InvalidArgumentException('INVALID_RECEIPT_DETAILS');
        foreach (['amount','discount'] as $key) {
            $value = $data[$key] ?? 0;
            if (!is_numeric($value) || !is_finite((float)$value) || (float)$value < 0 || abs((float)$value-round((float)$value,2)) > 0.000001) throw new InvalidArgumentException('INVALID_AMOUNT');
        }
        $cash = (int) round((float)($data['amount']??0)*100);
        $discount = (int) round((float)($data['discount']??0)*100);
        if ($cash <= 0 || $cash+$discount > 99999999999999) throw new InvalidArgumentException('INVALID_AMOUNT');
        $customerId = (int)($data['customer_id']??0);
        $selected = $data['allocations']??[];
        if (!is_array($selected) || count($selected)>1000) throw new InvalidArgumentException('INVALID_ALLOCATION');
        $normalized=[];
        foreach ($selected as $allocation) {
            if(!is_array($allocation)) throw new InvalidArgumentException('INVALID_ALLOCATION');
            $id=(int)($allocation['invoice_id']??0); $value=$allocation['amount']??null;
            if ($id<=0 || isset($normalized[$id]) || !is_numeric($value) || !is_finite((float)$value) || (float)$value<=0 || abs((float)$value-round((float)$value,2))>0.000001) throw new InvalidArgumentException('INVALID_ALLOCATION');
            $normalized[$id]=(int) round((float)$value*100);
        }
        ksort($normalized);
        $hash=hash('sha256',json_encode([$customerId,$cash,$discount,(int)($data['account_id']??0),(string)($data['reference']??''),(string)($data['notes']??''),$normalized,(string)($data['method']??'cash'),(int)($data['bank_account_id']??0)]));
        return FinancialTransaction::run(function($db) use($data,$branchId,$userId,$plan,$customerId,$cash,$discount,$normalized,$hash) {
            if (!$db->query('SELECT id FROM customers WHERE id=? AND deleted_at IS NULL FOR UPDATE',[$customerId])->getRowArray()) throw new InvalidArgumentException('CUSTOMER_REQUIRED');
            $existing=$db->query('SELECT * FROM customer_receipts WHERE uuid=? FOR UPDATE',[$data['uuid']??''])->getRowArray();
            if ($existing) {
                if ((int)$existing['branch_id']!==$branchId || !hash_equals((string)$existing['collection_request_hash'],$hash)) throw new InvalidArgumentException('DUPLICATE_REQUEST');
                $existing['id']=(int)$existing['id'];
                $existing['amount']=(float)$existing['amount'];
                $existing['discount']=(float)$existing['discount'];
                return $existing;
            }
            $invoices=$db->query("SELECT * FROM customer_invoices WHERE customer_id=? AND branch_id=? AND deleted_at IS NULL AND status IN ('unpaid','partial') AND total>paid_total ORDER BY invoice_date,id FOR UPDATE",[$customerId,$branchId])->getResultArray();
            $remaining=$cash+$discount; $allocations=[];
            foreach ($invoices as $invoice) {
                $id=(int)$invoice['id']; $due=(int)round(((float)$invoice['total']-(float)$invoice['paid_total'])*100);
                $take=$normalized ? ($normalized[$id]??0) : min($due,$remaining);
                if ($take>$due || $take>$remaining) throw new InvalidArgumentException('OVERPAYMENT');
                if ($take>0) { $allocations[]=['invoice'=>$invoice,'settled'=>$take]; $remaining-=$take; }
            }
            if ($remaining!==0 || ($normalized && count($allocations)!==count($normalized))) throw new InvalidArgumentException('INVALID_ALLOCATION');
            // Reuse cash posting and customer-balance rules exactly once.
            $header=$data; unset($header['invoice_id'],$header['allocations']);
            $receipt=$this->receivables->recordReceipt($header,$branchId,$userId,$plan,true);
            $cashLeft=$cash;
            foreach ($allocations as $allocation) {
                $invoice=$allocation['invoice']; $paid=min($cashLeft,$allocation['settled']); $waived=$allocation['settled']-$paid; $cashLeft-=$paid;
                $db->table('customer_receipt_allocations')->insert(['receipt_id'=>$receipt['id'],'invoice_id'=>$invoice['id'],'amount'=>$paid/100,'discount'=>$waived/100]);
                $newPaid=round((float)$invoice['paid_total']+$allocation['settled']/100,2);
                $db->table('customer_invoices')->where('id',$invoice['id'])->update(['paid_total'=>$newPaid,'status'=>$newPaid >= (float)$invoice['total']?'paid':'partial']);
                if ($invoice['sale_id']) $db->table('sales')->where('id',$invoice['sale_id'])->where('branch_id',$branchId)->update(['paid_total'=>$newPaid,'status'=>$newPaid >= (float)$invoice['total']?'completed':'partial']);
            }
            $db->table('customer_receipts')->where('id',$receipt['id'])->update(['collection_request_hash'=>$hash]);
            return $receipt;
        });
    }
}
