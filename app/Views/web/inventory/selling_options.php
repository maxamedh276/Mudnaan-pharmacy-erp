<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>
<div id="sellingOptionsWorkspace" data-save-url="<?= site_url('inventory/selling-options/save') ?>" data-can-edit="<?= \App\Libraries\AuthContext::can('products.edit') ? 'true' : 'false' ?>">
<div class="flex flex-wrap justify-between gap-3 mb-6"><div><h1 class="text-2xl font-bold">Selling Options</h1><p class="text-sm text-slate-500 mt-2">Manage quantity shortcuts once, then assign a group to products with the same unit.</p></div><div class="flex gap-2"><a href="<?= site_url('inventory/products') ?>" class="px-4 py-2 border rounded-lg text-sm">Products</a><?php if (\App\Libraries\AuthContext::can('products.edit')): ?><button id="newSellingGroup" class="px-4 py-2 bg-brand-600 text-white rounded-lg text-sm">Add group</button><?php endif; ?></div></div>
<div class="bg-white border rounded-xl p-5 overflow-x-auto"><table class="w-full text-left text-sm"><thead><tr><th class="p-3">Group</th><th class="p-3">Unit</th><th class="p-3">Quantity shortcuts</th><th class="p-3">Status</th><th class="p-3">Action</th></tr></thead><tbody id="sellingGroupRows"></tbody></table><p id="sellingGroupsEmpty" class="hidden text-slate-500 py-6">No groups yet. Create the names and quantities your shop uses.</p></div>
<script id="sellingGroupsData" type="application/json"><?= json_encode($groups,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?></script>
</div>
<dialog id="sellingGroupDialog" aria-labelledby="sellingGroupTitle" class="w-11/12 max-w-xl max-h-[90vh] overflow-y-auto rounded-xl p-6 backdrop:bg-slate-900/40">
<form id="sellingGroupForm" class="space-y-4"><h2 id="sellingGroupTitle" class="text-lg font-bold">Quantity group</h2><input id="sellingGroupId" type="hidden">
<label class="block text-sm">Group name<input id="sellingGroupName" required maxlength="100" class="block w-full mt-2 border rounded-lg p-2"></label>
<label class="block text-sm">Unit<select id="sellingGroupUnit" class="block w-full mt-2 border rounded-lg p-2"><?php foreach (['kg','litre','pcs','g','ml','box','pack','cup'] as $unit): ?><option><?= esc($unit) ?></option><?php endforeach; ?></select></label>
<p class="text-xs text-slate-500">Each shortcut replaces the quantity on the POS row. Prices come from the product.</p>
<div id="sellingOptionRows" class="space-y-2"></div><button type="button" id="addSellingOption" class="px-3 py-2 border rounded-lg text-sm">Add quantity</button>
<label class="flex items-center gap-2 text-sm"><input id="sellingGroupActive" type="checkbox" checked> Active</label>
<div class="flex justify-end gap-2"><button type="button" id="closeSellingGroup" class="px-4 py-2 border rounded-lg">Cancel</button><button id="saveSellingGroup" class="px-4 py-2 bg-brand-600 text-white rounded-lg">Save group</button></div>
</form></dialog>
<?= $this->endSection() ?>
<?= $this->section('scripts') ?><script src="<?= base_url('assets/js/selling-options.js').'?v='.filemtime(FCPATH.'assets/js/selling-options.js') ?>"></script><?= $this->endSection() ?>
