# ERP orders, reports, settings and integrity review

## Implemented behavior

- The duplicate Invoices & Receipts sales menu item is removed. Existing bookmarks remain valid; legacy menu grants map to Sales Orders.
- Customer Receipts retains its list and Add receipt button. Its modal selects a customer and shows all outstanding orders with a checkbox, order detail eye button, total, already paid, due, applied payment, and remaining balance.
- Selecting an order fills its amount. Editing the receipt total distributes money within explicitly selected orders. Without a selection it uses FIFO, ordered by invoice date then invoice ID. Integer cents are used on both client and server.
- One receipt stores immutable allocations across multiple orders. Collection locks the customer and invoices, validates the allocation total, and updates invoices, linked POS sales, the customer balance, receipt, allocation rows, audit and Premium journal atomically. Voiding restores only that receipt’s allocations. UUID retries compare a request hash; changed allocations cannot reuse an earlier request.
- Sales, Inventory Valuation, Customer Report, Supplier Report, Income Statement, Balance Sheet, Cash Flow and Trial Balance each have a dedicated page and data endpoint. All have date/search filters and Today, Yesterday, This week and This month shortcuts. Excel and PDF export the complete successfully filtered snapshot, including scope and totals. Changing filters disables exports until the new result loads.
- Financial report calculations include original entries and their reversals together, exclude drafts, honor dates, and scope web reports to the authenticated branch. Trial Balance shows debit/credit totals and their difference; Balance Sheet includes earnings in equity. Cash flow includes chart cash descendants and bank/payment accounts and excludes internal cash transfers.
- Settings manages one business name, primary/accent/background colors, logo, receipt header/footer, thermal paper width and classic/compact thermal styles. The whole brand palette derives from the selected primary color. Existing operational and printer connection controls remain available through Operational & printer settings.
- Logo uploads accept validated PNG/JPEG/WebP up to 2 MB, check dimensions, re-encode image content, generate random PNG filenames and remove failed upload files. Names and colors are validated before atomic settings persistence.
- Direct URL access checks now protect sales, POS, supplier directory, staff, roles, audit, dashboard, reports and settings. Report data endpoints also enforce action/plan and menu grants. Existing inventory/customer/finance permission checks are preserved.
- Supplier payments require an outstanding bill and cannot exceed its due. Deleting customers with debt or unused deposits, suppliers with unpaid bills/open orders, or products with stock/open purchase quantities is blocked under a transaction. Creation and settlement reject deleted customers. Model guards protect existing API callers without changing API controllers.

## Database changes

The additive `AddCustomerReceiptCashAccount` and `AddCustomerReceiptAllocations` migrations add a selected chart account, a collection request hash, and `customer_receipt_allocations`. Both upgrades were applied to the configured local tenant without inserting test financial records. Other tenant databases must receive the corresponding migrations before deployment. Equivalent SQL upgrades are in TenantSchema 12 and 13.

## Verification

Run from the project root:

```text
php tests/customer_receipts_smoke.php --export-fixtures
php tests/customer_receipts_concurrency.php
node tests/customer_receipts_ui_smoke.cjs
node tests/reports_export_smoke.cjs
php tests/settings_upload_smoke.php
php tests/finance_web_smoke.php
php tests/purchasing_web_smoke.php
php tests/customers_web_smoke.php
php tests/inventory_web_smoke.php
php tests/stock_adjustment_web_smoke.php
node tests/customer_statement_export_smoke.cjs
```

The export fixture is generated exclusively from temporary test records. The suites verify:

