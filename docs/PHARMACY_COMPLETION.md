# Retail pharmacy implementation — 13 November 2026

This is an implementation record, not a claim of universal clinical or legal compliance. The application remains a single retail-pharmacy workspace using the existing ERP stock ledger and double-entry accounting.

## Research and scope

The implementation prioritises traceable medicine supply, recorded patient care, stock/storage observations, cashier accountability and recoverable business data. Reference material consulted:

- [FIP Good Pharmacy Practice](https://www.fip.org/files/fip/Statements/latest/Dossier%20003%20total.PDF): pharmacy storage, repacking, dispensing and distribution practices.
- [NHS SPS: managing medicines safely](https://sps.nhs.uk/articles/managing-medicines-safely-in-pharmacy/): ordering, storage, selection and labelling workflows.
- [NHS SPS: prescription and dispensing records](https://sps.nhs.uk/articles/prescription-and-dispensing-records-in-community-pharmacy/): traceable care/dispensing records.
- [Malaysia Ministry of Health: community pharmacy benchmarking](https://pharmacy.moh.gov.my/sites/default/files/document-upload/community-pharmacy-benchmarking-guideline-2025.pdf): storage organisation and cold-chain handling.

These sources informed software workflow design; their country-specific legal rules were not copied into a universal dispensing policy. Local requirements, medicine-specific opening instructions and clinical decisions remain the responsibility of the deploying pharmacy and authorised professionals.

## Delivered additions

| Area | Entry point | Behaviour |
|---|---|---|
| Extensible master data | Medicines & Units → Dosage forms & custom units | Register new dosage forms and unit names; distinguish count, package, volume and mass. Names/families remain stable. The medicine editor has Details, Units and Clinical Details tabs. |
| Packaging variants | Medicine form → Medicine units | Distinct names such as strip-14 and box-100, per-medicine factors/prices/barcodes, compatible volume/mass checks. |
| Clinical composition | Medicine form → Clinical details | Multiple named ingredients with recorded strengths/concentrations and route of administration. Prescriptions and sales retain historical medicine facts. No dose or interaction engine is implied. |
| Clinical review | Medicines & Units / POS | Catalog-only users create pending profiles; reviewed clinical facts need clinical-review permission to change. Pending medicines cannot be dispensed. Existing profiles are grandfathered and visibly identified until reviewed. |
| Sealed/opened stock policy | Medicine units → Stock source | Choose any released stock, sealed/unopened lots, or opened lots for each selling unit. Existing units default to any for backward compatibility. |
| Opened packs | Medicines & Stock → Stock Control → Opened Packs & Use-by | Move whole packs into a child lot with source-batch traceability. Net quantity, average cost and journal value stay unchanged. |
| Exact use-by enforcement | Opened Packs & Use-by / POS | A staff-recorded use-by date/time limits the opened lot. FEFO respects that earlier time; expired opened lots cannot be dispensed. No opening lifetime is guessed. |
| Recall inheritance & trace | Batches & Stock → Trace distribution | Parent recall/quarantine blocks opened descendants. Paginated affected invoices include child-lot sales; patient contacts require patient-record permission. |
| Split tender | POS → Split payment | Two configured payment methods on one invoice, server-resolved ledger accounts, change/due preview. |
| Current POS availability | POS → quick cards / Refresh stock | Unit-specific opened/sealed availability, targeted refresh after checkout, search refresh and a read-only retry when refreshing fails after a committed sale. |
| Scalable data entry | Catalog, prescriptions and POS | Direct medicine-row editing, registered units in the inherited catalog, and server-backed patient/customer search beyond the initial dropdown limits. |
| Held tickets | POS → Hold sale / Held tickets | Server-side, user/branch-scoped carts; no stock reservation or financial posting; live revalidation on resume; revision checks and stable checkout UUIDs. |
| Discount control | POS cart → Discount | Permission `pharmacy.discounts.apply`, bounded monetary line discount and mandatory reason retained on the invoice line. |
| Cash reconciliation | Sales & Dispensing → Cash Shifts | Physical opening count, applied cash inflows/outflows, closing count, variance explanation and permanent history. No automatic variance journal. |
| Physical-count workflow | Medicines & Stock → Physical Stock Counts | Draft counts per batch, submission, approval permission, variance reasons and stock/accounting adjustments. Any intervening movement, including a net-zero pair, prevents stale approval. |
| Patient care | Patients → click patient name | Recorded allergies, care notes, prescription list and prescription dispensing timeline; no financial prices in the clinical response. |
| Care documentation | Patient care dialog | Append-only counselling, pharmacist intervention, referral and reported-adverse-event notes with request deduplication. Clinical text is not copied into the general audit log. |
| Original prescriptions | Prescription details → Original prescription documents | Private PDF/JPEG/PNG uploads, file-type/size checks, immutable SHA-256 integrity, authorised download-only delivery and duplicate protection. |
| Recorded allergy visibility | POS → selected prescription | Display the recorded allergy text or “Not recorded”; no automated interaction or dose assessment is implied. |
| Storage monitoring | Medicines & Stock → Storage & Cold Chain | Named shelf/room/fridge/freezer locations with locally configured limits; manual readings; excursion status; required action text outside limits. |
| Pharmacy import | Medicines & Stock → Medicine Import | UTF-8 CSV template, row-level preview, custom dosage forms/units, prices, barcodes and optional opening batches. All-or-nothing, create-only and retry-safe. |
| Local UI dependencies | Login and shared web layout | Compiled Tailwind CSS and pinned Lucide, jQuery and DataTables served from local assets. Backend connectivity is still required. |
| Correct HTTP errors | Pharmacy web endpoints | Permission/token failures use real HTTP status codes; patient/history/held data remains access-controlled. |
| Web request hardening | Shared web authentication and login | Session-bound CSRF headers for fetch/XHR and native forms, login CSRF and throttling, renewed sessions/request tokens on login, HTTP-only SameSite authentication cookie and same-origin framing headers. Mobile/API routes remain unchanged. |
| Backup/restore drill | CLI: `tools/pharmacy_backup.php --verify` | Consistent application-table dump, SHA-256 manifest and restore into a new temporary database which is removed afterwards. Existing databases are never restore targets. |

## Important business rules

- Every pack conversion is relative to the base unit. Changing a saved factor is prohibited; use a distinct unit/product for different packaging.
- Registering a unit named dose does not calculate clinical dosing or concentration conversions.
- Mark liquid/base units as opened-only and bottle/pack units as sealed-only when that workflow is needed. Old medicines do not silently switch allocation policies.
- A use-by time cannot exceed the original batch expiry. Opening moves stock between lots, not into or out of the business.
- Customer returns remain quarantined and cannot be ordinarily released for resale. Existing source-receipt restrictions on supplier returns remain in place.
- Cash shifts use integrated accounting. After a user opts in by opening a first shift, cash postings require an active shift and its selected cash account. Electronic tender remains separate. Journal posting and shift closing share a per-user row lock so closing boundaries do not depend on server timezone.
- Opening float is a physical observation of money already in the drawer; it is not new revenue or an automatic capital contribution.
- Clinical history currently covers recorded prescriptions/dispensing and care notes; unlinked OTC purchases are not automatically assigned to a patient.
- Cold-chain observations are manual records. There is no temperature-sensor integration or automatic batch release/quarantine decision.

## Operations

Apply additive migrations with the project's PHP 8.4 runtime:

```powershell
.tools/php84/php.exe tools/pharmacy_backup.php --verify
.tools/php84/php.exe spark migrate
node tools/build_web_assets.cjs
```

Backups are stored beneath ignored, web-blocked `.tools/backups`. They contain sensitive application data and are not encrypted by this utility. The dump covers application tables and verified private prescription files in the adjacent `.sql.files` folder; other uploads, external SQL automation and deployment secrets are separate. Off-device retention, encryption, scheduling and a complete deployment-file recovery procedure must be configured for the actual deployment. No Windows scheduled task is installed automatically.

The asset builder uses the pinned packages already installed in `.tools/ui-assets`. Ship the generated assets with a deployment; Node packages are not needed by the running PHP application.

## Verification commands

```powershell
.tools/php84/php.exe tests/pharmacy_input_smoke.php
.tools/php84/php.exe tests/pharmacy_workspace_additions.php
.tools/php84/php.exe tests/document_returns_smoke.php
.tools/php84/php.exe tests/pharmacy_concurrency_smoke.php
node tests/pharmacy_browser_smoke.cjs
```

Financial tests create disposable `pharmacy_test_*` databases and remove them. Main application business tables must not receive fixture sales, prescriptions, receiving or adjustments. Browser tests cover actual forms, AJAX, receipt printing/reprinting, custom definitions, held/split checkout, care notes, storage, opening/dispensing liquid packs, CSV onboarding, cashier shifts, discounts and mobile layouts.

## Remaining product work / deployment boundaries

The retail workflows above are implemented. Additional product work remains for a prescriber directory and scheduled refill rules beyond the existing authorised quantity/expiry checks; formal recall case/notification follow-up beyond batch blocking and distribution trace; dedicated procurement bonus/quality scoring tools; broader pharmacy-specific profitability/loss dashboards; and complete Somali coverage. Authentication/request hardening was implemented, but this is not a penetration test or a security certification. Existing web-to-API integrations still expose their bearer token to application JavaScript, so an XSS review and production CSP design remain relevant.

External drug-information/interaction services, insurance/e-prescribing, payment-provider callbacks, sensor integrations, off-device backup destinations and physical printer compatibility require a selected provider or deployment setup. They must not be advertised as implemented merely because a related category, payment-method name or storage field exists.

The old training video predates these additions. The sales prospectus now documents 47 workspaces and 14 workflows; it is an offline presentation rather than a live application. This document deliberately distinguishes implemented workflows from remaining work.

## Release verification

- `pharmacy_workspace_additions.php`: 123 isolated integration checks, including original mixed-unit/Rx/returns cases, custom definitions, split/held POS, notes, storage, opened packs, inherited recall blocking, import, shifts, discounts, counts, money precision, clinical snapshots/review and availability.
- `pharmacy_input_smoke.php`: 31 validation/translation checks.
- `pharmacy_service_smoke.php`: 22 isolated migration/profile/patient/prescription checks.
- `pharmacy_browser_smoke.cjs`: 62 real Edge workflow checks covering the actual forms, downloads, backup drill, printing, request protection and mobile views.
- `document_returns_smoke.php`: 152 regression checks and unchanged real business-table fingerprints.
- `pharmacy_concurrency_smoke.php`: 3 assertions proving no overselling or partial records under simultaneous checkout.
- Read-only local preview: desktop medicine details fit without scrolling; mobile Save/Cancel and full-window iframe modals remain usable.

Production rollout still needs the site's timezone/currency/tax settings, staff permission review, opening-balance reconciliation, TLS/hosting configuration, off-device encrypted backup scheduling and actual printer verification. These are not implied by passing local software checks.
