<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddCustomerCreditLimits extends Migration
{
    public function up()
    {
        if (!$this->db->fieldExists('credit_limit', 'customers')) {
            $this->forge->addColumn('customers', ['credit_limit' => ['type' => 'DECIMAL', 'constraint' => '12,2', 'unsigned' => true, 'default' => 0]]);
        }
        $this->db->resetDataCache();
    }

    public function down()
    {
        throw new \RuntimeException('Removing customer credit limits would disable an active financial control.');
    }
}