- Credit/partial POS sales, multiple collections, explicit allocations, FIFO, settlement discounts and unused deposit credits; customer balances and statements reconcile.
- Duplicate and concurrent requests, changed request bodies, repeated voids and isolated branch access; concurrent FIFO submissions settle once.
- Injected post-journal failures roll back receipts, allocations, sales, inventory, purchases, bills, balances and reversals.
- Invalid amounts, negative prices/discounts, duplicate product quantities, overpayment, nonexistent/cross-customer allocations, malformed date/query arrays, invalid colors/paper width, forbidden URLs and protected record deletion.
- All eight production report snapshots generate valid XLSX and PDF files with every filtered row, scope and summary. Trial Balance and Balance Sheet differences are zero after mixed transactions and reversals. Cash-flow closing equals opening plus net movement.
- Logo file validation, random filenames, image re-encoding and size limits. Classic and compact thermal settings generate different valid ESC/POS streams through a dummy connector, with no physical printing.
- Browser inspection of the customer order table, checkbox auto-fill, order details, report quick filters and thermal style preview. No live receipts or settings were saved during browser checks.

Database writes in financial tests use connection-local InnoDB temporary tables or a disposable randomly named QA database. Logo tests use temporary settings/audit tables and remove their test files. Real record counts are checked after temporary tables are removed.

The previously failing stock-adjustment suite now passes: legacy note matching no longer references the same MySQL temporary table twice in one query.

## Dashboard and settings design

Settings now has Business details, Appearance, Receipts and Printers sections, with live color and receipt previews. Business name is the single editable identity used in desktop/mobile navigation, page titles, sign-in, POS and receipt headings. The product name is fixed as `mudnaan-erp`; legacy `system_name` settings are ignored. Operational Settings shows the same business name read-only and links to Business details. `php tests/business_identity_smoke.php` verifies consistency, HTML escaping, fixed product identity and unchanged real settings using temporary settings/audit tables. Business identity, logo upload and printer activation retain their existing validation and AJAX save flows. Sections fit mobile widths and preserve unsaved fields when switching between them.

The dashboard has a full-width personalized welcome card, four KPIs, a daily sales bar chart, payment and order-settlement pie charts, recent orders and inventory watch tables. There are no quick actions or fallback demo records. Date filters use Nairobi calendar days. Sales and collections follow the selected period; customer balances and stock alerts explicitly show the current position. Collections include initial POS tenders capped at the sale total plus active customer receipts, excluding change and settlement discounts. Every financial query is branch-scoped.

Receipt business name, location, telephone, header and footer now use ReceiptBrandingService for both browser previews and ESC/POS. Tax and shipping appear in the breakdown. Settings apply to newly loaded receipt previews and new print jobs, including reprints. ESC/POS uses printer fonts, paper width and commands rather than copying browser CSS, so its physical layout is not pixel-identical to the modal.

Additional verification: dashboard revenue reconciles with the Sales Report; collections reconcile with initial payments and active receipts; empty branches show zero activity; date padding and invalid ranges are checked. Dummy ESC/POS streams contain saved branding and automatic cutting. Desktop and 390px browser checks covered settings sections, thermal text preview, date filtering, charts and tables. No live settings or financial records were changed by these checks.

## Administrative access

The administrative role migration preserves the legacy Owner role ID and renames it Superadmin. Admin is a separate system role with full ordinary access, including appearance, business details, logo and receipt printer management. Operational Settings and its legacy settings data/update endpoint are restricted to Superadmin. The local default tenant has received this migration; other tenants need migration `2026-11-04-150000_AddAdministrativeRoleTiers` or TenantSchema 14.

These two administrative tiers have fixed action and menu access. Operational access cannot be delegated by editing a custom role, inserting a stale permission, or changing a token's role label. Administrators cannot assign Superadmin or change a Superadmin account, password, or status. Only Superadmins can assign that tier. Transactions and role locks protect the last active Superadmin from demotion or deactivation. Manager, Cashier and other custom permissions are preserved.

The staff Assigned Role column uses the service's `role` field, matching the role selector and actual permissions. Web logout expires the token cookie on the returned redirect, removes authentication state and rotates the old session ID. The login page clears the cached browser token; other tabs follow logout, and restored protected pages revalidate with the server. Browser verification confirmed login remains visible after Sign out, Back, and direct `/users` navigation.

Additional regressions: `php tests/administrative_roles_smoke.php --users-view`, `php tests/web_logout_smoke.php`, and `node tests/web_auth_session_smoke.cjs` cover role-cell rendering, cookie expiration, session rotation, repeated logout, browser-token cleanup and navigation recovery without financial writes.

