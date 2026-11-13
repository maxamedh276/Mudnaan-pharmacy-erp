<?php $canNote = \App\Libraries\AuthContext::can('pharmacy.patients.manage'); ?>
<dialog id="patientCareDialog" aria-labelledby="patientCareTitle" class="rounded-2xl p-0 w-11/12 max-w-3xl max-h-[90vh] overflow-y-auto">
    <header class="flex justify-between items-center p-5 border-b"><div><h2 id="patientCareTitle" class="font-bold text-lg">Patient care / Taariikhda bukaanka</h2><p class="text-sm text-slate-500">Recorded care notes, prescriptions and dispensing history.</p></div><button type="button" id="patientCareClose" class="p-2" aria-label="Close">×</button></header>
    <div id="patientCareBody" class="p-5 space-y-4" aria-live="polite"></div>
    <?php if ($canNote): ?><form id="patientCareForm" class="p-5 border-t space-y-3">
        <input type="hidden" name="patient_id">
        <label class="block text-sm">Care record type<select name="kind" class="border rounded-lg p-2 mt-1 w-full"><option value="counselling">Counselling</option><option value="intervention">Pharmacist intervention</option><option value="referral">Referral</option><option value="adverse_event">Reported adverse event</option></select></label>
        <label class="block text-sm">Record / Qoraalka<textarea name="note" required maxlength="5000" rows="3" class="border rounded-lg p-2 mt-1 w-full"></textarea></label>
        <small class="block text-slate-500">Records are permanent. Add a follow-up note to correct or clarify a previous entry. No automated clinical assessment is performed.</small>
        <p role="status" data-care-feedback class="text-sm"></p><button type="submit" class="bg-emerald-600 text-white rounded-lg px-4 py-2">Save care note</button>
    </form><?php endif; ?>
</dialog>
