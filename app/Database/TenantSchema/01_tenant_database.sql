-- ##################################################################
-- #                                                                #
-- #   ERP SYSTEM  —  TENANT (PER-CLIENT) DATABASE                  #
-- #                                                                #
-- ##################################################################
--
--  WHAT IS THIS FILE?
--  ------------------
--  This creates ONE database for ONE client (one shop/pharmacy/etc).
--  Every client we sell to gets their OWN copy of this database, so
--  their data is fully separated from every other client.
--
--  Example: if we sell to "Acme Pharmacy", we run this file once and
--  name their database  erp_tenant_acme.  Their sales, products and
--  staff all live there and no other client can ever see it.
--
--  HOW DO WE USE ONE SCHEMA FOR DIFFERENT BUSINESS TYPES?
--  ------------------------------------------------------
--  (Pharmacy, Electronics, Shop, Clothing...)
--  We DON'T build a different app per industry. Instead this single
--  schema contains some OPTIONAL tables that simply stay EMPTY when a
--  business doesn't need them:
--     * A pharmacy uses  product_batches  (expiry dates).
--     * An electronics shop uses  product_serials  (warranty/serials).
--     * A clothing shop uses neither — those tables just stay empty.
--  Which features are "on" for this client is recorded in the
--  `settings` table at the bottom (business_type + feature flags).
--
--  WHY IS THE SCHEMA BUILT THIS WAY? (read before changing things)
--  ---------------------------------------------------------------
--  A few columns/tables exist NOW even though the MVP doesn't use them
--  yet. They are here so that when we grow, we ADD features instead of
--  rebuilding — meaning we NEVER have to tell a client "delete your
--  data and start over". Specifically:
--     * branch_id is on every table   -> multi-branch later = no rebuild
--     * sales.uuid exists             -> offline mobile sync later = no rebuild
--     * stock is a LEDGER of +/- rows -> accurate stock + offline sync = no rebuild
--  Each of these is explained again at the table where it appears.
--
--  HOW TO CREATE A NEW CLIENT DB:
--  ------------------------------
--  Replace {{DB_NAME}} below with the client's database name
--  (e.g. erp_tenant_acme), then run this whole file.
--
-- ##################################################################

CREATE DATABASE IF NOT EXISTS `{{DB_NAME}}`
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
  -- utf8mb4 = supports every language + emojis. Always use this.
USE `{{DB_NAME}}`;


-- ==================================================================
--  SECTION 1 — THE BUSINESS ITSELF (branches & settings)
-- ==================================================================

-- ------------------------------------------------------------------
-- TABLE: branches
-- WHAT:  The physical shop locations of this client.
-- WHY:   On the Basic plan a client has just ONE branch (one row here).
--        Premium clients can have many. Because every other table
--        links to a branch_id, upgrading a client to multi-branch
--        needs NO data changes — they just start adding more branches.
-- ------------------------------------------------------------------
CREATE TABLE branches (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name        VARCHAR(120) NOT NULL,           -- e.g. "Main Store", "Downtown"
  address     VARCHAR(255) NULL,
  phone       VARCHAR(40)  NULL,
  is_main     TINYINT(1)   NOT NULL DEFAULT 1, -- the primary branch
  is_active   TINYINT(1)   NOT NULL DEFAULT 1, -- 0 = closed/disabled
  created_at  TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at  TIMESTAMP    NULL DEFAULT NULL,  -- "soft delete": hide, don't erase
  PRIMARY KEY (id)
) ENGINE=InnoDB;

-- ------------------------------------------------------------------
-- TABLE: settings
-- WHAT:  Simple key/value box for this client's configuration.
-- WHY:   Avoids adding a new column every time we need a new option.
--        IMPORTANT: this is also where we record the BUSINESS TYPE and
--        which optional features are switched on for this client
--        (since we are not using a master database yet).
-- ------------------------------------------------------------------
CREATE TABLE settings (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `key`       VARCHAR(80)  NOT NULL,           -- e.g. 'currency','business_type'
  `value`     TEXT         NULL,               -- e.g. 'USD','pharmacy'
  PRIMARY KEY (id),
  UNIQUE KEY uq_setting_key (`key`)            -- each key can exist only once
) ENGINE=InnoDB;


