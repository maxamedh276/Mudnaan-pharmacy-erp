# Product scanning in POS

Use a USB/Bluetooth scanner in keyboard (HID) mode, configured to send **Enter** or **Tab**
after each barcode/QR read. The decoded value must exactly match a product's Barcode or SKU.
Keep identifiers as text to preserve leading zeros. QR codes containing unrelated websites,
product names or data not saved as that product's identifier will not match.

Scan into the product search box, or scan while focus is on the catalog/background.
The POS detects rapid keyboard input outside editable fields. It leaves payment, quantity,
customer and modal inputs alone. Devices without a suffix need to be configured to send
Enter/Tab. Camera-based scanning and device configuration are not implemented here.

Previously the search only filtered names and there was no scan-to-cart handler. The new
web-only `sales/pos/scan?code=...` lookup requires sales.create and finds exact barcode/SKU
matches across the complete product table, using current branch stock and product defaults.
Inactive, missing or ambiguous identifiers give an error instead of guessing a product.
Each successful scan adds one unit in the product's default sale mode. Repeated scans
increase quantity once each; requests run in arrival order. Sealed/opened shortages do not
open packages or consume the wrong stock pool. Checkout remains the authoritative stock check.

Receipt marketing QR output in ThermalPrintService::printBrandFooter is commented out,
ready to restore later. The receipt attribution is now `Powered by www.mudnaantechnology.com`
in thermal output and screen previews. The old receipt QR contained a company website,
not a product identifier.

Retained tests (no physical scanner/printer required):

```powershell
node tests/pos_scanner_smoke.cjs
C:/xampp/php/php.exe tests/pos_scan_lookup_smoke.php
C:/xampp/php/php.exe tests/thermal_layout_smoke.php
C:/xampp/php/php.exe tests/business_identity_smoke.php
```

Database lookup tests shadow the real tables with connection-local temporary tables and
verify real records remain unchanged. Thermal tests inspect dummy printer command bytes.
Keyboard tests simulate a keyboard-mode scanner. Physical hardware still needs one scan
and receipt-print check at the till. No schema updates are required.
