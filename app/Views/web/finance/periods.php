<?php $activePeriods = array_filter($financialPeriods, static fn ($p) => $p['status'] === 'open'); $canManagePeriods = \App\Libraries\AuthContext::can('periods.manage'); ?>
<div class="flex flex-wrap gap-4 items-start justify-between mb-6">
    <p class="text-sm text-slate-500 max-w-xl">Manage accounting dates and lock completed periods. Creating or activating a period closes the previous active period.</p>
    <?php if ($canManagePeriods): ?><button type="button" onclick="document.getElementById('periodDialog').showModal()" class="px-4 py-2 bg-brand-600 hover:bg-brand-700 text-white text-xs font-semibold rounded-lg">+ New period</button><?php endif; ?>
</div>
<?php if (count($activePeriods) > 1): ?><div class="mb-5 p-4 bg-amber-50 border border-amber-200 rounded-xl text-sm text-amber-800" role="status">There are multiple open periods from the existing setup. Activate the period you want to keep; the others will be closed.</div><?php endif; ?>
<div class="bg-white border border-slate-200 rounded-xl overflow-hidden">
<div class="overflow-x-auto"><table id="periodsTable" class="w-full min-w-[560px] text-xs text-left">
<thead class="bg-slate-50 text-slate-500"><tr><th class="p-4">Period</th><th class="p-4">Start date</th><th class="p-4">End date</th><th class="p-4">Status</th><th class="p-4 text-right">Action</th></tr></thead>
<tbody class="divide-y divide-slate-100">
<?php foreach ($financialPeriods as $period): $active = $period['status'] === 'open'; ?>
<tr><td class="p-4 font-semibold text-slate-800"><?= esc($period['name']) ?></td><td class="p-4 text-slate-500"><?= esc($period['start_date']) ?></td><td class="p-4 text-slate-500"><?= esc($period['end_date']) ?></td><td class="p-4"><span class="px-2 py-1 rounded-full font-medium <?= $active ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500' ?>"><?= $active ? 'Active' : 'Closed' ?></span></td><td class="p-4 text-right"><?php if ($canManagePeriods): ?><button type="button" onclick="changePeriod(<?= (int)$period['id'] ?>, '<?= $active && count($activePeriods) === 1 ? 'close' : 'activate' ?>', this)" class="text-brand-600 font-semibold hover:underline disabled:opacity-50"><?= $active && count($activePeriods) === 1 ? 'Close period' : 'Activate' ?></button><?php endif; ?></td></tr>
<?php endforeach; ?>
</tbody></table></div></div>
<dialog id="periodDialog" class="w-11/12 max-w-md rounded-2xl p-0 shadow-xl backdrop:bg-slate-900/40">
<form id="periodForm" class="p-6 space-y-4">
<h2 class="font-bold text-slate-900">New financial period</h2>
<p class="text-xs text-slate-500">This period will become active. Any currently active period will be closed.</p>
<label class="block text-xs font-semibold text-slate-600">Name<input name="name" required maxlength="80" placeholder="e.g. November 2026" class="mt-2 block w-full border border-slate-300 p-2.5 rounded-lg"></label>
<div class="grid grid-cols-2 gap-3"><label class="block text-xs font-semibold text-slate-600">Start date<input name="start_date" type="date" required class="mt-2 block w-full border border-slate-300 p-2.5 rounded-lg"></label><label class="block text-xs font-semibold text-slate-600">End date<input name="end_date" type="date" required class="mt-2 block w-full border border-slate-300 p-2.5 rounded-lg"></label></div>
<p id="periodError" class="text-xs text-rose-600" role="alert"></p>
<div class="flex justify-end gap-2"><button type="button" onclick="document.getElementById('periodDialog').close()" class="border border-slate-200 px-4 py-2 rounded-lg text-xs">Cancel</button><button id="periodSave" class="bg-brand-600 text-white px-4 py-2 rounded-lg text-xs disabled:opacity-50">Create & activate</button></div>
</form></dialog>
<script>
async function periodRequest(url, body) {
    const response = await fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' }, body: JSON.stringify(body || {}) });
    const result = await response.json();
    if (!response.ok || !result.success) throw new Error(result.message || 'Could not update the financial period.');
}
document.getElementById('periodForm').addEventListener('submit', async event => {
    event.preventDefault(); const form = event.currentTarget; const button = document.getElementById('periodSave');
    const data = Object.fromEntries(new FormData(form));
    if (data.start_date > data.end_date) { erp.toast('End date must be on or after the start date.', 'error'); return; }
    button.disabled = true;
    try { await periodRequest('<?= site_url('finance/periods/save') ?>', data); window.location.reload(); }
    catch(error) { erp.toast(error.message, 'error'); }
    finally { button.disabled = false; }
});
async function changePeriod(id, action, button) {
    if (!await erp.confirm(action === 'activate' ? 'Activate this period and close all other open periods?' : 'Close this period? Entries dated in this period will be locked.', { title: action === 'activate' ? 'Activate period' : 'Close period', confirmButtonText: action === 'activate' ? 'Activate' : 'Close period' })) return;
    button.disabled = true;
    try { await periodRequest('<?= site_url('finance/periods') ?>/' + id + '/' + action); window.location.reload(); }
    catch(error) { erp.toast(error.message, 'error'); button.disabled = false; }
}
</script>
