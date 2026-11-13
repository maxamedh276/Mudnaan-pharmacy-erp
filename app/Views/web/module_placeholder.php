<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>

<div class="mb-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
    <div>
        <div class="flex items-center gap-3">
            <h1 class="text-2xl sm:text-3xl font-bold text-slate-900 tracking-tight">
                <?= esc($moduleName) ?>
            </h1>
            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-blue-100 text-brand-700 border border-blue-200">
                <?= esc($phase) ?>
            </span>
        </div>
        <p class="text-xs sm:text-sm text-slate-500 mt-1">
            <?= esc($description) ?>
        </p>
    </div>

    <div>
        <a href="<?= site_url('dashboard') ?>" 
           class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold text-slate-700 bg-white border border-slate-200 rounded-lg hover:bg-slate-50 shadow-2xs transition-colors">
            <i data-lucide="arrow-left" class="w-3.5 h-3.5"></i>
            <span>Back to Dashboard</span>
        </a>
    </div>
</div>

<!-- Placeholder Card -->
<div class="bg-white rounded-2xl border border-slate-200/80 p-8 sm:p-12 text-center shadow-2xs">
    <div class="w-16 h-16 bg-blue-50 text-brand-600 rounded-2xl flex items-center justify-center mx-auto mb-4 shadow-inner">
        <i data-lucide="<?= esc($icon ?? 'layers') ?>" class="w-8 h-8"></i>
    </div>
    
    <h3 class="text-lg font-bold text-slate-900 mb-2">
        <?= esc($moduleName) ?> Module Ready for <?= esc($phase) ?>
    </h3>
    
    <p class="text-sm text-slate-500 max-w-lg mx-auto mb-6">
        All backend domain services and REST API endpoints for this module are operational. The full interactive web user interface will be implemented in <span class="font-semibold text-brand-600"><?= esc($phase) ?></span>.
    </p>

    <div class="flex flex-wrap items-center justify-center gap-3">
        <a href="<?= site_url('dashboard') ?>" class="px-4 py-2 bg-brand-600 hover:bg-brand-700 text-white text-xs font-semibold rounded-lg shadow-sm transition-colors">
            View Live Dashboard
        </a>
        <a href="<?= site_url('logout') ?>" class="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-semibold rounded-lg transition-colors">
            Switch User / Sign out
        </a>
    </div>
</div>

<?= $this->endSection() ?>
