<?php
namespace App\Services\Pharmacy;

use App\Services\AuditService;
use InvalidArgumentException;

/** Held tickets reserve neither stock nor money. Every checkout revalidates live state. */
final class HeldSaleService
{
    public function listing(int $branch, int $user): array
    {
        $db = db_connect();
        if (!$db->tableExists('pharmacy_held_sales')) return [];
        // A committed sale with the same UUID makes its held ticket obsolete, even after a lost response.
        $rows = $db->table('pharmacy_held_sales h')->select('h.*')->join('sales s','BINARY s.uuid=BINARY h.request_key AND s.branch_id=h.branch_id','left',false)
            ->where('h.branch_id',$branch)->where('h.user_id',$user)->where('s.id',null)->orderBy('h.id','DESC')->limit(30)->get()->getResultArray();
        foreach ($rows as &$row) $row['cart'] = json_decode($row['payload'],true,512,JSON_THROW_ON_ERROR);
        unset($row);
        foreach ($rows as &$row) unset($row['payload']);
        return $rows;
    }

    public function save(array $input, int $branch, int $user): int
    {
        $db = db_connect();
        if (!$db->tableExists('pharmacy_held_sales')) throw new InvalidArgumentException('Apply the pharmacy workspace migration before holding tickets.');
        $key = $input['request_key'] ?? '';
        if (!is_string($key) || !preg_match('/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/iD',$key)) throw new InvalidArgumentException('A hold request identifier is required.');
        $existing = $db->table('pharmacy_held_sales')->where(['branch_id'=>$branch,'user_id'=>$user,'request_key'=>$key])->get()->getRowArray();
        if ($existing && $db->table('sales')->where(['uuid'=>$key,'branch_id'=>$branch])->countAllResults()) throw new InvalidArgumentException('This held ticket has already been completed.');
        if (!is_array($input['items'] ?? null) || !$input['items'] || count($input['items'])>100) throw new InvalidArgumentException('Enter 1 to 100 medicine lines.');
        if (!$existing && $db->table('pharmacy_held_sales h')->join('sales s','BINARY s.uuid=BINARY h.request_key AND s.branch_id=h.branch_id','left',false)->where('h.branch_id',$branch)->where('h.user_id',$user)->where('s.id',null)->countAllResults() >= 30) throw new InvalidArgumentException('Resume or discard an existing held ticket first (maximum 30).');
        $items = [];
        foreach ($input['items'] as $item) {
            if (!is_array($item)) throw new InvalidArgumentException('Invalid held medicine line.');
            $product = PharmacyInput::id($item['product_id'] ?? null);
            $unit = (new MedicineUnitService())->resolve($product,$item['medicine_unit_id'] ?? null);
            MedicineUnitService::baseQuantity($unit,$item['quantity'] ?? null);
            $items[] = ['product_id'=>$product,'medicine_unit_id'=>(int)$unit['id'],'quantity'=>$item['quantity']] + (new PharmacyDiscountService())->validate($item,$unit);
        }
        $cart = ['items'=>$items];
        foreach (['customer_id','prescription_id'] as $field) if (!empty($input[$field])) $cart[$field] = PharmacyInput::id($input[$field]);
        if (isset($cart['customer_id']) && !$db->table('customers')->where('id',$cart['customer_id'])->where('deleted_at',null)->countAllResults()) throw new InvalidArgumentException('Customer not found.');
        if (isset($cart['prescription_id']) && !$db->table('pharmacy_prescriptions')->where('id',$cart['prescription_id'])->where('branch_id',$branch)->countAllResults()) throw new InvalidArgumentException('Prescription not found.');
        if ($existing) {
            $payload=json_encode($cart,JSON_THROW_ON_ERROR);
            if ($existing['payload']===$payload) return (int)$existing['id'];
            if ((int)($input['revision']??0)!==(int)$existing['revision']) throw new InvalidArgumentException('This held ticket changed. Reload it before editing.');
            $db->table('pharmacy_held_sales')->where(['id'=>$existing['id'],'revision'=>$existing['revision']])->update(['payload'=>$payload,'revision'=>(int)$existing['revision']+1]);
            if ($db->affectedRows()!==1) throw new InvalidArgumentException('Another session changed this ticket. Reload it before editing.');
            return (int)$existing['id'];
        }
        $db->table('pharmacy_held_sales')->insert(['branch_id'=>$branch,'user_id'=>$user,'request_key'=>$key,'label'=>'Ticket '.date('H:i'),'payload'=>json_encode($cart,JSON_THROW_ON_ERROR)]);
        $id = (int)$db->insertID();
        AuditService::log('sale.hold','pharmacy','pharmacy_held_sales',$id);
        return $id;
    }

    public function discard(array $input, int $branch, int $user): int
    {
        $id = PharmacyInput::id($input['id'] ?? null);
        $db = db_connect();
        $row = $db->table('pharmacy_held_sales')->where(['id'=>$id,'branch_id'=>$branch,'user_id'=>$user])->get()->getRowArray();
        if (!$row) throw new InvalidArgumentException('Held ticket not found.');
        $db->table('pharmacy_held_sales')->where(['id'=>$id,'branch_id'=>$branch,'user_id'=>$user])->delete();
        AuditService::log('sale.discard_hold','pharmacy','pharmacy_held_sales',$id);
        return $id;
    }
}
