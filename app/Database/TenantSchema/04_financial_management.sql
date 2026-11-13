-- ##################################################################
-- #                                                                #
-- #   ERP SYSTEM  —  FINANCIAL MANAGEMENT MODULES                  #
-- #                                                                #
-- ##################################################################
--
--  WHAT IS THIS FILE?
--  ------------------
--  Adds the document-level financial modules to a tenant database.
--  Run it AFTER 01 + 02 + 03, on the SAME database.
--
--  THE MODULES (and how they relate to what already exists):
--
--    supplier_bills / supplier_payments      (ACCOUNTS PAYABLE)
--      A bill is what we OWE a supplier. A purchase that isn't fully
--      paid automatically creates a linked bill; standalone bills can
--      also be recorded (e.g. a service invoice). Payments settle
--      bills and post  AP debit / Cash-Bank credit.
--
--    customer_invoices / customer_receipts   (ACCOUNTS RECEIVABLE)
--      An invoice is what a customer OWES us. A POS sale that isn't
--      fully paid automatically creates a linked invoice; standalone
--      invoices can also be issued. Receipts settle invoices and post
--      Cash-Bank debit / AR credit.
--
--    customer_deposits
--      Money a customer leaves with us in advance (store credit).
--      Posts Cash debit / Customer Deposits (liability) credit.
--
--    financial_periods
--      Accounting months that can be CLOSED. The backend refuses any
--      journal entry dated inside a closed period.
--
--    bank_accounts
--      Each bank account is backed by its own GL account under
--      Current Assets, so its balance always comes from the journal.
--
--    budgets / budget_lines                   (BUDGET VS ACTUAL)
--      Planned amounts per GL account for a date range; the report
--      compares them with actual journal activity.
--
--  Same conventions as the base schema: soft deletes where documents
--  can be listed, uuid columns on money documents (offline-ready),
--  branch_id everywhere, snapshots for line items.
--
-- ##################################################################

-- NOTE: assumes you are already connected to the tenant DB.


-- ==================================================================
--  SECTION 1 — FINANCIAL PERIODS
-- ==================================================================
CREATE TABLE financial_periods (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name        VARCHAR(80)  NOT NULL,          -- e.g. 'May 2025'
  start_date  DATE         NOT NULL,
  end_date    DATE         NOT NULL,
  status      ENUM('open','closed') NOT NULL DEFAULT 'open',
  closed_by   BIGINT UNSIGNED NULL,           -- user who closed it
  closed_at   TIMESTAMP    NULL DEFAULT NULL,
  created_at  TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_period_range (start_date, end_date),
  KEY idx_period_status (status),
  CONSTRAINT fk_period_closer FOREIGN KEY (closed_by) REFERENCES users (id)
) ENGINE=InnoDB;


-- ==================================================================
--  SECTION 2 — BANK ACCOUNTS
--  Each row is mirrored by ONE postable GL account (account_id), so
--  the money in the bank is always readable from the journal.
-- ==================================================================
-- Despite the name this is the app's whole "money accounts" registry:
-- cash tills/drawers AND banks/mobile-money wallets, distinguished by
-- `type`. Every money form (sales, purchases, supplier payments,
-- receipts, deposits) picks the exact account cash lands in from here.
CREATE TABLE bank_accounts (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  account_id      BIGINT UNSIGNED NOT NULL,   -- the GL account backing this money account
  type            ENUM('cash','card','mobile_money','bank') NOT NULL DEFAULT 'bank',
  name            VARCHAR(120) NOT NULL,      -- e.g. 'Salaam Bank - USD' / 'Main Till'
  bank_name       VARCHAR(120) NULL,
  account_number  VARCHAR(60)  NULL,
  currency        VARCHAR(10)  NOT NULL DEFAULT 'USD',
  opening_balance DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  is_active       TINYINT(1)   NOT NULL DEFAULT 1,
  is_system       TINYINT(1)   NOT NULL DEFAULT 0,  -- built-in Cash/Bank: undeletable
  created_at      TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at      TIMESTAMP    NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_bank_gl_account (account_id),
  CONSTRAINT fk_bank_account FOREIGN KEY (account_id) REFERENCES accounts (id)
) ENGINE=InnoDB;

