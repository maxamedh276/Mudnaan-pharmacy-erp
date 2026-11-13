<?php $t = static fn (string $key): string => esc(lang('Pharmacy.' . $key)); ?>
<link rel="stylesheet" href="<?= base_url('assets/css/pharmacy-pos.css').'?v='.filemtime(FCPATH.'assets/css/pharmacy-pos.css') ?>">
<div class="pos-receipt-tools"><button type="button" id="pharmacyRefreshStock" title="Refresh stock for visible medicines and the current cart">Refresh stock</button><button type="button" id="pharmacyHoldSale"><?= service('request')->getLocale() === 'so' ? 'Hakii iibka' : 'Hold sale' ?></button><button type="button" id="pharmacyHeldSales"><?= service('request')->getLocale() === 'so' ? 'Iibka la hakiyey' : 'Held tickets' ?></button><button type="button" data-pharmacy-receipt=""><?= $t('receiptsReprint') ?></button></div>
<dialog id="pharmacyHeldDialog" aria-labelledby="pharmacyHeldTitle" class="rounded-2xl p-6 w-11/12 max-w-2xl max-h-[85vh] overflow-y-auto"><header class="flex justify-between gap-4"><h2 id="pharmacyHeldTitle" class="font-bold">Held tickets / Iibka la hakiyey</h2><button type="button" id="pharmacyHeldClose" aria-label="Close">×</button></header><p class="text-sm text-slate-500 my-3">Your tickets only. Stock is not reserved. Current prices, stock and prescription limits are checked again at checkout.</p><div id="pharmacyHeldList" aria-live="polite"></div></dialog>
<div class="pos-search-bar">
    <label><span><?= $t('findMedicine') ?> <kbd>F2</kbd></span><input id="pharmacyCatalogSearch" type="search" autocomplete="off" placeholder="<?= $t('findMedicineHint') ?>" aria-controls="pharmacyQuickMedicines"></label>
    <label><span><?= $t('barcode') ?> <kbd>F4</kbd></span><input id="pharmacyScan" type="search" autocomplete="off" placeholder="Barcode / SKU"><span id="pharmacyScanFeedback" role="status"></span></label>
