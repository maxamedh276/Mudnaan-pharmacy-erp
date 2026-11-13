<?php
$importPermission = \App\Services\Web\WorkbookImportService::PERMISSIONS[$importKind];
if (\App\Libraries\AuthContext::can($importPermission)):
?>
<button type="button" data-open-workbook-import class="px-4 py-2 text-xs border border-slate-300 bg-white hover:bg-slate-50 rounded-lg font-semibold">Import Excel</button>
<dialog id="workbookImportDialog" aria-labelledby="workbookImportTitle" class="w-11/12 max-w-3xl max-h-[90vh] overflow-y-auto rounded-xl p-0 shadow-xl backdrop:bg-slate-900/40" data-endpoint="<?= site_url('web/imports/' . $importKind) ?>">
    <form id="workbookImportForm" class="p-5 sm:p-6 space-y-4">
        <div class="flex justify-between items-center gap-3"><h2 id="workbookImportTitle" class="text-lg font-bold">Import <?= esc($importKind) ?></h2><button type="button" data-close-workbook-import aria-label="Close import" class="p-2">&times;</button></div>
        <p class="text-sm text-slate-600">Download the sample, replace its example rows, then upload your file. Keep the <strong>Data</strong> sheet and column headers. Up to 5,000 rows, 5 MB, .xlsx only.</p>
        <a href="<?= site_url('web/imports/' . $importKind . '/template') ?>" class="inline-block text-sm text-brand-600 underline font-semibold">Download sample Excel</a>
        <p class="text-xs text-slate-500">Creates new records only. Existing or repeated names, <?= $importKind === 'products' ? 'barcodes' : 'phone numbers and emails' ?> are rejected. Any error blocks the entire file. Completely empty rows are ignored.</p>
        <?php if ($importKind === 'products'): ?>
        <p class="text-xs text-slate-500">Use an existing active category path from Categories. Opening stock needs stock adjustment permission. Opening cost is per sealed package for sealed products, or per base unit for non-sealed products.</p>
        <?php else: ?>
        <p class="text-xs text-slate-500">Name is required. Phone, email and address are optional. Balances, debts and credit limits are not imported.</p>
        <?php endif; ?>
        <label class="block text-sm font-semibold" for="workbookImportFile">Excel workbook</label>
        <input type="file" id="workbookImportFile" name="workbook" accept=".xlsx" required class="block w-full border border-slate-300 rounded-lg p-3 text-sm">
        <div id="workbookImportStatus" role="status" aria-live="polite" class="text-sm"></div>
        <div id="workbookImportErrors" class="hidden max-h-64 overflow-auto border border-red-200 rounded-lg" tabindex="0" aria-label="Import validation errors"><table class="w-full text-sm text-left"><thead class="bg-red-50"><tr><th class="p-2">Excel row</th><th class="p-2">Correction needed</th></tr></thead><tbody></tbody></table></div>
        <div class="flex flex-wrap justify-end gap-2 border-t pt-4"><button type="button" data-close-workbook-import class="px-4 py-2 border rounded-lg text-sm">Cancel</button><button type="submit" id="workbookValidate" class="px-4 py-2 border border-brand-600 text-brand-600 rounded-lg text-sm font-semibold">Validate Excel</button><button type="button" id="workbookCommit" disabled class="px-4 py-2 bg-brand-600 text-white rounded-lg text-sm font-semibold disabled:opacity-40">Import all rows</button></div>
    </form>
</dialog>
<script src="<?= base_url('assets/js/workbook-import.js') ?>" defer></script>
<?php endif; ?>
