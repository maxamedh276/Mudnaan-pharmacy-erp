-- Run once for existing tenants; fresh tenants include this in the base schema.
ALTER TABLE customer_receipts ADD COLUMN account_id BIGINT UNSIGNED NULL;
