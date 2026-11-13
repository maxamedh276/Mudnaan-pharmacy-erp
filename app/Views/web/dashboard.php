<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>
<link rel="stylesheet" href="<?= base_url('assets/css/dashboard.css') . '?v=' . filemtime(FCPATH . 'assets/css/dashboard.css') ?>">
<?php $labels = ['admin' => ['Admin Dashboard', 'Business overview'], 'accountant' => ['Accountant Dashboard', 'Finance workspace'], 'cashier' => ['Cashier Dashboard', 'My sales overview']]; ?>
<section id="roleDashboard" data-profile="<?= esc($dashboard['profile'], 'attr') ?>" data-notice="<?= esc($dashboardNotice??'', 'attr') ?>" data-url="<?= site_url('dashboard/data') ?>" data-order-url="<?= site_url('dashboard/orders') ?>" class="w-full min-w-0">
    <header class="flex flex-wrap items-center justify-between gap-3 mb-3">
        <div class="flex flex-wrap items-center gap-2"><h1 class="text-xl sm:text-2xl font-bold tracking-tight text-slate-900"><?= esc($labels[$dashboard['profile']][0]) ?></h1><span class="bg-brand-50 text-brand-700 border border-brand-100 rounded-full text-[11px] font-semibold px-2.5 py-1"><?= esc($labels[$dashboard['profile']][1]) ?></span></div>
        <form id="dashboardFilters" method="get" class="flex flex-wrap items-center gap-2 text-xs">
            <label for="dashboardRange" class="sr-only">Reporting period</label>
            <select id="dashboardRange" name="range" class="w-36 rounded-lg border border-slate-200 bg-white p-2 text-xs">
                <?php foreach (['today'=>'Today','yesterday'=>'Yesterday','7days'=>'Last 7 days','30days'=>'Last 30 days','month'=>'This month'] as $key=>$label): ?><option value="<?= $key ?>" <?= $dashboard['range']===$key?'selected':'' ?>><?= $label ?></option><?php endforeach; ?>
            </select>
            <span id="dashboardDates" class="bg-white rounded-lg border border-slate-200 px-3 py-2 text-slate-600"><?= esc($dashboard['from'] . ' — ' . $dashboard['to']) ?></span>
            <noscript><button class="bg-brand-600 text-white rounded-lg px-3 py-2">Apply</button></noscript>
        </form>
    </header>
    <p id="dashboardLoading" role="status" aria-live="polite" class="hidden mb-3 text-xs text-brand-700">Updating dashboard…</p>
    <div id="dashboardPanels" aria-busy="false"><?= view('web/dashboard/panels', ['dashboard'=>$dashboard]) ?></div>
</section>
<dialog id="dashboardOrderDialog" aria-labelledby="dashboardOrderTitle" class="w-full max-w-2xl p-0 rounded-2xl border border-slate-200 shadow-xl backdrop:bg-slate-900/40"><div class="p-4 sm:p-5"><div class="flex items-center justify-between gap-3 mb-4"><h2 id="dashboardOrderTitle" class="text-lg font-bold">Order details</h2><button id="closeDashboardOrder" type="button" aria-label="Close order details" class="rounded-lg p-2 text-slate-500 hover:bg-slate-100">✕</button></div><div id="dashboardOrderBody" role="status"></div></div></dialog>
<?= $this->endSection() ?>
<?= $this->section('scripts') ?><script src="<?= base_url('assets/js/dashboard.js') . '?v=' . filemtime(FCPATH . 'assets/js/dashboard.js') ?>"></script><?= $this->endSection() ?>
