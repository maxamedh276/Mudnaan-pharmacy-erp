<?php
namespace App\Services;

use InvalidArgumentException;

/** Tenant-wide POS tax configuration. Stored sale-item rates are historical snapshots. */
class TaxRateService
{
    public function list(): array
    {
        return db_connect()->table('tax_rates')->select('id,name,percentage,is_active,created_at')->orderBy('is_active','DESC')->orderBy('id','DESC')->get()->getResultArray();
    }

    public function active(): ?array
    {
        $db = db_connect();
        // Tenants not yet upgraded retain their existing product tax behavior.
        if (!$db->tableExists('tax_rates')) return null;
        return $db->table('tax_rates')->select('id,name,percentage')->where('is_active',1)->get()->getRowArray();
    }

    public function save(array $data): array
    {
        $action = $data['action'] ?? 'add';
        if (!in_array($action,['add','activate'],true)) throw new InvalidArgumentException('Choose a valid tax action.');
        if ($action === 'add') {
            if (!is_string($data['name'] ?? null) || trim($data['name']) === '' || mb_strlen(trim($data['name'])) > 100 || preg_match('/[\x00-\x1f\x7f]/',$data['name'])) throw new InvalidArgumentException('Enter a tax name of up to 100 characters.');
            $rate = $data['percentage'] ?? null;
            if ((!is_string($rate) && !is_int($rate) && !is_float($rate)) || !preg_match('/^\d{1,3}(?:\.\d{1,2})?$/',(string)$rate) || (float)$rate > 100) throw new InvalidArgumentException('Enter a percentage from 0 to 100 with up to two decimal places.');
            if (!is_string($data['uuid'] ?? null) || !preg_match('/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/i',$data['uuid'])) throw new InvalidArgumentException('A valid request ID is required. Reopen Add tax and try again.');
        } elseif (!filter_var($data['id'] ?? null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])) {
            throw new InvalidArgumentException('Tax not found.');
        }
        $db = db_connect();
        $lock = 'erp_tax_'.substr(hash('sha256',$db->getDatabase()),0,40);
        if ((int)$db->query('SELECT GET_LOCK(?,5) acquired',[$lock])->getRow('acquired') !== 1) throw new InvalidArgumentException('Another tax update is in progress. Retry shortly.');
        try {
            return FinancialTransaction::run(function ($db) use ($action,$data) {
                if ($action === 'add') {
                    $name = trim($data['name']); $rate = round((float)$data['percentage'],2);
                    $existing = $db->table('tax_rates')->where('request_uuid',$data['uuid'])->get()->getRowArray();
                    if ($existing) {
                        if ($existing['name'] !== $name || (float)$existing['percentage'] !== $rate) throw new InvalidArgumentException('This request ID has already been used for a different tax.');
                        return $this->list(); // A retry never reactivates a tax that has since been replaced.
                    }
                    if ($db->table('tax_rates')->where('name',$name)->countAllResults()) throw new InvalidArgumentException('A tax with this name already exists.');
                    $db->table('tax_rates')->insert(['name'=>$name,'percentage'=>$rate,'request_uuid'=>$data['uuid'],'is_active'=>0]);
                    $id = (int)$db->insertID();
                } else {
                    $id = (int)$data['id'];
                    if (!$db->table('tax_rates')->where('id',$id)->countAllResults()) throw new InvalidArgumentException('Tax not found.');
                }
                $db->table('tax_rates')->where('is_active',1)->update(['is_active'=>0]);
                $db->table('tax_rates')->where('id',$id)->update(['is_active'=>1]);
                AuditService::log('tax.'.$action,'finance','tax_rates',$id,after:$this->active());
                return $this->list();
            });
        } finally {
            $db->query('SELECT RELEASE_LOCK(?)',[$lock]);
        }
    }
}
