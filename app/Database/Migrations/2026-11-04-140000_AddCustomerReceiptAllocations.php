<?php
namespace App\Database\Migrations;
use CodeIgniter\Database\Migration;
class AddCustomerReceiptAllocations extends Migration
{
    public function up()
    {
        if (!$this->db->fieldExists('collection_request_hash', 'customer_receipts')) {
            $this->forge->addColumn('customer_receipts', ['collection_request_hash' => ['type'=>'VARCHAR','constraint'=>64,'null'=>true]]);
        }
        $this->db->query('CREATE TABLE IF NOT EXISTS customer_receipt_allocations (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            receipt_id BIGINT UNSIGNED NOT NULL, invoice_id BIGINT UNSIGNED NOT NULL,
            amount DECIMAL(14,2) NOT NULL, discount DECIMAL(14,2) NOT NULL DEFAULT 0,
            UNIQUE KEY receipt_invoice (receipt_id, invoice_id), KEY invoice_idx (invoice_id)
        ) ENGINE=InnoDB');
        $this->db->resetDataCache();
    }
    public function down()
    {
        $this->forge->dropTable('customer_receipt_allocations', true);
        $this->forge->dropColumn('customer_receipts','collection_request_hash');
    }
}
