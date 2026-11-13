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
