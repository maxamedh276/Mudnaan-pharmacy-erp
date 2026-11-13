(() => {
    'use strict';
    const root = document.getElementById('pharmacyDefinitions');
    const workspace = document.getElementById('pharmacyWorkspace');
    const form = document.getElementById('pharmacyDefinitionForm');
    if (!root || !form || !workspace) return;
    form.elements.kind.addEventListener('change', () => {
        const unit = form.elements.kind.value === 'unit';
        form.querySelector('[data-definition-family]').hidden = !unit;
        form.elements.name.maxLength = unit ? 30 : 80;
        form.elements.name.placeholder = unit ? 'e.g. strip-14' : 'e.g. Chewable tablet';
    });
    form.addEventListener('submit', async event => {
        event.preventDefault();
        const button = form.querySelector('button');
        if (button.disabled || !form.reportValidity()) return;
        button.disabled = true;
        const feedback = form.querySelector('[data-definition-feedback]');
        feedback.textContent = '';
        const data = Object.fromEntries(new FormData(form));
        try {
            const response = await fetch(root.dataset.url, {method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','Accept':'application/json','X-Pharmacy-CSRF':workspace.dataset.token},body:JSON.stringify(data)});
            const result = await response.json();
            if (!response.ok || !result.success) throw new Error(result.message || `HTTP ${response.status}`);
            const name = data.kind === 'unit' ? data.name.trim().toLowerCase() : data.name.trim();
            const selectors = data.kind === 'form' ? ['#pharmacyForm [name="dosage_form"]'] : ['#pharmacyForm [name="base_unit"]','#pharmacyForm [data-unit-field="name"]'];
            selectors.forEach(selector => document.querySelectorAll(selector).forEach(select => select.add(new Option(name,name))));
            const template = document.getElementById('medicineUnitTemplate');
            if (data.kind === 'unit') template?.content.querySelector('[data-unit-field="name"]')?.add(new Option(name,name));
            feedback.textContent = `${name} — ${result.message}`;
            window.erp?.toast(result.message);
            form.elements.name.value = '';
        } catch (error) { feedback.textContent = error.message; window.erp?.toast(error.message,'error'); }
        finally { button.disabled = false; }
    });
})();
