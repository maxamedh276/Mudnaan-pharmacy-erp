
Customer statement exports: ExcelJS 4.4.0 and pdfmake 0.2.20 (with bundled Roboto fonts), downloaded from their official npm registry packages. Licenses are included alongside the browser distributions.

QZ Tray connector: `qz-tray.js` version 2.3.0, from the installed official QZ Tray demo; LGPL-2.1-only. Source: https://github.com/qzind/tray (js/qz-tray.js). License: https://github.com/qzind/tray/blob/master/LICENSE.txt.

ERP patch: the pre-signed WebSocket send path includes the configured signAlgorithm, matching the callback-signing path. Without this field QZ defaults to SHA1 and rejects our SHA512 signatures. Keep this patch when updating the connector unless upstream implements equivalent behavior. The script URL is versioned to invalidate cached copies.
