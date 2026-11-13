<?php
namespace App\Services\Pharmacy;

use App\Services\AuditService;
use App\Services\FinancialTransaction;
use App\Services\InventoryService;
use InvalidArgumentException;

/** Opening transfers quantity between traceable lots without changing quantity, cost or journals. */
final class OpenedPackService
{
    public function open(array $input, int $branch, int $user): int
    {
        $product=PharmacyInput::id($input['product_id']??null);
        $source=PharmacyInput::id($input['source_batch_id']??null);
        $key=PharmacyInput::text($input,'request_key',36,true);
        if (!preg_match('/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/iD',$key)) throw new InvalidArgumentException('An opening request identifier is required.');
        $reason=PharmacyInput::text($input,'reason',500,true);
        $useBy=PharmacyInput::text($input,'use_by',16,true);
        $date=\DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$useBy);
        if (!$date || $date->format('Y-m-d\TH:i')!==$useBy) throw new InvalidArgumentException('Enter a valid use-by time from the approved opening instructions.');
        $hash=hash('sha256',json_encode($input,JSON_THROW_ON_ERROR));
        return FinancialTransaction::run(function($db)use($input,$product,$source,$key,$reason,$date,$hash,$branch,$user){
            $row=$db->query('SELECT * FROM products WHERE id=? AND deleted_at IS NULL FOR UPDATE',[$product])->getRowArray();
            if (!$row || !(new MedicineBatchService())->isMedicine($product)) throw new InvalidArgumentException('Select a medicine.');
            if (!$db->tableExists('pharmacy_opened_packs')) throw new InvalidArgumentException('Apply the opened-pack migration first.');
            $old=$db->table('pharmacy_opened_packs')->where(['branch_id'=>$branch,'request_key'=>$key])->get()->getRowArray();
            if ($old) {
                if (!hash_equals($old['request_hash'],$hash)) throw new InvalidArgumentException('This request already opened a different pack.');
                return (int)$old['id'];
            }
            if ($date<=new \DateTimeImmutable()) throw new InvalidArgumentException('Enter a future use-by time from the approved opening instructions.');
            $batch=null;foreach ((new MedicineBatchService())->batches($branch,$product) as $b) if ((int)$b['id']===$source) $batch=$b;
            if (!$batch || !MedicineBatchService::sellable($batch,date('Y-m-d')) || !empty($batch['opened_pack_id'])) throw new InvalidArgumentException('Select a released, unopened source batch.');
            if ($date->format('Y-m-d')>$batch['expiry_date']) throw new InvalidArgumentException('Opened use-by cannot exceed the original batch expiry.');
            $unit=(new MedicineUnitService())->resolve($product,$input['medicine_unit_id']??null);
            $definition=(new PharmacyDefinitionService())->units()[$unit['name']]??null;
            if (!$definition || $definition['family']!=='package' || $unit['pharmacy_pack_state']==='opened') throw new InvalidArgumentException('Select a whole packaging unit to open.');
            $packs=PharmacyInput::quantity($input['quantity']??null);
            if (floor($packs)!==$packs) throw new InvalidArgumentException('Open whole packs only.');
            $base=MedicineUnitService::baseQuantity($unit,$packs);
            if ($base>(float)$batch['quantity']) throw new InvalidArgumentException('The source batch has insufficient stock.');
            $child=(new MedicineBatchService())->create($branch,$product,['batch_no'=>'OPEN-'.$source.'-'.substr(hash('sha256',$key),0,16),'expiry_date'=>$date->format('Y-m-d'),'mfg_date'=>$batch['mfg_date']]);
            $db->table('pharmacy_opened_packs')->insert(['branch_id'=>$branch,'product_id'=>$product,'source_batch_id'=>$source,'batch_id'=>$child,'medicine_unit_id'=>$unit['id'],'packs'=>$packs,'base_quantity'=>$base,'opened_at'=>date('Y-m-d H:i:s'),'use_by'=>$date->format('Y-m-d H:i:s'),'reason'=>$reason,'user_id'=>$user,'request_key'=>$key,'request_hash'=>$hash]);
            $id=(int)$db->insertID();$inventory=new InventoryService();
            $inventory->recordMovement($branch,$product,-$base,'transfer','pharmacy_pack_open',$id,$user,$source);
            $inventory->recordMovement($branch,$product,$base,'transfer','pharmacy_pack_open',$id,$user,$child);
            AuditService::log('medicine.pack_open','pharmacy','pharmacy_opened_packs',$id,after:['source_batch_id'=>$source,'batch_id'=>$child,'base_quantity'=>$base,'use_by'=>$date->format('Y-m-d H:i:s')]);
            return $id;
        });
    }

    public function data(int $branch): array
    {
        $batches=(new MedicineBatchService())->batches($branch);
        return ['batches'=>$batches,'medicines'=>(new PharmacyService())->medicines(),'unitDefinitions'=>(new PharmacyDefinitionService())->units()];
    }
}
