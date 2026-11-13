# Pharmacy implementation

For the 13 November additions (custom master data, opened packs, split/held POS, cash shifts, care notes, storage monitoring, imports, local assets and backup verification), see [PHARMACY_COMPLETION.md](PHARMACY_COMPLETION.md). The verification totals and deployment boundaries below describe the earlier baseline.

The application now runs from the workspace root on `pharmacy-management`. It includes the ERP browser interface and the pharmacy implementation. The old Git remote has been removed. Old ERP source is retained as local backup/history rather than separate development workspaces.

## Delivered workflows

- Medicine categories, generic/brand names, strengths, manufacturers, storage instructions and prescription classification.
- Tablet, capsule, syrup, suspension, injection, infusion, drops, cream, ointment, inhaler and other dosage forms.
- Multiple selling units with per-medicine conversions/prices/barcodes: tablet, strip, box, bottle, ml and others. Conversion factors are immutable and are snapshotted into existing sale/purchase items.
- Batch/expiry receiving, FEFO allocation across lots, blocking expired/quarantined/recalled stock, opening/correction stock, disposal and reconciliation of unverified legacy inventory.
- Patients and recorded allergies; prescription registration, pharmacist approval, partial/final dispensing, remaining-quantity enforcement and printable dispensing labels.
- Dispensing POS with units, barcode/SKU entry, tax, payment accounts, credit customers and duplicate sale protection.
- Existing purchase orders, supplier bills/payments, customer invoices/receipts, returns, accounting, users/roles, audit, settings and financial reports.
- Batch reports, expiry alerts, quarantine/recall visibility and CSV export.
- English/Somali pharmacy labels. Existing finance/admin screens retain their English interface.

## Transaction and traceability design

Medicine profiles reference existing product IDs. Pharmacy selling units use the product's base unit; they do not invent a second financial inventory. Existing stock movement rows and `stock_levels` remain the ledger and aggregate cache. Sale transactions lock products before reading or allocating batch quantities; allocations update the batch ledger and aggregate inventory atomically.

The existing SalesService validates prescription medicine lines even when called by an older API/POS entry point. Approved prescription quantities are compared against prior dispensing in base units. Returns do not silently restore prescription entitlement. UUID retries return the original sale instead of dispensing again.

Customer returns follow the existing credit-note/refund accounting and enter a non-sellable quarantine lot. Ordinary release controls cannot turn customer-return stock into saleable stock. Supplier returns remove only batches attached to receipts of the original purchase. Expired/recalled goods may be removed this way.

Opening/correction and disposal use existing inventory adjustment accounting. Reconciliation of legacy stock moves quantities from unallocated movements into a verified batch without changing total physical quantity or value. Unverified legacy quantities are excluded from FEFO availability. Reconcile legacy sealed-package products before converting them to pharmacy units.

Labels print recorded prescription directions and batch/expiry information. The application does not generate clinical dosing instructions.

## Installation and schema

`CompletePortalSchema` supplies missing inherited payment/POS/shipping schema. `AddPharmacyFoundation` adds medicines, patients and prescriptions with immutable medicine snapshots. `AddPharmacyOperations` adds selling units, batch states, stock request deduplication, dispensing links, receiving references and permissions/roles. `CompletePharmacyPortal` supplies inherited role/user menu tables.

The fresh installer refuses to overwrite an existing database. Medical and traceability migrations refuse destructive rollback when records exist. Review existing ERP schema and migration history before upgrading populated data.

The previous configured `erp_tenant_demo` database was absent locally. A fresh `pharmacy_main` database now contains initial categories, roles/accounts and an administrator; no existing business database was reset. Credentials are privately stored in the ignored `.tools/LOCAL_ACCESS.json`. Local URL: `http://127.0.0.1:8085`.

The portable PHP 8.4 runtime, browser tools, screenshots and old source backups live under ignored `.tools`. XAMPP's PHP 8.0 installation was not modified. The application requires PHP 8.2+.

## Verification

Passed: 29 input/translation checks, 22 service/migration checks, 23 full pharmacy integration checks, 3 concurrent-checkout assertions and 14 real-browser workflow checks (91 new assertions/checks). The existing document-return regression suite also passed 152 checks while confirming real records were unchanged.

New suites cover input/translation validation, real migration retries and protected rollback, access isolation, units, stock receiving, FEFO, prescription approval/fills, retries, source-batch supplier returns, quarantined customer returns, accounting balance and simultaneous checkout contention. Browser testing creates a tablet/strip/box medicine, receives a box, sells strips, records and approves a prescription, checks Somali selection and a mobile viewport, then removes its isolated fixture database.

No automated financial test writes target the local pharmacy database. Physical thermal printing, production backup/restore, insurance and external drug-information integrations are outside the checks performed here. Country-specific dispensing rules still need deployment-specific configuration and validation.
