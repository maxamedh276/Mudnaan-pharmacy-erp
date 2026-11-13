<?php
namespace App\Database\Migrations;
use CodeIgniter\Database\Migration;
final class AddPrescriptionDocuments extends Migration
{
    public function up()
    {
        $this->db->query("CREATE TABLE IF NOT EXISTS pharmacy_prescription_documents (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,branch_id BIGINT UNSIGNED NOT NULL,
            prescription_id BIGINT UNSIGNED NOT NULL,user_id BIGINT UNSIGNED NOT NULL,
            original_name VARCHAR(150) NOT NULL,stored_name VARCHAR(50) NOT NULL,mime VARCHAR(40) NOT NULL,
            bytes BIGINT UNSIGNED NOT NULL,sha256 CHAR(64) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY prescription_document(prescription_id,sha256)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->db->resetDataCache();
    }
    public function down(){throw new \RuntimeException('Preserve original prescription documentation.');}
}
