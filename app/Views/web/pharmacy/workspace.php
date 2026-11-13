<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>
<?php
use App\Libraries\AuthContext;
$t = static fn (string $key): string => esc(lang('Pharmacy.' . $key));
$clinicalFacts=$ready && $section==='medicines' && db_connect()->fieldExists('active_ingredients','pharmacy_medicines');
$manage = match ($section) {
    'medicines' => AuthContext::can('pharmacy.medicines.manage'),
    'patients' => AuthContext::can('pharmacy.patients.manage'),
    'prescriptions' => AuthContext::can('pharmacy.prescriptions.manage') && AuthContext::can('pharmacy.patients.view'),
};
if (!session()->get('pharmacy_csrf')) session()->set('pharmacy_csrf', bin2hex(random_bytes(32)));
$columns = match ($section) {
    'medicines' => ['name', 'category_name', 'generic_name', 'strength', 'dosage_form', 'manufacturer', 'prescription_required'],
    'patients' => ['name', 'phone', 'date_of_birth', 'allergies'],
    'prescriptions' => ['patient_name', 'prescriber', 'reference', 'issued_on', 'expires_on', 'status'],
};
if ($clinicalFacts) $columns[]='clinical_verified';
?>
<section id="pharmacyWorkspace" data-section="<?= esc($section, 'attr') ?>" data-detail-url="<?= site_url('pharmacy/prescriptions') ?>" data-save-url="<?= site_url('pharmacy/' . $section . '/save') ?>" data-token="<?= esc(session()->get('pharmacy_csrf'), 'attr') ?>">
    <header class="flex flex-wrap justify-between gap-4 mb-6">
        <div><p class="text-xs uppercase tracking-widest text-emerald-600 font-bold">Mudnaan Pharmacy</p><h1 class="text-2xl font-bold text-slate-900 mt-1"><?= $t('title') ?></h1></div>
        <nav class="flex gap-2 items-center" aria-label="Language"><a href="<?= site_url('pharmacy/' . $section) ?>?language=en" lang="en" class="px-3 py-2 border rounded-lg bg-white">English</a><a href="<?= site_url('pharmacy/' . $section) ?>?language=so" lang="so" class="px-3 py-2 border rounded-lg bg-white">Soomaali</a></nav>
    </header>
    <?= $this->include('web/pharmacy/navigation') ?>
    <?php if ($ready && $section === 'medicines' && $manage): ?><?= $this->include('web/pharmacy/definitions') ?><?php endif; ?>
    <?php if (!$ready): ?>
    <p role="status" class="rounded-xl border border-amber-200 bg-amber-50 p-6 text-amber-900"><?= $t('setupRequired') ?></p>
    <?php else: ?>
    <div class="bg-white rounded-xl border border-slate-200 p-5 mb-6">
        <div class="flex flex-wrap justify-between gap-4 mb-4"><div><h2 class="font-bold text-lg"><?= $t($section) ?></h2><p class="text-sm text-slate-500 mt-1"><?= $t(match ($section) { 'medicines' => 'catalogHint', 'patients' => 'patientHint', default => 'rxHint' }) ?></p></div><?php if ($manage): ?><button type="button" id="pharmacyAdd" class="bg-emerald-600 text-white rounded-lg px-4 py-2 text-sm"><?= $t('add') ?></button><?php endif; ?></div>
        <?php if ($section !== 'prescriptions'): ?><form method="get" class="flex gap-2 mb-4"><label for="pharmacySearch" class="sr-only"><?= $t('search') ?></label><input id="pharmacySearch" type="search" name="q" maxlength="180" value="<?= esc($search, 'attr') ?>" class="border rounded-lg p-2 flex-1" placeholder="<?= $t('search') ?>"><button class="px-4 py-2 border rounded-lg"><?= $t('search') ?></button></form><?php endif; ?>
        <div class="overflow-x-auto"><table class="w-full text-sm text-left"><thead class="bg-slate-50 text-slate-500"><tr><?php foreach ($columns as $column): ?><th class="p-3"><?= $t($column === 'patient_name' ? 'patient_id' : $column) ?></th><?php endforeach; ?></tr></thead><tbody class="divide-y">
        <?php if ($section === 'patients' && $manage): ?><script type="application/json" id="patientProfileData"><?= json_encode($rows, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script><?php endif; ?>
        <?php foreach ($rows as $row): ?><tr><?php foreach ($columns as $column): ?><td class="p-3"><?php if ($column === 'patient_name'): ?><button type="button" data-rx-id="<?= (int) $row['id'] ?>" class="text-emerald-700 underline font-semibold"><?= esc($row[$column]) ?> (#<?= (int) $row['id'] ?>)</button><?php elseif ($section === 'medicines' && $column === 'name' && $manage): ?><button type="button" data-edit-medicine="<?= (int)$row['product_id'] ?>" class="text-emerald-700 underline" aria-label="<?= esc('Edit medicine '.$row['name'],'attr') ?>"><?= esc($row['name']) ?></button><?php elseif ($section === 'patients' && $column === 'name' && AuthContext::can('pharmacy.prescriptions.view')): ?><button type="button" data-patient-history="<?= (int)$row['id'] ?>" class="text-emerald-700 underline"><?= esc($row['name']) ?></button><?php elseif ($column === 'clinical_verified'): ?><?= $t(!$row['clinical_verified']?'clinicalPending':(empty($row['clinical_verified_by'])?'clinicalLegacy':'clinicalVerified')) ?><?php elseif ($column === 'prescription_required'): ?><span class="px-2 py-1 rounded bg-emerald-50 text-emerald-800"><?= $t($row[$column] ? 'rx' : 'otc') ?></span><?php elseif ($column === 'status'): ?><?= $t($row['status']) ?><?php else: ?><?= esc($row[$column] ?? '—') ?><?php endif; ?></td><?php endforeach; ?></tr><?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="<?= count($columns) ?>" class="p-8 text-center text-slate-400"><?= $t('empty') ?></td></tr><?php endif; ?>
        </tbody></table></div>
        <?php if ($section === 'patients' && $manage): ?><label class="block text-sm mt-4"><?= $t('patients') ?><select id="patientEdit" class="border rounded-lg p-2 ml-2"><option value=""><?= $t('select') ?></option><?php foreach ($rows as $row): ?><option value="<?= (int)$row['id'] ?>"><?= esc($row['name']) ?> (#<?= (int)$row['id'] ?>)</option><?php endforeach; ?></select></label><?php endif; ?>
    </div>
    <?php if ($section === 'prescriptions'): ?>
    <dialog id="prescriptionDetails" aria-labelledby="prescriptionDetailsTitle" class="rounded-2xl p-6 w-11/12 max-w-2xl max-h-[90vh] overflow-y-auto backdrop:bg-slate-900/40"><div class="flex justify-between items-center mb-4"><h2 id="prescriptionDetailsTitle" class="text-lg font-bold"><?= $t('prescriptions') ?></h2><button type="button" id="prescriptionDetailsClose" aria-label="Close" class="p-2">×</button></div><div id="prescriptionDetailsBody" class="space-y-3" aria-live="polite"></div><?php if (AuthContext::can('pharmacy.prescriptions.approve')): ?><button type="button" id="pharmacyApprove" hidden class="mt-4 rounded-lg bg-emerald-600 text-white px-4 py-2"><?= $t('approve') ?></button><?php endif; ?></dialog>
    <?php endif; ?>
    <?php if ($manage): ?>
    <dialog id="pharmacyDialog" aria-labelledby="pharmacyDialogTitle" class="rounded-2xl p-0 w-11/12 max-w-3xl max-h-[90vh] overflow-y-auto backdrop:bg-slate-900/40">
        <form id="pharmacyForm" class="p-6 space-y-4"><div class="entry-dialog-header flex justify-between items-center"><div><h2 id="pharmacyDialogTitle" class="text-lg font-bold"><?= $t($section) ?></h2><p><?= $t('formIntro') ?></p></div><button type="button" id="pharmacyClose" aria-label="Close" class="p-2">×</button></div>
        <?php if ($section === 'medicines'): ?>
        <div class="entry-editor-tabs" role="tablist" aria-label="<?= $t('medicines') ?>">
            <button type="button" role="tab" id="medicineDetailsTab" data-entry-tab="details" aria-controls="medicineDetailsPanel" aria-selected="true"><?= $t('medicineDetailsTab') ?></button>
            <button type="button" role="tab" id="medicineUnitsTab" data-entry-tab="units" aria-controls="medicineUnitsPanel" aria-selected="false" tabindex="-1"><?= $t('units') ?></button>
            <?php if ($clinicalFacts): ?><button type="button" role="tab" id="medicineClinicalTab" data-entry-tab="clinical" aria-controls="medicineClinicalPanel" aria-selected="false" tabindex="-1">Clinical details</button><?php endif; ?>
        </div>
        <?php endif; ?>
        <?php if ($section !== 'medicines'): ?><div class="entry-plain-body"><?php endif; ?>
        <div class="entry-details-grid grid gap-4 sm:grid-cols-2" <?= $section === 'medicines' ? 'id="medicineDetailsPanel" data-entry-panel="details" role="tabpanel" aria-labelledby="medicineDetailsTab"' : '' ?>>
        <?php if ($section === 'medicines'): ?>
        <div class="entry-section-heading"><span>01</span><h3><?= $t('medicineIdentity') ?></h3></div>
        <label class="text-sm"><?= $t('product_id') ?><select name="product_id" class="block w-full border rounded-lg p-2 mt-1"><option value=""><?= $t('newMedicine') ?></option><?php foreach ($products as $product): ?><option value="<?= (int) $product['id'] ?>" data-unit="<?= esc($product['unit'], 'attr') ?>"><?= esc($product['name']) ?></option><?php endforeach; ?></select></label>
        <label class="text-sm" data-new-medicine><?= $t('name') ?><input name="name" maxlength="180" class="block w-full border rounded-lg p-2 mt-1"></label>
        <label class="text-sm" data-new-medicine><?= $t('category_id') ?><select name="category_id" class="block w-full border rounded-lg p-2 mt-1"><option value=""><?= $t('select') ?></option><?php foreach ($categories as $category): ?><option value="<?= (int)$category['id'] ?>"><?= esc($category['name']) ?></option><?php endforeach; ?></select><a href="<?= site_url('inventory/categories') ?>" class="text-emerald-700 underline"><?= $t('categories') ?></a></label>
        <label class="text-sm" data-new-medicine><?= $t('base_unit') ?><select name="base_unit" class="block w-full border rounded-lg p-2 mt-1"><?php foreach (array_keys((new \App\Services\Pharmacy\PharmacyDefinitionService())->units()) as $unit): ?><option value="<?= $unit ?>"><?= esc($unit) ?></option><?php endforeach; ?></select></label>
        <?php foreach (['sell_price', 'reorder_level'] as $field): ?><label class="text-sm" data-new-medicine><?= $t($field) ?><input name="<?= $field ?>" type="number" min="0" step="0.01" value="<?= $field === 'reorder_level' ? '10' : '0' ?>" class="block w-full border rounded-lg p-2 mt-1"></label><?php endforeach; ?>
        <label class="text-sm" data-new-medicine><?= $t('barcode') ?><input name="barcode" maxlength="80" class="block w-full border rounded-lg p-2 mt-1"></label>
        <label class="text-sm"><?= $t('dosage_form') ?><select name="dosage_form" required class="block w-full border rounded-lg p-2 mt-1"><?php foreach ((new \App\Services\Pharmacy\PharmacyDefinitionService())->forms() as $form): ?><option value="<?= esc($form, 'attr') ?>"><?= esc($form) ?></option><?php endforeach; ?></select></label>
        <?php foreach (['generic_name' => 180, 'strength' => 80, 'manufacturer' => 180, 'storage_instructions' => 500] as $field => $max): ?><label class="text-sm"><?= $t($field) ?><input name="<?= $field ?>" maxlength="<?= $max ?>" <?= in_array($field, ['generic_name', 'strength'], true) ? 'required' : '' ?> class="block w-full border rounded-lg p-2 mt-1"></label><?php endforeach; ?>
        <label class="flex gap-2 items-center text-sm"><input type="checkbox" name="prescription_required" value="1"><?= $t('prescription_required') ?></label>
        <?php elseif ($section === 'patients'): ?>
        <input type="hidden" name="id" value="">
        <?php foreach (['name' => ['text', 180], 'phone' => ['tel', 40], 'date_of_birth' => ['date', 10]] as $field => [$type, $max]): ?><label class="text-sm"><?= $t($field) ?><input name="<?= $field ?>" type="<?= $type ?>" maxlength="<?= $max ?>" <?= $field === 'name' ? 'required' : '' ?> class="block w-full border rounded-lg p-2 mt-1"></label><?php endforeach; ?>
        <label class="text-sm sm:col-span-2"><?= $t('allergies') ?><textarea name="allergies" maxlength="5000" class="block w-full border rounded-lg p-2 mt-1"></textarea></label>
        <?php else: ?>
        <label class="text-sm"><?= $t('patient_id') ?><input type="search" aria-label="Find patient by name or phone" data-pharmacy-party-search="<?= site_url('pharmacy/lookup/patients') ?>" data-target="prescriptionPatientSelect" placeholder="Find patient by name or phone" class="block w-full border rounded-lg p-2 mt-1"><select id="prescriptionPatientSelect" aria-label="Patient" name="patient_id" required class="block w-full border rounded-lg p-2 mt-1"><option value=""><?= $t('select') ?></option><?php foreach ($patients as $patient): ?><option value="<?= (int) $patient['id'] ?>"><?= esc($patient['name']) ?> (#<?= (int) $patient['id'] ?>)</option><?php endforeach; ?></select></label>
        <?php foreach (['prescriber' => ['text', 180, true], 'reference' => ['text', 100, false], 'issued_on' => ['date', 10, true], 'expires_on' => ['date', 10, false]] as $field => [$type, $max, $required]): ?><label class="text-sm"><?= $t($field) ?><input name="<?= $field ?>" type="<?= $type ?>" maxlength="<?= $max ?>" <?= $required ? 'required' : '' ?> class="block w-full border rounded-lg p-2 mt-1"></label><?php endforeach; ?>
        <?php endif; ?>
        </div>
        <?php if ($section === 'medicines'): ?>
        <div id="medicineUnitsPanel" data-entry-panel="units" role="tabpanel" aria-labelledby="medicineUnitsTab" hidden>
        <div class="entry-unit-heading"><div><h3><?= $t('units') ?></h3><p><?= $t('unitHint') ?></p></div><button type="button" id="medicineAddUnit" class="border rounded-lg p-2 text-sm"><?= $t('addUnit') ?></button></div><div id="medicineUnitRows" class="space-y-3"></div>
        <template id="medicineUnitTemplate"><fieldset class="medicine-unit-row grid sm:grid-cols-3 gap-3 border rounded-lg p-3"><label class="text-sm"><?= $t('sellingUnit') ?><select data-unit-field="name" class="block border rounded-lg p-2 mt-1 w-full"><?php foreach (array_keys((new \App\Services\Pharmacy\PharmacyDefinitionService())->units()) as $unit): ?><option value="<?= $unit ?>"><?= esc($unit) ?></option><?php endforeach; ?></select></label><label class="text-sm"><?= $t('unitFactor') ?><input data-unit-field="factor" required type="number" min="0.001" step="0.001" value="1" class="block border rounded-lg p-2 mt-1 w-full"></label><label class="text-sm"><?= $t('unitPrice') ?><input data-unit-field="sell_price" required type="number" min="0" step="0.01" value="0" class="block border rounded-lg p-2 mt-1 w-full"></label><label class="text-sm">Stock source<select data-unit-field="pharmacy_pack_state" class="block border rounded-lg p-2 mt-1 w-full"><option value="any">Any released stock</option><option value="sealed">Unopened / sealed stock</option><option value="opened">Opened packs only</option></select></label><label class="text-sm sm:col-span-2"><?= $t('barcode') ?><input data-unit-field="barcode" maxlength="80" class="block border rounded-lg p-2 mt-1 w-full"></label><button type="button" data-remove-unit class="text-red-600 text-sm text-left"><?= $t('remove') ?></button></fieldset></template>
        <script type="application/json" id="medicineProfileData"><?= json_encode($rows, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
        </div>
        <?php endif; ?>
        <?php if ($section === 'prescriptions'): ?>
        <div id="prescriptionLines" class="space-y-3"></div><button type="button" id="pharmacyAddLine" class="border rounded-lg px-3 py-2 text-sm"><?= $t('addLine') ?></button>
        <template id="prescriptionLineTemplate"><fieldset class="rx-line grid sm:grid-cols-2 gap-3 border rounded-lg p-4"><label class="text-sm"><?= $t('medicines') ?><select data-field="product_id" required class="block w-full border p-2 rounded mt-1"><option value=""><?= $t('select') ?></option><?php foreach ($medicines as $medicine): ?><option value="<?= (int) $medicine['product_id'] ?>"><?= esc($medicine['name'] . ' · ' . $medicine['strength'] . ' · ' . $medicine['unit']) ?></option><?php endforeach; ?></select></label><label class="text-sm"><?= $t('quantity') ?><input data-field="quantity" required type="number" min="0.001" max="999999999.999" step="0.001" class="block w-full border p-2 rounded mt-1"></label><label class="text-sm sm:col-span-2"><?= $t('directions') ?><textarea data-field="directions" required maxlength="500" class="block w-full border p-2 rounded mt-1"></textarea></label><button type="button" data-remove-line class="text-sm text-red-600 text-left"><?= $t('remove') ?></button></fieldset></template>
        <?php endif; ?>
        <?php if ($clinicalFacts): ?><?= $this->include('web/pharmacy/medicine_clinical') ?><?php endif; ?>
        <?php if ($section !== 'medicines'): ?></div><?php endif; ?>
        <p id="pharmacyFeedback" role="status" aria-live="polite" class="text-sm text-red-700"></p>
        <div class="entry-form-footer"><small><?= $t('requiredHint') ?></small><div><button type="button" data-entry-cancel class="entry-cancel"><?= $t('cancel') ?></button><button id="pharmacySave" class="px-5 py-2 rounded-lg bg-emerald-600 text-white disabled:opacity-50"><?= $t('save') ?></button></div></div>
        </form>
    </dialog>
    <?php endif; endif; ?>
<?php if ($ready && $section === 'patients' && AuthContext::can('pharmacy.prescriptions.view')): ?><?= $this->include('web/pharmacy/patient_care') ?><?php endif; ?>
</section>
<?= $this->endSection() ?>
<?= $this->section('scripts') ?>
<script src="<?= base_url('assets/js/pharmacy-workspace.js').'?v='.filemtime(FCPATH.'assets/js/pharmacy-workspace.js') ?>"></script>
<script src="<?= base_url('assets/js/pharmacy-definitions.js').'?v='.filemtime(FCPATH.'assets/js/pharmacy-definitions.js') ?>"></script>
<script src="<?= base_url('assets/js/pharmacy-patient-care.js').'?v='.filemtime(FCPATH.'assets/js/pharmacy-patient-care.js') ?>"></script>
<script src="<?= base_url('assets/js/pharmacy-party-search.js').'?v='.filemtime(FCPATH.'assets/js/pharmacy-party-search.js') ?>"></script>
<?php if ($section==='prescriptions'): ?><script src="<?= base_url('assets/js/pharmacy-documents.js').'?v='.filemtime(FCPATH.'assets/js/pharmacy-documents.js') ?>" data-upload="<?= AuthContext::can('pharmacy.prescriptions.manage')?'1':'0' ?>"></script><?php endif; ?>
<?= $this->endSection() ?>
