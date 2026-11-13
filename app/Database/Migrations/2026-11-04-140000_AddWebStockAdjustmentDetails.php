<?php
namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/** Web metadata leaves the existing movement types and mobile payloads intact. */
class AddWebStockAdjustmentDetails extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'movement_id' => ['type' => 'BIGINT', 'unsigned' => true],
            'branch_id' => ['type' => 'BIGINT', 'unsigned' => true],
            'request_key' => ['type' => 'VARCHAR', 'constraint' => 36],
            'note' => ['type' => 'VARCHAR', 'constraint' => 500],
        ]);
        $this->forge->addKey('movement_id', true);
        $this->forge->addUniqueKey(['branch_id', 'request_key']);
        $this->forge->createTable('web_stock_adjustment_details', true);
    }
    public function down()
    {
        $this->forge->dropTable('web_stock_adjustment_details', true);
    }
}
