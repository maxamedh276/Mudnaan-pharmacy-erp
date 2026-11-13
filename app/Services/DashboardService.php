<?php
namespace App\Services;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/** Read-only operational overview, scoped to the authenticated branch and selected dates. */
class DashboardService
{
    public function get(int $branchId, string $range = '7days'): array
    {
        $today=new DateTimeImmutable('today',new DateTimeZone('Africa/Nairobi'));
        $to=$today;
        $from=match($range){'today'=>$today,'yesterday'=>$today->modify('-1 day'),'7days'=>$today->modify('-6 days'),'30days'=>$today->modify('-29 days'),'month'=>$today->modify('first day of this month'),default=>throw new InvalidArgumentException('Select a valid dashboard date range.')};
        if($range==='yesterday')$to=$from;
        $start=$from->format('Y-m-d');$end=$to->modify('+1 day')->format('Y-m-d');$db=db_connect();
        $sales=$db->query("SELECT s.id,s.invoice_no,s.sold_at,(s.grand_total-s.returned_total) grand_total,s.paid_total,s.status,COALESCE(c.name,'Walk-in') customer_name FROM sales s LEFT JOIN customers c ON c.id=s.customer_id WHERE s.branch_id=? AND s.sold_at>=? AND s.sold_at<? AND s.status IN ('completed','partial') ORDER BY s.sold_at DESC,s.id DESC",[$branchId,$start,$end])->getResultArray();
        $daily=[];for($day=$from;$day<=$to;$day=$day->modify('+1 day'))$daily[$day->format('Y-m-d')]=['date'=>$day->format('Y-m-d'),'label'=>$day->format('d M'),'amount'=>0.0];
        $status=['Paid'=>0,'On credit / partial'=>0];$revenue=0;
        foreach($sales as $sale){$revenue+=(float)$sale['grand_total'];$daily[substr($sale['sold_at'],0,10)]['amount']+=(float)$sale['grand_total'];$status[$sale['status']==='completed'?'Paid':'On credit / partial']++;}
        // Initial POS tenders are capped at each sale total so change is not counted as revenue collected.
        $payments=$db->query("SELECT s.id,s.grand_total,p.method,p.amount FROM payments p JOIN sales s ON s.id=p.sale_id WHERE s.branch_id=? AND s.sold_at>=? AND s.sold_at<? AND s.status IN ('completed','partial') ORDER BY s.id,p.id",[$branchId,$start,$end])->getResultArray();
        $mix=[];$applied=[];
        foreach($payments as $payment){$id=$payment['id'];$amount=max(0,min((float)$payment['amount'],(float)$payment['grand_total']-($applied[$id]??0)));$applied[$id]=($applied[$id]??0)+$amount;$mix[$payment['method']]=($mix[$payment['method']]??0)+$amount;}
        $receipts=$db->query("SELECT method,SUM(amount) amount FROM customer_receipts WHERE branch_id=? AND status='active' AND received_at>=? AND received_at<? GROUP BY method",[$branchId,$start,$end])->getResultArray();
        foreach($receipts as $receipt)$mix[$receipt['method']]=($mix[$receipt['method']]??0)+(float)$receipt['amount'];
        $refunds=(float)$db->query("SELECT COALESCE(SUM(refund),0) value FROM document_returns WHERE branch_id=? AND kind='sale' AND returned_at>=? AND returned_at<?",[$branchId,$start,$end])->getRow('value');
        $outstanding=(float)$db->query("SELECT COALESCE(SUM(total-paid_total),0) due FROM customer_invoices WHERE branch_id=? AND deleted_at IS NULL AND status IN ('unpaid','partial')",[$branchId])->getRow('due');
        $lowStockWhere="FROM products p LEFT JOIN stock_levels sl ON sl.product_id=p.id AND sl.branch_id=? WHERE p.is_active=1 AND p.deleted_at IS NULL AND COALESCE(sl.quantity,0)<=p.reorder_level";
        $lowStockCount=(int)$db->query('SELECT COUNT(*) n '.$lowStockWhere,[$branchId])->getRow('n');
        $lowStock=$db->query('SELECT p.name,p.sku,p.unit,COALESCE(sl.quantity,0) quantity,p.reorder_level '.$lowStockWhere.' ORDER BY quantity,p.name LIMIT 8',[$branchId])->getResultArray();
        return ['range'=>$range,'from'=>$start,'to'=>$to->format('Y-m-d'),'revenue'=>round($revenue,2),'collected'=>round(array_sum($mix)-$refunds,2),'outstanding'=>round($outstanding,2),'orders'=>count($sales),'daily'=>array_values($daily),'paymentMix'=>$mix,'statusMix'=>$status,'recentOrders'=>array_slice($sales,0,8),'lowStock'=>$lowStock,'lowStockCount'=>$lowStockCount];
    }
}
