<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddDocumentReturns extends Migration
{
    public function up()
    {
        foreach (explode(';', file_get_contents(APPPATH . 'Database/TenantSchema/16_document_returns.sql')) as $sql) {
            if (trim($sql) !== '') $this->db->query($sql);
        }
        foreach (['sales', 'customer_invoices', 'supplier_bills'] as $table) {
            foreach (['returned_total', 'refunded_total', 'return_discount_total'] as $field) {
                if (!$this->db->fieldExists($field, $table)) {
                    $this->forge->addColumn($table, [$field => ['type' => 'DECIMAL', 'constraint' => '14,2', 'default' => 0]]);
                }
            }
        }
        if (!$this->db->fieldExists('cost_total', 'sale_items')) {
            // NULL means a legacy sale whose original line cost was not captured.
            $this->forge->addColumn('sale_items', ['cost_total' => ['type' => 'DECIMAL', 'constraint' => '14,2', 'null' => true]]);
        }
        foreach (['sales.refund' => 'Process sales returns and customer refunds', 'purchases.return' => 'Process purchase returns and supplier refunds'] as $code => $description) {
            if (!$this->db->table('permissions')->where('code', $code)->countAllResults()) {
                $this->db->table('permissions')->insert(compact('code', 'description'));
            }
        }
        $this->db->resetDataCache();
    }

    public function down()
    {
        // Credit notes are permanent financial history. Never silently destroy posted returns.
        throw new \RuntimeException('Document returns must be retained for financial audit history.');
    }
}