Run `php tests/administrative_roles_smoke.php` to check role upgrades, effective permissions, forbidden direct URLs and GET/POST operational requests, ordinary settings saves, forged payloads, privilege escalation, account protection and rollback-safe rejections. Tests use temporary tables and verify hashes of all real access, settings and branch records afterward. Existing financial and printing suites were also rerun after the permission changes.

## Tax Management and Supplier Balances

Financial Management → Tax Management provides a searchable list and an Add tax modal with name and percentage (0–100, up to two decimal places). Adding a tax activates it; activating any saved rate deactivates its predecessor. Tax names and request IDs prevent duplicates. A transaction, tenant-specific advisory lock and unique active-slot database index protect activation and retries, including concurrent requests. The local tenant has the new empty tax configuration table; no live tax rates were inserted. Other tenants need migration `2026-11-05-100000_AddTaxManagement` or TenantSchema 15.

The active tax applies to new POS sales and is recomputed on the server. Before any managed tax is configured, existing per-product tax behavior is preserved. Each saved sale item retains its tax percentage. Switching rates does not alter prior transactions, receipts, stock, or ledger entries. Web checkout returns HTTP 409 with the latest tax when a stale open ticket needs review; the cart remains intact. Taxed partial collections and reversals reconcile, and the trial balance remains balanced. Tax management requires Settings management and its menu access; Admin and Superadmin have access.

Reports → Supplier Report lists purchases and standalone service bills, with closing payable through the selected end date, including supplier credit. The page has four quick date filters, searchable supplier selection, detail search and Excel/PDF exports. Existing report URLs and menu permission keys are retained.

Verification: `php tests/tax_management_smoke.php`, `php tests/customer_receipts_smoke.php --export-fixtures`, `php tests/customer_receipts_concurrency.php`, `node tests/pos_tax_calculation_smoke.cjs`, `node tests/pos_reset_confirmation_smoke.cjs`, and `node tests/reports_export_smoke.cjs`. Temporary tables/disposable QA databases cover invalid percentages, duplicates, concurrent additions, activation rollback, stale checkout, historical tax snapshots and report reconciliation. Export tests now cover all eight reports. Browser checks covered the tax list/modal, report navigation/date filters, and the POS tax field without saving live tax or financial records. Browser automation stopped during a legacy reset confirmation because it could not reliably identify the browser URL; the reset handler was subsequently changed to SweetAlert2 and checked in isolation.

## Scope and limits

These tests cover the listed financial and permission failure cases; they do not prove every possible misuse, deployment failure or database tampering scenario.

Inventory quantities include the current opening-stock baseline, reversed by movements after the closing date using movement effective dates; historical values are explicitly estimates using current average cost. They are not an immutable historical cost-layer valuation. Cash Flow is a cash-movement statement, rather than a classification into operating/investing/financing activities. As-of reports use closing balances through the end date; their start date does not reset those balances. Detail searches leave the full-scope statement totals intact, as explained on the page and in exports.

## Report layout and document drilldowns

All reports use full-width responsive filter grids, summary cards and styled tables with aligned amounts, status badges, empty states and accessible eye-button disclosures. Asset versioning prevents cached older report scripts from leaving new pages blank. Sidebar route matching uses route prefixes so supplier/customer/sales reports no longer open unrelated operational menus.

The Income Statement has separate closing cash/payment-account balances, period income accounts and period expense accounts. Cash is not counted as income. The Balance Sheet groups account rows by type, group and category; group and section totals include each account's own balance once. Net income is included in equity, and the closing equation displays a Balanced/Out of balance indicator. Inactive accounts and groups with historical balances remain represented.

Supplier rows expand to ordered/received/pending quantities, dated receiving values, bill settlement status and payment history. Standalone service bills remain reportable. Customer rows expand to period orders, older outstanding orders, items, receipt allocations and separate deposit credits. POS sales and their mirrored invoices appear once. Receipt/payment reversals remain visible in details. Settlement calculations reconstruct the closing date from initial payments plus effective allocations, so future payments and later reversals do not silently rewrite earlier results. Purchase bill values exclude goods received after the cutoff. Current operational order statuses are labelled separately from historical settlement figures.

