<?php
namespace App\Database\Migrations;
use CodeIgniter\Database\Migration;

/** Reconcile portal columns which were missing from the original fresh-install scripts. */
final class CompletePortalSchema extends Migration
{
    public function up()
    {
        if (!$this->db->fieldExists('shipping', 'sales')) $this->forge->addColumn('sales', ['shipping' => ['type' => 'DECIMAL', 'constraint' => '12,2', 'default' => 0]]);
        if (!$this->db->fieldExists('account_id', 'payments')) $this->forge->addColumn('payments', ['account_id' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true]]);
        $this->db->query("CREATE TABLE IF NOT EXISTS pos_payment_methods (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, account_id BIGINT UNSIGNED NOT NULL,
            name VARCHAR(100) NOT NULL, code VARCHAR(50) NOT NULL UNIQUE,
            type VARCHAR(20) NOT NULL, is_active TINYINT NOT NULL DEFAULT 1, sort_order INT NOT NULL DEFAULT 0,
            created_at DATETIME NULL, updated_at DATETIME NULL,
            CONSTRAINT fk_pos_method_account FOREIGN KEY(account_id) REFERENCES accounts(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->db->query("INSERT IGNORE INTO pos_payment_methods(account_id,name,code,type) SELECT id,'Cash','cash','cash' FROM accounts WHERE code='1000'");
        $this->db->resetDataCache();
    }
    public function down() { throw new \RuntimeException('Portal financial fields must be preserved.'); }
}
