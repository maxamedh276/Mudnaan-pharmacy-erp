<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

final class AddPharmacyFoundation extends Migration
{
    public function up()
    {
        $source = file_get_contents(APPPATH . 'Database/TenantSchema/19_pharmacy_catalog.sql');
        if ($source === false) throw new RuntimeException('Pharmacy schema source is unavailable.');
        $source = preg_replace('/^--.*$/m', '', $source);
        foreach (explode(';', $source) as $statement) {
            $statement = trim($statement);
            if ($statement === '') continue;
            // Recover safely after a partially applied MySQL DDL migration.
            if (str_starts_with($statement, 'CREATE TABLE pharmacy_')) $statement = preg_replace('/^CREATE TABLE /', 'CREATE TABLE IF NOT EXISTS ', $statement);
            $this->db->query($statement);
        }
        unset($this->db->dataCache['table_names']);
    }

    public function down()
    {
        // Medical records must not disappear during a routine application rollback.
        foreach (['pharmacy_prescription_items', 'pharmacy_prescriptions', 'pharmacy_patients', 'pharmacy_medicines'] as $table) {
            if ($this->db->tableExists($table) && $this->db->table($table)->countAllResults() > 0) {
                throw new RuntimeException('Pharmacy records exist. Export and review them before removing the pharmacy schema.');
            }
        }
        foreach (['pharmacy_prescription_items', 'pharmacy_prescriptions', 'pharmacy_patients', 'pharmacy_medicines'] as $table) $this->forge->dropTable($table, true);
        $permissions = $this->db->table('permissions')->like('code', 'pharmacy.', 'after')->get()->getResultArray();
        foreach ($permissions as $permission) {
            $this->db->table('role_permissions')->where('permission_id', $permission['id'])->delete();
            $this->db->table('permissions')->where('id', $permission['id'])->delete();
        }
    }
}
