<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddSealedProductBarcode extends Migration
{
    public function up()
    {
        if (!$this->db->fieldExists('sealed_barcode', 'products')) {
            $this->db->query('ALTER TABLE products ADD COLUMN sealed_barcode VARCHAR(80) NULL, ADD KEY idx_prod_sealed_barcode (sealed_barcode)');
            $this->db->resetDataCache();
        }
    }

    public function down()
    {
        throw new \RuntimeException('Retain configured sealed product barcodes.');
    }
}
