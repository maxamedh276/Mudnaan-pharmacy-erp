<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/** Existing receipts retain their original payment routing. */
class AddCustomerReceiptCashAccount extends Migration
{
    public function up()
    {
        if (!$this->db->fieldExists('account_id', 'customer_receipts')) {
            $this->forge->addColumn('customer_receipts', [
                'account_id' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
            ]);
            $this->db->resetDataCache();
        }
    }

    public function down()
    {
        $this->forge->dropColumn('customer_receipts', 'account_id');
        $this->db->resetDataCache();
    }
}
