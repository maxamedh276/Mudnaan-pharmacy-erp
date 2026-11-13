<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/** Additive delivery metadata; existing order and receipt dates remain unchanged. */
class AddPurchaseDeliverySchedule extends Migration
{
    public function up()
    {
        $fields = [];
        if (!$this->db->fieldExists('expected_received_at', 'purchases')) {
            $fields['expected_received_at'] = ['type' => 'DATETIME', 'null' => true];
        }
        if (!$this->db->fieldExists('notes', 'purchases')) {
            $fields['notes'] = ['type' => 'TEXT', 'null' => true];
        }
        if ($fields) $this->forge->addColumn('purchases', $fields);
    }

    public function down()
    {
        $this->forge->dropColumn('purchases', ['expected_received_at', 'notes']);
    }
}
