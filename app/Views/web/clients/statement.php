<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>
<div class="flex flex-wrap items-center justify-between gap-4 mb-6">
    <div><a href="<?= site_url('clients') ?>" class="text-sm text-brand-600">← Customers</a><h1 class="text-2xl font-bold mt-2"><?= esc($statement['party']) ?> — Account statement</h1></div>
    <div class="flex gap-2"><button id="exportExcel" class="border rounded-lg bg-white px-4 py-2 text-sm font-semibold">Export Excel</button><button id="exportPdf" class="border rounded-lg bg-white px-4 py-2 text-sm font-semibold">Export PDF</button></div>
</div>
<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
<?php foreach (['opening_balance' => 'Opening balance', 'total_debit' => 'Charges', 'total_credit' => 'Payments & credits', 'closing_balance' => 'Closing balance'] as $key => $label): ?>
<div class="bg-white border rounded-xl p-5"><div class="text-xs text-slate-500"><?= $label ?></div><strong id="summary-<?= $key ?>" class="block mt-2 text-xl">USD <?= number_format($statement['summary'][$key], 2) ?></strong></div>
<?php endforeach; ?>
</div>
<div class="bg-white border rounded-xl p-5 sm:p-6">
    <form id="statementFilters" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-5">
        <label class="text-xs font-semibold">From date<input type="date" name="date_from" value="<?= esc($statement['filters']['date_from'], 'attr') ?>" class="block mt-2 border p-2.5 rounded-lg w-full"></label>
        <label class="text-xs font-semibold">Through date<input type="date" name="date_to" value="<?= esc($statement['filters']['date_to'], 'attr') ?>" class="block mt-2 border p-2.5 rounded-lg w-full"></label>
        <label class="text-xs font-semibold">Transaction type<select name="type" class="block mt-2 border p-2.5 rounded-lg w-full"><option value="">All types</option><?php foreach (['sale','invoice','receipt','deposit'] as $type): ?><option value="<?= $type ?>" <?= $statement['filters']['type'] === $type ? 'selected' : '' ?>><?= ucfirst($type) ?></option><?php endforeach; ?></select></label>
        <label class="text-xs font-semibold">Reference<input type="search" name="q" placeholder="Search reference…" value="<?= esc($statement['filters']['q'], 'attr') ?>" class="block mt-2 border p-2.5 rounded-lg w-full"></label>
    </form>
    <div class="flex items-center justify-between mb-5"><span id="statementStatus" role="status" class="text-xs text-slate-500"></span><button id="clearStatementFilters" class="text-xs text-brand-600 font-semibold">Clear filters</button></div>
    <p class="text-xs text-slate-500 mb-4">Opening and closing balances include all activity in the date range; transaction totals follow the selected filters.</p>
    <div class="overflow-x-auto"><table id="statementTable" class="min-w-[760px] w-full text-left text-xs"><thead><tr class="bg-slate-50"><th class="p-3">Date</th><th class="p-3">Type</th><th class="p-3">Reference</th><th class="p-3 text-right">Charges</th><th class="p-3 text-right">Payments / credits</th><th class="p-3 text-right">Running balance</th></tr></thead><tbody></tbody></table></div>
</div>
<script type="application/json" id="statementConfig"><?= json_encode(['dataUrl' => site_url('clients/' . $customerId . '/statement/data'), 'statement' => $statement, 'excelUrl' => base_url('assets/js/vendor/exceljs.min.js'), 'pdfUrl' => base_url('assets/js/vendor/pdfmake.min.js'), 'fontsUrl' => base_url('assets/js/vendor/vfs_fonts.js')], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<?= $this->endSection() ?>
<?= $this->section('scripts') ?>
<script src="<?= base_url('assets/js/customer-statement.js') ?>"></script>
<?= $this->endSection() ?>
