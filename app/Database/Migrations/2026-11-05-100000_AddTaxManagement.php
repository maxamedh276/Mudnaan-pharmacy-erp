<?php
namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddTaxManagement extends Migration
{
    public function up()
    {
        $this->db->query(file_get_contents(APPPATH.'Database/TenantSchema/15_tax_management.sql'));
        $this->db->resetDataCache();
    }

    public function down()
    {
        $this->forge->dropTable('tax_rates', true);
    }
}
