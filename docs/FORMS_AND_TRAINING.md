# Forms and narrated training

The shared web layout loads `assets/css/entry-forms.css` and
`assets/js/entry-forms.js`. These provide consistent field sizes, focus states,
required markers, invalid-field styling, searchable-select sizing and mobile
spacing. Pharmacy entry dialogs include a persistent header and Save/Cancel
footer. Existing field names, validation rules and save endpoints are retained.

Workspace screens use a bounded viewport. When a dialog opens, its workspace
frame temporarily covers the viewport and background navigation becomes inert.
Closing the dialog restores the tab. Medicine entry separates Details and Units;
header/footer actions stay outside the active form panel. Switching workspace
tabs still preserves each mounted screen.

## POS receipts

Completing a POS sale opens the shared receipt modal. The POS Receipts / Reprint
button and Sales & Invoices receipt actions open the same modal. Its recent
invoice list supports invoice-number search; Print prints only the receipt
document through the browser, including Save as PDF where supported.

`GET pharmacy/receipt/{id}` reads the saved sale snapshot, items, payments and
business identity. `GET pharmacy/receipts?q=...` lists up to 30 matching invoices.
Both endpoints are branch-scoped. Users with `sales.view` can access branch
invoices; users with only `sales.create` can access their own invoices. Responses
are private and not cached. Receipt output escapes stored values and does not
include patient clinical notes.

Receipt printing is read-only. `tests/pharmacy_receipt_snapshot.php` hashes sales,
payments, stock, dispensing and journal tables in a regex-guarded disposable
fixture, allowing browser regression checks to prove that reprinting does not
change financial records. Physical printer hardware requires its normal browser
printer selection and paper configuration.

## English walkthrough

Generated files are in `deliverables/pharmacy-training/`:

- `Pharmacy-Walkthrough-English.mp4`: actual browser recording with English audio.
- `index.html`: local video player and clickable chapter menu.
- `English-captions.srt` and `.vtt`: optional captions.
- `Walkthrough-Guide.md`: chapter timestamps and narration transcript.

The recording uses fictional records in a new `pharmacy_test_<12 hex>` database.
The fixture installer and cleanup guard prohibit targeting a business database.
Passwords shown in the recording belong to that temporary fixture. English
narration is generated locally with the Windows Microsoft Zira Desktop voice.

The 24 chapters perform category/medicine/unit setup, supplier registration,
purchasing, receiving, mixed-unit cash sales, patient registration, prescription
approval and partial/final dispensing, customer returns, quarantine disposal,
supplier returns and supplier payment. Customer receipt, reporting, accounting,
staff, permissions, audit and settings screens are reviewed on screen.

### Recreate the recording

Dependencies are kept in ignored `.tools` directories. The scripts use the local
PHP 8.4 executable, installed Edge, Playwright, Windows speech synthesis and
FFmpeg. Rendering assets are served from local package copies during recording
so the video does not depend on external CDNs.

```powershell
npm install --prefix .tools/media ffmpeg-static --no-audit --no-fund
npm install --prefix .tools/ui-assets tailwindcss@3.4.17 lucide@0.468.0 jquery@3.7.1 datatables.net@1.13.7 datatables.net-responsive@2.5.0 --no-audit --no-fund
node tools/prepare_walkthrough_assets.cjs
node tools/create_pharmacy_walkthrough.cjs --prepare
powershell -NoProfile -File tools/narrate_pharmacy_walkthrough.ps1 -Manifest .tools/pharmacy-training/narration.json -OutputDirectory .tools/pharmacy-training/voice
node tools/create_pharmacy_walkthrough.cjs --rehearsal
node tools/create_pharmacy_walkthrough.cjs
node tools/finish_pharmacy_walkthrough.cjs
```

Recording resources and intermediate screen/audio files stay under
`.tools/pharmacy-training`. The assembly step checks chapter completeness,
narration timing and WAV consistency, then decodes the final MP4 to verify it.
