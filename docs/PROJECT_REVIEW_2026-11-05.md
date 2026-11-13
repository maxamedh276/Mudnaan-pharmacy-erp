# Project and POS-58C printing review — 5 November 2026

## Scope

Reviewed recent commits, web printing entry points, printing services, tenant schema availability, receipt branding and existing regression suites. Read local Windows printer/process state and selected printer settings. No application code, real financial records, saved settings, printer configuration or database schema was changed. No physical print job was sent. This is a targeted review, not an exhaustive security audit.

## Confirmed printing blockers

1. **QZ integration is absent.** POS and Sales call the PHP `/api/v1/sales/{id}/print` endpoint; customer receipts also call server-side printing. There is no QZ browser library, WebSocket connection, printer discovery or `qz.print` call in the application. Installing QZ cannot change this transport. See `app/Views/web/pos/index.php`, `app/Views/web/sales/index.php`, `assets/js/customer-receipts.js` and `app/Services/ThermalPrintService.php`.
2. **Current saved configuration generates bytes instead of printing.** The configured `demo` tenant (`erp_tenant_demo`) has `thermal_printer_type=dummy`, `thermal_printer_name=POS80`, `thermal_paper_width=80`, and automatic drawer/cut enabled. No managed printer list or explicit enabled flag was returned by the settings query. The service defaults enabled to true; the POS page defaults its automatic-print flag to false when absent. Dummy success means a memory buffer was generated, not paper output.
3. **Managed printer activation forces a missing Laragon dependency.** `PrinterManagementService::save()` sets `thermal_print_engine=localprint_order`. `ThermalPrintService::getConnector()` then requires `C:/laragon/www/localprint_order/vendor/autoload.php`, which does not exist here. The project already declares and installs `mike42/escpos-php` through Composer. The missing path also blocks the injected dummy connector in the settings test because the file check occurs first.
4. **The existing Windows connector requires a share.** Windows reports `POS-58C`, driver `POS-58C`, port `USB001`, offline flag false, and `Shared=False`. The bundled PHP Windows connector treats a printer name as an SMB share; the installed queue alone does not satisfy that route. QZ uses the local Windows queue and does not require sharing for that path.

QZ is running: process 18996 is `C:/Program Files/QZ Tray/runtime/bin/javaw.exe`, listening on ports 8181 and 8182. This confirms a running listener, not successful browser permission, certificate trust, raw-driver support or physical USB printing. Both POS-58C and POS-76C queues reference USB001; explicitly choose POS-58C when testing. The offline flag does not prove paper or device readiness.

## Other findings

- **Tenant schema is behind the code.** `tax_rates` and `customer_receipt_allocations` are absent, as is `customer_receipts.collection_request_hash`. Migration history lists only purchase delivery scheduling and web stock adjustment details. Tax Management rendering throws a missing-table error; receipt/customer suites stop at the missing allocation table. The current tenant must be reconciled with the supplied migrations before the new features can be validated. Previous review documents describing upgrades as applied do not describe this current database.
- **Receipt attribution and tests disagree.** Latest commit `bb327f7` returns `Mudnaan Technology` from `ReceiptBrandingService`, while its constant and business identity assertions still expect `mudnaan-erp`. Treat this as a contract/test inconsistency; the requested branding may be intentional.
- **Reports export verification is blocked.** `build/erp-report-fixtures.json` is absent. Its documented generator is the receipt smoke suite with `--export-fixtures`, which cannot complete against the current missing schema. This is not evidence of an export rendering defect.
- **Cutter capability is not configurable for managed profiles.** Activation and configuration force automatic cutting, while customer collection output cuts unconditionally. Confirm the actual POS-58C variant's cutter support; the queue name alone does not establish that capability.

## Executed checks

Passed 10 JavaScript suites: customer receipt UI, customer statement exports, feedback, POS payment selection, POS print queue, POS reset confirmation, POS tax calculations, responsive behavior, web authentication session and workspace logo.

Passed 6 PHP suites: administrative roles, finance, purchasing, inventory, stock adjustment and web logout. These suites isolate writes with temporary tables and report unchanged real records.

Blocked/failed PHP suites: settings upload (missing Laragon dependency); customer receipts, customers and tax management (missing tables); responsive page rendering (renders 15 variants before Tax Management fails); business identity (changed attribution versus old assertion). Reports export JavaScript cannot load its fixture.

These results do not establish end-to-end hardware printing or complete financial validation for the missing-schema features. No concurrency suite was run.

## Recommended next work

1. Reconcile and back up the tenant schema, then apply the appropriate supplied migrations to that tenant. Re-run receipt/tax checks and generate report fixtures. Do not blindly rerun raw SQL upgrades against partially upgraded schemas.
2. Implement QZ as a browser-side web printing transport using authorized stored-document bytes, printer discovery/selection, connection and permission handling, and truthful job-submission feedback. Preserve sale/payment idempotency and do not automatically retry ambiguous physical submissions. Keep mobile/API controllers and `/api/v1` definitions unchanged; use web-specific endpoints as needed.
3. Remove the absolute Laragon dependency from the applicable print path. Retain server-side connectors only for deliberately configured server printers.
4. Select the exact queue `POS-58C`, set receipt width to 58 mm, and make cut/drawer options reflect actual hardware. Test Windows printing, QZ raw ESC/POS output, then ERP POS, Sales reprint and customer collections.
5. Align the branding constant, output and regression expectations with the intended product attribution.

Official QZ references: [Getting started](https://qz.io/docs/getting-started), [raw/base64 printing](https://qz.io/docs/raw), [demo](https://demo.qz.io/). QZ's workflow requires connecting the browser, selecting a printer and sending a job; raw driver compatibility remains a separate hardware test.
