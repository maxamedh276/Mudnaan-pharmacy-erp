<?php
namespace App\Database\Migrations;
use CodeIgniter\Database\Migration;
final class CompletePharmacyPortal extends Migration
{
    public function up()
    {
        $this->db->query("CREATE TABLE IF NOT EXISTS role_menu_permissions (role_id BIGINT UNSIGNED NOT NULL, menu_key VARCHAR(100) NOT NULL, PRIMARY KEY(role_id,menu_key)) ENGINE=InnoDB");
        $this->db->query("CREATE TABLE IF NOT EXISTS user_menu_permissions (user_id BIGINT UNSIGNED NOT NULL, menu_key VARCHAR(100) NOT NULL, is_allowed TINYINT NOT NULL DEFAULT 1, PRIMARY KEY(user_id,menu_key)) ENGINE=InnoDB");
        $this->db->resetDataCache();
    }
    public function down() { throw new \RuntimeException('Retain pharmacy menu access assignments.'); }
}
