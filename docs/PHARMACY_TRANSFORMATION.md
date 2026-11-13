# Pharmacy transformation: source review and delivery scope

This was the initial assessment. The current delivered implementation and verification are documented in `PHARMACY_IMPLEMENTATION.md`; the application now runs from the workspace root.

Reviewed: 2026-11-11. Status: source assessment, not an implemented pharmacy system.

## Source baseline

- `main` at `5b89553` contains the older API implementation.
- `premium_erp` at `e451970` includes the browser portal and additional financial/inventory workflows. Recommended foundation for pharmacy work.
- `samal_minimarket` at `0902152` differs from premium in seven files, primarily POS pricing and associated tests. Review and port useful price protections individually; do not blindly merge shop-specific rules.
- Browser layouts and screens are PHP views under `app/Views`; assets live under `assets`. This is an integrated CodeIgniter application, not a separate frontend project.

## Existing request and transaction flow

Browser routes use `WebAuthFilter`: session/cookie JWT verification, tenant resolution, tenant database connection, active-user lookup and permission refresh. API routes use `AuthFilter`. Web controllers serve views and JSON actions; services own business transactions.

Purchase orders record intent. `PurchaseReceiptService` locks the purchase/products, checks remaining receivable quantities, records goods receipts, converts package quantities to base units, updates inventory and weighted-average cost, grows supplier payables and posts premium accounting entries.

`SalesService` validates stock and line values, saves sales/items/payments, changes stock, updates customer financial documents and posts premium accounting. Existing UUID handling supports duplicate-request protection; pharmacy changes must preserve and test it.

`InventoryService` appends stock movements and updates cached branch/product balances. `PackageInventoryService` separately tracks sealed packages and derives opened quantities from total stock. Opening a package changes the split without changing total inventory value.

`DocumentReturnService` links returns to original documents and coordinates quantities, credit notes/refunds, inventory and accounting. It is a reusable foundation, not yet a pharmacy batch disposition workflow.

## Reuse and extension map

| Area | Existing foundation | Pharmacy extension |
| --- | --- | --- |
| Identity | JWT, tenant isolation, users, roles, permissions | Pharmacist/dispensing permissions and patient access boundaries |
| Catalog | Products, barcode/SKU, category, brand, attributes | Generic name, ingredients, strength, dosage form, manufacturer, prescription classification |
| Packaging | Base unit plus one sealed package size/price | Explicit multi-level box/strip/tablet conversions and batch-aware package opening |
| Inventory | Movement ledger and branch balances; batch schema | Verified batch balances, availability status, FEFO allocation, expiry enforcement |
| Purchasing | Orders, partial receipts, supplier bills/payments | Batch and expiry capture on receipt; multiple batches per received product |
| POS | Scanning, checkout, payment methods, receipt printing | Medicine search, batch allocations, prescription-linked dispensing and labels |
| Returns | Source-linked financial/stock returns | Original batch traceability; quarantine/restock/disposal disposition |
| Finance | Accounts, journals, periods, receivables/payables, reports | Expiry/write-off accounting and reconciliation with pharmacy inventory |
| Reporting | Dashboards, exports and financial reports | Near-expiry, expired, quarantined, recalled, batch traceability and dispensing reports |
| UI | Layouts, navigation, AJAX actions, shared feedback | Pharmacy screens, terminology, batch visibility and dispensing workspace |

## Confirmed gaps from inspected source

`product_batches` and optional `batch_id` columns exist, but inspected receiving does not capture batch number/expiry or pass a batch to the stock movement. Inspected sales accept an optional batch reference without a full FEFO/expiry workflow. Package balances are branch/product aggregates, not batch-level quantities. The service/controller searches did not locate patient, prescription or FEFO implementations.

Consequently, simply enabling `track_batches` or renaming products would leave stock and dispensing incomplete. Aggregate stock checks must not permit selling expired, recalled or quarantined quantities.

## Delivery sequence and acceptance criteria

1. Create an isolated pharmacy development branch from the UI foundation. Prepare a separate development tenant/database; establish existing test results before changing behavior.
2. Add upgrade migrations and medicine catalog fields with validated API and web forms. Preserve existing product IDs, document history, accounting references and non-medicine items.
3. Implement batch receiving and a reconciled batch movement ledger. Existing stock without known batch/expiry must remain explicitly unverified until reconciled, not assigned invented dates.
4. Make stock adjustment, package opening, checkout and returns batch-aware together. FEFO allocates eligible batches deterministically; expiry/status validation occurs again inside checkout transactions. Concurrent requests cannot oversell a batch. One sale line may require allocations across several batches.
5. Add patient, prescriber, prescription and dispensing workflows. Record quantity dispensed, partial fills and the responsible staff member. Define prescription rules from the target jurisdiction and business scope before enforcing them.
6. Add quarantine, recalls, supplier/customer returns and disposal with reasons and audit trails. Customer returns must not automatically become sellable stock.
7. Deliver pharmacy navigation, dashboard, expiry alerts, labels and batch traceability reports using existing shared UI conventions.
8. Verify upgrade compatibility, tenant/branch boundaries, permissions, duplicate submissions, rollback, stock/accounting reconciliation, returns and concurrent checkout using isolated data. Conduct browser and printer checks.
9. Document installation, backup/restore, deployment and operating procedures. Only after delivery, change the Git remote to the new repository supplied by the user; preserve history unless explicitly instructed otherwise.

## Decisions still needed

- Operating country and retail, wholesale or hospital scope.
- Whether insurance billing, controlled-medicine registers, branch transfers, cold-chain records or hospital integrations belong in this delivery.
- Prescription/label requirements, expiry interpretation and pharmacist approval policy.
- Existing production data migration versus a new tenant setup.
- New repository URL at the final repository transition.

## Verification limits

This assessment reads source and compares branch contents. It does not certify runtime behavior. PHP exists at `C:/xampp/php/php.exe`; the checked-out premium worktree has no `vendor/autoload.php`, so application tests have not been run there. No production database migrations or financial test records were created.
