-- ##################################################################
--  MIGRATION 09 — SETTLEMENT DISCOUNT ON CUSTOMER RECEIPTS
--
--  Lets a receipt waive part of what the customer owes as a discount
--  (e.g. they owe 100, pay 90, you write off 10). The waived part is
--  posted to a contra-revenue account so the books stay balanced:
--     DEBIT Cash/Bank (amount) + DEBIT Sales Discounts (discount)
--     CREDIT Accounts Receivable (amount + discount)
--
--  Run once per tenant. Fresh tenants get this from the base schema.
-- ##################################################################

-- 1) Sales Discounts account (contra-revenue, under Operating Income).
INSERT INTO accounts (account_group_id, code, name, is_system)
SELECT g.id, '4200', 'Sales Discounts', 1
  FROM account_groups g
 WHERE g.code = 'INC'
   AND NOT EXISTS (SELECT 1 FROM accounts a WHERE a.code = '4200');

-- 2) The waived amount recorded on the receipt itself.
ALTER TABLE customer_receipts
  ADD COLUMN discount DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER amount;
