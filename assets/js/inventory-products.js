/** Paginated web catalog, four daily filters, and sectioned product forms. */
(() => {
    'use strict';
    const workspace = document.getElementById('catalogWorkspace');
    if (!workspace) return;
    const baseUrl = workspace.dataset.baseUrl;
    const initialData = JSON.parse(document.getElementById('catalogInitialData').textContent);
    const tableBody = document.getElementById('productRows');
    const statusLabel = document.getElementById('catalogStatus');
    const searchField = document.getElementById('productSearch');
    const dialog = document.getElementById('productDialog');
    const imageDialog = document.getElementById('productImageDialog');
    const categoryPicker = new CategoryPicker(document.getElementById('productCategoryPicker'), initialData.categories);
    const filterCategory = document.getElementById('filterCategory');
    const filterStock = document.getElementById('filterStock');
    const filterMinPrice = document.getElementById('filterMinPrice');
    const filterMaxPrice = document.getElementById('filterMaxPrice');
    const pageSize = document.getElementById('catalogPageSize');
    let catalog = initialData.catalog;
    let pendingRequest;
    let searchTimer;
    let savingProduct = false;
    let creationKey;
    filterCategory.value = Number(workspace.dataset.categoryId) > 0 ? workspace.dataset.categoryId : '';
    pageSize.value = String(catalog.per_page);

    function cell(text, classes = '') {
        const element = document.createElement('td');
        element.className = 'p-3 ' + classes;
        element.textContent = String(text ?? '');
        return element;
    }
    function actionButton(label, action, productId, classes = '') {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'px-2 py-2 text-xs font-semibold hover:underline ' + classes;
        button.textContent = label;
        button.dataset.productAction = action;
        button.dataset.productId = productId;
        return button;
    }
    function renderCatalog() {
        tableBody.replaceChildren();
        catalog.items.forEach(product => {
            const row = document.createElement('tr');
            const nameCell = cell('', 'font-semibold text-slate-900');
            const nameLabel = document.createElement('span');
            nameLabel.textContent = product.name;
            nameCell.append(nameLabel);
            const identifier = document.createElement('span');
            identifier.className = 'block text-[10px] text-slate-400 font-normal mt-1';
            identifier.textContent = product.sku || '';
            nameCell.append(identifier);
            if (product.image_path) {
                const imageUrl = new URL(product.image_path, workspace.dataset.imageBase);
                if (imageUrl.origin === location.origin) {
                    const image = document.createElement('img');
                    image.src = imageUrl.href;
                    image.alt = product.name;
                    image.loading = 'lazy';
                    image.addEventListener('error', () => image.remove());
                    image.className = 'w-9 h-9 object-cover rounded-lg float-left mr-3';
                    nameCell.prepend(image);
                }
            }
            const stockLabel = product.package_unit ? `${product.reorder_quantity} ${product.package_unit} sealed · ${product.quantity} ${product.unit} total` : product.quantity <= 0 ? 'Out of stock' : product.reorder_quantity <= product.reorder_level ? product.quantity + ' · Low' : String(product.quantity);
            const actions = cell('', 'text-right whitespace-nowrap');
            if (workspace.dataset.canEdit === 'true') actions.append(actionButton('Edit', 'edit', product.id, 'text-brand-600'), actionButton('Photo', 'image', product.id, 'text-slate-500'));
            if (workspace.dataset.canDelete === 'true') actions.append(actionButton('Archive', 'archive', product.id, 'text-rose-600'));
            row.append(nameCell, cell(product.category_path, 'text-slate-500'), cell(Number(product.cost_price).toFixed(2), 'text-right text-slate-500 tabular-nums'), cell(Number(product.sell_price).toFixed(2), 'text-right font-semibold tabular-nums'), cell(stockLabel, product.quantity <= 0 ? 'text-rose-600' : product.reorder_quantity <= product.reorder_level ? 'text-amber-600' : 'text-emerald-600'), cell(product.is_active ? 'Active' : 'Inactive', 'text-slate-500'), actions);
            tableBody.append(row);
        });
        if (!catalog.items.length) {
            const row = document.createElement('tr');
            const emptyCell = cell('No products match these filters.', 'text-center text-slate-400 py-10');
            emptyCell.colSpan = 7;
            row.append(emptyCell);
            tableBody.append(row);
        }
        const firstItem = catalog.total ? (catalog.page - 1) * catalog.per_page + 1 : 0;
        statusLabel.textContent = `${firstItem}–${Math.min(catalog.page * catalog.per_page, catalog.total)} of ${catalog.total} products`;
        document.querySelectorAll('[data-catalog-summary]').forEach(element => {
            const key = element.dataset.catalogSummary;
            element.textContent = Number(catalog.summary[key] || 0).toLocaleString(undefined, { minimumFractionDigits: key === 'valuation' ? 2 : 0, maximumFractionDigits: key === 'valuation' ? 2 : 0 });
        });
        document.getElementById('previousProductPage').disabled = catalog.page <= 1;
        document.getElementById('nextProductPage').disabled = catalog.page * catalog.per_page >= catalog.total;
    }
    async function loadCatalog(page = 1) {
        pendingRequest?.abort();
        const requestController = new AbortController();
        pendingRequest = requestController;
        const endpoint = new URL(baseUrl + '/data', location.origin);
        Object.entries({ q: searchField.value.trim(), category_id: filterCategory.value, stock: filterStock.value, min_price: filterMinPrice.value, max_price: filterMaxPrice.value, page, per_page: pageSize.value }).forEach(([key, value]) => endpoint.searchParams.set(key, value));
        statusLabel.textContent = 'Loading products…';
        tableBody.setAttribute('aria-busy', 'true');
        try {
            const result = await erp.web(endpoint, { signal: requestController.signal });
            if (pendingRequest !== requestController) return;
            catalog = result.data;
            renderCatalog();
        } catch (error) {
            if (error.name !== 'AbortError') statusLabel.textContent = 'Could not load products. Check filters and try again.';
        } finally {
            if (pendingRequest === requestController) tableBody.setAttribute('aria-busy', 'false');
        }
    }
    function openProduct(product) {
        document.getElementById('productForm').reset();
        document.getElementById('productDialogTitle').textContent = product ? 'Edit product' : 'Add product';
        document.getElementById('productId').value = product?.id || '';
        document.getElementById('productName').value = product?.name || '';
        document.getElementById('productBrand').value = product?.brand || '';
        document.getElementById('productBarcode').value = product?.barcode || '';
        document.getElementById('productSealedBarcode').value = product?.sealed_barcode || '';
        document.getElementById('productSellingPrice').value = product?.sell_price ?? 0;
        document.getElementById('productReorderLevel').value = product?.reorder_level ?? 10;
        creationKey = crypto.randomUUID();
        document.getElementById('productStockType').value = product?.package_unit ? 'sealed' : 'non_sealed';
        if (document.getElementById('productOpeningStock')) document.getElementById('productOpeningStock').hidden = Boolean(product);
        const unitField = document.getElementById('productUnit');
        const unit = product?.unit || 'pcs';
        if (!Array.from(unitField.options).some(option => option.value === unit)) unitField.append(new Option(unit, unit));
        unitField.value = unit;
        document.getElementById('productPackageUnit').value = product?.package_unit || '';
        document.getElementById('productPackageSize').value = product?.package_size || '';
        document.getElementById('productPackagePrice').value = product?.package_price ?? '';
        refreshProductUnits();
        document.getElementById('productDefaultSale').value = product?.default_sale_mode || 'loose';
        document.getElementById('productSellingGroup').value = product?.selling_group_id || '';
        categoryPicker.select(product?.category_id || null);
        dialog.showModal();
        dialog.scrollTop = 0;
    }
    function refreshProductUnits() {
        const sealed = document.getElementById('productStockType').value === 'sealed';
        const unit = document.getElementById('productUnit').value;
        const pack = document.getElementById('productPackageUnit').value.trim() || 'package';
        document.getElementById('productPackageFields').classList.toggle('hidden',!sealed);
        ['productPackageUnit','productPackageSize','productPackagePrice','productSealedBarcode'].forEach(id=>{const field=document.getElementById(id);field.disabled=!sealed;field.required=sealed && id!=='productSealedBarcode';});
        const capacity = document.getElementById('productPackageSize');
        capacity.min = capacity.step = unit === 'pcs' ? '1' : '0.001';
        document.getElementById('productPackageSizeLabel').textContent = `${unit === 'pcs' ? 'Pieces' : unit} per package`;
        document.getElementById('productReorderLabel').textContent = `Low-stock alert (${sealed ? 'sealed ' + pack : unit})`;
        document.getElementById('productReorderLevel').step = sealed ? '1' : '0.001';
        const sale = document.getElementById('productDefaultSale'), prior=sale.value;
        sale.replaceChildren(new Option(unit,'loose'));if(sealed)sale.add(new Option(pack + ' (sealed)','sealed'));sale.value=sealed && prior==='sealed' ? 'sealed' : 'loose';
        const groups=document.getElementById('productSellingGroup'),group=groups.value;
        groups.replaceChildren(new Option('None',''));
        (initialData.sellingGroups || []).filter(g=>Number(g.is_active) && g.unit===unit).forEach(g=>groups.add(new Option(g.name,g.id)));groups.value=group;
        const opening=document.getElementById('openingSealedLabel');if(opening){opening.classList.toggle('hidden',!sealed);document.getElementById('productOpeningSealed').disabled=!sealed;document.getElementById('openingQuantityLabel').textContent=(sealed?'Already opened ':'Opening quantity ')+`(${unit})`;document.getElementById('openingCostLabel').textContent=`Opening cost per ${sealed?pack:unit} (USD)`;}
    }
    ['productStockType','productUnit','productPackageUnit'].forEach(id=>document.getElementById(id).addEventListener('change',refreshProductUnits));
    // Search and price fields update while typing; dropdowns update immediately.
    [searchField, filterMinPrice, filterMaxPrice].forEach(field => field.addEventListener('input', () => {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(() => loadCatalog(), 250);
    }));
    [filterCategory, filterStock, filterMinPrice, filterMaxPrice, pageSize].forEach(field => field.addEventListener('change', () => {
        clearTimeout(searchTimer);
        loadCatalog();
    }));
    document.getElementById('clearProductFilters').addEventListener('click', () => {
        clearTimeout(searchTimer);
        searchField.value = ''; filterCategory.value = ''; filterStock.value = ''; filterMinPrice.value = ''; filterMaxPrice.value = ''; loadCatalog();
    });
    document.getElementById('previousProductPage').addEventListener('click', () => loadCatalog(catalog.page - 1));
    document.getElementById('nextProductPage').addEventListener('click', () => loadCatalog(catalog.page + 1));
    document.getElementById('addProduct')?.addEventListener('click', () => openProduct());
    dialog.querySelectorAll('[data-close-product]').forEach(button => button.addEventListener('click', () => dialog.close()));
    document.getElementById('productForm').addEventListener('submit', async event => {
        event.preventDefault();
        if (savingProduct) return;
        if (!document.getElementById('productForm').reportValidity()) return;
        if (!categoryPicker.value) { erp.toast('Select a product category.', 'error'); return; }
        const saveButton = document.getElementById('saveProduct');
        savingProduct = true; saveButton.disabled = true; saveButton.textContent = 'Saving…';
        try {
            await erp.web(baseUrl + '/save', { method: 'POST', body: JSON.stringify({
                id: Number(document.getElementById('productId').value) || null,
                creation_request_key: document.getElementById('productId').value ? undefined : creationKey,
                stock_type: document.getElementById('productStockType').value,
                default_sale_mode: document.getElementById('productDefaultSale').value,
                selling_group_id: document.getElementById('productSellingGroup').value,
                opening_sealed: document.getElementById('productId').value || document.getElementById('productStockType').value !== 'sealed' ? 0 : (document.getElementById('productOpeningSealed')?.value || 0),
                opening_quantity: document.getElementById('productId').value ? 0 : (document.getElementById('productOpeningQuantity')?.value || 0),
                opening_unit_cost: document.getElementById('productOpeningCost')?.value,
                name: document.getElementById('productName').value.trim(), brand: document.getElementById('productBrand').value.trim(),
                barcode: document.getElementById('productBarcode').value.trim(), unit: document.getElementById('productUnit').value,
                sealed_barcode: document.getElementById('productSealedBarcode').value.trim(),
                package_unit: document.getElementById('productPackageUnit').value.trim(),
                package_size: document.getElementById('productPackageSize').value,
                package_price: document.getElementById('productPackagePrice').value,
                category_id: categoryPicker.value, sell_price: Number(document.getElementById('productSellingPrice').value),
                reorder_level: Number(document.getElementById('productReorderLevel').value),
            }) });
            dialog.close(); erp.toast('Product saved.'); await loadCatalog(catalog.page);
        } catch (_) { /* Shared request helper displays validation failures. */ }
        finally { savingProduct = false; saveButton.disabled = false; saveButton.textContent = 'Save product'; }
    });
    tableBody.addEventListener('click', async event => {
        const button = event.target.closest('[data-product-action]');
        if (!button) return;
        const product = catalog.items.find(item => Number(item.id) === Number(button.dataset.productId));
        if (!product) return;
        if (button.dataset.productAction === 'edit') { openProduct(product); return; }
        if (button.dataset.productAction === 'image') {
            document.getElementById('productImageForm').reset(); document.getElementById('imageProductId').value = product.id;
            document.getElementById('productImageTitle').textContent = 'Product image · ' + product.name; imageDialog.showModal(); return;
        }
        if (!await erp.confirm(`Archive “${product.name}”? Remaining stock will be written off.`, { title: 'Archive product', confirmButtonText: 'Archive', destructive: true })) return;
        button.disabled = true;
        try { await erp.api('products/' + product.id, { method: 'DELETE' }); erp.toast('Product archived.'); await loadCatalog(catalog.page); }
        catch (_) { button.disabled = false; }
    });
    document.getElementById('closeProductImage').addEventListener('click', () => imageDialog.close());
    document.getElementById('productImageForm').addEventListener('submit', async event => {
        event.preventDefault();
        const file = document.getElementById('productImageFile').files[0];
        if (!file || file.size > 4 * 1024 * 1024) { erp.toast('Choose an image up to 4 MB.', 'error'); return; }
        const saveButton = document.getElementById('saveProductImage'); saveButton.disabled = true;
        const formData = new FormData(); formData.append('image', file);
        try {
            const token = window.ERP_TOKEN || localStorage.getItem('mudnaan_erp_token') || '';
            const response = await fetch(workspace.dataset.imageUrl + '/' + document.getElementById('imageProductId').value + '/image', { method: 'POST', headers: token ? { Authorization: 'Bearer ' + token } : {}, body: formData });
            const result = await response.json();
            if (!response.ok || !result.success) throw new Error(result.error?.message || 'Image upload failed.');
            imageDialog.close(); erp.toast('Product image saved.'); await loadCatalog(catalog.page);
        } catch (error) { erp.toast(error.message, 'error'); }
        finally { saveButton.disabled = false; }
    });
    renderCatalog();
})();
