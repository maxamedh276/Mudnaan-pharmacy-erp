-- Run against each tenant database before deploying the barcode changes.
SET @mudnaan_barcode_sql = IF(EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'products' AND column_name = 'sealed_barcode'
), 'SELECT 1', 'ALTER TABLE products ADD COLUMN sealed_barcode VARCHAR(80) NULL, ADD KEY idx_prod_sealed_barcode (sealed_barcode)');
PREPARE mudnaan_barcode_stmt FROM @mudnaan_barcode_sql;
EXECUTE mudnaan_barcode_stmt;
DEALLOCATE PREPARE mudnaan_barcode_stmt;
