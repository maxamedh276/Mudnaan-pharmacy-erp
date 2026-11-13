<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>
<section id="categoryWorkspace" data-base-url="<?= site_url('inventory/categories') ?>" data-products-url="<?= site_url('inventory/products') ?>" data-can-edit="<?= \App\Libraries\AuthContext::can('products.edit') ? 'true' : 'false' ?>" data-can-delete="<?= \App\Libraries\AuthContext::can('products.delete') ? 'true' : 'false' ?>" data-can-create="<?= \App\Libraries\AuthContext::can('products.create') ? 'true' : 'false' ?>">
    <div class="flex flex-wrap items-center justify-between gap-4 mb-6">
        <div><h1 class="text-2xl font-bold text-slate-900">Product Categories</h1><p class="text-sm text-slate-500 mt-2">Browse your category hierarchy and its products.</p></div>
        <div class="flex gap-2"><a href="<?= site_url('inventory/products') ?>" class="px-4 py-2 text-xs font-semibold rounded-lg border border-slate-200 bg-white">Products catalog</a><?php if (\App\Libraries\AuthContext::can('products.create')): ?><button id="addCategory" class="px-4 py-2 text-xs font-semibold rounded-lg bg-brand-600 hover:bg-brand-700 text-white">+ Add category</button><?php endif; ?></div>
    </div>
    <div class="bg-white border border-slate-200 rounded-xl p-5 sm:p-6">
        <label for="categorySearch" class="sr-only">Search categories</label><input id="categorySearch" type="search" placeholder="Search a category or its full path…" class="w-full px-4 py-3 mb-5 text-sm border border-slate-200 rounded-lg focus:ring-2 focus:ring-brand-100 focus:border-brand-600 outline-none">
        <div class="overflow-x-auto"><table class="w-full min-w-[680px] text-left text-xs"><thead class="bg-slate-50 text-slate-500"><tr><th class="p-3">Category</th><th class="p-3">Full path</th><th class="p-3">Products</th><th class="p-3">Status</th><th class="p-3 text-right">Actions</th></tr></thead><tbody id="categoryRows" class="divide-y divide-slate-100"></tbody></table></div>
        <p id="categoryCount" class="text-xs text-slate-400 mt-4" role="status"></p>
    </div>
    <script type="application/json" id="categoryInitialData"><?= json_encode($categories, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
</section>
<dialog id="categoryDialog" aria-labelledby="categoryDialogTitle" class="w-11/12 max-w-2xl max-h-[90vh] overflow-y-auto rounded-2xl p-0 shadow-xl backdrop:bg-slate-900/40">
<form id="categoryForm" class="p-6 space-y-5"><div class="flex items-center justify-between"><h2 id="categoryDialogTitle" class="font-bold text-slate-900">Add category</h2><button type="button" data-close-category aria-label="Close category form" class="p-2 text-slate-400">✕</button></div>
<label class="block text-xs font-semibold text-slate-600">Category name<input id="categoryName" required maxlength="120" class="block mt-2 w-full border border-slate-300 rounded-lg p-2.5"></label>
<fieldset><legend class="text-xs font-semibold text-slate-600 mb-3">Parent category</legend><div id="parentCategoryPicker" class="grid grid-cols-1 sm:grid-cols-2 gap-3"></div></fieldset>
<div class="flex justify-end gap-2 border-t border-slate-100 pt-4"><button type="button" data-close-category class="px-4 py-2 text-xs border border-slate-200 rounded-lg">Cancel</button><button id="saveCategory" class="px-4 py-2 text-xs bg-brand-600 hover:bg-brand-700 text-white rounded-lg disabled:opacity-50">Save category</button></div></form>
</dialog>
<?= $this->endSection() ?>
<?= $this->section('scripts') ?>
<script src="<?= base_url('assets/js/category-picker.js') ?>"></script>
<script src="<?= base_url('assets/js/inventory-categories.js') . '?v=' . filemtime(FCPATH . 'assets/js/inventory-categories.js') ?>"></script>
<?= $this->endSection() ?>
