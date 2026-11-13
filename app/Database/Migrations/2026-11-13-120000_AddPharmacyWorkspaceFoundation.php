<?php
namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/** Additive schema only: historical medicine and financial records remain intact. */
final class AddPharmacyWorkspaceFoundation extends Migration
{
    public function up()
    {
        $this->db->query("CREATE TABLE IF NOT EXISTS pharmacy_storage_locations (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, branch_id BIGINT UNSIGNED NOT NULL,
            name VARCHAR(100) NOT NULL, kind VARCHAR(20) NOT NULL,
            min_c DECIMAL(6,2) NOT NULL, max_c DECIMAL(6,2) NOT NULL,
            created_by BIGINT UNSIGNED NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY storage_location(branch_id,name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->db->query("CREATE TABLE IF NOT EXISTS pharmacy_temperature_logs (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, branch_id BIGINT UNSIGNED NOT NULL,
            location_id BIGINT UNSIGNED NOT NULL, user_id BIGINT UNSIGNED NOT NULL,
            measured_at DATETIME NOT NULL, temperature_c DECIMAL(6,2) NOT NULL,
            min_c DECIMAL(6,2) NOT NULL, max_c DECIMAL(6,2) NOT NULL, excursion TINYINT NOT NULL,
            action_taken VARCHAR(2000) NULL, request_key CHAR(36) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY temperature_request(branch_id,user_id,request_key), KEY storage_readings(branch_id,location_id,measured_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->db->query("CREATE TABLE IF NOT EXISTS pharmacy_definitions (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            kind VARCHAR(10) NOT NULL, name VARCHAR(80) NOT NULL,
            family VARCHAR(12) NOT NULL DEFAULT 'count', fractional TINYINT NOT NULL DEFAULT 0,
            created_by BIGINT UNSIGNED NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY definition_name(kind,name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->db->query("CREATE TABLE IF NOT EXISTS pharmacy_held_sales (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            branch_id BIGINT UNSIGNED NOT NULL, user_id BIGINT UNSIGNED NOT NULL,
            request_key CHAR(36) NOT NULL, label VARCHAR(100) NOT NULL, payload MEDIUMTEXT NOT NULL, revision INT NOT NULL DEFAULT 1,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY held_request(branch_id,user_id,request_key), KEY held_owner(branch_id,user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->db->resetDataCache();
        $this->db->query("CREATE TABLE IF NOT EXISTS pharmacy_care_notes (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, branch_id BIGINT UNSIGNED NOT NULL,
            patient_id BIGINT UNSIGNED NOT NULL, user_id BIGINT UNSIGNED NOT NULL,
            kind VARCHAR(24) NOT NULL, note TEXT NOT NULL, request_key CHAR(36) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY care_request(branch_id,user_id,request_key), KEY patient_notes(branch_id,patient_id,id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->db->resetDataCache();
    }

    public function down()
    {
        throw new \RuntimeException('Preserve catalog definitions and held sales. Restore a verified backup instead.');
    }
}
