-- ##################################################################
--  MIGRATION 08 — MONEY ACCOUNT KINDS (cash / card / mobile money / bank)
--
--  Widens bank_accounts.type so each money account matches a real
--  payment method, not just cash-vs-bank. That lets the payment forms
--  pre-filter the account list to the method chosen (e.g. picking
--  "Mobile Money" shows only mobile-money wallets like EVC/Zaad).
--
--  Existing 'cash' and 'bank' rows are preserved. Run once per tenant.
-- ##################################################################

ALTER TABLE bank_accounts
  MODIFY COLUMN type ENUM('cash','card','mobile_money','bank') NOT NULL DEFAULT 'bank';
