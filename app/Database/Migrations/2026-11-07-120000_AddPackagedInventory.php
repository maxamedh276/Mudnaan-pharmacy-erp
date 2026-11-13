<?php
namespace App\Database\Migrations;
use CodeIgniter\Database\Migration;

/** Additive split: total stock remains in base units; sealed packages have a separate ledger. */
class AddPackagedInventory extends Migration
{
    public function up()
    {
        $sql = file_get_contents(APPPATH . 'Database/TenantSchema/17_packaged_inventory.sql');
        foreach (explode(';', $sql) as $statement) {
            if (trim($statement) !== '') $this->db->query($statement);
        }
    }
    public function down()
    {
        throw new \RuntimeException('Package history must be preserved. Restore a verified backup to reverse this migration.');
    }
}
