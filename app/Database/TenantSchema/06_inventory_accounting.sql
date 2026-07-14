-- ##################################################################
-- #                                                                #
-- #   ERP SYSTEM  —  INVENTORY ACCOUNTING                          #
-- #                                                                #
-- ##################################################################
--
--  Run AFTER 01–05 on the SAME tenant database. Idempotent.
--
--  WHY: manual stock adjustments (write-offs, count corrections,
--  opening stock) change the value of inventory, so they must hit the
--  books. This adds the offsetting account they post against:
--
--     5100 Inventory Adjustment  (under Cost of Sales)
--
--  Postings the backend now makes:
--     stock UP   : DEBIT 1200 Inventory / CREDIT 5100 Inventory Adjustment
--     stock DOWN : DEBIT 5100 Inventory Adjustment / CREDIT 1200 Inventory
--     opening    : DEBIT 1200 Inventory / CREDIT 3000 Owner's Capital
--
-- ##################################################################

INSERT INTO accounts (account_group_id, code, name, is_system)
  SELECT g.id, '5100', 'Inventory Adjustment', 1
  FROM account_groups g
  WHERE g.code = 'COS'
    AND NOT EXISTS (SELECT 1 FROM accounts a WHERE a.code = '5100');
