<?php
namespace App\Services;

use App\Libraries\Uuid;
use App\Models\SettingModel;
use InvalidArgumentException;

/** One atomic settings document: activating any device disables all its predecessors. */
class PrinterManagementService
{
    public function list(): array
    {
        $printers=json_decode((new SettingModel())->getVal('receipt_printers','[]'),true);
        return is_array($printers)?$printers:[];
    }

    public function save(array $data): array
    {
        $action=$data['action']??'add';
        if(!in_array($action,['add','activate','disable','delete'],true))throw new InvalidArgumentException('Unknown printer action.');
        if($action==='add'){
            foreach(['label','name','ip'] as $field)if(isset($data[$field])&&(!is_string($data[$field])||strlen($data[$field])>120||preg_match('/[\x00-\x1f\x7f]/',$data[$field])))throw new InvalidArgumentException('Enter valid printer details.');
            if(trim($data['label']??'')==='')throw new InvalidArgumentException('Enter a printer label.');
            if(!in_array($data['type']??'', ['windows','network'],true))throw new InvalidArgumentException('Select a printer connection type.');
            if($data['type']==='windows'&&(trim($data['name']??'')===''||preg_match('/["\'<>|;&`$]/',$data['name'])))throw new InvalidArgumentException('Enter the Windows printer share name, for example POS80.');
            if($data['type']==='network'&&(!filter_var($data['ip']??'',FILTER_VALIDATE_IP)||!filter_var($data['port']??9100,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>65535]])))throw new InvalidArgumentException('Enter a valid printer IP and port.');
        }
        $db=db_connect();$lock='erp_printers_'.substr(hash('sha256',$db->getDatabase()),0,40);
        if((int)$db->query('SELECT GET_LOCK(?,5) acquired',[$lock])->getRow('acquired')!==1)throw new InvalidArgumentException('Another printer update is in progress. Retry shortly.');
        try{return FinancialTransaction::run(function()use($action,$data){
            $printers=$this->list();$id=$data['id']??null;
            if($action==='add'){
                foreach($printers as $printer)if(strcasecmp(trim($printer['label']),trim($data['label']))===0)throw new InvalidArgumentException('A printer with this label already exists.');
                $id=Uuid::v4();$printers[]=['id'=>$id,'label'=>trim($data['label']),'name'=>trim($data['name']??''),'type'=>$data['type'],'ip'=>$data['ip']??'','port'=>(int)($data['port']??9100),'active'=>false];
            }
            if(!in_array($id,array_column($printers,'id'),true))throw new InvalidArgumentException('Printer not found.');
            if ($action === 'delete') {
                $printers = array_values(array_filter($printers, static fn ($printer) => $printer['id'] !== $id));
            } else {
                foreach ($printers as &$printer) {
                    if ($action !== 'disable' || $printer['id'] === $id) {
                        $printer['active'] = $action !== 'disable' && $printer['id'] === $id;
                    }
                }
                unset($printer);
            }
            $active=array_values(array_filter($printers,static fn($printer)=>$printer['active']));
            $settings=new SettingModel();
            $settings->saveBatchAssoc(['receipt_printers'=>json_encode($printers),'thermal_printer_enabled'=>$active?'1':'0','thermal_auto_cut'=>'1','thermal_print_engine'=>'erp']);
            if($active)$settings->saveBatchAssoc(['thermal_printer_type'=>$active[0]['type'],'thermal_printer_name'=>$active[0]['name'],'thermal_printer_ip'=>$active[0]['ip'],'thermal_printer_port'=>$active[0]['port']]);
            AuditService::log('printer.'.$action,'settings','settings',null,after:['printer_id'=>$id]);
            return $printers;
        });}finally{$db->query('SELECT RELEASE_LOCK(?)',[$lock]);}
    }
}
