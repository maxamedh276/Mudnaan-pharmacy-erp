-- Requires 17_packaged_inventory.sql. No existing stock quantities or prices are changed.
CREATE TABLE selling_option_groups (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 name VARCHAR(100) NOT NULL,
 unit VARCHAR(30) NOT NULL,
 options_json TEXT NOT NULL,
 is_active TINYINT(1) NOT NULL DEFAULT 1,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
ALTER TABLE products
 ADD selling_group_id BIGINT UNSIGNED NULL,
 ADD default_sale_mode VARCHAR(10) NOT NULL DEFAULT 'loose',
 ADD creation_request_key VARCHAR(36) NULL,
 ADD creation_request_hash CHAR(64) NULL,
 ADD UNIQUE KEY product_creation_request (creation_request_key),
 MODIFY reorder_level DECIMAL(15,3) NOT NULL DEFAULT 10;
ALTER TABLE web_stock_adjustment_details
 ADD stock_mode VARCHAR(10) NOT NULL DEFAULT 'loose',
 ADD selected_quantity DECIMAL(12,3) NULL,
 ADD unit_label VARCHAR(30) NULL;
CREATE TABLE product_opening_stocks (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 product_id BIGINT UNSIGNED NOT NULL,
 branch_id BIGINT UNSIGNED NOT NULL,
 sealed_quantity INT UNSIGNED NOT NULL DEFAULT 0,
 opened_quantity DECIMAL(12,3) NOT NULL DEFAULT 0,
 base_unit_cost DECIMAL(18,6) NOT NULL,
 total_value DECIMAL(14,2) NOT NULL,
 created_by BIGINT UNSIGNED NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY opening_product_branch (product_id, branch_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
