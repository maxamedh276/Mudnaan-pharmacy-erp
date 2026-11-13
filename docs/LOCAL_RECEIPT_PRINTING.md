# QZ Tray receipt printing

## Deploying this update

Deploy the updated ERP files together, including `app/Controllers/Web/PrintingWebController.php`, the new web routes and partial, `assets/js/qz-printing.js`, and the bundled `assets/js/vendor/qz-tray.js`. Deploy all changed views and services too. This change needs no database migration. Existing project Composer dependencies are still required. No `localprint_order` folder or Laragon installation is needed.

On each cashier computer, install the printer driver and QZ Tray, and keep QZ running. Open the production ERP over HTTPS. Under Settings → Printers, activate the exact Windows queue name, for example `POS-58C`. Under Receipts, save 58 mm paper width for POS-58C. Click **Test QZ printer** and allow the QZ connection, printer access and printing prompts. POS, Sales reprints, customer collections and Operational Settings test printing now use QZ. Existing mobile/API controllers and `/api/v1` definitions are unchanged.

PHP generates base64 ESC/POS bytes from authorized stored documents, scoped to the authenticated branch. The browser connects to QZ on the cashier computer and submits those bytes. Web jobs do not open server printer connectors. Printer profiles remain shared tenant settings; computers need a matching queue name to use the same Windows profile. Network profiles use QZ's host/port transport from the cashier computer.

## Signing setup for multiple clients and computers

Settings → Printers now includes **QZ signing for this computer**. Each organization and browser profile has separate signing files. Settings management permission is required to upload them; ordinary authorized printing users can obtain the public certificate and signatures, never the private key.

One-time production server setup:

1. Create a protected writable folder outside the entire web document root, owned by the PHP service account (for example `/home/account/private/qz-signing`, not `public_html`, or `C:/ProgramData/MudnaanERP/qz-signing` on Windows).
2. Add `QZ_SIGNING_DIRECTORY = "/home/account/private/qz-signing"` to the production `.env`, using the real folder path. Grant filesystem access only to the PHP service account and administrators. Back up this private directory securely.
3. Deploy the changed routes, controller, signing services, settings partial and JavaScript together. No database migration is required. PHP OpenSSL must be enabled.

The local XAMPP `.env` has this directory configured outside htdocs under the current user's Local AppData. This machine-specific `.env` and its directory are not pushed.

For each cashier PC/browser profile:

1. Generate demo files through QZ Tray → Advanced → Site Manager → + → Create New. Follow QZ's prompts to generate/install its demo trust files on that PC.
2. Open the ERP over HTTPS in production and sign in as an administrator. Open Settings → Printers on that PC.
3. Upload `digital-certificate.txt` and the matching unencrypted `private-key.pem` from that PC's Desktop “QZ Tray Demo Cert” folder. The private key is transmitted only to your own ERP server for protected storage. Uploading does not install trust on another PC.
4. Reload the page. Test the printer. If QZ shows the trusted certificate and offers it, choose Remember this decision → Allow. Never choose permanent Block to try to suppress print prompts.

QZ describes these free demo keys as intended for development and specific to the installation where they were generated. This feature does not make them globally trusted production certificates or supply Premium Support. Validate each installation; for centrally trusted multi-client deployment use an appropriate trusted certificate arrangement. Official instructions: https://qz.io/docs/signing.

The browser computer ID is stored in localStorage. A different browser/profile, a different origin (localhost versus production) or cleared storage creates another ID and requires uploading the pair for that profile. Uploading replaces the active pair only after certificate validity, RSA strength and key match checks. Files are versioned on disk; retain/protect backups and remove obsolete private versions according to your key rotation policy. Private keys cannot be downloaded from this UI. The Git repository contains no generated keys or client certificates.

Without a pair configured for this computer, printing still uses the usual QZ permission prompts. A broken configured signing setup fails explicitly rather than silently falling back to unsigned printing. Requests use SHA512 signatures over QZ's SHA256 hex request digest. The signing endpoint allows printer discovery and only recently generated, session-owned receipt bytes for their exact target, organization, branch and computer. Arbitrary file/USB commands and extra copies are rejected.

