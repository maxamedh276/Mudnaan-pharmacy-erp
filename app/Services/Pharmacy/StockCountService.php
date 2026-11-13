<?php
namespace App\Services\Pharmacy;

use App\Services\AuditService;
use App\Services\FinancialTransaction;
use App\Services\InventoryService;
use App\Services\AccountingService;
use InvalidArgumentException;

final class StockCountService
{
    public function listing(int $branch): array
    {
        $db=db_connect();
        return $db->tableExists('pharmacy_count_sessions') ? $db->table('pharmacy_count_sessions c')->select('c.*,u.name AS staff_name')->join('users u','u.id=c.user_id')->where('c.branch_id',$branch)->orderBy('c.id','DESC')->limit(50)->get()->getResultArray() : [];
    }

    public function detail(int $id,int $branch): array
    {
        $db=db_connect();$count=$db->table('pharmacy_count_sessions')->where(['id'=>$id,'branch_id'=>$branch])->get()->getRowArray();
        if (!$count) throw new InvalidArgumentException('Stock count not found.');
        $lines=$db->table('pharmacy_count_lines l')->select('l.*,p.name,p.unit,b.batch_no')->join('products p','p.id=l.product_id')->join('product_batches b','b.id=l.batch_id')->where('l.count_id',$id)->orderBy('p.name')->orderBy('b.id')->get()->getResultArray();
        $definitions=(new PharmacyDefinitionService())->units();foreach ($lines as &$line) $line['fractional']=!empty($definitions[$line['unit']]['fractional']);unset($line);
        return ['count'=>$count,'lines'=>$lines];
    }

    public function start(array $input,int $branch,int $user): int
    {
        $key=PharmacyInput::text($input,'request_key',36,true);
        if (!preg_match('/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/iD',$key)) throw new InvalidArgumentException('A count request identifier is required.');
        $product=empty($input['product_id'])?null:PharmacyInput::id($input['product_id']);
        return FinancialTransaction::run(function($db)use($key,$product,$branch,$user){
            $existing=$db->table('pharmacy_count_sessions')->where(['branch_id'=>$branch,'request_key'=>$key])->get()->getRowArray();
            if ($existing) {
                if ((int)$existing['scope_product']!==(int)$product) throw new InvalidArgumentException('This request started a different count.');
                return (int)$existing['id'];
            }
            $ids=$product?[$product]:array_map('intval',array_column($db->table('pharmacy_medicines')->select('product_id')->get()->getResultArray(),'product_id'));sort($ids);
            foreach ($ids as $id) $db->query('SELECT id FROM products WHERE id=? FOR UPDATE',[$id]);
            $batches=(new MedicineBatchService())->batches($branch,$product);
            if (!$batches || count($batches)>1000) throw new InvalidArgumentException('Choose medicines with 1 to 1,000 recorded batches. Narrow the scope if needed.');
            $db->table('pharmacy_count_sessions')->insert(['branch_id'=>$branch,'user_id'=>$user,'request_key'=>$key,'scope_product'=>$product,'created_at'=>date('Y-m-d H:i:s')]);
            $id=(int)$db->insertID();foreach ($batches as $batch) $db->table('pharmacy_count_lines')->insert(['count_id'=>$id,'product_id'=>$batch['product_id'],'batch_id'=>$batch['id'],'expected_qty'=>$batch['quantity'],'last_movement_id'=>$batch['latest_movement_id']]);
            AuditService::log('stock_count.start','pharmacy','pharmacy_count_sessions',$id);return $id;
        });
    }

