/** Keyboard-wedge scanners: exact code + Enter/Tab, using the normal cart and stock pools. */
(() => {
    'use strict';
    const search = document.getElementById('productSearchInput');
    const endpoint = document.currentScript?.dataset.scanUrl;
    if (!search || !endpoint) return;
    let buffer = '', lastKey = 0, queue = Promise.resolve();
    async function addScan(code) {
        const response = await erp.web(`${endpoint}?code=${encodeURIComponent(code)}`);
        const product = response.data;
        const mode = product.scan_mode || product.default_sale_mode || 'loose';
        const key = `${product.id}:${mode}`;
        const existing = POS.cart.find(item => cartKey(item) === key);
        const requested = (existing?.qty || 0) + 1;
        const shortage = product.package_unit ? poolMessage(product, mode, requested)
            : (requested > Number(product.quantity) ? `${product.name}: only ${product.quantity} ${product.unit} available.` : '');
        POS.products[product.id] = product;
        POS.productTaxRates[product.id] = Number(product.tax_rate || 0);
        if (shortage) { await showStockShortage(shortage); return; }
        addToCart(product.id, product.name, Number(mode === 'sealed' ? product.package_price : product.sell_price), 1, mode, mode === 'sealed' ? product.package_unit : product.unit);
    }
    document.addEventListener('keydown', event => {
        if (event.isComposing || event.ctrlKey || event.metaKey || event.altKey || event.repeat) { buffer = ''; return; }
        const target = event.target;
        const inSearch = target === search;
        // Never consume payment, quantity, customer, Select2 or modal input.
        if (target.closest?.('dialog[open], [role="dialog"], .swal2-container') || (!inSearch && target.closest?.('input, textarea, select, [contenteditable="true"]'))) { buffer = ''; return; }
        const now = performance.now();
        if (event.key === 'Enter' || event.key === 'Tab') {
            const code = (inSearch ? search.value : (now - lastKey <= 100 && buffer.length >= 3 ? buffer : '')).trim();
            buffer = '';
            if (!code) return;
            event.preventDefault(); event.stopImmediatePropagation();
            if (inSearch) { search.value = ''; renderProductFilter(); }
            if (code.length > 80) { erp.toast('Scan a product barcode or SKU up to 80 characters.', 'error'); return; }
            // Preserve every scan, including repeated identical scans, in arrival order.
            queue = queue.then(() => addScan(code)).catch(error => { if (!error.reported) erp.toast(error.message || 'Product could not be scanned. Try again.', 'error'); });
            return;
        }
        if (event.key.length === 1) { buffer = (now - lastKey <= 100 ? buffer : '') + event.key; lastKey = now; }
        else buffer = '';
    }, true);
})();
