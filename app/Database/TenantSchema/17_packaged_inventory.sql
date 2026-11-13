-- Existing quantities remain base units and are treated as opened stock.
ALTER TABLE products ADD package_unit VARCHAR(30) NULL, ADD package_size DECIMAL(12,3) NULL, ADD package_price DECIMAL(12,2) NULL, MODIFY cost_price DECIMAL(18,6) NOT NULL DEFAULT 0;
ALTER TABLE sale_items ADD stock_factor DECIMAL(12,3) NOT NULL DEFAULT 1, ADD unit_label VARCHAR(30) NULL, ADD stock_mode VARCHAR(10) NOT NULL DEFAULT 'loose';
ALTER TABLE purchase_items ADD stock_factor DECIMAL(12,3) NOT NULL DEFAULT 1, ADD unit_label VARCHAR(30) NULL, ADD stock_mode VARCHAR(10) NOT NULL DEFAULT 'loose';
CREATE TABLE package_movements (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 branch_id BIGINT UNSIGNED NOT NULL,
 product_id BIGINT UNSIGNED NOT NULL,
 package_change INT NOT NULL,
 package_size DECIMAL(12,3) NOT NULL,
 reason VARCHAR(30) NOT NULL,
 ref_id BIGINT UNSIGNED NULL,
 user_id BIGINT UNSIGNED NULL,
 request_key VARCHAR(36) NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY package_request (branch_id, request_key),
 KEY package_balance (branch_id, product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