    public function save(array $input,int $branch,int $user): int
    {
        $id=PharmacyInput::id($input['id']??null);$submit=($input['submit']??false)===true;
        return FinancialTransaction::run(function($db)use($input,$id,$submit,$branch,$user){
            $count=$db->query('SELECT * FROM pharmacy_count_sessions WHERE id=? AND branch_id=? FOR UPDATE',[$id,$branch])->getRowArray();
            if (!$count || $count['status']!=='draft') throw new InvalidArgumentException('Only a draft stock count can be edited.');
            if ((int)$count['revision']!==(int)($input['revision']??0)) throw new InvalidArgumentException('This count changed in another session. Reload it.');
            $saved=$db->table('pharmacy_count_lines')->where('count_id',$id)->get()->getResultArray();$byId=array_column($saved,null,'id');
            $lines=$input['lines']??null;
            if (!is_array($lines) || count($lines)!==count($saved)) throw new InvalidArgumentException('Enter every line from this count.');
            $seen=[];$definitions=(new PharmacyDefinitionService())->units();
            foreach ($lines as $line) {
                if (!is_array($line)) throw new InvalidArgumentException('Invalid count line.');
                $lineId=PharmacyInput::id($line['id']??null);$old=$byId[$lineId]??null;
                if (!$old || isset($seen[$lineId])) throw new InvalidArgumentException('Count lines do not match this session.');$seen[$lineId]=true;
                $raw=$line['counted_qty']??null;$quantity=null;
                if ($raw!==null && $raw!=='') {
                    if (!is_numeric($raw)||!is_finite((float)$raw)||(float)$raw<0||(float)$raw>999999999.999||abs((float)$raw-round((float)$raw,3))>0.0000001) throw new InvalidArgumentException('Count base quantities with up to three decimals.');
                    $quantity=round((float)$raw,3);$unit=$db->table('products')->where('id',$old['product_id'])->get()->getRow('unit');
                    if (empty($definitions[$unit]['fractional']) && floor($quantity)!==$quantity) throw new InvalidArgumentException('Countable medicine units require whole quantities.');
                }
                if ($submit && $quantity===null) throw new InvalidArgumentException('Count every batch before submitting.');
                $reason=PharmacyInput::text($line,'reason',300,$submit && $quantity!==null && $quantity!==(float)$old['expected_qty']);
                $db->table('pharmacy_count_lines')->where('id',$lineId)->update(['counted_qty'=>$quantity,'reason'=>$reason]);
            }
            $db->table('pharmacy_count_sessions')->where('id',$id)->update(['status'=>$submit?'submitted':'draft','revision'=>(int)$count['revision']+1]);
            AuditService::log($submit?'stock_count.submit':'stock_count.save','pharmacy','pharmacy_count_sessions',$id);return $id;
        });
    }

    public function finish(array $input,int $branch,int $user,string $plan,bool $approve): int
    {
        $id=PharmacyInput::id($input['id']??null);
        return FinancialTransaction::run(function($db)use($id,$branch,$user,$plan,$approve){
            $count=$db->query('SELECT * FROM pharmacy_count_sessions WHERE id=? AND branch_id=? FOR UPDATE',[$id,$branch])->getRowArray();
            if (!$count) throw new InvalidArgumentException('Stock count not found.');
            if ($approve && $count['status']==='approved') return $id;
            if (!$approve && $count['status']==='cancelled') return $id;
            if (!in_array($count['status'],$approve?['submitted']:['draft','submitted'],true)) throw new InvalidArgumentException('This count cannot perform that action.');
            if ($approve) {
                $lines=$db->table('pharmacy_count_lines')->where('count_id',$id)->orderBy('product_id')->get()->getResultArray();
                $products=[];foreach ($lines as $line) $products[$line['product_id']]=$db->query('SELECT * FROM products WHERE id=? FOR UPDATE',[$line['product_id']])->getRowArray();
                $batches=array_column((new MedicineBatchService())->batches($branch),null,'id');
                $scopeBatches=array_filter($batches,static fn($b)=>empty($count['scope_product']) || (int)$b['product_id']===(int)$count['scope_product']);
                if (array_diff(array_map('intval',array_keys($scopeBatches)),array_map('intval',array_column($lines,'batch_id')))) throw new InvalidArgumentException('A new batch was added after counting started. Cancel this session and recount.');
                foreach ($lines as $line) {
                    if (!isset($batches[$line['batch_id']]) || (int)$batches[$line['batch_id']]['latest_movement_id']!==(int)$line['last_movement_id'] || (float)$batches[$line['batch_id']]['quantity']!==(float)$line['expected_qty']) throw new InvalidArgumentException('Stock moved after this count started. Cancel it and recount; no adjustments were posted.');
                    $delta=round((float)$line['counted_qty']-(float)$line['expected_qty'],3);
                    if ($delta==0.0) continue;
                    (new InventoryService())->recordMovement($branch,(int)$line['product_id'],$delta,'adjustment','pharmacy_count',$id,$user,(int)$line['batch_id']);
                    if ($plan==='premium') (new AccountingService())->postInventoryAdjustment($branch,round(abs($delta)*(float)$products[$line['product_id']]['cost_price'],2),$delta>0,(int)$line['product_id'],$user);
                }
            }
            $db->table('pharmacy_count_sessions')->where('id',$id)->update(['status'=>$approve?'approved':'cancelled','approved_by'=>$user,'approved_at'=>date('Y-m-d H:i:s'),'revision'=>(int)$count['revision']+1]);
            AuditService::log($approve?'stock_count.approve':'stock_count.cancel','pharmacy','pharmacy_count_sessions',$id);return $id;
        });
    }
}
