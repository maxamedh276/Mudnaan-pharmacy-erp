<?php
namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class AddOpenedMedicinePacks extends Migration
{
    public function up()
    {
        $this->db->query("CREATE TABLE IF NOT EXISTS pharmacy_opened_packs (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, branch_id BIGINT UNSIGNED NOT NULL,
            product_id BIGINT UNSIGNED NOT NULL, source_batch_id BIGINT UNSIGNED NOT NULL,
            batch_id BIGINT UNSIGNED NOT NULL, medicine_unit_id BIGINT UNSIGNED NOT NULL,
            packs DECIMAL(12,3) NOT NULL, base_quantity DECIMAL(12,3) NOT NULL,
            opened_at DATETIME NOT NULL, use_by DATETIME NOT NULL, reason VARCHAR(500) NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL, request_key CHAR(36) NOT NULL, request_hash CHAR(64) NOT NULL,
            UNIQUE KEY opened_batch(batch_id), UNIQUE KEY opened_request(branch_id,request_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        foreach (['pharmacy_medicine_units','sale_items'] as $table) {
            if (!$this->db->fieldExists('pharmacy_pack_state',$table)) $this->db->query('ALTER TABLE '.$table." ADD pharmacy_pack_state VARCHAR(10) NOT NULL DEFAULT 'any'");
        }
        $this->db->resetDataCache();
    }
    public function down() { throw new \RuntimeException('Opened-pack and sale allocation history must be preserved.'); }
}