-- ==================================================================
--  SECTION 2 — PEOPLE WHO LOG IN (users, roles, permissions)
-- ==================================================================

-- ------------------------------------------------------------------
-- TABLE: roles
-- WHAT:  Job types of staff: Owner, Manager, Cashier...
-- WHY:   We attach permissions to a ROLE, then give a user that role,
--        instead of setting permissions on each person one by one.
-- ------------------------------------------------------------------
CREATE TABLE roles (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name        VARCHAR(80) NOT NULL,            -- "Owner", "Cashier"...
  is_system   TINYINT(1)  NOT NULL DEFAULT 0,  -- 1 = built-in, can't be deleted
  created_at  TIMESTAMP   NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_role_name (name)
) ENGINE=InnoDB;

-- ------------------------------------------------------------------
-- TABLE: permissions
-- WHAT:  The list of individual actions a user can be allowed to do.
-- WHY:   Fine-grained control, e.g. a cashier can 'sales.create' but
--        not 'products.delete'. We fill this list as we build modules.
-- ------------------------------------------------------------------
CREATE TABLE permissions (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code        VARCHAR(100) NOT NULL,           -- 'sales.create','products.edit'
  description VARCHAR(150) NULL,               -- human-readable explanation
  PRIMARY KEY (id),
  UNIQUE KEY uq_perm_code (code)
) ENGINE=InnoDB;

