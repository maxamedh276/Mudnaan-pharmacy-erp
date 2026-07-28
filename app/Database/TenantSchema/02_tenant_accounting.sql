-- ##################################################################
-- #                                                                #
-- #   ERP SYSTEM  —  ACCOUNTING MODULE  (Premium tier)            #
-- #                                                                #
-- ##################################################################
--
--  WHAT IS THIS FILE?
--  ------------------
--  This ADDS a proper double-entry accounting system to a tenant
--  database. Run it AFTER tenant_database.sql, on the SAME database,
--  for clients who are on the Premium plan (full management).
--  Basic-plan clients don't need it — skip this file for them.
--
--  WHAT IS "DOUBLE-ENTRY"? (quick refresher for the team)
--  ------------------------------------------------------
--  Every financial event is recorded as at least TWO lines that always
--  balance: total DEBITS must equal total CREDITS. Example — a $100
--  cash sale:
--        Cash account        DEBIT  100   (money came IN)
--        Sales Revenue       CREDIT 100   (we earned it)
--  100 = 100, so the books balance. This is the rule the whole module
--  is built to protect.
--
--  THE 5 TABLES (and how they stack):
--    account_types   -> the 5 roots (Asset, Liability, Equity, Income, Expense)
--      account_groups  -> sub-folders under a type (e.g. "Current Assets")
--        accounts        -> the real accounts = the CHART OF ACCOUNTS
--    journal_entries     -> one row per financial event (the "voucher")
--      journal_entry_lines -> the debit/credit rows of that event
--
--  HOW IT CONNECTS TO THE REST OF THE ERP:
--    A sale, purchase or payment in the operational tables generates
--    ONE journal_entry plus its lines. The `source_type`/`source_id`
--    columns on journal_entries point back to what caused it, so you
--    can always trace a ledger line to its original sale/purchase.
--
-- ##################################################################