## Failures and retrying

If QZ is closed, start it and manually retry the saved receipt. If the exact queue is missing, correct the printer name in Settings. If a submission fails after starting, inspect the physical device before retrying: paper may already have printed. Jobs are serialized and duplicate pending clicks are suppressed. They are never automatically resubmitted after an ambiguous failure. Printing does not create another sale or payment. Success means QZ accepted/completed its submission, not proof of paper output.

## Verification — 5 November 2026

The official QZ demo connected to QZ 2.3.0 and printed a simple ESC/POS slip to POS-58C; the user confirmed physical output. After this implementation, Settings → Test QZ printer submitted the ERP's thermal test slip and QZ logged `Printing complete`. The user reported a power outage, so physical output of that ERP slip remains unconfirmed and will be tested manually. No further hardware jobs were sent after that report. No financial records or saved settings were changed during verification.

Automated checks: `php tests/settings_upload_smoke.php`, `node tests/qz_printing_smoke.cjs`, `node tests/pos_print_queue_smoke.cjs`, `node tests/pos_payment_selection_smoke.cjs`, and `node tests/customer_receipts_ui_smoke.cjs`. Temporary settings/audit tables verify unchanged real records. Checks cover byte generation without hardware/Laragon, authorization, missing/inaccessible documents, disabled printers, exact queue matching, base64 transport, serialization, duplicate suppression, errors and manual retry. Syntax checks also passed.

Manual follow-up: test 58 mm layout, POS saved-sale reprint, customer receipt output, permissions on the production origin, printer disconnection/recovery and actual cutter capability. Managed profiles currently force the cutter setting; the queue name alone does not establish cutter support.

## Production failure logs

Deploy the logging update with both the web controller/routes and the versioned `qz-printing.js`. Printer failures are logged at **error** level, included by the default production logging threshold (4). Check `writable/logs/log-YYYY-MM-DD.log` on the production server and search for `QZ_PRINT` or the reference UUID shown in the user's error message. The log directory must be writable and logging must not be disabled by deployment environment overrides.

Example (illustrative):

```text
ERROR - ... --> QZ_PRINT {"attempt":"...","source":"browser","kind":"sale","document_id":123,"user_id":7,"branch_id":1,"tenant":"demo","printer":"POS-58C","type":"windows","stage":"connection","outcome":"failed","code":"permission_denied"}
```

Stages: `generation` = server could not build the receipt; `configuration` = invalid/disabled target; `library` = QZ JavaScript missing; `connection` = cannot connect to QZ; `discovery` = printer lookup failed; `submission` = QZ did not confirm sending the job. Browser reason codes include `permission_denied`, `certificate_error`, `timeout`, `printer_not_found` and `unavailable`. Server generation exceptions record the exception class; look for corresponding framework errors for further investigation. No receipt bytes, customer details, raw browser exception messages or credentials are included in these diagnostic records.

Server ready events and QZ acceptance events use **info** level. To capture those too, temporarily configure `logger.threshold = 7` in the production `.env`; this also enables other application info logs. Restore the previous threshold after investigation. `qz_accepted` is a browser-reported submission acknowledgement, not verification of paper output.

If the browser cannot contact the production server, its failure report cannot reach the server log. Open browser Developer Tools → Console and search `QZ_PRINT`; the reference and stage are printed there too. Reports are best-effort and never retry the physical print job. Only authenticated AJAX reports for a recently generated session-owned attempt are accepted, with one terminal report per attempt. Pending metadata is capped at 20 attempts and expires after ten minutes. Client reports remain untrusted diagnostics.

## Receipt appearance and QZ Allow prompts

Sale/customer paper receipts now use a QR payload of `https://mudnaantechnology.com` and a normal-size bold attribution on two lines: `Powered by` / `Mudnaan Technology`. Headers and sale totals use emphasis instead of double-width/double-height glyphs. Set 58 mm paper width for POS-58C. These are ESC/POS printer fonts, not CSS font weights; physical darkness also depends on the printer and paper.

