-- ##################################################################
-- #                                                                #
-- #   ERP SYSTEM  —  FINANCIAL CONTROLS & AUDIT ENHANCEMENTS       #
-- #                                                                #
-- ##################################################################
--
--  Run AFTER 01–04 on the SAME tenant database.
--
--  WHAT THIS ADDS:
--   1. Richer audit_log — who/what/where/before/after, so every
--      financial and admin action is fully traceable.
--   2. Void/cancel state on supplier_payments and customer_receipts
--      (purchases, bills, invoices, deposits already had a status),
--      so money documents are reversed, never deleted.
--
-- ##################################################################


-- ==================================================================
--  1) AUDIT LOG — extra context columns
--  entity_id already stores the record id; we add role, module,
--  device (user agent) and full before/after snapshots.
-- ==================================================================
ALTER TABLE audit_log
  ADD COLUMN role          VARCHAR(80)  NULL AFTER user_id,
  ADD COLUMN module        VARCHAR(60)  NULL AFTER action,
  ADD COLUMN user_agent    VARCHAR(255) NULL AFTER ip_address,
  ADD COLUMN before_values JSON         NULL AFTER details,
  ADD COLUMN after_values  JSON         NULL AFTER before_values;

ALTER TABLE audit_log
  ADD KEY idx_audit_module (module),
  ADD KEY idx_audit_action (action),
  ADD KEY idx_audit_created (created_at);


-- ==================================================================
--  2) VOID STATE for supplier payments
--  Cancelled payments keep their row (audit) but are excluded from
--  balances; a reversing journal entry undoes the accounting.
-- ==================================================================
ALTER TABLE supplier_payments
  ADD COLUMN status     ENUM('active','cancelled') NOT NULL DEFAULT 'active' AFTER amount,
  ADD COLUMN voided_at  TIMESTAMP NULL DEFAULT NULL,
  ADD COLUMN voided_by  BIGINT UNSIGNED NULL,
  ADD COLUMN void_reason VARCHAR(255) NULL,
  ADD KEY idx_spay_status (status);


-- ==================================================================
--  3) VOID STATE for customer receipts
-- ==================================================================
ALTER TABLE customer_receipts
  ADD COLUMN status     ENUM('active','cancelled') NOT NULL DEFAULT 'active' AFTER amount,
  ADD COLUMN voided_at  TIMESTAMP NULL DEFAULT NULL,
  ADD COLUMN voided_by  BIGINT UNSIGNED NULL,
  ADD COLUMN void_reason VARCHAR(255) NULL,
  ADD KEY idx_crcpt_status (status);
