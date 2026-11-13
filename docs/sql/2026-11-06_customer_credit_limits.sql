-- Select the production tenant database first. Safe to run again without resetting limits.
-- Existing debt and financial history are unchanged. An Admin must authorize a limit
-- before a customer (including an existing customer) can take additional credit.
SET @mudnaan_credit_sql = IF(EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'customers' AND column_name = 'credit_limit'), 'SELECT 1', 'ALTER TABLE `customers` ADD COLUMN `credit_limit` DECIMAL(12,2) UNSIGNED NOT NULL DEFAULT 0');
PREPARE mudnaan_credit_stmt FROM @mudnaan_credit_sql;
EXECUTE mudnaan_credit_stmt;
DEALLOCATE PREPARE mudnaan_credit_stmt;
