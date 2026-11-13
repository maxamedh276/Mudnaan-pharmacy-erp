<?php
namespace App\Database\Migrations;
use CodeIgniter\Database\Migration;
final class AddPharmacyDiscountControls extends Migration
{
    public function up()
    {
        $this->db->query("INSERT IGNORE INTO permissions(code,description) VALUES ('pharmacy.discounts.apply','Apply pharmacy POS line discounts with a recorded reason')");
        $this->db->query("INSERT IGNORE INTO role_permissions(role_id,permission_id) SELECT r.id,p.id FROM roles r CROSS JOIN permissions p WHERE r.name IN ('Superadmin','Admin','Owner','Manager') AND p.code='pharmacy.discounts.apply'");
        if (!$this->db->fieldExists('pharmacy_discount_reason','sale_items')) $this->db->query('ALTER TABLE sale_items ADD pharmacy_discount_reason VARCHAR(300) NULL');
        $this->db->resetDataCache();
    }
    public function down(){throw new \RuntimeException('Preserve discount reasons and permission history.');}
}
