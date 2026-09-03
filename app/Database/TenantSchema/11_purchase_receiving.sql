-- ##################################################################
--  MIGRATION 11 — PURCHASE ORDER / RECEIVING SPLIT
--
--  Separates "what we ordered" from "what actually arrived". Before this
--  migration, POST /purchases immediately booked stock + GL + a supplier
--  bill for the full order — i.e. creating an order silently meant
--  "received in full". That's wrong: a purchase order and the goods
--  physically arriving are two different events, and orders are often
--  received in more than one batch.
--
--  New model:
--    purchases.status: pending -> partially_received -> received
--      (or -> cancelled, only while still pending — see ReversalService)
--    purchase_items.quantity_received: running total received per line
--    purchase_receipts / purchase_receipt_items: one row per goods-in
--      event, however many of them a purchase order takes to fulfill.
--
--  Creating a PO now books NOTHING (no stock, no GL, no bill) — only a
--  receipt does that, valued at exactly what was received. Existing
--  purchases were created under the old model (always instantly
--  "received" in full), so they're backfilled as fully received with
--  no synthetic receipt rows — they don't need to support further
--  partial receiving.
--
--  Run once per tenant. Fresh tenants get this from the base schema.
-- ##################################################################

ALTER TABLE purchases
  MODIFY COLUMN status ENUM('pending','partially_received','received','cancelled')
                        NOT NULL DEFAULT 'pending';

ALTER TABLE purchase_items
  ADD COLUMN quantity_received DECIMAL(12,3) NOT NULL DEFAULT 0 AFTER quantity;

-- Every purchase before this migration was created under the old
-- "instantly received" model — mark their lines as fully received so
-- "remaining" computes to 0 for historical orders.
UPDATE purchase_items pi
  JOIN purchases p ON p.id = pi.purchase_id
  SET pi.quantity_received = pi.quantity
  WHERE p.status = 'received';

CREATE TABLE purchase_receipts (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  uuid         CHAR(36)     NOT NULL,
  branch_id    BIGINT UNSIGNED NOT NULL,
  purchase_id  BIGINT UNSIGNED NOT NULL,
  receipt_no   VARCHAR(50)  NULL,          -- GRN-00001
  received_at  TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP,
  notes        VARCHAR(255) NULL,
  created_by   BIGINT UNSIGNED NULL,
  created_at   TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_prcpt_uuid (uuid),
  KEY idx_prcpt_purchase (purchase_id),
  CONSTRAINT fk_prcpt_branch   FOREIGN KEY (branch_id)   REFERENCES branches (id),
  CONSTRAINT fk_prcpt_purchase FOREIGN KEY (purchase_id) REFERENCES purchases (id)
) ENGINE=InnoDB;

CREATE TABLE purchase_receipt_items (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  receipt_id        BIGINT UNSIGNED NOT NULL,
  purchase_item_id  BIGINT UNSIGNED NOT NULL,
  product_id        BIGINT UNSIGNED NOT NULL,
  quantity          DECIMAL(12,3) NOT NULL,    -- received in THIS batch
  unit_cost         DECIMAL(12,2) NOT NULL,    -- snapshot of the PO line's cost
  line_total        DECIMAL(12,2) NOT NULL,
  PRIMARY KEY (id),
  KEY idx_prcptitem_receipt (receipt_id),
  CONSTRAINT fk_prcptitem_receipt FOREIGN KEY (receipt_id)
       REFERENCES purchase_receipts (id) ON DELETE CASCADE,
  CONSTRAINT fk_prcptitem_pi      FOREIGN KEY (purchase_item_id) REFERENCES purchase_items (id),
  CONSTRAINT fk_prcptitem_product FOREIGN KEY (product_id) REFERENCES products (id)
) ENGINE=InnoDB;
