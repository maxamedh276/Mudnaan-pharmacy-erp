ALTER TABLE customer_receipts ADD COLUMN collection_request_hash VARCHAR(64) NULL;
CREATE TABLE IF NOT EXISTS customer_receipt_allocations (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 receipt_id BIGINT UNSIGNED NOT NULL, invoice_id BIGINT UNSIGNED NOT NULL,
 amount DECIMAL(14,2) NOT NULL, discount DECIMAL(14,2) NOT NULL DEFAULT 0,
 UNIQUE KEY receipt_invoice (receipt_id,invoice_id), KEY invoice_idx (invoice_id)
) ENGINE=InnoDB;
