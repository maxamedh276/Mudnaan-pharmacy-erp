# Customer receipt collection and transaction review

## Findings

The project uses CodeIgniter 4 with separate tenant MySQL databases. POS sales, inventory movements, receivables, payables, accounting, and reversals are implemented in shared services; web and API controllers call those services.

An unpaid or partially paid POS sale already created a `customer_invoices` row and reduced the customer's balance by the unpaid portion. The missing web collection handler and form made that debt difficult to settle. The receipts list also read `receipt_date` and `payment_method`, while the stored fields are `received_at` and `method`.

The existing unique receipt UUID index prevented identical UUIDs from being inserted twice, but retries were not handled as successful replays. Outstanding balances were checked before transactions, allowing competing requests to pass the same check. Several services opened transactions without catching business/accounting exceptions, and did not verify transaction completion. Receipt reversals checked cancellation before locking. Customer statements treated settlement discounts as part of the initial payment.

## Resulting behavior

- Client Management → Customer Receipts shows only receipt history and an Add receipt button. The collection modal selects an outstanding customer invoice, including credit POS sales, and a cash account directly from the chart of accounts. Customer Invoices uses the same collection form.
- Collection updates the receipt, invoice, linked POS sale, customer balance, and Premium journal in one transaction. Voiding reverses those effects and preserves the receipt and reversal history.
- Collections and voids lock the customer, then read the affected financial documents under lock. A duplicate UUID returns its original receipt, including its cancelled status; changed payment details cannot reuse that UUID.
- The browser disables duplicate submissions and retains uncertain requests in tab session storage, scoped to tenant, branch, and invoice. Lost responses and page reloads preserve the original UUID and payment details. Definite validation rejections allow corrections.
- The web collection endpoint requires a specific invoice, management permission, a UUID, and an active postable account in the chart’s Cash category. Previously saved receipts can still be replayed with their original payment details. It rejects another branch's invoice, mismatched customers, inactive/missing money accounts, invalid amounts, closed periods, and overpayment including discounts.
- POS, purchase orders, goods receiving, supplier payments, standalone invoices, deposits/refunds, stock adjustments, bank-account creation, and budgets now explicitly check begin/commit and roll back on exceptions. POS/standalone invoice stock validation and goods receiving happen under locks; repeated product quantities are aggregated for stock validation, and repeated goods-receipt lines are rejected. Purchase cancellation and deposit refund status checks are serialized.
- Financial API controllers and `/api/v1` route definitions remain unchanged. Shared service protections apply to their existing callers too. Receipt UUIDs already have a unique index. The additive AddCustomerReceiptCashAccount migration persists the selected chart account in customer_receipts.account_id; it was applied to the configured local tenant. Migration 12 supplies the equivalent upgrade SQL for other existing tenants; fresh tenants include the column in the base schema. Existing receipt routing remains intact.

## Verification

Run from the project root:

```text
php tests/customer_receipts_smoke.php
php tests/customer_receipts_concurrency.php
node tests/customer_receipts_ui_smoke.cjs
php tests/finance_web_smoke.php
php tests/purchasing_web_smoke.php
php tests/customers_web_smoke.php
node tests/customer_statement_export_smoke.cjs
```

All listed checks passed. PHP syntax checks, JavaScript syntax checks, and `git diff --check` passed too.

The receipt scenarios run on both Basic and Premium: 300 total with 0, 150, or 200 paid at POS; multiple later collections; complete settlement; receipt voiding; duplicate retries; repeated void rejection; discount settlement; statement reconciliation; permissions; and validation. Injected failures after journal writes verify complete rollback for customer receipts, POS sales, goods receiving, supplier payments, and receipt reversals.

The main smoke test shadows every writable financial table with connection-local InnoDB temporary tables. Concurrency tests create a randomly named disposable QA database, run separate PHP processes, and remove that database afterward. They verify competing collections, simultaneous duplicate submissions, and simultaneous voids. Tenant financial tables receive no test records.

Selected-account tests verify direct chart selection without bank registration, posting and reversal to that exact account, invalid-account rejection, and idempotent schema migration. UI handler tests verify invoice selection, double-click protection, confirmation inside the native dialog, validation, reload recovery, and safe network retries. The live receipts layout was also inspected read-only in Chrome; read-only cashier access correctly lacked management controls, and the Owner’s Add receipt modal correctly showed invoice selection and cash accounts.

## Subsequent updates

See [ERP updates review](ERP_UPDATES_REVIEW.md) for multi-order allocations, report pages/exports, branding, and additional permission and deletion guards. The stock-adjustment issue described below has now been fixed and its suite passes.

## Earlier verification limit (resolved)

`php tests/stock_adjustment_web_smoke.php` stops in the unchanged legacy note-matching query with MySQL's `Can't reopen table: 'm'`, because that query references a temporary `stock_movements` table twice. Its initial stock mutation and journal checks passed before that point. This is separate from the passing receipt and rollback suites above.

This review covers the affected transaction paths and scenarios; it is not an exhaustive certification of every financial rule, reporting calculation, or deployment configuration in the ERP.
