<?php
namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class AddPharmacyOperations extends Migration
{
    public function up()
    {
        $this->db->query("CREATE TABLE IF NOT EXISTS pharmacy_stock_requests (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, branch_id BIGINT UNSIGNED NOT NULL,
            request_key CHAR(36) NOT NULL, request_hash CHAR(64) NOT NULL, product_id BIGINT UNSIGNED NOT NULL,
            batch_id BIGINT UNSIGNED NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY pharmacy_stock_request(branch_id,request_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->db->query("CREATE TABLE IF NOT EXISTS pharmacy_medicine_units (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, product_id BIGINT UNSIGNED NOT NULL,
            name VARCHAR(30) NOT NULL, factor DECIMAL(12,3) NOT NULL, sell_price DECIMAL(12,2) NOT NULL,
            barcode VARCHAR(80) NULL, fractional TINYINT NOT NULL DEFAULT 0,
            UNIQUE KEY medicine_unit_name(product_id,name), UNIQUE KEY medicine_unit_barcode(barcode),
            CONSTRAINT fk_pharmacy_unit_product FOREIGN KEY(product_id) REFERENCES pharmacy_medicines(product_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->db->query("CREATE TABLE IF NOT EXISTS pharmacy_batch_states (
            batch_id BIGINT UNSIGNED PRIMARY KEY, status VARCHAR(20) NOT NULL DEFAULT 'active',
            reason VARCHAR(500) NULL, updated_by BIGINT UNSIGNED NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_pharmacy_batch_state FOREIGN KEY(batch_id) REFERENCES product_batches(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->db->query("CREATE TABLE IF NOT EXISTS pharmacy_dispensings (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, sale_id BIGINT UNSIGNED NOT NULL UNIQUE,
            prescription_id BIGINT UNSIGNED NOT NULL, user_id BIGINT UNSIGNED NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_pharmacy_dispensing_sale FOREIGN KEY(sale_id) REFERENCES sales(id),
            CONSTRAINT fk_pharmacy_dispensing_rx FOREIGN KEY(prescription_id) REFERENCES pharmacy_prescriptions(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        if (!$this->db->fieldExists('batch_id', 'purchase_receipt_items')) $this->forge->addColumn('purchase_receipt_items', ['batch_id' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true]]);
        foreach (['pharmacy.stock.manage' => 'Receive, quarantine, recall and dispose pharmacy batches', 'pharmacy.dispense' => 'Dispense prescription medicines', 'pharmacy.prescriptions.approve' => 'Approve prescriptions for dispensing'] as $code => $description) {
            $this->db->query('INSERT IGNORE INTO permissions(code,description) VALUES(?,?)', [$code, $description]);
        }
        $this->db->query("INSERT IGNORE INTO role_permissions(role_id,permission_id) SELECT r.id,p.id FROM roles r CROSS JOIN permissions p WHERE r.name IN ('Superadmin','Owner') AND p.code LIKE 'pharmacy.%'");
        $this->db->query("INSERT IGNORE INTO roles(name,is_system) VALUES ('Pharmacist',1),('Storekeeper',1)");
        $this->db->query("INSERT IGNORE INTO role_permissions(role_id,permission_id) SELECT r.id,p.id FROM roles r CROSS JOIN permissions p
            WHERE (r.name='Pharmacist' AND (p.code LIKE 'pharmacy.%' OR p.code IN ('sales.create','sales.view','products.view','stock.view')))
               OR (r.name='Storekeeper' AND (p.code IN ('pharmacy.view','pharmacy.stock.manage','pharmacy.medicines.manage') OR p.code LIKE 'purchases.%' OR p.code LIKE 'suppliers.%' OR p.code LIKE 'stock.%' OR p.code LIKE 'products.%'))
               OR (r.name IN ('Cashier','Manager','Admin') AND p.code='pharmacy.view')");
        unset($this->db->dataCache['table_names']);
    }

    public function down()
    {
        throw new \RuntimeException('Pharmacy operations contain traceability data; use a reviewed database restore instead of destructive rollback.');
    }
}
