<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddSupplierPaymentAccount extends Migration
{
    public function up()
    {
        if (!$this->db->fieldExists('account_id', 'supplier_payments')) {
            $this->forge->addColumn('supplier_payments', [
                'account_id' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
            ]);
            $this->db->resetDataCache();
        }
    }

    public function down()
    {
        throw new \RuntimeException('Retain supplier payment accounts for financial history.');
    }
}