Remembered approval requires a certificate trusted by the local QZ installation and signed requests. Use the signing setup above; receipt formatting alone does not change QZ permissions.

Signing checks: `php tests/qz_signing_smoke.php` verifies cryptographic signatures, organization/workstation separation, invalid keys, public-storage rejection and signing scope using disposable keys outside the project. The JavaScript suite checks signed discovery/submission and that signing errors never cause unsigned fallback. Real QZ trust/remembered approval still requires the matching locally generated certificate to be installed by the administrator and a manual printer check.

## Narrow receipt layout follow-up

Thermal sale tables now use Item / Qty / Total for both widths. Totals come from each stored line total, not a new quantity/price calculation. The layout budgets 42 Font-A columns for 80 mm and 32 for 58 mm, including separators, and wraps long descriptions within their column. Long metadata wraps without displacing the amount column. The fixed-width sale body is centered within the printer's available print area and uses double-strike for additional weight. The centered shop title uses proportional 2×2 size, emphasis and double-strike; its size is reset before body text. The QR-to-footer extra feed and blank configured footer lines are removed. Printer firmware still controls the QR quiet zone and physical print density. Select the paper width matching the actual roll; a narrower setting cannot turn a physically 80 mm printer into a 58 mm device.

`php tests/thermal_layout_smoke.php` exercises both widths, long descriptions, fractional/large quantities, stored totals, title mode resets and footer spacing entirely through dummy connectors, without creating financial records or printing paper. Verify the new layout on the actual hardware before considering physical margins and darkness confirmed.

Receipt price column: sale tables now show Item / Qty / Price / Total on both widths. Price uses the stored unit_price and Total uses the stored line_total; amounts are not recalculated. Long descriptions wrap inside the item column. If unusually large numeric values leave too little room, the item uses labelled Qty, Price and Total lines to preserve every digit.

## Invalid Signature after a successful certificate upload

A connector defect was found in the pre-signed request path: it omitted `signAlgorithm` even after selecting SHA512. QZ defaults an omitted algorithm to SHA1, so a matching certificate/key pair could still produce “Cannot verify trust - Invalid Signature.” The vendored connector now includes SHA512 metadata for pre-signed discovery and print requests, matching its normal callback-signing behavior. The connector asset URL is versioned to invalidate browser caches after deployment. No certificate regeneration or key upload is needed for this specific fix. After deploying, fully reload the production ERP and print again. If an invalid-signature warning remains, inspect the QZ request details and log before changing certificate trust.

`node tests/qz_wire_signature_smoke.cjs` runs the actual QZ connector against a fake WebSocket and verifies the serialized discovery/print signatures using ephemeral RSA keys, including the algorithm field. It does not contact hardware or install trust certificates.

## Checkout printing and closed accounting periods

Successful web checkout returns the current auto-print enabled flag and starts the serialized QZ print immediately after the save response, before rendering the receipt preview or clearing the ticket. Paid, partial and zero-payment credit orders follow the same print path. A printer-settings read failure cannot turn a committed sale into a failed checkout. Disabled managed printers remain disabled. `node tests/pos_checkout_print_smoke.cjs` covers these cases and confirms a rejected checkout never prints or clears the cart.

`PERIOD_CLOSED` means the sale date lies inside a closed accounting period, including for an unpaid credit sale (which posts receivables and revenue). The web controller returns a friendly HTTP 409 instead of exposing a RuntimeException. It does not bypass closure, reopen periods or alter transaction dates. An authorized administrator must review Financial Periods and reopen the relevant period only if that is appropriate. Isolated receipt-suite checks verify no partial sale, stock, customer debt or ledger changes survive rejection.

Replacement laptop: a new browser computer ID creates another signing folder. New credentials do not delete or revoke the previous computer's credentials. Re-uploading for the same computer ID switches its active pair but retains private version files. Retire a lost/compromised computer separately, including its server signing credentials and account sessions; generating a new pair for another PC is not revocation.
