<?php
namespace App\Services\Pharmacy;

use App\Services\AuditService;
use InvalidArgumentException;

final class PatientCareService
{
    public function history(int $patient, int $branch): array
    {
        $db=db_connect();
        $profile=$db->table('pharmacy_patients')->select('id,name,phone,date_of_birth,allergies')->where(['id'=>$patient,'branch_id'=>$branch])->get()->getRowArray();
        if (!$profile) throw new InvalidArgumentException('Patient not found.');
        $notes=$db->tableExists('pharmacy_care_notes') ? $db->table('pharmacy_care_notes n')->select('n.id,n.kind,n.note,n.created_at,u.name AS staff_name')->join('users u','u.id=n.user_id','left')->where(['n.patient_id'=>$patient,'n.branch_id'=>$branch])->orderBy('n.id','DESC')->limit(100)->get()->getResultArray() : [];
        $prescriptions=$db->table('pharmacy_prescriptions')->select('id,prescriber,reference,issued_on,expires_on,status')->where(['patient_id'=>$patient,'branch_id'=>$branch])->orderBy('id','DESC')->limit(100)->get()->getResultArray();
        $fills=$db->table('pharmacy_dispensings d')->select('s.invoice_no,s.sold_at,i.product_id,i.quantity,i.unit_label,i.stock_factor,i.description AS medicine_name,r.id AS prescription_id')
            ->join('pharmacy_prescriptions r','r.id=d.prescription_id')->join('sales s','s.id=d.sale_id')->join('sale_items i','i.sale_id=s.id')->join('products p','p.id=i.product_id')
            ->where(['r.patient_id'=>$patient,'r.branch_id'=>$branch,'s.branch_id'=>$branch])->orderBy('s.id','DESC')->limit(200)->get()->getResultArray();
        // Financial prices and unrelated patient records are not part of the clinical timeline.
        return ['patient'=>$profile,'notes'=>$notes,'prescriptions'=>$prescriptions,'dispensings'=>$fills];
    }

    public function addNote(array $input, int $branch, int $user): int
    {
        $patient=PharmacyInput::id($input['patient_id']??null);
        $kind=$input['kind']??null;
        if (!in_array($kind,['counselling','intervention','referral','adverse_event'],true)) throw new InvalidArgumentException('Select the note type.');
        $note=PharmacyInput::text($input,'note',5000,true);
        $key=PharmacyInput::text($input,'request_key',36,true);
        if (!preg_match('/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/iD',$key)) throw new InvalidArgumentException('A note request identifier is required.');
        $db=db_connect();
        if (!$db->tableExists('pharmacy_care_notes')) throw new InvalidArgumentException('Apply the pharmacy workspace migration before adding notes.');
        if (!$db->table('pharmacy_patients')->where(['id'=>$patient,'branch_id'=>$branch])->countAllResults()) throw new InvalidArgumentException('Patient not found.');
        $existing=$db->table('pharmacy_care_notes')->where(['branch_id'=>$branch,'user_id'=>$user,'request_key'=>$key])->get()->getRowArray();
        if ($existing) {
            if ((int)$existing['patient_id']!==$patient || $existing['kind']!==$kind || $existing['note']!==$note) throw new InvalidArgumentException('This request was already used for a different note.');
            return (int)$existing['id'];
        }
        $db->table('pharmacy_care_notes')->insert(['branch_id'=>$branch,'patient_id'=>$patient,'user_id'=>$user,'kind'=>$kind,'note'=>$note,'request_key'=>$key]);
        $id=(int)$db->insertID();
        // Do not copy sensitive clinical text to the general audit log.
        AuditService::log('patient.care_note','pharmacy','pharmacy_care_notes',$id);
        return $id;
    }
}
