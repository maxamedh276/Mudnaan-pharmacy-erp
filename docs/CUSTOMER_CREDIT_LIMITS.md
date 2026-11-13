# Customer credit limits

Admin and Superadmin (including the legacy Owner alias) can set a customer's credit limit in the Customers add/edit form or the POS quick-add form. Cashiers with customer-management permission can maintain contact details and create customers; they cannot set or raise a limit by modifying a request. Limit changes are audited with the actor and old/new values.

The default is **$0: payment in full required**. Existing customers also start at $0 after the upgrade. This preserves all existing debt, receipts, returns, deposits and accounting entries; an administrator must explicitly authorize limits before customers can take additional credit. Lowering a limit never writes off debt or changes earlier orders.

## Calculation and posting

Outstanding credit is the sum of positive unpaid amounts on non-deleted, unpaid/partial customer invoices across all branches in this tenant. A POS sale creates a linked invoice for its unpaid portion; the sale itself is not counted again. Unused prepayment deposits remain separate until applied to invoices. This figure can therefore differ from the net account balance when the customer has an unapplied deposit.

`Available credit = max(0, credit limit - outstanding invoices)`

`New debt = order grand total - payment applied to that order`

The new debt must be within available credit. An $80 debt with a $100 limit permits a $40 order when at least $20 is paid. Extra cash tender is change, not an automatic payment against older invoices; collect an older invoice using Customer Receipt. A fully paid new order is allowed even when old debt exceeds the current limit.

The POS shows the limit, outstanding invoices, available credit and the minimum order payment. It refreshes when a customer is selected, the window regains focus, a sale succeeds or Refresh Credit is clicked. Failed refreshes leave an explanatory message, and switching customers cannot apply an older response to the new selection. Displayed figures are advisory; the server always rechecks at posting.

SalesService and standalone invoice creation share the same server policy. The posting transaction locks the customer before product/document locks, reads current invoice balances with locking reads, checks the limit, and then writes the sale/invoice, payments, stock and accounting. Collections, receipt reversals, returns and admin limit edits serialize on that same customer row. Retrying a committed POS sale UUID returns the original sale even if it exhausted credit. Limit failures roll back the entire operation and leave the POS cart intact.

Receipt collections and unpaid returns restore available credit. Voiding a receipt can legitimately put existing debt above the limit; that reversal remains permitted and further credit sales are blocked. Fully paid purchases remain available.

## Production upgrade

Apply `docs/sql/2026-11-06_customer_credit_limits.sql` to each production tenant database before deploying the updated code, or run migration `2026-11-06-120000_AddCustomerCreditLimits` through the tenant migration workflow. Both are additive and safe to rerun; neither resets limits. The new-install customer schema also includes the field. No mobile/API controller or `/api/v1` route definitions changed; their shared sale/invoice services enforce the same policy.

After deploying, set limits for customers who are allowed credit. The field accepts nonnegative amounts with up to two decimal places, capped at $9,999,999,999.99 to match the existing customer-balance storage range.

## Verification

- `php tests/customer_credit_limits_smoke.php`: temporary-table financial, permission, rollback, receipt, return, branch and cent-boundary scenarios; fingerprints real records before/after.
- `php tests/customer_credit_limits_concurrency.php`: random disposable QA database; tests rerunnable production SQL, competing cashiers using different products, duplicate UUIDs, collection versus sale, admin limit reduction versus sale, and standalone invoice versus sale. Removes the QA database and verifies real records are unchanged.
- `node tests/customer_credit_ui_smoke.cjs`: minimum payment, paid-in-full behavior, customer-selection response races and refresh failure recovery.

The receipt, return and dashboard regression fixtures explicitly authorize credit so they continue testing their original financial behavior under the new default policy.
