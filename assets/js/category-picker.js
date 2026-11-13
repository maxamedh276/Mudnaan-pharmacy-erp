/** Reusable cascading category selector with no fixed number of levels. */
window.CategoryPicker = class CategoryPicker {
    constructor(container, categories, { optional = false } = {}) {
        this.container = container;
        this.categories = categories;
        this.optional = optional;
        this.selectedId = null;
        this.render();
    }
    setCategories(categories) { this.categories = categories; this.render(); }
    select(categoryId) { this.selectedId = Number(categoryId) || null; this.render(); }
    get value() { return this.selectedId; }
    render() {
        this.container.replaceChildren();
        const selectedCategory = this.categories.find(category => Number(category.id) === this.selectedId);
        const pathIds = selectedCategory?.path_ids || [];
        let parentId = null;
        let level = 1;
        for (;;) {
            const siblings = this.categories.filter(category => category.selectable && (Number(category.parent_id) || null) === parentId);
            if (siblings.length === 0) break;
            const label = document.createElement('label');
            label.className = 'block text-xs font-medium text-slate-500';
            label.textContent = 'Level ' + level;
            const select = document.createElement('select');
            select.className = 'mt-1.5 block w-full p-2.5 border border-slate-300 rounded-lg bg-white text-sm text-slate-800';
            select.setAttribute('aria-label', 'Category level ' + level);
            select.append(new Option(level === 1 ? this.optional ? 'No parent (top level)' : 'Select category' : 'No further subcategory', ''));
            siblings.forEach(category => select.append(new Option(category.name, category.id)));
            const selectedAtLevel = pathIds[level - 1];
            select.value = selectedAtLevel ? String(selectedAtLevel) : '';
            const previousParent = parentId;
            const selectionLevel = level;
            select.addEventListener('change', () => {
                this.selectedId = Number(select.value) || previousParent;
                this.render();
                const controls = this.container.querySelectorAll('select');
                controls[Math.min(selectionLevel - 1, controls.length - 1)]?.focus();
            });
            label.append(select);
            this.container.append(label);
            if (!selectedAtLevel) break;
            parentId = selectedAtLevel;
            level++;
        }
        if (selectedCategory) {
            const breadcrumb = document.createElement('p');
            breadcrumb.className = 'col-span-full text-xs text-brand-600';
            breadcrumb.textContent = selectedCategory.path;
            this.container.append(breadcrumb);
        }
    }
};
