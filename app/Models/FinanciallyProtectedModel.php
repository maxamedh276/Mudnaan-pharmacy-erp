<?php

namespace App\Models;

use App\Services\FinancialTransaction;
use CodeIgniter\HTTP\Exceptions\HTTPException;
use CodeIgniter\Model;

/** Keep unsettled money and inventory visible until the underlying obligations are resolved. */
abstract class FinanciallyProtectedModel extends Model
{
    public function delete($id = null, bool $purge = false)
    {
        $ids = is_array($id) ? $id : [$id];
        foreach ($ids as $recordId) {
            if (!filter_var($recordId, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]])) {
                throw new HTTPException('Select a specific record to delete.', 422);
            }
        }
        sort($ids);
        return FinancialTransaction::run(function ($database) use ($ids, $id, $purge) {
            foreach ($ids as $recordId) {
                // Table names come from the three model definitions, never request input.
                $record = $database->query('SELECT * FROM '.$this->table.' WHERE id=? FOR UPDATE', [$recordId])->getRowArray();
                if (!$record) continue;
                $blocked = false;
                if ($this->table === 'customers') {
                    $blocked = abs((float)$record['balance']) > 0.001
                        || $database->table('customer_invoices')->where('customer_id',$recordId)->whereIn('status',['unpaid','partial'])->where('total > paid_total',null,false)->countAllResults()>0
                        || $database->table('customer_deposits')->where('customer_id',$recordId)->where('status','active')->where('amount > used_amount',null,false)->countAllResults()>0;
                } elseif ($this->table === 'suppliers') {
                    $blocked = $database->table('supplier_bills')->where('supplier_id',$recordId)->whereIn('status',['unpaid','partial'])->where('total > paid_total',null,false)->countAllResults()>0
                        || $database->table('purchases')->where('supplier_id',$recordId)->whereIn('status',['pending','partially_received'])->countAllResults()>0;
                } elseif ($this->table === 'products') {
                    $blocked = $database->table('stock_levels')->where('product_id',$recordId)->where('quantity !=',0)->countAllResults()>0
                        || $database->table('purchase_items pi')->join('purchases p','p.id=pi.purchase_id')->where('pi.product_id',$recordId)->whereIn('p.status',['pending','partially_received'])->where('pi.quantity > pi.quantity_received',null,false)->countAllResults()>0;
                }
                if ($blocked) throw new HTTPException('Resolve outstanding balances, deposits, stock, and open orders before deleting this record.',422);
            }
            return parent::delete($id,$purge);
        });
    }
}
