-- ##################################################################
--  MIGRATION 10 — PRODUCT IMAGE
--
--  Stores the web-relative path of a product photo (uploaded to
--  erp-backend/uploads/products/ and served statically). The API
--  returns the path; the client composes the full URL from its host.
--
--  Run once per tenant. Fresh tenants get it from the base schema.
-- ##################################################################

ALTER TABLE products
  ADD COLUMN image_path VARCHAR(255) NULL AFTER attributes;
