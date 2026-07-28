-- ##################################################################
--  MIGRATION 07 — MONEY ACCOUNTS (cash tills + bank / wallet accounts)
--
--  Turns bank_accounts into the single "money accounts" registry that
--  the whole app selects from when recording WHERE cash physically
--  lands. It adds:
--
--    - type ('cash' | 'bank')  so a business can keep several cash
--      tills/drawers as well as banks and mobile-money wallets, and
--      every money form can pick the exact account hit.
--    - is_system                so the built-in Cash on Hand / Bank
--      rows can't be renamed away or deleted.
--    - bank_account_id on sale payments and on purchases, so those two
--      paths route to the chosen account exactly like supplier
--      payments / customer receipts / customer deposits already do
--      (previously they were hard-wired to Cash on Hand).
--
--  Fresh tenants get all of this from the base schema files; run this
--  ONCE per existing tenant DB. Assumes you are connected to it.
-- ##################################################################

-- 1) Money-account type + system flag ------------------------------
ALTER TABLE bank_accounts
  ADD COLUMN type      ENUM('cash','bank') NOT NULL DEFAULT 'bank' AFTER account_id,
  ADD COLUMN is_system TINYINT(1)          NOT NULL DEFAULT 0       AFTER is_active;

-- 2) Register the built-in Cash on Hand (1000) and Bank (1010) as
--    selectable system money accounts, backed by their existing GL
--    accounts. The NOT EXISTS guard makes this safe to re-run.
INSERT INTO bank_accounts (account_id, type, name, currency, opening_balance, is_active, is_system)
SELECT a.id, 'cash', 'Cash on Hand', 'USD', 0.00, 1, 1
  FROM accounts a
 WHERE a.code = '1000'
   AND NOT EXISTS (SELECT 1 FROM bank_accounts b WHERE b.account_id = a.id);

INSERT INTO bank_accounts (account_id, type, name, currency, opening_balance, is_active, is_system)
SELECT a.id, 'bank', 'Bank', 'USD', 0.00, 1, 1
  FROM accounts a
 WHERE a.code = '1010'
   AND NOT EXISTS (SELECT 1 FROM bank_accounts b WHERE b.account_id = a.id);

-- 3) Route SALE payments to a chosen money account -----------------
ALTER TABLE payments
  ADD COLUMN bank_account_id BIGINT UNSIGNED NULL AFTER method,
  ADD CONSTRAINT fk_pay_bank FOREIGN KEY (bank_account_id) REFERENCES bank_accounts (id);

-- 4) Route PURCHASE payments to a chosen money account -------------
ALTER TABLE purchases
  ADD COLUMN method          ENUM('cash','card','mobile_money','bank','cheque')
                             NOT NULL DEFAULT 'cash' AFTER status,
  ADD COLUMN bank_account_id BIGINT UNSIGNED NULL AFTER method,
  ADD CONSTRAINT fk_pur_bank FOREIGN KEY (bank_account_id) REFERENCES bank_accounts (id);
