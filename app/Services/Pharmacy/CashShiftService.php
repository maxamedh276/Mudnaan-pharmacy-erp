<?php
namespace App\Services\Pharmacy;

use App\Services\AuditService;
use App\Services\FinancialTransaction;
use InvalidArgumentException;

/** Drawer counts are observations; discrepancies never silently create accounting journals. */
final class CashShiftService
{
    public function accounts(): array
    {
        return db_connect()->table('pos_payment_methods m')->distinct()->select('a.id,a.name,a.code')
            ->join('accounts a','a.id=m.account_id')->where(['m.type'=>'cash','m.is_active'=>1,'a.is_active'=>1,'a.is_postable'=>1])->where('a.deleted_at',null)->orderBy('a.name')->get()->getResultArray();
    }

    private function amount(mixed $value): float
    {
        if (!is_numeric($value)||!is_finite((float)$value)||(float)$value<0||(float)$value>9999999999.99||abs((float)$value-round((float)$value,2))>0.000001) throw new InvalidArgumentException('Enter a non-negative cash amount with up to two decimals.');
        return round((float)$value,2);
    }

    public function expected(array $shift,?int $end=null): float
    {
        $query=db_connect()->table('journal_entry_lines l')->select('COALESCE(SUM(l.debit-l.credit),0) AS movement',false)->join('journal_entries e','e.id=l.journal_entry_id')
            ->where(['e.branch_id'=>$shift['branch_id'],'e.created_by'=>$shift['user_id'],'l.account_id'=>$shift['account_id']])->whereIn('e.status',['posted','void'])->where('e.id >',$shift['opening_entry_id']);
        if ($end!==null) $query->where('e.id <=',$end);
        return round((float)$shift['opening_cash']+(float)$query->get()->getRow('movement'),2);
    }

    public function data(int $branch,int $user,bool $manager=false): array
    {
        $db=db_connect();$rows=[];$active=null;
        if ($db->tableExists('pharmacy_cash_shifts')) {
            $query=$db->table('pharmacy_cash_shifts s')->select('s.*,a.name AS account_name,u.name AS staff_name')->join('accounts a','a.id=s.account_id')->join('users u','u.id=s.user_id')->where('s.branch_id',$branch);
            if (!$manager) $query->where('s.user_id',$user);
            $rows=$query->orderBy('s.id','DESC')->limit(100)->get()->getResultArray();
            foreach ($rows as $row) if ((int)$row['user_id']===$user && (int)$row['active_marker']===1) {$active=$row;$active['expected_cash']=$this->expected($row);break;}
            if (!$active) {
                $active=$db->table('pharmacy_cash_shifts')->where(['branch_id'=>$branch,'user_id'=>$user,'active_marker'=>1])->get()->getRowArray();
                if ($active) $active['expected_cash']=$this->expected($active);
            }
        }
        return ['accounts'=>$this->accounts(),'shifts'=>$rows,'active'=>$active];
    }

    public function open(array $input,int $branch,int $user): int
    {
        if (\App\Libraries\AuthContext::plan()!=='premium') throw new InvalidArgumentException('Cash reconciliation requires integrated accounting to be enabled.');
        $cash=$this->amount($input['opening_cash']??null);$account=PharmacyInput::id($input['account_id']??null);
        $key=PharmacyInput::text($input,'request_key',36,true);
        if (!preg_match('/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/iD',$key)) throw new InvalidArgumentException('An opening request identifier is required.');
        if (!in_array($account,array_map('intval',array_column($this->accounts(),'id')),true)) throw new InvalidArgumentException('Select an active configured cash account.');
        return FinancialTransaction::run(function($db)use($branch,$user,$cash,$account,$key){
            $db->query('INSERT IGNORE INTO pharmacy_cash_shift_locks(branch_id,user_id) VALUES (?,?)',[$branch,$user]);
            $db->query('SELECT user_id FROM pharmacy_cash_shift_locks WHERE branch_id=? AND user_id=? FOR UPDATE',[$branch,$user]);
            $existing=$db->table('pharmacy_cash_shifts')->where(['branch_id'=>$branch,'user_id'=>$user,'request_key'=>$key])->get()->getRowArray();
            if ($existing) {
                if ((float)$existing['opening_cash']!==$cash || (int)$existing['account_id']!==$account) throw new InvalidArgumentException('This request opened a different shift.');
                return (int)$existing['id'];
            }
            if ($db->table('pharmacy_cash_shifts')->where(['branch_id'=>$branch,'user_id'=>$user,'active_marker'=>1])->countAllResults()) throw new InvalidArgumentException('Close your active cash shift before opening another.');
            $last=(int)$db->table('journal_entries')->selectMax('id')->get()->getRow('id');
            $db->table('pharmacy_cash_shifts')->insert(['branch_id'=>$branch,'user_id'=>$user,'account_id'=>$account,'request_key'=>$key,'opening_cash'=>$cash,'opening_entry_id'=>$last,'opened_at'=>date('Y-m-d H:i:s')]);
            $id=(int)$db->insertID();AuditService::log('cash_shift.open','pharmacy','pharmacy_cash_shifts',$id);return $id;
        });
    }