-- NOTE: assumes you are already connected to the tenant DB
-- (the base schema's `USE` statement). If running separately, add:
-- USE `{{DB_NAME}}`;


-- ==================================================================
--  TABLE: account_types
--  WHAT: The five fundamental categories every account falls under.
--  WHY:  They decide how an account behaves and where it appears on
--        reports (Balance Sheet vs Profit & Loss). `normal_balance`
--        says which side INCREASES the account:
--          Assets   & Expenses           grow with DEBITS
--          Liabilities, Equity & Income  grow with CREDITS
--  These 5 rows are standard and should not be edited.
-- ==================================================================
CREATE TABLE account_types (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code           VARCHAR(20)  NOT NULL,        -- ASSET, LIABILITY, EQUITY, INCOME, EXPENSE
  name           VARCHAR(60)  NOT NULL,
  normal_balance ENUM('debit','credit') NOT NULL,
  -- which financial statement this type appears on:
  statement      ENUM('balance_sheet','profit_and_loss') NOT NULL,
  sort_order     INT NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_acctype_code (code)
) ENGINE=InnoDB;


-- ==================================================================
--  TABLE: account_groups
--  WHAT: Sub-folders under a type, e.g. under ASSET -> "Current Assets"
--        and "Fixed Assets". Helps organise reports into readable
--        sections instead of one long flat list.
--  WHY:  parent_id lets groups nest further if a client wants detail.
-- ==================================================================
CREATE TABLE account_groups (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  account_type_id BIGINT UNSIGNED NOT NULL,    -- which of the 5 roots
  parent_id       BIGINT UNSIGNED NULL,        -- NULL = top group under the type
  code            VARCHAR(30)  NOT NULL,
  name            VARCHAR(120) NOT NULL,
  is_active       TINYINT(1)   NOT NULL DEFAULT 1,
  created_at      TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_group_code (code),
  KEY idx_group_type (account_type_id),
  KEY idx_group_parent (parent_id),
  CONSTRAINT fk_group_type   FOREIGN KEY (account_type_id) REFERENCES account_types (id),
  CONSTRAINT fk_group_parent FOREIGN KEY (parent_id)       REFERENCES account_groups (id)
) ENGINE=InnoDB;


-- ==================================================================
--  TABLE: accounts          ( <-- THIS IS THE CHART OF ACCOUNTS )
--  WHAT: The actual accounts money is recorded against: Cash, Bank,
--        Accounts Receivable, Sales Revenue, Cost of Goods Sold, etc.
--  WHY:  Each account sits in a group (which sits under a type), so it
--        automatically inherits where it shows on reports. `code` is
--        the account number accountants use (e.g. 1000 = Cash).
--        parent_id allows sub-accounts (e.g. "Bank" > "Bank - USD").
--  NAME: We call the table `accounts` (shorter in queries); it IS your
--        Chart of Accounts.
-- ==================================================================
CREATE TABLE accounts (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  account_group_id   BIGINT UNSIGNED NOT NULL,
  parent_id          BIGINT UNSIGNED NULL,     -- for sub-accounts
  code               VARCHAR(30)  NOT NULL,    -- account number, e.g. '1000'
  name               VARCHAR(150) NOT NULL,    -- e.g. 'Cash on Hand'
  description        VARCHAR(255) NULL,
  -- a header account (e.g. "Bank") groups sub-accounts and isn't posted
  -- to directly; only "postable" accounts can receive journal lines:
  is_postable        TINYINT(1)   NOT NULL DEFAULT 1,
  is_system          TINYINT(1)   NOT NULL DEFAULT 0, -- built-in, don't delete
  is_active          TINYINT(1)   NOT NULL DEFAULT 1,
  created_at         TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at         TIMESTAMP    NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_account_code (code),
  KEY idx_account_group (account_group_id),
  KEY idx_account_parent (parent_id),
  CONSTRAINT fk_account_group  FOREIGN KEY (account_group_id) REFERENCES account_groups (id),
  CONSTRAINT fk_account_parent FOREIGN KEY (parent_id)        REFERENCES accounts (id)
) ENGINE=InnoDB;


-- ==================================================================
--  TABLE: journal_entries        ( = your "transactions-summary" )
--  WHAT: The HEADER of one financial event (also called a voucher).
--        One sale, one purchase, one expense = one journal_entry.
--  WHY:  Holds the date, description, and the link back to whatever
--        caused it (a sale, a purchase, or a manual entry). The actual
--        debit/credit amounts live in the lines table below.
--  RULE: total_debit MUST equal total_credit. The backend enforces
--        this before saving (status moves to 'posted').
--  NOTE: `uuid` mirrors the sales table so accounting can also be
--        generated from offline mobile data later without duplicates.
-- ==================================================================
CREATE TABLE journal_entries (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  uuid          CHAR(36)     NOT NULL,         -- globally unique (offline-ready)
  branch_id     BIGINT UNSIGNED NOT NULL,
  entry_no      VARCHAR(50)  NULL,             -- human-friendly voucher number
  entry_date    DATE         NOT NULL,         -- the accounting date
  description   VARCHAR(255) NULL,             -- memo, e.g. "Cash sale #1042"
  -- traceability back to the operational document that created this:
  source_type   VARCHAR(40)  NULL,             -- 'sale','purchase','payment','manual'
  source_id     BIGINT UNSIGNED NULL,          -- id in that source table
  total_debit   DECIMAL(14,2) NOT NULL DEFAULT 0.00, -- must equal total_credit
  total_credit  DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  status        ENUM('draft','posted','void') NOT NULL DEFAULT 'posted',
  created_by    BIGINT UNSIGNED NULL,          -- user id
  created_at    TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_journal_uuid (uuid),
  KEY idx_journal_branch (branch_id),
  KEY idx_journal_date (entry_date),           -- fast date-range reports
  KEY idx_journal_source (source_type, source_id),
  CONSTRAINT fk_journal_branch FOREIGN KEY (branch_id) REFERENCES branches (id)
) ENGINE=InnoDB;


-- ==================================================================
--  TABLE: journal_entry_lines    ( = your "transaction-details" )
--  WHAT: The individual debit/credit rows of a journal entry.
--  WHY:  Each line hits ONE account with EITHER a debit OR a credit
--        (never both). All the lines of one entry must sum so that
--        debits = credits. `branch_id` is repeated here so we can build
--        profit & loss reports per branch.
--  GUARD: The two CHECK rules below stop a single line from holding
--         both a debit and a credit, and stop negatives. (The full
--         "debits = credits per entry" balance is checked in the
--         backend, since that spans multiple rows.)
-- ==================================================================
CREATE TABLE journal_entry_lines (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  journal_entry_id  BIGINT UNSIGNED NOT NULL,
  account_id        BIGINT UNSIGNED NOT NULL,
  branch_id         BIGINT UNSIGNED NOT NULL,
  debit             DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  credit            DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  description       VARCHAR(255) NULL,         -- optional per-line memo
  created_at        TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_line_entry (journal_entry_id),
  KEY idx_line_account (account_id),
  KEY idx_line_branch (branch_id),
  CONSTRAINT fk_line_entry   FOREIGN KEY (journal_entry_id)
       REFERENCES journal_entries (id) ON DELETE CASCADE,
  CONSTRAINT fk_line_account FOREIGN KEY (account_id) REFERENCES accounts (id),
  CONSTRAINT fk_line_branch  FOREIGN KEY (branch_id)  REFERENCES branches (id),
  -- a line is either a debit OR a credit, not both:
  CONSTRAINT chk_line_one_side  CHECK (debit = 0 OR credit = 0),
  -- no negative amounts allowed:
  CONSTRAINT chk_line_positive  CHECK (debit >= 0 AND credit >= 0)
) ENGINE=InnoDB;


-- ==================================================================
--  SEED DATA — a ready-to-use starter Chart of Accounts
--  Gives every new Premium client a working set of accounts for a
--  typical retail/POS business. They can rename/add/remove later.
--  We insert by looking codes up (not hard-coded ids) so it stays
--  correct no matter what auto-increment values are assigned.
-- ==================================================================

-- 1) The five fixed account types ---------------------------------
INSERT INTO account_types (code, name, normal_balance, statement, sort_order) VALUES
  ('ASSET',     'Assets',      'debit',  'balance_sheet',   1),
  ('LIABILITY', 'Liabilities', 'credit', 'balance_sheet',   2),
  ('EQUITY',    'Equity',      'credit', 'balance_sheet',   3),
  ('INCOME',    'Income',      'credit', 'profit_and_loss', 4),
  ('EXPENSE',   'Expenses',    'debit',  'profit_and_loss', 5);

