<?php $so = service('request')->getLocale() === 'so'; ?>
<details class="bg-white rounded-xl border border-slate-200 p-5 mb-5" id="pharmacyDefinitions" data-url="<?= site_url('pharmacy/definitions/save') ?>">
    <summary class="font-semibold cursor-pointer"><?= $so ? 'Noocyada daawooyinka & units cusub' : 'Dosage forms & custom units' ?></summary>
    <p class="text-sm text-slate-500 my-3"><?= $so ? 'Ku dar nooc ama unit cusub adigoon ka bixin catalog-ka. Conversions-ka ku deji medicine kasta.' : 'Extend the catalog without changing code. Set conversion factors and prices separately for each medicine.' ?></p>
    <form id="pharmacyDefinitionForm" class="grid gap-3 sm:grid-cols-4 items-end">
        <label class="text-sm"><?= $so ? 'Nooca' : 'Definition' ?><select name="kind" class="block border rounded-lg p-2 mt-1 w-full"><option value="form">Dosage form</option><option value="unit">Selling / base unit</option></select></label>
        <label class="text-sm"><?= $so ? 'Magaca' : 'Name' ?><input name="name" required maxlength="80" class="block border rounded-lg p-2 mt-1 w-full" placeholder="e.g. Chewable tablet"></label>
        <label class="text-sm" data-definition-family hidden>Unit family<select name="family" class="block border rounded-lg p-2 mt-1 w-full"><option value="count">Count — whole pieces</option><option value="package">Package — strip, box, bottle</option><option value="volume">Volume — fractional</option><option value="mass">Mass — fractional</option></select></label>
        <button type="submit" class="rounded-lg bg-emerald-600 text-white px-4 py-2"><?= $so ? 'Ku dar' : 'Add definition' ?></button>
        <p class="text-sm sm:col-span-4" role="status" data-definition-feedback></p>
    </form>
    <small class="block text-slate-500 mt-2"><?= $so ? 'Magaca iyo family-ga unit la keydiyey lama beddelo. Baakad cabbir kale leh u samee magac gaar ah: strip-14, box-100.' : 'Saved names and unit families are permanent. Use distinct names for packaging variants, such as strip-14 or box-100. Volume and mass require a compatible base unit; registering a dose does not calculate clinical dosing.' ?></small>
</details>