-- The built-in Cash on Hand (1000) and Bank (1010) accounts become the
-- default selectable money accounts (a fresh tenant's first till/bank).
INSERT INTO bank_accounts (account_id, type, name, currency, opening_balance, is_active, is_system)
SELECT id, 'cash', 'Cash on Hand', 'USD', 0.00, 1, 1 FROM accounts WHERE code = '1000';
INSERT INTO bank_accounts (account_id, type, name, currency, opening_balance, is_active, is_system)
SELECT id, 'bank', 'Bank',         'USD', 0.00, 1, 1 FROM accounts WHERE code = '1010';

-- Sale payments and purchases reference a money account too (added here,
-- after bank_accounts exists, since those tables live in 01_*).
ALTER TABLE payments
  ADD CONSTRAINT fk_pay_bank FOREIGN KEY (bank_account_id) REFERENCES bank_accounts (id);
ALTER TABLE purchases
  ADD CONSTRAINT fk_pur_bank FOREIGN KEY (bank_account_id) REFERENCES bank_accounts (id);


-- ==================================================================
--  SECTION 3 — SUPPLIER BILLS (Accounts Payable)
-- ==================================================================
CREATE TABLE supplier_bills (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  uuid         CHAR(36)     NOT NULL,
  branch_id    BIGINT UNSIGNED NOT NULL,
  supplier_id  BIGINT UNSIGNED NOT NULL,
  purchase_id  BIGINT UNSIGNED NULL,          -- set when auto-created from a purchase
  bill_no      VARCHAR(80)  NULL,             -- supplier's own invoice number
  bill_date    DATE         NOT NULL,
  due_date     DATE         NULL,
  subtotal     DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  tax_total    DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  total        DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  paid_total   DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  returned_total DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  refunded_total DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  return_discount_total DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  status       ENUM('unpaid','partial','paid','cancelled') NOT NULL DEFAULT 'unpaid',
  notes        VARCHAR(255) NULL,
  created_by   BIGINT UNSIGNED NULL,
  created_at   TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at   TIMESTAMP    NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_bill_uuid (uuid),
  KEY idx_bill_branch (branch_id),
  KEY idx_bill_supplier (supplier_id),
  KEY idx_bill_status (status),
  KEY idx_bill_due (due_date),
  CONSTRAINT fk_bill_branch   FOREIGN KEY (branch_id)   REFERENCES branches (id),
  CONSTRAINT fk_bill_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers (id),
  CONSTRAINT fk_bill_purchase FOREIGN KEY (purchase_id) REFERENCES purchases (id)
) ENGINE=InnoDB;

CREATE TABLE supplier_bill_items (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  bill_id     BIGINT UNSIGNED NOT NULL,
  product_id  BIGINT UNSIGNED NULL,           -- NULL = a service / non-stock line
  description VARCHAR(180) NOT NULL,          -- snapshot, like sale_items
  quantity    DECIMAL(12,3) NOT NULL DEFAULT 1,
  unit_cost   DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  line_total  DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (id),
  KEY idx_billitem_bill (bill_id),
  CONSTRAINT fk_billitem_bill FOREIGN KEY (bill_id)
       REFERENCES supplier_bills (id) ON DELETE CASCADE,
  CONSTRAINT fk_billitem_product FOREIGN KEY (product_id) REFERENCES products (id)
) ENGINE=InnoDB;


-- ==================================================================
--  SECTION 4 — SUPPLIER PAYMENTS
-- ==================================================================
CREATE TABLE supplier_payments (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  uuid            CHAR(36)     NOT NULL,
  branch_id       BIGINT UNSIGNED NOT NULL,
  supplier_id     BIGINT UNSIGNED NOT NULL,
  bill_id         BIGINT UNSIGNED NULL,       -- which bill this settles (NULL = on account)
  payment_no      VARCHAR(50)  NULL,          -- PAY-00001
  method          ENUM('cash','card','mobile_money','bank','cheque') NOT NULL DEFAULT 'cash',
  bank_account_id BIGINT UNSIGNED NULL,       -- required when method = bank/cheque
  account_id      BIGINT UNSIGNED NULL,       -- selected Chart of Accounts ledger account
  amount          DECIMAL(14,2) NOT NULL,
  reference       VARCHAR(120) NULL,
  notes           VARCHAR(255) NULL,
  paid_at         TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP,
  created_by      BIGINT UNSIGNED NULL,
  created_at      TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_spay_uuid (uuid),
  KEY idx_spay_branch (branch_id),
  KEY idx_spay_supplier (supplier_id),
  KEY idx_spay_bill (bill_id),
  CONSTRAINT fk_spay_branch   FOREIGN KEY (branch_id)       REFERENCES branches (id),
  CONSTRAINT fk_spay_supplier FOREIGN KEY (supplier_id)     REFERENCES suppliers (id),
  CONSTRAINT fk_spay_bill     FOREIGN KEY (bill_id)         REFERENCES supplier_bills (id),
  CONSTRAINT fk_spay_bank     FOREIGN KEY (bank_account_id) REFERENCES bank_accounts (id)
) ENGINE=InnoDB;


-- ==================================================================
--  SECTION 5 — CUSTOMER INVOICES (Accounts Receivable)
-- ==================================================================
CREATE TABLE customer_invoices (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  uuid         CHAR(36)     NOT NULL,
  branch_id    BIGINT UNSIGNED NOT NULL,
  customer_id  BIGINT UNSIGNED NOT NULL,
  sale_id      BIGINT UNSIGNED NULL,          -- set when auto-created from a POS sale
  invoice_no   VARCHAR(50)  NULL,             -- CINV-00001
  invoice_date DATE         NOT NULL,
  due_date     DATE         NULL,
  subtotal     DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  discount     DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  tax_total    DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  total        DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  paid_total   DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  returned_total DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  refunded_total DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  return_discount_total DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  status       ENUM('unpaid','partial','paid','cancelled') NOT NULL DEFAULT 'unpaid',
  notes        VARCHAR(255) NULL,
  created_by   BIGINT UNSIGNED NULL,
  created_at   TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at   TIMESTAMP    NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_cinv_uuid (uuid),
  KEY idx_cinv_branch (branch_id),
  KEY idx_cinv_customer (customer_id),
  KEY idx_cinv_status (status),
  KEY idx_cinv_due (due_date),
  CONSTRAINT fk_cinv_branch   FOREIGN KEY (branch_id)   REFERENCES branches (id),
  CONSTRAINT fk_cinv_customer FOREIGN KEY (customer_id) REFERENCES customers (id),
  CONSTRAINT fk_cinv_sale     FOREIGN KEY (sale_id)     REFERENCES sales (id)
) ENGINE=InnoDB;

CREATE TABLE customer_invoice_items (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  invoice_id  BIGINT UNSIGNED NOT NULL,
  product_id  BIGINT UNSIGNED NULL,           -- NULL = a service / non-stock line
  description VARCHAR(180) NOT NULL,
  quantity    DECIMAL(12,3) NOT NULL DEFAULT 1,
  unit_price  DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  discount    DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  tax_rate    DECIMAL(5,2)  NOT NULL DEFAULT 0.00,
  line_total  DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (id),
  KEY idx_cinvitem_invoice (invoice_id),
  CONSTRAINT fk_cinvitem_invoice FOREIGN KEY (invoice_id)
       REFERENCES customer_invoices (id) ON DELETE CASCADE,
  CONSTRAINT fk_cinvitem_product FOREIGN KEY (product_id) REFERENCES products (id)
) ENGINE=InnoDB;


-- ==================================================================
--  SECTION 6 — CUSTOMER RECEIPTS
-- ==================================================================
CREATE TABLE customer_receipts (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  uuid            CHAR(36)     NOT NULL,
  branch_id       BIGINT UNSIGNED NOT NULL,
  customer_id     BIGINT UNSIGNED NOT NULL,
  invoice_id      BIGINT UNSIGNED NULL,       -- which invoice this settles (NULL = on account)
  receipt_no      VARCHAR(50)  NULL,          -- RCV-00001
  method          ENUM('cash','card','mobile_money','bank','cheque') NOT NULL DEFAULT 'cash',
  bank_account_id BIGINT UNSIGNED NULL,
  account_id      BIGINT UNSIGNED NULL,       -- selected Chart of Accounts ledger account
  amount          DECIMAL(14,2) NOT NULL,
  discount        DECIMAL(14,2) NOT NULL DEFAULT 0.00,  -- settlement discount waived off the due
  reference       VARCHAR(120) NULL,
  notes           VARCHAR(255) NULL,
  received_at     TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP,
  created_by      BIGINT UNSIGNED NULL,
  created_at      TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_crcpt_uuid (uuid),
  KEY idx_crcpt_branch (branch_id),
  KEY idx_crcpt_customer (customer_id),
  KEY idx_crcpt_invoice (invoice_id),
  CONSTRAINT fk_crcpt_branch   FOREIGN KEY (branch_id)       REFERENCES branches (id),
  CONSTRAINT fk_crcpt_customer FOREIGN KEY (customer_id)     REFERENCES customers (id),
  CONSTRAINT fk_crcpt_invoice  FOREIGN KEY (invoice_id)      REFERENCES customer_invoices (id),
  CONSTRAINT fk_crcpt_bank     FOREIGN KEY (bank_account_id) REFERENCES bank_accounts (id)
) ENGINE=InnoDB;


-- ==================================================================
--  SECTION 7 — CUSTOMER DEPOSITS (advance payments / store credit)
-- ==================================================================
CREATE TABLE customer_deposits (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  uuid            CHAR(36)     NOT NULL,
  branch_id       BIGINT UNSIGNED NOT NULL,
  customer_id     BIGINT UNSIGNED NOT NULL,
  deposit_no      VARCHAR(50)  NULL,          -- DEP-00001
  method          ENUM('cash','card','mobile_money','bank','cheque') NOT NULL DEFAULT 'cash',
  bank_account_id BIGINT UNSIGNED NULL,
  amount          DECIMAL(14,2) NOT NULL,
  used_amount     DECIMAL(14,2) NOT NULL DEFAULT 0.00, -- consumed by later sales/refunds
  status          ENUM('active','used','refunded') NOT NULL DEFAULT 'active',
  reference       VARCHAR(120) NULL,
  notes           VARCHAR(255) NULL,
  deposited_at    TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP,
  created_by      BIGINT UNSIGNED NULL,
  created_at      TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_dep_uuid (uuid),
  KEY idx_dep_branch (branch_id),
  KEY idx_dep_customer (customer_id),
  KEY idx_dep_status (status),
  CONSTRAINT fk_dep_branch   FOREIGN KEY (branch_id)       REFERENCES branches (id),
  CONSTRAINT fk_dep_customer FOREIGN KEY (customer_id)     REFERENCES customers (id),
  CONSTRAINT fk_dep_bank     FOREIGN KEY (bank_account_id) REFERENCES bank_accounts (id)
) ENGINE=InnoDB;


-- ==================================================================
--  SECTION 8 — BUDGETS (Budget vs Actual)
-- ==================================================================
CREATE TABLE budgets (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  branch_id   BIGINT UNSIGNED NOT NULL,
  name        VARCHAR(120) NOT NULL,          -- e.g. 'H2 2025 Operating Budget'
  start_date  DATE         NOT NULL,
  end_date    DATE         NOT NULL,
  status      ENUM('active','archived') NOT NULL DEFAULT 'active',
  notes       VARCHAR(255) NULL,
  created_by  BIGINT UNSIGNED NULL,
  created_at  TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at  TIMESTAMP    NULL DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_budget_branch (branch_id),
  CONSTRAINT fk_budget_branch FOREIGN KEY (branch_id) REFERENCES branches (id)
) ENGINE=InnoDB;

CREATE TABLE budget_lines (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  budget_id  BIGINT UNSIGNED NOT NULL,
  account_id BIGINT UNSIGNED NOT NULL,        -- which GL account is budgeted
  amount     DECIMAL(14,2) NOT NULL DEFAULT 0.00, -- planned for the budget's range
  PRIMARY KEY (id),
  UNIQUE KEY uq_budget_account (budget_id, account_id),
  CONSTRAINT fk_bline_budget FOREIGN KEY (budget_id)
       REFERENCES budgets (id) ON DELETE CASCADE,
  CONSTRAINT fk_bline_account FOREIGN KEY (account_id) REFERENCES accounts (id)
) ENGINE=InnoDB;


-- ==================================================================
--  SECTION 9 — NEW CHART-OF-ACCOUNTS ROWS
--  Customer deposits are a liability until spent or refunded.
-- ==================================================================
INSERT INTO accounts (account_group_id, code, name, is_system)
  SELECT id, '2200', 'Customer Deposits', 1 FROM account_groups WHERE code='CL';

-- Standalone supplier bills for services (no stock) post here.
INSERT INTO accounts (account_group_id, code, name, is_system)
  SELECT id, '6900', 'General Expense', 1 FROM account_groups WHERE code='OPEX';


-- ==================================================================
--  SECTION 10 — NEW PERMISSIONS + ROLE GRANTS
-- ==================================================================
INSERT INTO permissions (code, description) VALUES
  ('bills.view',      'View supplier bills and payments'),
  ('bills.manage',    'Record supplier bills and payments'),
  ('invoices.view',   'View customer invoices, receipts and deposits'),
  ('invoices.manage', 'Issue invoices, receipts and deposits'),
  ('banking.view',    'View bank accounts (Premium)'),
  ('banking.manage',  'Manage bank accounts (Premium)'),
  ('budgets.view',    'View budgets and budget vs actual (Premium)'),
  ('budgets.manage',  'Create and edit budgets (Premium)'),
  ('periods.manage',  'Open and close financial periods (Premium)');

-- Owner: everything new.
INSERT INTO role_permissions (role_id, permission_id)
  SELECT r.id, p.id FROM roles r JOIN permissions p
  WHERE r.name = 'Owner'
    AND p.code IN ('bills.view','bills.manage','invoices.view','invoices.manage',
                   'banking.view','banking.manage','budgets.view','budgets.manage',
                   'periods.manage');

-- Manager: operate daily finance, but can't close periods.
INSERT INTO role_permissions (role_id, permission_id)
  SELECT r.id, p.id FROM roles r JOIN permissions p
  WHERE r.name = 'Manager'
    AND p.code IN ('bills.view','bills.manage','invoices.view','invoices.manage',
                   'banking.view','budgets.view');

-- Cashier: may look up customer documents at the counter.
INSERT INTO role_permissions (role_id, permission_id)
  SELECT r.id, p.id FROM roles r JOIN permissions p
  WHERE r.name = 'Cashier'
    AND p.code IN ('invoices.view');
