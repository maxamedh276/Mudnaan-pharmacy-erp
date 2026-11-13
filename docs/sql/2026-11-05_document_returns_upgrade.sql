-- Sales and purchase returns production upgrade (MySQL / MariaDB).
-- Select the tenant's existing ERP database before running this complete file.
-- Re-running preserves existing columns, permissions, balances and return records.
-- DDL commits automatically: this is an additive schema upgrade, not sample data.

-- Immutable credit notes. Original sale lines and goods receipts remain unchanged.
CREATE TABLE IF NOT EXISTS document_returns (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL,
    request_hash CHAR(64) NOT NULL,
    kind VARCHAR(10) NOT NULL,
    branch_id BIGINT UNSIGNED NOT NULL,
    source_id BIGINT UNSIGNED NOT NULL,
    document_id BIGINT UNSIGNED NULL,
    party_id BIGINT UNSIGNED NULL,
    return_no VARCHAR(40) NULL,
    source_no VARCHAR(120) NOT NULL,
    party_name VARCHAR(180) NOT NULL,
    reason VARCHAR(500) NOT NULL,
    total DECIMAL(14,2) NOT NULL,
    tax_total DECIMAL(14,2) NOT NULL DEFAULT 0,
    stock_value DECIMAL(14,2) NOT NULL DEFAULT 0,
    debt_reduction DECIMAL(14,2) NOT NULL DEFAULT 0,
    refund DECIMAL(14,2) NOT NULL DEFAULT 0,
    discount_reversal DECIMAL(14,2) NOT NULL DEFAULT 0,
    account_id BIGINT UNSIGNED NULL,
    returned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by BIGINT UNSIGNED NOT NULL,
    UNIQUE KEY return_uuid (uuid),
    KEY return_source (branch_id, kind, source_id),
    KEY return_party (branch_id, kind, party_id, returned_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS document_return_items (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    return_id BIGINT UNSIGNED NOT NULL,
    source_item_id BIGINT UNSIGNED NOT NULL,
    product_id BIGINT UNSIGNED NOT NULL,
    description VARCHAR(180) NOT NULL,
    quantity DECIMAL(12,3) NOT NULL,
    total DECIMAL(14,2) NOT NULL,
    tax_total DECIMAL(14,2) NOT NULL DEFAULT 0,
    stock_value DECIMAL(14,2) NOT NULL DEFAULT 0,
    UNIQUE KEY return_line (return_id, source_item_id),
    KEY return_product (product_id)
) ENGINE=InnoDB;

SET @mudnaan_returns_sql = IF(EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'sales' AND column_name = 'returned_total'), 'SELECT 1', 'ALTER TABLE `sales` ADD COLUMN `returned_total` DECIMAL(14,2) NOT NULL DEFAULT 0');
PREPARE mudnaan_returns_stmt FROM @mudnaan_returns_sql;
EXECUTE mudnaan_returns_stmt;
DEALLOCATE PREPARE mudnaan_returns_stmt;

SET @mudnaan_returns_sql = IF(EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'sales' AND column_name = 'refunded_total'), 'SELECT 1', 'ALTER TABLE `sales` ADD COLUMN `refunded_total` DECIMAL(14,2) NOT NULL DEFAULT 0');
PREPARE mudnaan_returns_stmt FROM @mudnaan_returns_sql;
EXECUTE mudnaan_returns_stmt;
DEALLOCATE PREPARE mudnaan_returns_stmt;

SET @mudnaan_returns_sql = IF(EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'sales' AND column_name = 'return_discount_total'), 'SELECT 1', 'ALTER TABLE `sales` ADD COLUMN `return_discount_total` DECIMAL(14,2) NOT NULL DEFAULT 0');
PREPARE mudnaan_returns_stmt FROM @mudnaan_returns_sql;
EXECUTE mudnaan_returns_stmt;
DEALLOCATE PREPARE mudnaan_returns_stmt;

