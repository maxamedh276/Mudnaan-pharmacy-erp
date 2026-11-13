# Role dashboards

The existing `/dashboard` route automatically chooses a dashboard from the authenticated role. Superadmin, Admin and Manager use the Admin layout. Accountant uses the finance layout. Cashier uses the personal-sales layout, including when additional report permissions have been assigned. Other custom roles with `accounting.view` use the Accountant layout; other custom roles use the Cashier layout. This does not grant new permissions or create roles.

Dashboard access requires a signed-in user with an allowed Dashboard menu. Individual sections check their module permissions. Newly created roles without actions see setup guidance instead of a post-login 403; no financial or sales information is returned for those roles. Ledger reporting requires Premium and `accounting.view` or `reports.advanced`. Document sections respect invoice/bill permissions. Staff activity and stock panels have their own checks. The server determines profile, branch and operator; URL parameters cannot switch them.

The sidebar, its menus, logo and permissions are unchanged. Only the dashboard receives full-width content and 12–16px outer padding; other pages retain their existing layout. Tailwind styles all content, with local CSS for container sizing and subtle card/chart animations. Reduced-motion preferences disable animations. No quick-action controls were added.

## Figures and scope

- Admin: branch sales and collections, ledger profit, customer/supplier dues, low stock, chart breakdowns, recent orders, financial snapshot and activity.
- Accountant: closing cash, invoices/bills due, income/expenses/profit, cash movement, expense/ageing charts, recent entries and actual reconciliation differences. Empty accounting books are labelled “No entries”; a mismatch shows “Review”. Cash closing balances are separate from income.
- Cashier: personal order values/counts, payments and receipts recorded by that cashier, their recorded refunds, outstanding own orders including older orders, hourly sales, payment/status mix, recent orders, receipts and top-selling items. Other operators’ collections on their orders affect settlement/due but do not inflate their personal collections. Refunds are attributed to the operator who records them.

Sales values are net of returns through the selected closing date. Collection totals cap initial tender at the order value, add cash receipt amounts without waived discounts, and subtract recorded cash refunds. Payment mix and collections bars are before refunds and labelled accordingly. Top-item figures are original item sales before returns and order-level adjustments, also labelled explicitly. Cash-flow charts net money accounts per entry so internal transfers are excluded. Income/profit and reconciliation use branch-scoped posted accounting, including original voided entries and their reversal entries. Closing document balances reconstruct later receipts, payments, returns and voids so historical filters remain meaningful. Date presets use the existing Nairobi business-day convention.

Date changes use `/dashboard/data` to replace dashboard panels without navigating away. Failed requests retain the last good snapshot and restore its selected period. Out-of-order responses are ignored, and revoked access clears old content. The recent-order search and tabs filter the displayed latest twelve orders, not the full sales register. Eye buttons open read-only current-order details from `/dashboard/orders/{id}`; personal dashboards reject other cashiers’ orders. No mobile/API routes or controllers changed.

## Verification and rollout

No database changes or migrations are required beyond the already-installed return schema. Assign an Accountant role the required reporting, accounting and document permissions using the existing Roles & Permissions page, if that role is not yet configured.

Role creation now includes an explicit “Accountant — read-only finance” preset, and the existing action-permissions editor includes the same selection shortcut. This grants `reports.basic`, `reports.advanced`, `accounting.view`, `invoices.view`, `bills.view`, and `banking.view`. It grants no transaction-writing, settings or user-management permissions. A name alone never grants access, and explicit menu restrictions remain effective. The web-only creation endpoint requires `users.manage`; mobile/API role controllers are unchanged.

- `php tests/role_dashboards_smoke.php`: isolated temporary tables, role/actor/branch access, financial reconciliation, closing dates, invalid filters, tampered URLs, read-only behavior and real-record fingerprints.
- `node tests/dashboard_ui_smoke.cjs`: refresh failure recovery, request races, order filtering, escaped preview content and access changes.
- `php tests/responsive_layout_smoke.php`: includes Admin, Accountant and Cashier page renders.

Live browser visual verification is blocked by the saved browser permission denying localhost. Automated HTML, financial and interaction checks can run without changing that permission.
