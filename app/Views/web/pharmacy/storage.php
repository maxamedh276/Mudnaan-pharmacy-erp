<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>
<?php
if (!session()->get('pharmacy_csrf')) session()->set('pharmacy_csrf',bin2hex(random_bytes(32)));
$manage=\App\Libraries\AuthContext::can('pharmacy.stock.manage');
?>
<section id="pharmacyStorage" data-base="<?= site_url('pharmacy') ?>" data-token="<?= esc(session()->get('pharmacy_csrf'),'attr') ?>">
    <header class="mb-5"><p class="text-xs font-bold text-emerald-600 uppercase tracking-widest">Medicine storage</p><h1 class="text-2xl font-bold mt-2">Storage & Cold Chain</h1><p class="text-slate-500 text-sm mt-2">Record storage locations and manual temperature checks. Configure limits from your approved storage procedures and manufacturer instructions.</p></header>
    <div class="grid sm:grid-cols-3 gap-4 mb-5" id="storageSummary" aria-live="polite"></div>
    <?php if ($manage): ?>
    <div class="bg-white rounded-2xl border p-5 mb-5">
        <h2 class="font-semibold mb-3">Record temperature / Diiwaangeli heerkulka</h2>
        <form data-storage-action="temperature" class="grid sm:grid-cols-3 gap-4">
            <label class="text-sm">Storage location<select name="location_id" required class="block w-full border rounded-lg p-2 mt-1"></select></label>
            <label class="text-sm">Observed at<input name="measured_at" type="datetime-local" required value="<?= date('Y-m-d\TH:i') ?>" class="block w-full border rounded-lg p-2 mt-1"></label>
            <label class="text-sm">Temperature °C<input name="temperature_c" type="number" required min="-100" max="100" step="0.01" class="block w-full border rounded-lg p-2 mt-1"></label>
            <label class="text-sm sm:col-span-3">Action / observation<textarea name="action_taken" maxlength="2000" rows="2" class="block w-full border rounded-lg p-2 mt-1" placeholder="Required for readings outside the configured limits"></textarea></label>
            <p data-reading-warning class="sm:col-span-3 text-sm text-amber-800" role="status"></p>
            <div class="sm:col-span-3 flex justify-end"><button class="bg-emerald-600 text-white rounded-lg px-5 py-2">Save observation</button></div>
            <p data-feedback class="sm:col-span-3 text-sm" role="status"></p>
        </form>
    </div>
    <details class="bg-white border rounded-2xl p-5 mb-5"><summary class="font-semibold cursor-pointer">Add storage location / Ku dar meel kaydin</summary><p class="text-sm text-slate-500 mt-3">Limits are retained with each reading. Create a distinct location name when monitoring requirements change.</p>
        <form data-storage-action="storage-location" class="grid sm:grid-cols-4 gap-4 mt-3">
            <label class="text-sm">Name<input name="name" required maxlength="100" placeholder="e.g. Fridge A" class="block w-full border rounded-lg p-2 mt-1"></label>
            <label class="text-sm">Type<select name="kind" class="block w-full border rounded-lg p-2 mt-1"><option value="room">Room</option><option value="shelf">Shelf / cabinet</option><option value="fridge">Fridge</option><option value="freezer">Freezer</option></select></label>
            <label class="text-sm">Minimum °C<input name="min_c" type="number" required min="-100" max="100" step="0.01" class="block w-full border rounded-lg p-2 mt-1"></label>
            <label class="text-sm">Maximum °C<input name="max_c" type="number" required min="-100" max="100" step="0.01" class="block w-full border rounded-lg p-2 mt-1"></label>
            <div class="sm:col-span-4 flex justify-end"><button class="bg-emerald-600 text-white rounded-lg px-5 py-2">Add location</button></div><p data-feedback role="status" class="sm:col-span-4 text-sm"></p>
        </form>
    </details><?php endif; ?>
    <div class="bg-white border rounded-2xl p-5"><div class="flex justify-between gap-3 items-center"><h2 class="font-semibold">Latest 200 observations</h2><button type="button" id="storageRefresh" class="border rounded-lg px-3 py-2 text-sm">Refresh</button></div><p class="text-sm text-slate-500 my-3">An excursion records an exception; it does not automatically quarantine or release medicine batches. Use Batches & Stock for the authorised stock decision.</p><p id="storageStatus" role="status" class="text-sm"></p><div class="overflow-x-auto"><table class="w-full text-sm text-left"><thead class="bg-slate-50"><tr><th class="p-3">Location</th><th class="p-3">Observed</th><th class="p-3">°C / limits</th><th class="p-3">Status</th><th class="p-3">Action / staff</th></tr></thead><tbody id="storageReadings"></tbody></table></div></div>
    <script type="application/json" id="storageInitialData"><?= json_encode(compact('locations','readings'),JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_QUOT|JSON_HEX_APOS) ?></script>
</section>
<?= $this->endSection() ?>
<?= $this->section('scripts') ?><script src="<?= base_url('assets/js/pharmacy-storage.js').'?v='.filemtime(FCPATH.'assets/js/pharmacy-storage.js') ?>"></script><?= $this->endSection() ?>