-- 2) Groups under each type ---------------------------------------
INSERT INTO account_groups (account_type_id, code, name)
  SELECT id, 'CA',  'Current Assets'        FROM account_types WHERE code='ASSET';
INSERT INTO account_groups (account_type_id, code, name)
  SELECT id, 'FA',  'Fixed Assets'          FROM account_types WHERE code='ASSET';
INSERT INTO account_groups (account_type_id, code, name)
  SELECT id, 'CL',  'Current Liabilities'   FROM account_types WHERE code='LIABILITY';
INSERT INTO account_groups (account_type_id, code, name)
  SELECT id, 'EQ',  'Owner''s Equity'       FROM account_types WHERE code='EQUITY';
INSERT INTO account_groups (account_type_id, code, name)
  SELECT id, 'INC', 'Operating Income'      FROM account_types WHERE code='INCOME';
INSERT INTO account_groups (account_type_id, code, name)
  SELECT id, 'COS', 'Cost of Sales'         FROM account_types WHERE code='EXPENSE';
INSERT INTO account_groups (account_type_id, code, name)
  SELECT id, 'OPEX','Operating Expenses'    FROM account_types WHERE code='EXPENSE';

-- 3) The starter accounts (the Chart of Accounts) -----------------
-- Assets
INSERT INTO accounts (account_group_id, code, name, is_system)
  SELECT id, '1000', 'Cash on Hand',          1 FROM account_groups WHERE code='CA';
INSERT INTO accounts (account_group_id, code, name, is_system)
  SELECT id, '1010', 'Bank Account',          1 FROM account_groups WHERE code='CA';
INSERT INTO accounts (account_group_id, code, name, is_system)
  SELECT id, '1100', 'Accounts Receivable',   1 FROM account_groups WHERE code='CA';
INSERT INTO accounts (account_group_id, code, name, is_system)
  SELECT id, '1200', 'Inventory',             1 FROM account_groups WHERE code='CA';
-- Liabilities
INSERT INTO accounts (account_group_id, code, name, is_system)
  SELECT id, '2000', 'Accounts Payable',      1 FROM account_groups WHERE code='CL';
INSERT INTO accounts (account_group_id, code, name, is_system)
  SELECT id, '2100', 'Tax Payable',           1 FROM account_groups WHERE code='CL';
-- Equity
INSERT INTO accounts (account_group_id, code, name, is_system)
  SELECT id, '3000', 'Owner''s Capital',      1 FROM account_groups WHERE code='EQ';
INSERT INTO accounts (account_group_id, code, name, is_system)
  SELECT id, '3100', 'Retained Earnings',     1 FROM account_groups WHERE code='EQ';
-- Income
INSERT INTO accounts (account_group_id, code, name, is_system)
  SELECT id, '4000', 'Sales Revenue',         1 FROM account_groups WHERE code='INC';
INSERT INTO accounts (account_group_id, code, name, is_system)
  SELECT id, '4100', 'Other Income',          1 FROM account_groups WHERE code='INC';
INSERT INTO accounts (account_group_id, code, name, is_system)
  SELECT id, '4200', 'Sales Discounts',       1 FROM account_groups WHERE code='INC';
-- Cost of Sales
INSERT INTO accounts (account_group_id, code, name, is_system)
  SELECT id, '5000', 'Cost of Goods Sold',    1 FROM account_groups WHERE code='COS';
INSERT INTO accounts (account_group_id, code, name, is_system)
  SELECT id, '5100', 'Inventory Adjustment',  1 FROM account_groups WHERE code='COS';
-- Operating Expenses
INSERT INTO accounts (account_group_id, code, name, is_system)
  SELECT id, '6000', 'Salaries Expense',      1 FROM account_groups WHERE code='OPEX';
INSERT INTO accounts (account_group_id, code, name, is_system)
  SELECT id, '6100', 'Rent Expense',          1 FROM account_groups WHERE code='OPEX';
INSERT INTO accounts (account_group_id, code, name, is_system)
  SELECT id, '6200', 'Utilities Expense',     1 FROM account_groups WHERE code='OPEX';
