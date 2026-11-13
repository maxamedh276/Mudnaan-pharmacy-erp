# Sales and purchase returns

Sales Management → Sales Returns and Supplier Management → Purchase Returns each provide a searchable register, Add return modal and detail view. Choose an original order, select its lines, enter quantities and a reason, review the server preview, and confirm. Refunds require an active cash account from the chart of accounts. A supplier refund means money actually received from the supplier; record it only after receiving that money.

Unpaid balances are reduced first. Any excess paid portion is refunded immediately. A previously waived receipt discount is reversed before calculating refundable cash. Partial returns cannot exceed the original quantities less previous returns. Purchase returns are limited to goods actually received and stock still on hand. Purchase quantities received remain unchanged: returning goods does not authorize receiving the same order again. Replacement goods need their own purchase order.

Sales returns restore inventory; original sale quantities and goods-receiving documents remain immutable. Order discounts and shipping are allocated proportionally across original sale lines. Cumulative rounding guarantees that partial returns sum to the original values and tax, even after price/tax changes. New sales snapshot line COGS. For older Premium sales, original posted COGS is recovered and allocated across lines using catalog cost weights, then frozen on first return. Basic legacy sales without accounting history use catalog cost at the first return. Historical line costs cannot be reconstructed more precisely where no snapshot exists.

Purchase inventory leaves at current average cost; any difference from the supplier's original credit value goes to Inventory Adjustment. Credit notes, stock movements/cache, customer or supplier documents, refunds and journals share one transaction boundary. Row locks serialize competing returns, receipts, receiving and payments. Unique request IDs prevent duplicate posting; a changed payload cannot reuse a posted request ID. The confirmation preview is checked again under locks before posting. Current closed periods reject returns, including Basic transactions. Existing posted accounting is also adjusted after a plan downgrade.

Original sales totals remain available alongside returned and net totals. Invoice/bill totals and paid amounts reflect credits/refunds. Statements add dated return/refund events and reconstruct original payments. Customer/supplier report closing balances exclude later returns, and their detail tables include return history. Sales receipt previews and ESC/POS output show returns, refunds and net total. Posted returns are retained permanently; there is no edit/delete action. A payment reversal that would make a refunded document's settled balance negative is rejected.

## Installation

For production using phpMyAdmin, select the existing tenant database and import `docs/sql/2026-11-05_document_returns_upgrade.sql`. This complete, rerunnable SQL upgrade is equivalent to the PHP migration: two return tables, nine return/refund counters, the nullable original line-cost snapshot and action permissions. Export a database backup first. Do not import the complete base tenant schema into an existing production database. Repeat the upgrade for each tenant before using the updated application. Existing balances and transactions are preserved.

Run migration `2026-11-05-160000_AddDocumentReturns` against **each tenant database before using this version**. With that tenant configured as the default database, use `php spark migrate --all`. The upgrade is additive and idempotent, creates empty return tables, adds counters/cost snapshots and registers `purchases.return`; it does not create sample transactions. The configured local tenant has already received the upgrade. Fresh tenant SQL schemas also include the required columns; run `16_document_returns.sql` after the earlier schema files.

Superadmin and Admin have access automatically. Other roles require both the appropriate menu and action permission: `sales.returns` + `sales.refund`, or `suppliers.returns` + `purchases.return`. Set these in Roles & Permissions, including user-specific menu overrides where applicable. Direct URLs and AJAX endpoints enforce the same checks and use the authenticated branch. Mobile/API controllers and `/api/v1` routes are unchanged.

## Verification

- `php tests/document_returns_upgrade_smoke.php`: runs the standalone production SQL twice against a disposable pre-return database and checks columns, permissions and preserved financial amounts.
- `php tests/document_returns_smoke.php`: temporary InnoDB tables; unpaid/partial/paid cases on Basic/Premium, receipt discounts, tax changes, legacy costs, partial receiving, unavailable stock, foreign lines/branches/accounts, invalid quantities, stale preview, closed periods, failed journal posting, protected URLs and historical report balances. Hash checks verify real records remain unchanged.
- `php tests/document_returns_concurrency.php`: simultaneous PHP workers in a randomly named disposable QA database; duplicate/competing returns and returns racing customer collections or supplier payments.
- `node tests/document_returns_ui_smoke.cjs`: cancellation, duplicate prevention during posting, request reuse after failure, escaped text, disabled exhausted lines and late preview recovery.
- `php tests/responsive_layout_smoke.php`: includes both return pages in the shared page-render suite.

Browser-based visual verification has not been performed for this change. Physical refunds are recorded by the ERP; the application does not initiate a bank or mobile-wallet transfer.
