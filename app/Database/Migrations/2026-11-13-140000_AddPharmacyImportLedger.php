<?php
namespace App\Database\Migrations;
use CodeIgniter\Database\Migration;
final class AddPharmacyImportLedger extends Migration
{
    public function up()
    {
        $this->db->query("CREATE TABLE IF NOT EXISTS pharmacy_imports (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, branch_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL, file_hash CHAR(64) NOT NULL, medicine_count INT NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY pharmacy_import_file(branch_id,file_hash)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->db->resetDataCache();
    }
    public function down() { throw new \RuntimeException('Keep import history to prevent duplicate stock.'); }
}