-- ------------------------------------------------------------------
-- TABLE: role_permissions
-- WHAT:  The link saying "this role is allowed these permissions".
-- WHY:   A role has many permissions; a permission belongs to many
--        roles. This middle table connects them (many-to-many).
-- ------------------------------------------------------------------
CREATE TABLE role_permissions (
  role_id       BIGINT UNSIGNED NOT NULL,
  permission_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (role_id, permission_id),        -- no duplicate pairs
  CONSTRAINT fk_rp_role FOREIGN KEY (role_id)
       REFERENCES roles (id) ON DELETE CASCADE,
  CONSTRAINT fk_rp_perm FOREIGN KEY (permission_id)
       REFERENCES permissions (id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------------
-- TABLE: users
-- WHAT:  The staff accounts that log into the app for this client.
-- WHY:   Each user has ONE role (which decides what they can do) and
--        belongs to a branch. Passwords are stored HASHED, never plain.
-- ------------------------------------------------------------------
CREATE TABLE users (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  branch_id     BIGINT UNSIGNED NULL,          -- which branch they work at
  role_id       BIGINT UNSIGNED NOT NULL,      -- their job/role
  name          VARCHAR(120) NOT NULL,
  email         VARCHAR(150) NULL,
  phone         VARCHAR(40)  NULL,
  password_hash VARCHAR(255) NOT NULL,         -- store a HASH, never the password
  is_active     TINYINT(1)   NOT NULL DEFAULT 1,
  last_login_at TIMESTAMP    NULL DEFAULT NULL,
  created_at    TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at    TIMESTAMP    NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_user_email (email),
  KEY idx_user_branch (branch_id),
  KEY idx_user_role (role_id),
  CONSTRAINT fk_user_branch FOREIGN KEY (branch_id) REFERENCES branches (id),
  CONSTRAINT fk_user_role   FOREIGN KEY (role_id)   REFERENCES roles (id)
) ENGINE=InnoDB;


-- ==================================================================
--  SECTION 3 — WHAT THE BUSINESS SELLS (catalog)
-- ==================================================================

-- ------------------------------------------------------------------
-- TABLE: categories
-- WHAT:  Groups products together (e.g. "Painkillers", "Laptops").
-- WHY:   parent_id lets a category have sub-categories
--        (e.g. Electronics > Phones > Accessories).
-- ------------------------------------------------------------------
CREATE TABLE categories (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  parent_id   BIGINT UNSIGNED NULL,            -- NULL = top-level category
  name        VARCHAR(120) NOT NULL,
  is_active   TINYINT(1)   NOT NULL DEFAULT 1,
  created_at  TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP,
  deleted_at  TIMESTAMP    NULL DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_cat_parent (parent_id),
  CONSTRAINT fk_cat_parent FOREIGN KEY (parent_id) REFERENCES categories (id)
) ENGINE=InnoDB;

-- ------------------------------------------------------------------
-- TABLE: suppliers
-- WHAT:  The companies/people this client buys stock from.
-- WHY:   Lets us track where products came from and run purchase orders.
-- ------------------------------------------------------------------
CREATE TABLE suppliers (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name        VARCHAR(150) NOT NULL,
  phone       VARCHAR(40)  NULL,
  email       VARCHAR(150) NULL,
  address     VARCHAR(255) NULL,
  is_active   TINYINT(1)   NOT NULL DEFAULT 1,
  created_at  TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP,
  deleted_at  TIMESTAMP    NULL DEFAULT NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB;

-- ------------------------------------------------------------------
-- TABLE: products   (THE CORE TABLE — used by every business type)
-- WHAT:  Each item the business sells.
-- WHY:   Common columns (name, price, etc.) are real columns. Anything
--        industry-specific and small (voltage, dosage, color) goes in
--        the `attributes` JSON column, so we DON'T add new columns per
--        industry. Bigger industry needs (batches, serials) get their
--        own optional tables below.
-- NOTE:  track_batches / track_serials tell the app whether to show
--        those extra screens for this product.
-- ------------------------------------------------------------------
CREATE TABLE products (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  category_id   BIGINT UNSIGNED NULL,
  supplier_id   BIGINT UNSIGNED NULL,
  sku           VARCHAR(80)  NULL,             -- our internal product code
  barcode       VARCHAR(80)  NULL,             -- scanner barcode
  sealed_barcode VARCHAR(80) NULL,             -- barcode printed on a sealed package
  name          VARCHAR(180) NOT NULL,
  brand         VARCHAR(120) NULL,
  unit          VARCHAR(30)  NULL DEFAULT 'pcs', -- pcs, box, kg, litre...
  cost_price    DECIMAL(12,2) NOT NULL DEFAULT 0.00, -- what we paid
  sell_price    DECIMAL(12,2) NOT NULL DEFAULT 0.00, -- what we charge
  tax_rate      DECIMAL(5,2)  NOT NULL DEFAULT 0.00,  -- % tax on this item
  reorder_level INT           NOT NULL DEFAULT 0,     -- alert when stock <= this
  attributes    JSON          NULL,            -- {"voltage":"220V","dosage":"500mg"}
  image_path    VARCHAR(255)  NULL,            -- web-relative path under uploads/products/
  track_batches TINYINT(1)    NOT NULL DEFAULT 0, -- 1 for pharmacy/grocery items
  track_serials TINYINT(1)    NOT NULL DEFAULT 0, -- 1 for electronics items
  is_active     TINYINT(1)    NOT NULL DEFAULT 1,
  created_at    TIMESTAMP     NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP     NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at    TIMESTAMP     NULL DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_prod_category (category_id),
  KEY idx_prod_supplier (supplier_id),
  KEY idx_prod_barcode (barcode),              -- fast barcode scanning
  KEY idx_prod_sealed_barcode (sealed_barcode),
  KEY idx_prod_sku (sku),
  CONSTRAINT fk_prod_category FOREIGN KEY (category_id) REFERENCES categories (id),
  CONSTRAINT fk_prod_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers (id)
) ENGINE=InnoDB;

-- ------------------------------------------------------------------
-- TABLE: product_batches   (OPTIONAL — Pharmacy / Grocery)
-- WHAT:  Tracks stock by batch and expiry date.
-- WHY:   A pharmacy must know which batch expires when. Businesses that
--        don't need this simply never put rows here — it stays empty.
-- ------------------------------------------------------------------
CREATE TABLE product_batches (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  product_id    BIGINT UNSIGNED NOT NULL,
  branch_id     BIGINT UNSIGNED NOT NULL,
  batch_no      VARCHAR(80)  NULL,
  expiry_date   DATE         NULL,             -- the important one for pharmacy
  mfg_date      DATE         NULL,
  cost_price    DECIMAL(12,2) NULL,
  created_at    TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_batch_product (product_id),
  KEY idx_batch_branch (branch_id),
  KEY idx_batch_expiry (expiry_date),          -- fast "expiring soon" reports
  CONSTRAINT fk_batch_product FOREIGN KEY (product_id) REFERENCES products (id),
  CONSTRAINT fk_batch_branch  FOREIGN KEY (branch_id)  REFERENCES branches (id)
) ENGINE=InnoDB;

-- ------------------------------------------------------------------
-- TABLE: product_serials   (OPTIONAL — Electronics)
-- WHAT:  One row per individual item, identified by its serial number.
-- WHY:   Electronics need to track each unit and its warranty. Other
--        businesses leave this table empty.
-- ------------------------------------------------------------------
CREATE TABLE product_serials (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  product_id    BIGINT UNSIGNED NOT NULL,
  branch_id     BIGINT UNSIGNED NOT NULL,
  serial_no     VARCHAR(120) NOT NULL,
  status        ENUM('in_stock','sold','returned','defective')
                            NOT NULL DEFAULT 'in_stock',
  warranty_until DATE        NULL,
  created_at    TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_serial (product_id, serial_no), -- same serial can't repeat
  KEY idx_serial_branch (branch_id),
  CONSTRAINT fk_serial_product FOREIGN KEY (product_id) REFERENCES products (id),
  CONSTRAINT fk_serial_branch  FOREIGN KEY (branch_id)  REFERENCES branches (id)
) ENGINE=InnoDB;


-- ==================================================================
--  SECTION 4 — STOCK / INVENTORY  (read this carefully)
-- ==================================================================
--
--  IMPORTANT DESIGN CHOICE — please don't "simplify" this away:
--  We do NOT keep stock as one editable number like "quantity = 7".
--  Instead, EVERY change to stock is recorded as a separate +/- row in
--  `stock_movements` (a "ledger", like a bank statement). The current
--  quantity is the SUM of all those rows.
--
--  WHY bother?
--    1. History/audit: we can always see exactly why stock changed.
--    2. Accuracy: two cashiers selling at once can't overwrite each
--       other — each just adds a "-1" row.
--    3. Future offline mobile sync: phones can send their "-1" rows
--       and we add them up safely. No rebuild needed later.
--
--  `stock_levels` is just a fast "current total" cache so we don't have
--  to SUM the whole ledger every time we show a product. It can always
--  be recalculated from stock_movements if it ever drifts.
-- ------------------------------------------------------------------

CREATE TABLE stock_movements (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  branch_id     BIGINT UNSIGNED NOT NULL,
  product_id    BIGINT UNSIGNED NOT NULL,
  batch_id      BIGINT UNSIGNED NULL,          -- if the product uses batches
  qty_change    DECIMAL(12,3) NOT NULL,        -- POSITIVE in, NEGATIVE out
  reason        ENUM('purchase','sale','return','adjustment','transfer','opening')
                            NOT NULL,          -- why stock changed
  ref_type      VARCHAR(40)  NULL,             -- linked doc type: 'sale','purchase'
  ref_id        BIGINT UNSIGNED NULL,          -- linked doc id
  user_id       BIGINT UNSIGNED NULL,          -- who did it
  created_at    TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_mov_branch_product (branch_id, product_id),
  KEY idx_mov_product (product_id),
  KEY idx_mov_reason (reason),
  CONSTRAINT fk_mov_branch  FOREIGN KEY (branch_id)  REFERENCES branches (id),
  CONSTRAINT fk_mov_product FOREIGN KEY (product_id) REFERENCES products (id)
) ENGINE=InnoDB;

CREATE TABLE stock_levels (
  branch_id     BIGINT UNSIGNED NOT NULL,
  product_id    BIGINT UNSIGNED NOT NULL,
  quantity      DECIMAL(12,3) NOT NULL DEFAULT 0, -- current total (a cache)
  updated_at    TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (branch_id, product_id),         -- one row per product per branch
  CONSTRAINT fk_lvl_branch  FOREIGN KEY (branch_id)  REFERENCES branches (id),
  CONSTRAINT fk_lvl_product FOREIGN KEY (product_id) REFERENCES products (id)
) ENGINE=InnoDB;


-- ==================================================================
--  SECTION 5 — CUSTOMERS
-- ==================================================================

-- ------------------------------------------------------------------
-- TABLE: customers
-- WHAT:  The client's OWN customers (the shoppers who buy from them).
-- WHY:   Needed for receipts, loyalty, and credit/debt tracking.
--        `balance` can store store-credit (+) or money owed (-).
-- ------------------------------------------------------------------
CREATE TABLE customers (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name        VARCHAR(150) NOT NULL,
  phone       VARCHAR(40)  NULL,
  email       VARCHAR(150) NULL,
  address     VARCHAR(255) NULL,
  balance     DECIMAL(12,2) NOT NULL DEFAULT 0.00, -- + credit / - they owe us
  credit_limit DECIMAL(12,2) UNSIGNED NOT NULL DEFAULT 0.00,
  created_at  TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at  TIMESTAMP    NULL DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_cust_phone (phone)                   -- fast lookup by phone at POS
) ENGINE=InnoDB;


-- ==================================================================
--  SECTION 6 — SALES (the POS / cash register)
-- ==================================================================

-- ------------------------------------------------------------------
-- TABLE: sales
-- WHAT:  One row per completed sale (one receipt).
-- WHY:   `uuid` is a globally-unique code generated by the device at
--        the moment of sale. The MVP is online-only, but having the
--        uuid NOW means that when we add offline mobile sales later,
--        each sale is already uniquely identified and can never be
--        synced twice. `sync_status` is also here ready for that day.
-- ------------------------------------------------------------------
CREATE TABLE sales (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  uuid          CHAR(36)     NOT NULL,         -- e.g. 'a3f8c1e2-...' (offline-ready)
  branch_id     BIGINT UNSIGNED NOT NULL,
  user_id       BIGINT UNSIGNED NOT NULL,      -- the cashier
  customer_id   BIGINT UNSIGNED NULL,          -- optional (walk-in = NULL)
  invoice_no    VARCHAR(50)  NULL,             -- human-friendly receipt number
  subtotal      DECIMAL(12,2) NOT NULL DEFAULT 0.00, -- before tax/discount
  discount      DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  tax_total     DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  grand_total   DECIMAL(12,2) NOT NULL DEFAULT 0.00, -- final amount due
  paid_total    DECIMAL(12,2) NOT NULL DEFAULT 0.00, -- amount actually paid
  returned_total DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  refunded_total DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  return_discount_total DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  status        ENUM('completed','held','refunded','partial')
                            NOT NULL DEFAULT 'completed',
  sync_status   ENUM('synced','pending') NOT NULL DEFAULT 'synced', -- for offline later
  sold_at       TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP, -- real time of sale
  created_at    TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sale_uuid (uuid),              -- blocks duplicate/double-synced sales
  KEY idx_sale_branch (branch_id),
  KEY idx_sale_user (user_id),
  KEY idx_sale_customer (customer_id),
  KEY idx_sale_soldat (sold_at),               -- fast date-range sales reports
  CONSTRAINT fk_sale_branch   FOREIGN KEY (branch_id)   REFERENCES branches (id),
  CONSTRAINT fk_sale_user     FOREIGN KEY (user_id)     REFERENCES users (id),
  CONSTRAINT fk_sale_customer FOREIGN KEY (customer_id) REFERENCES customers (id)
) ENGINE=InnoDB;

-- ------------------------------------------------------------------
-- TABLE: sale_items
-- WHAT:  The individual lines on a receipt (one row per product sold).
-- WHY:   We copy the price/name into the row ("snapshot") so old
--        receipts stay correct even if the product price changes later.
-- ------------------------------------------------------------------
CREATE TABLE sale_items (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  sale_id       BIGINT UNSIGNED NOT NULL,
  product_id    BIGINT UNSIGNED NOT NULL,
  batch_id      BIGINT UNSIGNED NULL,          -- which batch was sold (pharmacy)
  serial_id     BIGINT UNSIGNED NULL,          -- which unit was sold (electronics)
  description   VARCHAR(180) NULL,             -- product name at time of sale
  quantity      DECIMAL(12,3) NOT NULL DEFAULT 1,
  unit_price    DECIMAL(12,2) NOT NULL DEFAULT 0.00, -- price at time of sale
  discount      DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  tax_rate      DECIMAL(5,2)  NOT NULL DEFAULT 0.00,
  line_total    DECIMAL(12,2) NOT NULL DEFAULT 0.00, -- qty * price - discount + tax
  cost_total    DECIMAL(14,2) NULL,             -- original line COGS; NULL only for legacy sales
  PRIMARY KEY (id),
  KEY idx_item_sale (sale_id),
  KEY idx_item_product (product_id),
  CONSTRAINT fk_item_sale    FOREIGN KEY (sale_id)
       REFERENCES sales (id) ON DELETE CASCADE, -- delete sale => delete its lines
  CONSTRAINT fk_item_product FOREIGN KEY (product_id) REFERENCES products (id)
) ENGINE=InnoDB;

-- ------------------------------------------------------------------
-- TABLE: payments
-- WHAT:  How a sale was paid. One sale can have several payment rows
--        (e.g. part cash + part card = "split payment").
-- ------------------------------------------------------------------
CREATE TABLE payments (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  sale_id     BIGINT UNSIGNED NOT NULL,
  method      ENUM('cash','card','mobile_money','bank','credit')
                          NOT NULL DEFAULT 'cash',
  bank_account_id BIGINT UNSIGNED NULL,        -- which money account it landed in (FK added in 04_*)
  amount      DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  reference   VARCHAR(120) NULL,               -- e.g. mobile-money transaction id
  paid_at     TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_pay_sale (sale_id),
  CONSTRAINT fk_pay_sale FOREIGN KEY (sale_id)
       REFERENCES sales (id) ON DELETE CASCADE
) ENGINE=InnoDB;


-- ==================================================================
--  SECTION 7 — PURCHASES (stock coming IN from suppliers)
-- ==================================================================

-- ------------------------------------------------------------------
-- TABLE: purchases
-- WHAT:  A record of buying stock from a supplier (a "goods received").
-- WHY:   This is what ADDS stock. Each purchase should create positive
--        rows in stock_movements (reason = 'purchase').
-- ------------------------------------------------------------------
CREATE TABLE purchases (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  branch_id     BIGINT UNSIGNED NOT NULL,
  supplier_id   BIGINT UNSIGNED NULL,
  user_id       BIGINT UNSIGNED NOT NULL,      -- who recorded it
  reference_no  VARCHAR(80)  NULL,             -- supplier invoice number
  total         DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  -- pending = ordered, nothing received yet; partially_received = some
  -- lines short of their ordered quantity; received = everything in.
  -- Only a pending PO (nothing received yet) can be cancelled.
  status        ENUM('pending','partially_received','received','cancelled')
                        NOT NULL DEFAULT 'pending',
  method          ENUM('cash','card','mobile_money','bank','cheque')
                          NOT NULL DEFAULT 'cash',  -- unused by new POs; kept for old data
  bank_account_id BIGINT UNSIGNED NULL,             -- unused by new POs; kept for old data
  purchased_at  TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP,
  created_at    TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_pur_branch (branch_id),
  KEY idx_pur_supplier (supplier_id),
  CONSTRAINT fk_pur_branch   FOREIGN KEY (branch_id)   REFERENCES branches (id),
  CONSTRAINT fk_pur_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers (id),
  CONSTRAINT fk_pur_user     FOREIGN KEY (user_id)     REFERENCES users (id)
) ENGINE=InnoDB;

-- ------------------------------------------------------------------
-- TABLE: purchase_items
-- WHAT:  The line items of a purchase (which products, how many, cost).
-- ------------------------------------------------------------------
CREATE TABLE purchase_items (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  purchase_id        BIGINT UNSIGNED NOT NULL,
  product_id         BIGINT UNSIGNED NOT NULL,
  batch_id           BIGINT UNSIGNED NULL,          -- new batch created on receipt
  quantity           DECIMAL(12,3) NOT NULL DEFAULT 1,   -- ORDERED quantity
  quantity_received  DECIMAL(12,3) NOT NULL DEFAULT 0,   -- running total actually received
  unit_cost          DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  line_total         DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (id),
  KEY idx_puritem_purchase (purchase_id),
  CONSTRAINT fk_puritem_purchase FOREIGN KEY (purchase_id)
       REFERENCES purchases (id) ON DELETE CASCADE,
  CONSTRAINT fk_puritem_product  FOREIGN KEY (product_id) REFERENCES products (id)
) ENGINE=InnoDB;

-- ------------------------------------------------------------------
-- TABLE: purchase_receipts / purchase_receipt_items
-- WHAT:  A goods-in event against a purchase order. A PO can be
--        received in one shot or several partial batches; each batch
--        is its own receipt row. Only a receipt moves stock and posts
--        the GL — creating the PO itself never does.
-- ------------------------------------------------------------------
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


-- ==================================================================
--  SECTION 8 — AUDIT LOG (who did what)
-- ==================================================================

-- ------------------------------------------------------------------
-- TABLE: audit_log
-- WHAT:  A trail of important actions taken in the system.
-- WHY:   ERPs handle money and stock, so when something looks wrong we
--        need to see who changed what and when. Helps trust + debugging.
-- ------------------------------------------------------------------
CREATE TABLE audit_log (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id     BIGINT UNSIGNED NULL,
  action      VARCHAR(80)  NOT NULL,           -- 'sale.create','product.delete'
  entity      VARCHAR(60)  NULL,               -- which table/thing was affected
  entity_id   BIGINT UNSIGNED NULL,            -- the id of that thing
  details     JSON         NULL,               -- optional extra info
  ip_address  VARCHAR(45)  NULL,
  created_at  TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_audit_user (user_id),
  KEY idx_audit_entity (entity, entity_id)
) ENGINE=InnoDB;


-- ==================================================================
--  SECTION 9 — STARTER DATA
--  Every brand-new client database begins with these rows so the app
--  works on first login. EDIT the business_type/feature flags to match
--  the client you're setting up (pharmacy, electronics, shop...).
-- ==================================================================

-- A first branch (Basic plan clients only ever use this one).
INSERT INTO branches (name, is_main) VALUES ('Main Branch', 1);

-- The three built-in roles. Permissions get attached as we build modules.
INSERT INTO roles (name, is_system) VALUES
  ('Owner', 1),
  ('Manager', 1),
  ('Cashier', 1);

-- Basic configuration + the all-important business type & feature flags.
-- >>> CHANGE 'business_type' and the 'uses_*' flags per client <<<
INSERT INTO settings (`key`, `value`) VALUES
  ('business_type',  'shop'),   -- 'shop' | 'pharmacy' | 'electronics' | 'clothing' | 'grocery'
  ('uses_batches',   '0'),      -- set '1' for pharmacy / grocery
  ('uses_serials',   '0'),      -- set '1' for electronics
  ('currency',       'USD'),
  ('tax_rate',       '0'),
  ('receipt_header', 'Thank you for your purchase');
