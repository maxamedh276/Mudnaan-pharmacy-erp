<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>
<link rel="stylesheet" href="<?= base_url('assets/css/reports.css').'?v='.filemtime(FCPATH.'assets/css/reports.css') ?>">
<div class="reports-workspace">
    <header class="report-header">
        <div><p class="report-eyebrow">Business insights</p><h1><?= esc($report['title']) ?></h1><p class="report-subtitle"><?= esc(match($report['type']) {
            'customers'=>'Customers, their orders and the payments collected.',
            'suppliers'=>'Follow purchases from ordered goods to receiving and payment.',
            'income-statement'=>'Income, expenses and profitability, with cash balances shown separately.',
            'balance-sheet'=>'Assets, liabilities and equity in one clear financial position.',
            'trial-balance'=>'Compare posted debit and credit balances across your accounts.',
            'cash-flow'=>'Understand money coming in, money going out and closing cash.',
            'inventory'=>'Closing stock quantities and estimated inventory value.',
            default=>'Review sales, settlements and outstanding order balances.',
        }) ?></p></div>
        <div class="report-exports"><button id="reportExcel" type="button" class="report-button"><i data-lucide="file-spreadsheet"></i>Export Excel</button><button id="reportPdf" type="button" class="report-button"><i data-lucide="file-text"></i>Export PDF</button></div>
    </header>
    <section class="report-card report-filter-card" aria-label="Report filters">
        <form id="reportFilters" class="report-filter-grid <?= isset($report['parties'])?'has-party':'' ?>">
            <label>From<input type="date" name="date_from" required value="<?= esc($report['filters']['date_from']) ?>"></label>
            <label>Through / as of<input type="date" name="date_to" required value="<?= esc($report['filters']['date_to']) ?>"></label>
            <?php if(isset($report['parties'])): ?><label><?= $report['type']==='customers'?'Customer':'Supplier' ?><select name="party_id" aria-label="<?= $report['type']==='customers'?'Customer':'Supplier' ?>"><option value="">All <?= $report['type']==='customers'?'customers':'suppliers' ?></option><?php foreach($report['parties'] as $party): ?><option value="<?= $party['id'] ?>" <?= (string)$party['id']===$report['filters']['party_id']?'selected':'' ?>><?= esc($party['name']) ?></option><?php endforeach; ?></select></label><?php endif; ?>
            <label class="report-search-label">Search details<input name="q" maxlength="120" value="<?= esc($report['filters']['q']) ?>" placeholder="Name, reference or account…"></label>
            <button type="submit" class="report-button report-button-primary" id="reportApply"><i data-lucide="sliders-horizontal"></i>Apply filters</button>
        </form>
        <div class="report-filter-footer"><span>Quick dates</span><div class="report-date-ranges"><?php foreach(['today'=>'Today','yesterday'=>'Yesterday','week'=>'This week','month'=>'This month'] as $key=>$label): ?><button type="button" data-report-range="<?= $key ?>"><?= $label ?></button><?php endforeach; ?></div><span class="report-currency">Amounts in USD</span></div>
    </section>
    <div id="reportSummary" class="report-kpis" aria-label="Report totals"></div>
    <div class="report-scope"><p id="reportNotice"></p><p id="reportStatus" role="status" aria-live="polite"></p></div>
    <div id="reportContent" aria-busy="false"></div>
</div>
<script type="application/json" id="reportConfig"><?= json_encode(['report'=>$report,'dataUrl'=>site_url('reports/'.$report['type'].'/data')],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?></script>
<?= $this->endSection() ?>
<?= $this->section('scripts') ?>
<script src="<?= base_url('assets/js/vendor/exceljs.min.js') ?>"></script><script src="<?= base_url('assets/js/vendor/pdfmake.min.js') ?>"></script><script src="<?= base_url('assets/js/vendor/vfs_fonts.js') ?>"></script><script src="<?= base_url('assets/js/reports.js').'?v='.filemtime(FCPATH.'assets/js/reports.js') ?>"></script>
<?= $this->endSection() ?>
