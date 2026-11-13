<?php
namespace App\Database\Migrations;
use CodeIgniter\Database\Migration;
final class AddPharmacyCashShifts extends Migration
{
    public function up()
    {
        $this->db->query("CREATE TABLE IF NOT EXISTS pharmacy_cash_shift_locks (
            branch_id BIGINT UNSIGNED NOT NULL,user_id BIGINT UNSIGNED NOT NULL,
            PRIMARY KEY(branch_id,user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->db->query("CREATE TABLE IF NOT EXISTS pharmacy_cash_shifts (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, branch_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,account_id BIGINT UNSIGNED NOT NULL,
            request_key CHAR(36) NOT NULL,opening_cash DECIMAL(14,2) NOT NULL,
            opening_entry_id BIGINT UNSIGNED NOT NULL,closing_entry_id BIGINT UNSIGNED NULL,
            expected_cash DECIMAL(14,2) NULL,counted_cash DECIMAL(14,2) NULL,variance DECIMAL(14,2) NULL,
            close_note VARCHAR(1000) NULL,active_marker TINYINT NULL DEFAULT 1,
            opened_at DATETIME NOT NULL,closed_at DATETIME NULL,
            UNIQUE KEY cash_shift_request(branch_id,user_id,request_key),
            UNIQUE KEY cash_shift_active(branch_id,user_id,active_marker)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->db->resetDataCache();
    }
    public function down(){throw new \RuntimeException('Retain drawer counts and reconciliation history.');}
}
