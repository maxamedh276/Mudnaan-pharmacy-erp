<?php
namespace App\Database\Migrations;
use CodeIgniter\Database\Migration;
class AddInventoryWorkflow extends Migration
{
    public function up()
    {
        foreach (explode(';', file_get_contents(APPPATH . 'Database/TenantSchema/18_inventory_workflow.sql')) as $sql) {
            if (trim($sql) !== '') $this->db->query($sql);
        }
    }
    public function down() { throw new \RuntimeException('Opening balances must be preserved. Restore a verified backup to reverse this migration.'); }
}
