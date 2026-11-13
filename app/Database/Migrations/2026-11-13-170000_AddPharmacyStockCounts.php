<?php
namespace App\Database\Migrations;
use CodeIgniter\Database\Migration;
final class AddPharmacyStockCounts extends Migration
{
    public function up()
    {
        $this->db->query("CREATE TABLE IF NOT EXISTS pharmacy_count_sessions (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,branch_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,request_key CHAR(36) NOT NULL,scope_product BIGINT UNSIGNED NULL,
            status VARCHAR(12) NOT NULL DEFAULT 'draft',revision INT NOT NULL DEFAULT 1,
            approved_by BIGINT UNSIGNED NULL,approved_at DATETIME NULL,
            created_at DATETIME NOT NULL,UNIQUE KEY count_request(branch_id,request_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->db->query("CREATE TABLE IF NOT EXISTS pharmacy_count_lines (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,count_id BIGINT UNSIGNED NOT NULL,
            product_id BIGINT UNSIGNED NOT NULL,batch_id BIGINT UNSIGNED NOT NULL,
            expected_qty DECIMAL(12,3) NOT NULL,last_movement_id BIGINT UNSIGNED NOT NULL,
            counted_qty DECIMAL(12,3) NULL,reason VARCHAR(300) NULL,
            UNIQUE KEY count_batch(count_id,batch_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->db->query("INSERT IGNORE INTO permissions(code,description) VALUES ('pharmacy.stock.approve','Approve physical pharmacy stock counts and their financial adjustments')");
        $this->db->query("INSERT IGNORE INTO role_permissions(role_id,permission_id) SELECT r.id,p.id FROM roles r CROSS JOIN permissions p WHERE r.name IN ('Superadmin','Admin','Owner','Manager') AND p.code='pharmacy.stock.approve'");
        $this->db->resetDataCache();
    }
    public function down(){throw new \RuntimeException('Retain physical-count approvals and adjustment references.');}
}
