-- Run against the tenant database before deploying the payment form.
SET @mudnaan_payment_sql = IF(EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'supplier_payments' AND column_name = 'account_id'
), 'SELECT 1', 'ALTER TABLE supplier_payments ADD COLUMN account_id BIGINT UNSIGNED NULL');
PREPARE mudnaan_payment_stmt FROM @mudnaan_payment_sql;
EXECUTE mudnaan_payment_stmt;
DEALLOCATE PREPARE mudnaan_payment_stmt;