    public function close(array $input,int $branch,int $user): int
    {
        $id=PharmacyInput::id($input['id']??null);$counted=$this->amount($input['counted_cash']??null);
        $note=PharmacyInput::text($input,'close_note',1000);
        return FinancialTransaction::run(function($db)use($id,$counted,$note,$branch,$user){
            $db->query('SELECT user_id FROM pharmacy_cash_shift_locks WHERE branch_id=? AND user_id=? FOR UPDATE',[$branch,$user]);
            $shift=$db->table('pharmacy_cash_shifts')->where(['id'=>$id,'branch_id'=>$branch,'user_id'=>$user])->get()->getRowArray();
            if (!$shift) throw new InvalidArgumentException('Cash shift not found.');
            if ($shift['closed_at']!==null) {
                if ((float)$shift['counted_cash']!==$counted || $shift['close_note']!==$note) throw new InvalidArgumentException('This shift is already closed with a different count.');
                return $id;
            }
            $end=(int)$db->table('journal_entries')->selectMax('id')->get()->getRow('id');
            $expected=$this->expected($shift,$end);$variance=round($counted-$expected,2);
            if ($variance!=0.0 && $note===null) throw new InvalidArgumentException('Explain the cash-count difference before closing.');
            $db->table('pharmacy_cash_shifts')->where('id',$id)->update(['closing_entry_id'=>$end,'expected_cash'=>$expected,'counted_cash'=>$counted,'variance'=>$variance,'close_note'=>$note,'closed_at'=>date('Y-m-d H:i:s'),'active_marker'=>null]);
            AuditService::log('cash_shift.close','pharmacy','pharmacy_cash_shifts',$id,after:['expected'=>$expected,'counted'=>$counted,'variance'=>$variance]);return $id;
        });
    }

    /** Call inside the posting transaction, before allocating journal IDs. */
    public static function guardPosting(int $branch,?int $user,array $accountIds): void
    {
        if (!$user) return;$db=db_connect();
        if (!$db->tableExists('pharmacy_cash_shift_locks')) return;
        $mutex=$db->query('SELECT user_id FROM pharmacy_cash_shift_locks WHERE branch_id=? AND user_id=? FOR UPDATE',[$branch,$user])->getRowArray();
        if (!$mutex) return; // Existing installations opt in when a user opens their first shift.
        $cashAccounts=array_map('intval',array_column((new self())->accounts(),'id'));
        $cashLines=array_intersect(array_map('intval',$accountIds),$cashAccounts);
        if (!$cashLines) return;
        $shift=$db->table('pharmacy_cash_shifts')->where(['branch_id'=>$branch,'user_id'=>$user,'active_marker'=>1])->get()->getRowArray();
        if (!$shift) throw new InvalidArgumentException('Open a cash shift before posting cash transactions.');
        foreach ($cashLines as $account) if ($account!==(int)$shift['account_id']) throw new InvalidArgumentException('Use the cash account selected for your active shift.');
    }
}