</div>
<form id="pharmacyCheckout" data-operation="checkout" class="pos-checkout">
    <div class="pos-sale-workspace">
        <section class="pos-catalog" aria-label="<?= $t('medicines') ?>"><div class="pos-section-heading"><h2><?= $t('quickAdd') ?></h2><span id="pharmacyCatalogCount" aria-live="polite"></span></div><div id="pharmacyQuickMedicines" class="pos-quick-medicines"></div></section>
        <section class="pos-ticket"><div class="pos-section-heading"><div><h2><?= $t('currentSale') ?></h2><p><?= $t('mixedUnitHint') ?></p></div><span class="pos-line-count" id="pharmacyLineCount">0</span></div>
        <div id="pharmacyCart"></div><button type="button" id="pharmacyCartAdd" class="pos-add-line">+ <?= $t('addLine') ?></button></section>
    </div>
    <aside class="pos-payment-panel" aria-label="<?= $t('checkout') ?>">
        <div class="pos-payment-title"><span><?= $t('checkout') ?></span><span class="pos-payment-dot"></span></div>
        <div class="pos-total-block"><span><?= $t('subtotal') ?></span><p id="pharmacyCartTotal">0.00</p><small><?= $t('taxHint') ?></small></div>
        <div class="pos-payment-fields">
            <p id="pharmacyPatientSafety" class="pos-paid-label text-sm border rounded-lg p-3 bg-amber-50" role="status" hidden></p>
            <label><?= $t('customer') ?><input type="search" aria-label="Find customer by name or phone" data-pharmacy-party-search="<?= site_url('pharmacy/lookup/customers') ?>" data-target="pharmacyCustomerSelect" placeholder="Find customer by name or phone"><select id="pharmacyCustomerSelect" aria-label="Customer account" name="customer_id"><option value=""><?= $t('walkIn') ?></option><?php foreach ($customers as $customer): ?><option value="<?= (int)$customer['id'] ?>"><?= esc($customer['name']) ?></option><?php endforeach; ?></select></label>
            <label><?= $t('prescriptions') ?><select name="prescription_id"><option value=""><?= $t('otc') ?></option><?php foreach ($prescriptions as $rx): if (!in_array($rx['status'],['approved','partial'],true)) continue; ?><option value="<?= (int)$rx['id'] ?>" data-allergies="<?= esc($rx['patient_allergies']??'','attr') ?>" data-allergy-access="<?= \App\Libraries\AuthContext::can('pharmacy.patients.view')?'1':'0' ?>">#<?= (int)$rx['id'] ?> · <?= esc($rx['patient_name']) ?></option><?php endforeach; ?></select></label>
            <label><?= $t('paymentMethod') ?><select name="payment_method_id" required><?php foreach ($payments as $payment): ?><option value="<?= (int)$payment['id'] ?>"><?= esc($payment['name']) ?></option><?php endforeach; ?></select></label>
            <label class="pos-paid-label"><?= $t('paid') ?><div class="pos-paid-control"><input type="number" name="paid" required min="0" step="0.01" inputmode="decimal"><button type="button" id="pharmacyExactPaid"><?= $t('exactAmount') ?></button></div></label>
            <label class="pos-paid-label" style="display:flex;align-items:center;gap:8px"><input type="checkbox" id="pharmacySplitPayment" style="width:18px;height:18px"> <?= service('request')->getLocale() === 'so' ? 'Laba hab ku bixi' : 'Split payment' ?></label>
            <div id="pharmacySecondPayment" hidden class="pos-paid-label">
                <label><?= $t('paymentMethod') ?> 2<select name="second_method_id"><?php foreach ($payments as $payment): ?><option value="<?= (int)$payment['id'] ?>"><?= esc($payment['name']) ?></option><?php endforeach; ?></select></label>
                <label><?= $t('paid') ?> 2<input name="second_paid" type="number" min="0" step="0.01" value="0" inputmode="decimal"></label>
            </div>
            <p id="pharmacyPaymentBalance" class="pos-paid-label text-sm" role="status" aria-live="polite"></p>
        </div>
        <p data-feedback role="status" aria-live="polite" class="pos-payment-feedback"></p>
        <button type="submit" class="pos-complete-sale"><?= $t('completeSale') ?><span aria-hidden="true">→</span></button>
        <p class="pos-checkout-note"><?= $t('dispenseHint') ?></p>
    </aside>
</form>
<template id="pharmacyCartTemplate"><fieldset class="cart-line pos-cart-line">
    <label class="pos-line-medicine"><?= $t('medicines') ?><select data-medicine data-field="product_id" required><option value=""><?= $t('select') ?></option><?php foreach ($medicines as $medicine): ?><option value="<?= (int)$medicine['product_id'] ?>"><?= esc($medicine['name'].' · '.$medicine['strength']) ?></option><?php endforeach; ?></select></label>
    <label><?= $t('sellingUnit') ?><select data-unit data-field="medicine_unit_id" required></select></label>
    <label class="pos-line-quantity"><?= $t('quantity') ?><input data-field="quantity" type="number" min="1" step="1" required value="1" inputmode="decimal"></label>
    <div class="pos-line-total"><span><?= $t('lineTotal') ?></span><strong data-pos-line-total>0.00</strong></div>
    <?php if (\App\Libraries\AuthContext::can('pharmacy.discounts.apply')): ?><details style="grid-column:1/-1" class="text-sm"><summary class="cursor-pointer text-emerald-700">Discount / Qiimo-dhimis</summary><div class="grid sm:grid-cols-2 gap-3 mt-2"><label>Line discount amount<input data-field="discount" type="number" min="0" step="0.01" value="0" inputmode="decimal"></label><label>Reason<input data-field="pharmacy_discount_reason" maxlength="300" placeholder="Required when a discount is applied"></label></div></details><?php endif; ?>
    <button type="button" data-remove-cart class="pos-remove-line"><?= $t('remove') ?></button>
</fieldset></template>
