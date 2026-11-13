<?php
namespace App\Database\Migrations;
use CodeIgniter\Database\Migration;
final class AddMedicineClinicalFacts extends Migration
{
    public function up()
    {
        foreach (['pharmacy_medicines','pharmacy_prescription_items'] as $table) {
            if (!$this->db->fieldExists('route_of_administration',$table)) $this->db->query('ALTER TABLE '.$table.' ADD route_of_administration VARCHAR(80) NULL');
            if (!$this->db->fieldExists('active_ingredients',$table)) $this->db->query('ALTER TABLE '.$table.' ADD active_ingredients JSON NULL');
        }
        if (!$this->db->fieldExists('pharmacy_medicine_snapshot','sale_items')) $this->db->query('ALTER TABLE sale_items ADD pharmacy_medicine_snapshot JSON NULL');
        if (!$this->db->fieldExists('clinical_verified','pharmacy_medicines')) $this->db->query('ALTER TABLE pharmacy_medicines ADD clinical_verified TINYINT NOT NULL DEFAULT 1, ADD clinical_verified_by BIGINT UNSIGNED NULL, ADD clinical_verified_at DATETIME NULL');
        $this->db->query("INSERT IGNORE INTO permissions(code,description) VALUES ('pharmacy.medicines.clinical','Review medicine clinical facts and prescription classification before dispensing')");
        $this->db->query("INSERT IGNORE INTO role_permissions(role_id,permission_id) SELECT r.id,p.id FROM roles r CROSS JOIN permissions p WHERE r.name IN ('Superadmin','Admin','Owner','Pharmacist') AND p.code='pharmacy.medicines.clinical'");
        $this->db->resetDataCache();
    }
    public function down(){throw new \RuntimeException('Preserve recorded medicine composition and historical snapshots.');}
}