Excel includes the normalized Report sheet plus a formatted Statement sheet or complete Details sheet when applicable. PDF includes the corresponding statement hierarchy or all drilldown tables. Details are exported even when their eye buttons are collapsed. Quantity precision is preserved. Edited/failed filters disable exports; aborted or late responses cannot replace the newest successful snapshot.

Verification: 257 checks in `php tests/customer_receipts_smoke.php --export-fixtures`, plus `node tests/reports_export_smoke.cjs` covering populated/empty versions of all eight reports, real XLSX/PDF bytes, expandable rows, formula-like names, quantity precision, filter edits during loading, failures, retries and out-of-order responses. Financial tests use temporary tables and verify unchanged real record counts. Browser checks covered all eight pages, customer/supplier disclosures, AJAX search, invalid date recovery and Excel/PDF downloads. No live financial records were saved.

## Sidebar logo updates

Logo upload has its own versioned script, independent of the appearance preview. A successful upload updates the sidebar, mobile header and settings image immediately, preserving unsaved settings. These views use one validated, versioned image URL after navigation/reload as well. Missing or invalid saved paths fall back to the bundled logo, and transparent PNG uploads retain alpha transparency. The logo tiles contain the full image rather than cropping it.

`php tests/settings_upload_smoke.php` verifies replacement uploads, image sanitization, transparency, rendered navigation/preview URLs and fallback paths using temporary settings/audit tables. `node tests/workspace_logo_smoke.cjs` verifies immediate updates, duplicate-click prevention, invalid files/responses, same-origin URLs and retries. `php tests/business_identity_smoke.php` confirms business-name behavior remains consistent. The follow-up live browser check was blocked by declined localhost access; no live logo/settings upload was performed. The current database had no saved `system_logo`, so the user must upload the intended logo through Settings once the updated page is loaded.

## Responsive pages and POS

Below 1024px, POS uses Products and Current order views with a persistent catalog-side order summary. Both views share the original cart and checkout; the mobile summary mirrors the displayed totals instead of recalculating money. Desktop retains both panels. The catalog grid adapts to available panel width, category buttons scroll horizontally, and the ticket's scrolling surface keeps payment/reset controls reachable on short screens. Dynamic viewport height, safe-area spacing, larger mobile controls, searchable selects and scrollable receipt/customer modals accommodate smaller screens and on-screen keyboards. Ctrl+K reveals the catalog before focusing search. Product cards support Enter/Space without duplicate activation. Browser-print overflow remains separate from the screen layout.

The shared layout constrains flex/grid children, preserves the desktop sidebar width, wraps page-title/action groups, contains wide tables (including AJAX-added tables), and allows dialogs to scroll within the viewport. Mobile navigation updates ARIA state, excludes hidden navigation from keyboard focus, traps/restores focus while open, closes with Escape, and resets correctly on desktop resize. Report date filters stack on phones and settings tabs can scroll horizontally.

Verification: `php tests/responsive_layout_smoke.php` renders 32 page variants and checks layout structure, unique checkout controls and versioned assets. `node tests/responsive_layout_smoke.cjs` covers view changes, breakpoint transitions, total mirroring, keyboard products, mobile navigation and dynamic table containment. Existing POS payment/tax/reset/print-queue and receipt UI suites pass. Isolated PHP suites pass 257 receipt/report checks, 35 finance checks, 32 purchasing checks, 23 inventory checks and 23 customer checks. No live financial records were created or updated. Live phone/tablet visual verification could not run: the saved browser permission continued to block localhost after the user authorized the check. Rendering/behavior checks do not replace that visual verification.

Customer balances stored on the customer record span the tenant; branch report/statement totals reflect the authenticated branch. Basic plans do not create Premium journal entries, so GL statements apply to journal-backed activity, rather than reconstructing books retroactively from Basic transactions.
