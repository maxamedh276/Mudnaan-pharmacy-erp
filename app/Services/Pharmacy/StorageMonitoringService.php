<?php
namespace App\Services\Pharmacy;

use App\Services\AuditService;
use InvalidArgumentException;

/** Manual observations, not sensor integration or an automatic medicine-release decision. */
final class StorageMonitoringService
{
    public function data(int $branch): array
    {
        $db=db_connect();
        if (!$db->tableExists('pharmacy_storage_locations')) return ['locations'=>[],'readings'=>[]];
        return [
            'locations'=>$db->table('pharmacy_storage_locations')->where('branch_id',$branch)->orderBy('name')->get()->getResultArray(),
            'readings'=>$db->table('pharmacy_temperature_logs t')->select('t.*,l.name AS location_name,u.name AS staff_name')->join('pharmacy_storage_locations l','l.id=t.location_id')->join('users u','u.id=t.user_id','left')->where('t.branch_id',$branch)->orderBy('t.measured_at','DESC')->orderBy('t.id','DESC')->limit(200)->get()->getResultArray(),
        ];
    }

    private function temperature(mixed $value): float
    {
        if (!is_numeric($value) || !is_finite((float)$value) || (float)$value < -100 || (float)$value > 100 || abs((float)$value-round((float)$value,2))>0.000001) throw new InvalidArgumentException('Enter a temperature from -100 to 100 °C with up to two decimals.');
        return round((float)$value,2);
    }

    public function location(array $input, int $branch, int $user): int
    {
        $name=PharmacyInput::text($input,'name',100,true);
        $kind=$input['kind']??null;
        if (!in_array($kind,['shelf','room','fridge','freezer'],true)) throw new InvalidArgumentException('Select the storage type.');
        $min=$this->temperature($input['min_c']??null);$max=$this->temperature($input['max_c']??null);
        if ($min >= $max) throw new InvalidArgumentException('Minimum temperature must be lower than maximum.');
        $db=db_connect();
        if (!$db->tableExists('pharmacy_storage_locations')) throw new InvalidArgumentException('Apply the pharmacy workspace migration first.');
        $existing=$db->table('pharmacy_storage_locations')->where(['branch_id'=>$branch,'name'=>$name])->get()->getRowArray();
        if ($existing) {
            if ($existing['kind']!==$kind || (float)$existing['min_c']!==$min || (float)$existing['max_c']!==$max) throw new InvalidArgumentException('This location already has different limits. Use a distinct name for a new monitoring configuration.');
            return (int)$existing['id'];
        }
        $db->table('pharmacy_storage_locations')->insert(['branch_id'=>$branch,'name'=>$name,'kind'=>$kind,'min_c'=>$min,'max_c'=>$max,'created_by'=>$user]);
        $id=(int)$db->insertID();AuditService::log('storage.create','pharmacy','pharmacy_storage_locations',$id);return $id;
    }

    public function reading(array $input, int $branch, int $user): int
    {
        $id=PharmacyInput::id($input['location_id']??null);
        $temperature=$this->temperature($input['temperature_c']??null);
        $measured=PharmacyInput::text($input,'measured_at',16,true);
        $date=\DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$measured);
        if (!$date || $date->format('Y-m-d\TH:i')!==$measured || $date>new \DateTimeImmutable('+5 minutes')) throw new InvalidArgumentException('Enter a valid observation time that is not in the future.');
        $key=PharmacyInput::text($input,'request_key',36,true);
        if (!preg_match('/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/iD',$key)) throw new InvalidArgumentException('A reading request identifier is required.');
        $db=db_connect();
        if (!$db->tableExists('pharmacy_storage_locations')) throw new InvalidArgumentException('Apply the pharmacy workspace migration first.');
        $location=$db->table('pharmacy_storage_locations')->where(['id'=>$id,'branch_id'=>$branch])->get()->getRowArray();
        if (!$location) throw new InvalidArgumentException('Storage location not found.');
        $excursion=$temperature<(float)$location['min_c'] || $temperature>(float)$location['max_c'];
        $action=PharmacyInput::text($input,'action_taken',2000,$excursion);
        $existing=$db->table('pharmacy_temperature_logs')->where(['branch_id'=>$branch,'user_id'=>$user,'request_key'=>$key])->get()->getRowArray();
        if ($existing) {
            if ((int)$existing['location_id']!==$id || (float)$existing['temperature_c']!==$temperature || $existing['measured_at']!==$date->format('Y-m-d H:i:s') || $existing['action_taken']!==$action) throw new InvalidArgumentException('This request already recorded a different observation.');
            return (int)$existing['id'];
        }
        $db->table('pharmacy_temperature_logs')->insert(['branch_id'=>$branch,'location_id'=>$id,'user_id'=>$user,'measured_at'=>$date->format('Y-m-d H:i:s'),'temperature_c'=>$temperature,'min_c'=>$location['min_c'],'max_c'=>$location['max_c'],'excursion'=>$excursion?1:0,'action_taken'=>$action,'request_key'=>$key]);
        $result=(int)$db->insertID();AuditService::log('storage.temperature','pharmacy','pharmacy_temperature_logs',$result);return $result;
    }
}
