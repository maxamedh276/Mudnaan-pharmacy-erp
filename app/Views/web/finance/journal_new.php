<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>
<a href="<?= site_url('finance/journals') ?>" class="text-xs font-semibold text-slate-500 hover:text-brand-600">← Journal Entries</a>
<div class="mt-4 mb-6"><h1 class="text-2xl font-bold text-slate-900">New Journal Entry</h1><p class="text-sm text-slate-500 mt-2">Record a balanced transaction across your ledger accounts.</p></div>
<form id="journalForm" class="bg-white border border-slate-200 rounded-xl overflow-hidden">
<div class="p-5 grid sm:grid-cols-3 gap-5 border-b border-slate-100">
<label class="text-xs font-semibold text-slate-600">Entry date<input id="journalDate" type="date" required value="<?= date('Y-m-d') ?>" class="block mt-2 w-full rounded-lg border border-slate-300 p-2.5"></label>
<label class="sm:col-span-2 text-xs font-semibold text-slate-600">Description<input id="journalDescription" required maxlength="255" placeholder="What is this journal entry for?" class="block mt-2 w-full rounded-lg border border-slate-300 p-2.5"></label>
</div>
<div class="overflow-x-auto"><table class="w-full text-left text-xs min-w-[640px]"><thead class="bg-slate-50 text-slate-500"><tr><th class="p-4">Account</th><th class="p-4">Line description</th><th class="p-4 w-32">Debit (USD)</th><th class="p-4 w-32">Credit (USD)</th><th class="p-4 w-12"><span class="sr-only">Remove</span></th></tr></thead><tbody id="journalLines" class="divide-y divide-slate-100"></tbody><tfoot class="border-t border-slate-200 bg-slate-50"><tr><td colspan="2" class="p-4 font-semibold">Total</td><td id="debitTotal" class="p-4 font-semibold tabular-nums">0.00</td><td id="creditTotal" class="p-4 font-semibold tabular-nums">0.00</td><td></td></tr></tfoot></table></div>
<div class="p-5 flex flex-wrap items-center justify-between gap-4"><button type="button" onclick="addJournalLine()" class="text-xs text-brand-600 font-semibold">+ Add line</button><p id="journalBalance" aria-live="polite" class="text-xs text-slate-500">Enter at least two lines.</p></div>
<p id="journalError" role="alert" class="px-5 text-sm text-rose-600"></p>
<div class="p-5 flex justify-end gap-3 border-t border-slate-100"><a href="<?= site_url('finance/journals') ?>" class="px-4 py-2 text-xs border border-slate-200 rounded-lg">Cancel</a><button id="journalSave" type="submit" disabled class="px-4 py-2 text-xs bg-brand-600 hover:bg-brand-700 text-white rounded-lg font-semibold disabled:opacity-40 disabled:cursor-not-allowed">Post journal entry</button></div>
</form>
<template id="journalLineTemplate"><tr class="journal-line">
<td class="p-3"><select required data-field="account_id" aria-label="Account" class="w-full border border-slate-300 rounded-lg p-2"><option value="">Select account</option><?php foreach ($accounts as $account): ?><option value="<?= (int)$account['id'] ?>"><?= esc($account['code'] . ' · ' . $account['name']) ?></option><?php endforeach; ?></select></td>
<td class="p-3"><input data-field="description" aria-label="Line description" maxlength="255" class="w-full border border-slate-300 rounded-lg p-2"></td>
<td class="p-3"><input data-field="debit" aria-label="Debit amount" type="number" min="0" max="9999999999.99" step="0.01" value="0" class="w-full border border-slate-300 rounded-lg p-2"></td>
<td class="p-3"><input data-field="credit" aria-label="Credit amount" type="number" min="0" max="9999999999.99" step="0.01" value="0" class="w-full border border-slate-300 rounded-lg p-2"></td>
<td class="p-3"><button type="button" onclick="removeJournalLine(this)" aria-label="Remove journal line" class="p-2 text-rose-600 disabled:opacity-30">✕</button></td>
</tr></template>
<?= $this->endSection() ?>
<?= $this->section('scripts') ?>
<script>
function addJournalLine() { document.getElementById('journalLines').appendChild(document.getElementById('journalLineTemplate').content.cloneNode(true)); updateJournalTotals(); }
function removeJournalLine(button) { if (document.querySelectorAll('.journal-line').length > 2) button.closest('tr').remove(); updateJournalTotals(); }
function journalData() { return Array.from(document.querySelectorAll('.journal-line')).map(row => Object.fromEntries(Array.from(row.querySelectorAll('[data-field]')).map(input => [input.dataset.field, ['account_id','debit','credit'].includes(input.dataset.field) ? Number(input.value) : input.value.trim()]))); }
function updateJournalTotals() {
    const lines = journalData(); const cents = value => Math.round(value * 100);
    const debit = lines.reduce((sum,line) => sum + cents(line.debit), 0); const credit = lines.reduce((sum,line) => sum + cents(line.credit), 0);
    document.getElementById('debitTotal').textContent = (debit / 100).toFixed(2); document.getElementById('creditTotal').textContent = (credit / 100).toFixed(2);
    const valid = lines.length >= 2 && debit > 0 && debit === credit && lines.every(line => line.account_id > 0 && line.debit >= 0 && line.credit >= 0 && ((line.debit > 0) !== (line.credit > 0)));
    document.getElementById('journalSave').disabled = !valid;
    document.getElementById('journalBalance').textContent = debit !== credit ? 'Difference: USD ' + (Math.abs(debit - credit) / 100).toFixed(2) : valid ? 'Balanced · ready to post' : 'Choose accounts and enter a debit or credit on each line.';
    document.querySelectorAll('.journal-line button').forEach(button => button.disabled = lines.length <= 2);
    return valid;
}
document.getElementById('journalLines').addEventListener('input', updateJournalTotals);
document.getElementById('journalLines').addEventListener('change', updateJournalTotals);
document.getElementById('journalForm').addEventListener('submit', async event => {
    event.preventDefault(); if (!updateJournalTotals()) return;
    const button = document.getElementById('journalSave'); button.disabled = true; button.textContent = 'Posting…';
    try {
        await erp.api('journal-entries', { method: 'POST', body: JSON.stringify({ entry_date: document.getElementById('journalDate').value, description: document.getElementById('journalDescription').value.trim(), lines: journalData() }) });
        window.location.href = '<?= site_url('finance/journals') ?>';
    } catch(error) { document.getElementById('journalError').textContent = error.message; updateJournalTotals(); button.textContent = 'Post journal entry'; }
});
addJournalLine(); addJournalLine();
</script>
<?= $this->endSection() ?>