SET @mudnaan_returns_sql = IF(EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'customer_invoices' AND column_name = 'returned_total'), 'SELECT 1', 'ALTER TABLE `customer_invoices` ADD COLUMN `returned_total` DECIMAL(14,2) NOT NULL DEFAULT 0');
PREPARE mudnaan_returns_stmt FROM @mudnaan_returns_sql;
EXECUTE mudnaan_returns_stmt;
DEALLOCATE PREPARE mudnaan_returns_stmt;

SET @mudnaan_returns_sql = IF(EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'customer_invoices' AND column_name = 'refunded_total'), 'SELECT 1', 'ALTER TABLE `customer_invoices` ADD COLUMN `refunded_total` DECIMAL(14,2) NOT NULL DEFAULT 0');
PREPARE mudnaan_returns_stmt FROM @mudnaan_returns_sql;
EXECUTE mudnaan_returns_stmt;
DEALLOCATE PREPARE mudnaan_returns_stmt;

SET @mudnaan_returns_sql = IF(EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'customer_invoices' AND column_name = 'return_discount_total'), 'SELECT 1', 'ALTER TABLE `customer_invoices` ADD COLUMN `return_discount_total` DECIMAL(14,2) NOT NULL DEFAULT 0');
PREPARE mudnaan_returns_stmt FROM @mudnaan_returns_sql;
EXECUTE mudnaan_returns_stmt;
DEALLOCATE PREPARE mudnaan_returns_stmt;

SET @mudnaan_returns_sql = IF(EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'supplier_bills' AND column_name = 'returned_total'), 'SELECT 1', 'ALTER TABLE `supplier_bills` ADD COLUMN `returned_total` DECIMAL(14,2) NOT NULL DEFAULT 0');
PREPARE mudnaan_returns_stmt FROM @mudnaan_returns_sql;
EXECUTE mudnaan_returns_stmt;
DEALLOCATE PREPARE mudnaan_returns_stmt;

SET @mudnaan_returns_sql = IF(EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'supplier_bills' AND column_name = 'refunded_total'), 'SELECT 1', 'ALTER TABLE `supplier_bills` ADD COLUMN `refunded_total` DECIMAL(14,2) NOT NULL DEFAULT 0');
PREPARE mudnaan_returns_stmt FROM @mudnaan_returns_sql;
EXECUTE mudnaan_returns_stmt;
DEALLOCATE PREPARE mudnaan_returns_stmt;

SET @mudnaan_returns_sql = IF(EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'supplier_bills' AND column_name = 'return_discount_total'), 'SELECT 1', 'ALTER TABLE `supplier_bills` ADD COLUMN `return_discount_total` DECIMAL(14,2) NOT NULL DEFAULT 0');
PREPARE mudnaan_returns_stmt FROM @mudnaan_returns_sql;
EXECUTE mudnaan_returns_stmt;
DEALLOCATE PREPARE mudnaan_returns_stmt;

SET @mudnaan_returns_sql = IF(EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'sale_items' AND column_name = 'cost_total'), 'SELECT 1', 'ALTER TABLE `sale_items` ADD COLUMN `cost_total` DECIMAL(14,2) NULL DEFAULT NULL');
PREPARE mudnaan_returns_stmt FROM @mudnaan_returns_sql;
EXECUTE mudnaan_returns_stmt;
DEALLOCATE PREPARE mudnaan_returns_stmt;

-- Register actions without changing existing role assignments.
-- Superadmin/Admin already have universal access. Other roles require explicit assignment.
INSERT INTO permissions (code, description)
SELECT 'sales.refund', 'Process sales returns and customer refunds'
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE code = 'sales.refund');

INSERT INTO permissions (code, description)
SELECT 'purchases.return', 'Process purchase returns and supplier refunds'
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE code = 'purchases.return');

SET @mudnaan_returns_sql = NULL;
