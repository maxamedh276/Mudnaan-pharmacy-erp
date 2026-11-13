<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>
<?php $catalogUnits=['pcs'=>'Pieces','box'=>'Box','pack'=>'Pack','kg'=>'Kilogram','g'=>'Gram','litre'=>'Litre','ml'=>'Millilitre','cup'=>'Cup']; foreach((new \App\Services\Pharmacy\PharmacyDefinitionService())->units() as $unitName=>$definition) if(!isset($catalogUnits[$unitName])) $catalogUnits[$unitName]=ucfirst($unitName); ?>
<section id="catalogWorkspace" data-base-url="<?= site_url('inventory/products') ?>" data-category-id="<?= (int)$initialCategoryId ?>" data-image-url="<?= site_url('api/v1/products') ?>" data-image-base="<?= base_url() ?>" data-can-create="<?= \App\Libraries\AuthContext::can('products.create') ? 'true' : 'false' ?>" data-can-edit="<?= \App\Libraries\AuthContext::can('products.edit') ? 'true' : 'false' ?>" data-can-delete="<?= \App\Libraries\AuthContext::can('products.delete') ? 'true' : 'false' ?>">
    <div class="flex flex-wrap items-center justify-between gap-4 mb-6"><h1 class="text-2xl font-bold text-slate-900">Medicines & Catalog</h1><div class="erp-page-actions flex flex-wrap gap-2"><a href="<?= site_url('inventory/selling-options') ?>" class="px-4 py-2 text-xs border rounded-lg">Selling Options</a><a href="<?= site_url('inventory/categories') ?>" class="px-4 py-2 text-xs border border-slate-200 bg-white rounded-lg font-semibold">Categories</a><a href="<?= site_url('inventory/stock') ?>" class="px-4 py-2 text-xs border border-slate-200 bg-white rounded-lg font-semibold">Stock levels</a><?php if (\App\Libraries\AuthContext::can('products.create')): ?><?= view('web/partials/workbook_import', ['importKind' => 'products']) ?>
        <button id="addProduct" class="px-4 py-2 text-xs bg-brand-600 hover:bg-brand-700 text-white rounded-lg font-semibold">+ Add product</button><?php endif; ?></div></div>
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <?php foreach (['total_products' => 'Total products', 'valuation' => 'Stock value (USD)', 'low_stock' => 'Low stock', 'out_of_stock' => 'Out of stock'] as $key => $label): ?><div class="p-5 bg-white border border-slate-200 rounded-xl"><span class="text-xs text-slate-500"><?= esc($label) ?></span><p data-catalog-summary="<?= esc($key, 'attr') ?>" class="text-2xl font-bold text-slate-900 mt-2"></p></div><?php endforeach; ?>
    </div>
    <div class="p-5 sm:p-6 bg-white border border-slate-200 rounded-xl">
        <label for="productSearch" class="sr-only">Search products</label><input id="productSearch" type="search" placeholder="Search product name, barcode, SKU or brand…" class="w-full px-4 py-3 mb-4 border border-slate-200 rounded-lg text-sm focus:ring-2 focus:ring-brand-100 focus:border-brand-600 outline-none">
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 mb-4">
            <label class="text-xs font-semibold text-slate-500">Category<select id="filterCategory" class="block w-full mt-2 p-2.5 border border-slate-300 rounded-lg text-sm font-normal"><option value="">All categories</option><?php foreach ($categories as $category): ?><option value="<?= (int)$category['id'] ?>"><?= esc($category['path']) ?></option><?php endforeach; ?></select></label>
            <label class="text-xs font-semibold text-slate-500">Stock<select id="filterStock" class="block w-full mt-2 p-2.5 border border-slate-300 rounded-lg text-sm font-normal"><option value="">All stock</option><option value="in">In stock</option><option value="low">Low stock</option><option value="out">Out of stock</option></select></label>
            <label class="text-xs font-semibold text-slate-500">Minimum selling price<input id="filterMinPrice" type="number" min="0" step="0.01" placeholder="Any" class="block w-full mt-2 p-2.5 border border-slate-300 rounded-lg text-sm font-normal"></label>
            <label class="text-xs font-semibold text-slate-500">Maximum selling price<input id="filterMaxPrice" type="number" min="0" step="0.01" placeholder="Any" class="block w-full mt-2 p-2.5 border border-slate-300 rounded-lg text-sm font-normal"></label>
        </div>
        <div class="flex items-center justify-between mb-4"><p id="catalogStatus" class="text-xs text-slate-400" role="status" aria-live="polite"></p><button type="button" id="clearProductFilters" class="text-xs text-brand-600 font-semibold">Clear filters</button></div>
        <div class="overflow-x-auto"><table class="w-full min-w-[900px] text-left text-xs"><thead class="bg-slate-50 text-slate-500"><tr><th class="p-3">Product</th><th class="p-3">Category</th><th class="p-3 text-right">Average cost</th><th class="p-3 text-right">Selling price</th><th class="p-3">Stock</th><th class="p-3">Status</th><th class="p-3 text-right">Actions</th></tr></thead><tbody id="productRows" class="divide-y divide-slate-100"></tbody></table></div>
        <div class="flex flex-wrap justify-between items-center gap-4 mt-5"><label class="text-xs text-slate-500">Show <select id="catalogPageSize" class="ml-2 p-2 border rounded-lg"><option>10</option><option selected>25</option><option>50</option><option>100</option></select></label><div class="flex gap-2"><button id="previousProductPage" class="px-3 py-2 text-xs border rounded-lg disabled:opacity-40">Previous</button><button id="nextProductPage" class="px-3 py-2 text-xs border rounded-lg disabled:opacity-40">Next</button></div></div>
    </div>
    <script type="application/json" id="catalogInitialData"><?= json_encode(['catalog' => $catalog, 'categories' => $categories, 'sellingGroups' => $sellingGroups], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
</section>
<dialog id="productDialog" aria-labelledby="productDialogTitle" class="w-11/12 max-w-4xl max-h-[92vh] overflow-y-auto rounded-2xl p-0 shadow-xl backdrop:bg-slate-900/40">
<form id="productForm" class="p-5 sm:p-8 space-y-6">
    <div class="flex items-center justify-between"><h2 id="productDialogTitle" class="text-lg font-bold text-slate-900">Add product</h2><button type="button" data-close-product aria-label="Close product form" class="p-2 text-slate-400">✕</button></div>
    <input id="productId" type="hidden">
    <fieldset class="border-t border-slate-100 pt-4"><legend class="pr-3 text-sm font-bold text-slate-900">Product details</legend><div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <label class="text-xs font-semibold text-slate-600">Product name<input id="productName" required maxlength="180" class="block mt-2 w-full p-2.5 border border-slate-300 rounded-lg font-normal text-sm"></label>
        <label class="text-xs font-semibold text-slate-600">Brand (optional)<input id="productBrand" maxlength="120" class="block mt-2 w-full p-2.5 border border-slate-300 rounded-lg font-normal text-sm"></label>
        <label class="text-xs font-semibold text-slate-600">Main barcode (optional)<input id="productBarcode" maxlength="80" class="block mt-2 w-full p-2.5 border border-slate-300 rounded-lg font-normal text-sm"></label>
        <label class="text-xs font-semibold text-slate-600">Unit<select id="productUnit" class="block mt-2 w-full p-2.5 border border-slate-300 rounded-lg font-normal text-sm"><?php foreach ($catalogUnits as $value => $label): ?><option value="<?= esc($value) ?>"><?= esc($label) ?></option><?php endforeach; ?></select></label>
    </div></fieldset>
    <label class="block text-sm font-semibold">Stock type<select id="productStockType" class="block mt-2 w-full p-2.5 border rounded-lg"><option value="non_sealed">Non-Sealed</option><option value="sealed">Sealed</option></select></label>
    <fieldset id="productPackageFields" class="border-t border-slate-100 pt-4 hidden"><legend class="pr-3 text-sm font-bold text-slate-900">Sealed package details (required)</legend>
        <p class="text-xs text-slate-500 mb-3">Choose pieces for boxes of individual items, or kg or litre for bulk products. If the sealed package has its own barcode, enter it here. Open packages from Stock levels to sell the contents individually.</p>
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <label class="text-xs font-semibold text-slate-600">Package name<input id="productPackageUnit" maxlength="30" placeholder="Loor / Jirgaan" class="block mt-2 w-full p-2.5 border rounded-lg text-sm"></label>
            <label class="text-xs font-semibold text-slate-600"><span id="productPackageSizeLabel">Base units per package</span><input id="productPackageSize" type="number" min="0.001" step="0.001" placeholder="25" class="block mt-2 w-full p-2.5 border rounded-lg text-sm"></label>
            <label class="text-xs font-semibold text-slate-600">Selling price per sealed package (USD)<input id="productPackagePrice" type="number" min="0" step="0.01" placeholder="24.00" class="block mt-2 w-full p-2.5 border rounded-lg text-sm"></label>
            <label class="text-xs font-semibold text-slate-600 sm:col-span-3">Sealed package barcode (optional)<input id="productSealedBarcode" maxlength="80" class="block mt-2 w-full p-2.5 border border-slate-300 rounded-lg font-normal text-sm"></label>
        </div>
    </fieldset>
    <fieldset class="border-t border-slate-100 pt-4"><legend class="pr-3 text-sm font-bold text-slate-900">Category</legend><div id="productCategoryPicker" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4"></div></fieldset>
    <fieldset class="border-t border-slate-100 pt-4"><legend class="pr-3 text-sm font-bold text-slate-900">Pricing</legend><div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <label class="text-xs font-semibold text-slate-600">Selling price per base unit (USD)<input id="productSellingPrice" type="number" required min="0" max="9999999999.99" step="0.01" value="0" class="block mt-2 w-full p-2.5 border border-slate-300 rounded-lg font-normal text-sm"></label>
    </div></fieldset>
    <fieldset class="border-t border-slate-100 pt-4"><legend class="pr-3 text-sm font-bold text-slate-900">Inventory</legend><div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <label class="text-xs font-semibold text-slate-600"><span id="productReorderLabel">Low-stock alert</span><input id="productReorderLevel" type="number" required min="0" max="2147483647" step="0.001" value="10" class="block mt-2 w-full p-2.5 border border-slate-300 rounded-lg font-normal text-sm"></label>
    </div></fieldset>
    <fieldset class="border-t pt-4"><legend class="text-sm font-bold">POS selling</legend><div class="grid sm:grid-cols-2 gap-4 mt-3">
        <label class="text-xs font-semibold">Default selling unit<select id="productDefaultSale" class="block mt-2 w-full p-2.5 border rounded-lg"></select></label>
        <label class="text-xs font-semibold">Quantity shortcut group<select id="productSellingGroup" class="block mt-2 w-full p-2.5 border rounded-lg"><option value="">None</option></select></label>
    </div></fieldset>
    <?php if (\App\Libraries\AuthContext::can('stock.adjust')): ?>
    <fieldset id="productOpeningStock" class="border-t pt-4"><legend class="text-sm font-bold">Opening stock (optional)</legend><p class="text-xs text-slate-500 mt-2">Stock already on hand in this branch. Enter once when creating the product. Future purchases go through Receiving.</p><div class="grid sm:grid-cols-3 gap-4 mt-3">
        <label id="openingSealedLabel" class="text-xs font-semibold hidden">Sealed packages<input id="productOpeningSealed" type="number" min="0" max="999999999" step="1" value="0" class="block mt-2 w-full p-2.5 border rounded-lg"></label>
        <label class="text-xs font-semibold"><span id="openingQuantityLabel">Opening quantity</span><input id="productOpeningQuantity" type="number" min="0" max="999999999.999" step="0.001" value="0" class="block mt-2 w-full p-2.5 border rounded-lg"></label>
        <label class="text-xs font-semibold"><span id="openingCostLabel">Opening unit cost (USD)</span><input id="productOpeningCost" type="number" min="0" max="9999999999.99" step="0.000001" class="block mt-2 w-full p-2.5 border rounded-lg"></label>
    </div></fieldset><?php endif; ?>
    <div class="flex justify-end gap-2 pt-4 border-t border-slate-100"><button type="button" data-close-product class="px-4 py-2 text-xs border border-slate-200 rounded-lg">Cancel</button><button id="saveProduct" class="px-4 py-2 text-xs bg-brand-600 hover:bg-brand-700 text-white font-semibold rounded-lg disabled:opacity-50">Save product</button></div>
</form></dialog>
<dialog id="productImageDialog" aria-labelledby="productImageTitle" class="w-11/12 max-w-md rounded-2xl p-0 shadow-xl backdrop:bg-slate-900/40"><form id="productImageForm" class="p-6 space-y-5"><h2 id="productImageTitle" class="font-bold text-slate-900">Product image</h2><input id="imageProductId" type="hidden"><label class="block text-xs font-semibold text-slate-600">Image<input id="productImageFile" type="file" accept="image/jpeg,image/png,image/webp" required class="block mt-2 w-full text-sm"></label><div class="flex justify-end gap-2"><button type="button" id="closeProductImage" class="px-4 py-2 text-xs border rounded-lg">Cancel</button><button id="saveProductImage" class="px-4 py-2 text-xs bg-brand-600 text-white rounded-lg disabled:opacity-50">Upload image</button></div></form></dialog>
<?= $this->endSection() ?>
<?= $this->section('scripts') ?>
<script src="<?= base_url('assets/js/category-picker.js') ?>"></script>
<script src="<?= base_url('assets/js/inventory-products.js').'?v='.filemtime(FCPATH.'assets/js/inventory-products.js') ?>"></script>
<?= $this->endSection() ?>
