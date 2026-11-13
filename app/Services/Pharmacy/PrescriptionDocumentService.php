<?php
namespace App\Services\Pharmacy;

use App\Services\AuditService;
use CodeIgniter\HTTP\Files\UploadedFile;
use InvalidArgumentException;

/** Private, download-only documents; neither original names nor stored bytes are public URLs. */
final class PrescriptionDocumentService
{
    public function listing(int $prescription,int $branch): array
    {
        $db=db_connect();if (!$db->tableExists('pharmacy_prescription_documents')) return [];
        return $db->table('pharmacy_prescription_documents')->select('id,original_name,mime,bytes,created_at')->where(['prescription_id'=>$prescription,'branch_id'=>$branch])->orderBy('id')->get()->getResultArray();
    }

    public function path(array $document): string
    {
        if (!preg_match('/^[a-f0-9]{32}\.(pdf|png|jpg)$/D',$document['stored_name']??'')) throw new InvalidArgumentException('Document storage reference is invalid.');
        $path=WRITEPATH.'pharmacy-documents'.DIRECTORY_SEPARATOR.$document['stored_name'];
        if (!is_file($path)) throw new InvalidArgumentException('Document file is unavailable. Restore its protected file backup.');
        if (!hash_equals($document['sha256'],hash_file('sha256',$path))) throw new InvalidArgumentException('Document integrity check failed. Restore a verified copy.');
        return $path;
    }

    public function find(int $id,int $branch): array
    {
        $document=db_connect()->table('pharmacy_prescription_documents d')->select('d.*')->join('pharmacy_prescriptions r','r.id=d.prescription_id AND r.branch_id=d.branch_id')->where(['d.id'=>$id,'d.branch_id'=>$branch])->get()->getRowArray();
        if (!$document) throw new InvalidArgumentException('Prescription document not found.');
        $this->path($document);return $document;
    }

    public function upload(int $prescription,int $branch,int $user,?UploadedFile $file): int
    {
        $db=db_connect();
        if (!$db->tableExists('pharmacy_prescription_documents')) throw new InvalidArgumentException('Apply the prescription document migration first.');
        if (!$db->table('pharmacy_prescriptions')->where(['id'=>$prescription,'branch_id'=>$branch])->countAllResults()) throw new InvalidArgumentException('Prescription not found.');
        if (!$file || !$file->isValid() || $file->hasMoved() || $file->getSize()>2*1024*1024 || $file->getSize()===0) throw new InvalidArgumentException('Upload a PDF, JPEG or PNG up to 2 MB.');
        $mime=$file->getMimeType();$extension=['application/pdf'=>'pdf','image/png'=>'png','image/jpeg'=>'jpg'][$mime]??null;
        if (!$extension) throw new InvalidArgumentException('Only PDF, JPEG and PNG documents are accepted.');
        if ($extension!=='pdf' && !@getimagesize($file->getTempName())) throw new InvalidArgumentException('The image is invalid.');
        if ($extension==='pdf') {
            $stream=fopen($file->getTempName(),'rb');$magic=fread($stream,5);fclose($stream);
            if ($magic!=='%PDF-') throw new InvalidArgumentException('The PDF file is invalid.');
        }
        $hash=hash_file('sha256',$file->getTempName());
        $existing=$db->table('pharmacy_prescription_documents')->where(['prescription_id'=>$prescription,'sha256'=>$hash])->get()->getRowArray();
        if ($existing) {$this->path($existing);return (int)$existing['id'];}
        if ($db->table('pharmacy_prescription_documents')->where('prescription_id',$prescription)->countAllResults()>=10) throw new InvalidArgumentException('This prescription already has ten documents.');
        $original=pathinfo(basename($file->getClientName()),PATHINFO_FILENAME);
        $original=preg_replace('/[\x00-\x1F\x7F\\\\\/:*?"<>|]/u','',mb_convert_encoding($original,'UTF-8','UTF-8'))??'';
        $name=mb_substr(trim($original),0,120);$name=($name===''?'Prescription':$name).'.'.$extension;
        $directory=WRITEPATH.'pharmacy-documents';
        if (!is_dir($directory)&&!mkdir($directory,0700,true)) throw new \RuntimeException('Private document storage is unavailable.');
        $stored=bin2hex(random_bytes(16)).'.'.$extension;$bytes=$file->getSize();
        $file->move($directory,$stored);
        try {
            $db->table('pharmacy_prescription_documents')->insert(['branch_id'=>$branch,'prescription_id'=>$prescription,'user_id'=>$user,'original_name'=>$name,'stored_name'=>$stored,'mime'=>$mime,'bytes'=>$bytes,'sha256'=>$hash]);
            $id=(int)$db->insertID();AuditService::log('prescription.document','pharmacy','pharmacy_prescription_documents',$id);return $id;
        } catch (\Throwable $error) { unlink($directory.DIRECTORY_SEPARATOR.$stored);throw $error; }
    }
}
