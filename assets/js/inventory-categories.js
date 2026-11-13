/** Full-path category directory and AJAX creation at any hierarchy depth. */
(() => {
    'use strict';
    const workspace = document.getElementById('categoryWorkspace');
    if (!workspace) return;
    const baseUrl = workspace.dataset.baseUrl;
    const tableBody = document.getElementById('categoryRows');
    const searchField = document.getElementById('categorySearch');
    const dialog = document.getElementById('categoryDialog');
    let categories = JSON.parse(document.getElementById('categoryInitialData').textContent);
    const parentPicker = new CategoryPicker(document.getElementById('parentCategoryPicker'), categories, { optional: true });

    function cell(text, classes = '') {
        const element = document.createElement('td');
        element.className = 'p-3 ' + classes;
        element.textContent = String(text);
        return element;
    }
    let editingCategory = null;

    function openCategoryForm(category = null) {
        editingCategory = category;
        document.getElementById('categoryForm').reset();
        document.getElementById('categoryDialogTitle').textContent = category ? 'Edit category' : 'Add category';
        document.getElementById('categoryName').value = category?.name || '';
        const categoryId = Number(category?.id);
        parentPicker.setCategories(category ? categories.filter(item => !item.path_ids.some(id => Number(id) === categoryId)) : categories);
        parentPicker.select(category?.parent_id ?? null);
        dialog.showModal();
    }

    const expandedParents = new Set();

    function renderCategories() {
        const search = searchField.value.trim().toLocaleLowerCase();
        const parentIds = new Set(categories.map(category => Number(category.parent_id)).filter(Boolean));
        const searchMatches = categories.filter(category => category.path.toLocaleLowerCase().includes(search));
        const visibleSearchIds = new Set(searchMatches.flatMap(category => category.path_ids.map(Number)));
        // Searching reveals matching branches; clearing search restores the user's expansion state.
        const matches = categories.filter(category => search
            ? visibleSearchIds.has(Number(category.id))
            : category.path_ids.slice(0, -1).every(id => expandedParents.has(Number(id))));
        tableBody.replaceChildren();
        matches.forEach(category => {
            const row = document.createElement('tr');
            const nameCell = cell('', 'font-semibold text-slate-800');
            const nameLabel = document.createElement('div');
            nameLabel.className = 'flex items-center gap-2';
            nameLabel.style.paddingLeft = Math.min(category.depth - 1, 8) * 14 + 'px';
            const icon = document.createElement('i');
            icon.dataset.lucide = category.depth > 1 ? 'corner-down-right' : 'folder';
            icon.className = 'w-4 h-4 text-slate-400 shrink-0';
            const name = document.createElement('span');
            name.textContent = category.name;
            const level = document.createElement('span');
            level.className = 'text-[10px] font-normal px-2 py-1 rounded bg-brand-50 text-brand-600 whitespace-nowrap';
            level.textContent = 'Level ' + category.depth;
            if (parentIds.has(Number(category.id))) {
                const expanded = Boolean(search) || expandedParents.has(Number(category.id));
                const toggle = document.createElement('button');
                toggle.type = 'button';
                toggle.dataset.categoryToggle = category.id;
                toggle.className = 'flex items-center gap-2 rounded p-1 -ml-1 text-left hover:bg-slate-100 focus-visible:ring-2 focus-visible:ring-brand-500';
                toggle.setAttribute('aria-expanded', String(expanded));
                toggle.setAttribute('aria-label', (expanded ? 'Collapse ' : 'Expand ') + category.name);
                icon.dataset.lucide = expanded ? 'chevron-down' : 'chevron-right';
                toggle.append(icon, name, level);
                toggle.addEventListener('click', () => {
                    if (search) searchField.value = '';
                    expandedParents.has(Number(category.id)) ? expandedParents.delete(Number(category.id)) : expandedParents.add(Number(category.id));
                    renderCategories();
                    tableBody.querySelector(`[data-category-toggle="${Number(category.id)}"]`)?.focus();
                });
                nameLabel.append(toggle);
            } else nameLabel.append(icon, name, level);
            nameCell.append(nameLabel);
            const productsCell = cell(category.total_product_count + ' total', 'text-slate-600 whitespace-nowrap');
            const directCount = document.createElement('span');
            directCount.className = 'block text-[10px] text-slate-400 mt-1';
            directCount.textContent = category.product_count + ' directly assigned';
            productsCell.append(directCount);
            const actionCell = cell('', 'text-right');
            const browseLink = document.createElement('a');
            browseLink.href = workspace.dataset.productsUrl + '?category_id=' + category.id;
            browseLink.className = 'text-brand-600 font-semibold whitespace-nowrap hover:underline';
            browseLink.textContent = 'Browse products →';
            actionCell.append(browseLink);
            if (workspace.dataset.canEdit === 'true') {
                const edit = document.createElement('button');
                edit.type = 'button';
                edit.className = 'ml-3 text-brand-600 font-semibold hover:underline';
                edit.textContent = 'Edit';
                edit.setAttribute('aria-label', 'Edit ' + category.name);
                edit.addEventListener('click', () => openCategoryForm(category));
                actionCell.append(edit);
            }
            const remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'ml-3 text-red-700 font-semibold hover:underline disabled:opacity-40';
            remove.textContent = 'Delete';
            remove.setAttribute('aria-label', 'Delete ' + category.name);
            const reason = workspace.dataset.canDelete !== 'true' ? 'No delete permission'
                : parentIds.has(Number(category.id)) ? 'Has subcategories'
                : Number(category.product_count) > 0 ? 'Has assigned products' : '';
            remove.disabled = Boolean(reason);
            if (reason) remove.title = reason;
            remove.addEventListener('click', async () => {
                remove.disabled = true;
                try {
                    if (!await erp.confirm('Remove “' + category.path + '” from the category list?', {
                        title: 'Delete category?', confirmButtonText: 'Delete', destructive: true
                    })) return;
                    await erp.web(baseUrl + '/delete/' + category.id, { method: 'POST' });
                    categories = categories.filter(item => Number(item.id) !== Number(category.id));
                    parentPicker.setCategories(categories);
                    renderCategories();
                    searchField.focus();
                    erp.toast('Category deleted.');
                } catch (_) { /* The shared request helper shows the error. */ }
                finally { remove.disabled = false; }
            });
            actionCell.append(remove);
            if (reason) {
                const note = document.createElement('span');
                note.className = 'block mt-1 text-xs text-slate-500';
                note.textContent = reason;
                actionCell.append(note);
            }
            const status = !category.hierarchy_valid ? 'Invalid hierarchy' : category.selectable ? 'Active' : 'Inactive';
            row.append(nameCell, cell(category.path, 'text-slate-500'), productsCell, cell(status, category.selectable ? 'text-emerald-600' : 'text-slate-400'), actionCell);
            tableBody.append(row);
        });
        if (!matches.length) {
            const row = document.createElement('tr');
            const emptyCell = cell('No matching categories.', 'py-10 text-center text-slate-400');
            emptyCell.colSpan = 5;
            row.append(emptyCell);
            tableBody.append(row);
        }
        document.getElementById('categoryCount').textContent = `${matches.length} of ${categories.length} categories`;
        lucide.createIcons({ root: tableBody });
    }
    searchField.addEventListener('input', renderCategories);
    document.getElementById('addCategory')?.addEventListener('click', () => openCategoryForm());
    dialog.querySelectorAll('[data-close-category]').forEach(button => button.addEventListener('click', () => dialog.close()));
    document.getElementById('categoryForm').addEventListener('submit', async event => {
        event.preventDefault();
        const saveButton = document.getElementById('saveCategory');
        saveButton.disabled = true;
        saveButton.textContent = 'Saving…';
        try {
            await erp.web(baseUrl + (editingCategory ? '/update/' + editingCategory.id : '/create'), { method: 'POST', body: JSON.stringify({ name: document.getElementById('categoryName').value.trim(), parent_id: parentPicker.value }) });
            dialog.close();
            erp.toast(editingCategory ? 'Category updated.' : 'Category created.');
            categories = (await erp.web(baseUrl + '/data')).data;
            parentPicker.setCategories(categories);
            renderCategories();
        } catch (_) { /* Keep form input on validation failures; shared helper reports the error. */ }
        finally { saveButton.disabled = false; saveButton.textContent = 'Save category'; }
    });
    renderCategories();
})();
