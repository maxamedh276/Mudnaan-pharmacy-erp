(() => {
    'use strict';
    const root = document.getElementById('pharmacyWorkspace');
    const form = document.getElementById('pharmacyForm');
    if (!root) return;
    const details = document.getElementById('prescriptionDetails');
    const approve = document.getElementById('pharmacyApprove');
    approve?.addEventListener('click', async () => {
        if (approve.disabled) return;
        approve.disabled = true;
        try {
            const response = await fetch(`${root.dataset.detailUrl.replace(/\/prescriptions$/, '')}/approve/save`, {
                method: 'POST', credentials: 'same-origin',
                headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Pharmacy-CSRF': root.dataset.token},
                body: JSON.stringify({prescription_id: approve.dataset.id})
            });
            const result = await response.json();
            if (!response.ok || !result.success) throw new Error(result.message || `HTTP ${response.status}`);
            window.erp?.toast(result.message);
            window.location.reload();
        } catch (error) { document.getElementById('prescriptionDetailsBody').textContent = error.message; window.erp?.toast(error.message, 'error'); }
        finally { approve.disabled = false; }
    });
    let detailsRequest;
    document.getElementById('prescriptionDetailsClose')?.addEventListener('click', () => details.close());
    root.addEventListener('click', async event => {
        const button = event.target.closest('[data-rx-id]');
        if (!button || !details) return;
        detailsRequest?.abort();
        detailsRequest = new AbortController();
        const body = document.getElementById('prescriptionDetailsBody');
        body.textContent = '…';
        if (approve) approve.hidden = true;
        details.showModal();
        try {
            const response = await fetch(`${root.dataset.detailUrl}/${button.dataset.rxId}`, {
                credentials: 'same-origin', headers: {'Accept': 'application/json'}, signal: detailsRequest.signal
            });
            const result = await response.json();
            if (!response.ok || !result.success) throw new Error(result.message || `HTTP ${response.status}`);
            body.replaceChildren();
            if (approve) { approve.dataset.id = String(result.data.id); approve.hidden = result.data.status !== 'recorded'; }
            const patient = document.createElement('p');
            patient.className = 'font-semibold';
            patient.textContent = `${result.data.patient_name} · ${result.data.prescriber}`;
            body.append(patient);
            for (const item of result.data.items) {
                const card = document.createElement('div');
                card.className = 'border rounded-lg p-4 space-y-2';
                const title = document.createElement('p');
                title.className = 'font-semibold';
                title.textContent = `${item.name} · ${item.strength} · ${item.dosage_form}`;
                const quantity = document.createElement('p');
                quantity.textContent = `${item.quantity} ${item.unit || ''}`;
                const directions = document.createElement('p');
                directions.textContent = item.directions;
                card.append(title, quantity, directions);
                if(item.route_of_administration){const route=document.createElement('p');route.className='text-sm';route.textContent='Recorded route: '+item.route_of_administration;card.append(route);}
                const composition=Array.isArray(item.active_ingredients)?item.active_ingredients:JSON.parse(item.active_ingredients||'[]');
                if(composition.length){const ingredients=document.createElement('p');ingredients.className='text-sm';ingredients.textContent=composition.map(i=>`${i.name} ${i.strength}`).join(' + ');card.append(ingredients);}
                body.append(card);
            }
            root.dispatchEvent(new CustomEvent('pharmacy:prescription-loaded',{detail:result.data}));
        } catch (error) {
            if (error.name !== 'AbortError') body.textContent = error.message;
        }
    });
    if (!form) return;
    const dialog = document.getElementById('pharmacyDialog');
    const feedback = document.getElementById('pharmacyFeedback');
    const save = document.getElementById('pharmacySave');
    const lines = document.getElementById('prescriptionLines');
    const units = document.getElementById('medicineUnitRows');
    const profiles = JSON.parse(document.getElementById('medicineProfileData')?.textContent || '[]');
    const ingredients=document.getElementById('medicineIngredients');
    function addIngredient(values={}){if(!ingredients||ingredients.children.length>=20)return;const fragment=document.getElementById('medicineIngredientTemplate').content.cloneNode(true);fragment.querySelectorAll('[data-ingredient-field]').forEach(input=>input.value=values[input.dataset.ingredientField]||'');ingredients.append(fragment);}
    document.getElementById('medicineAddIngredient')?.addEventListener('click',()=>addIngredient());
    ingredients?.addEventListener('click',event=>{if(event.target.closest('[data-remove-ingredient]'))event.target.closest('.ingredient-row').remove();});
    ingredients?.addEventListener('input',event=>{const row=event.target.closest('.ingredient-row');if(!row)return;const fields=[...row.querySelectorAll('[data-ingredient-field]')];const present=fields.some(field=>field.value.trim()!=='');fields.forEach(field=>field.required=present);});
    let creationKey;
    function addUnit(values = {}) {
        if (!units || units.children.length >= 30) return;
        const fragment = document.getElementById('medicineUnitTemplate').content.cloneNode(true);
        fragment.querySelectorAll('[data-unit-field]').forEach(input => {
            if (values[input.dataset.unitField] != null) input.value = values[input.dataset.unitField];
        });
        units.append(fragment);
    }
    document.getElementById('medicineAddUnit')?.addEventListener('click', () => addUnit());
    units?.addEventListener('click', event => {
        if (event.target.closest('[data-remove-unit]') && units.children.length > 1) event.target.closest('.medicine-unit-row').remove();
    });
    form.elements.product_id?.addEventListener('change', event => {
        if (!units) return;
        const profile = profiles.find(profile => String(profile.product_id) === event.target.value);
        form.querySelectorAll('[data-new-medicine]').forEach(label => { label.hidden = !!event.target.value; });
        for (const field of ['generic_name', 'strength', 'dosage_form', 'manufacturer', 'storage_instructions']) {
            if (profile) form.elements[field].value = profile[field] || '';
        }
        form.elements.prescription_required.checked = !!Number(profile?.prescription_required || 0);
        if(form.elements.clinical_reviewed)form.elements.clinical_reviewed.checked=!!Number(profile?.clinical_verified??1);
        if(ingredients){ingredients.replaceChildren();for(const ingredient of profile?.active_ingredients||[])addIngredient(ingredient);if(!ingredients.children.length)addIngredient();form.elements.route_of_administration.value=profile?.route_of_administration||'';}
        units.replaceChildren();
        if (profile?.units?.length) profile.units.forEach(addUnit);
        else addUnit({name: event.target.selectedOptions[0]?.dataset.unit || form.elements.base_unit.value, factor: 1});
    });
    form.elements.base_unit?.addEventListener('change', event => {
        const base = [...units.querySelectorAll('.medicine-unit-row')].find(row => Number(row.querySelector('[data-unit-field="factor"]').value) === 1);
        if (base) base.querySelector('[data-unit-field="name"]').value = event.target.value;
    });
    function addLine() {
        if (!lines || lines.children.length >= 100) return;
        lines.append(document.getElementById('prescriptionLineTemplate').content.cloneNode(true));
    }
    document.getElementById('pharmacyAdd').addEventListener('click', () => {
        form.reset();
        creationKey = crypto.randomUUID();
        feedback.textContent = '';
        if (lines) { lines.replaceChildren(); addLine(); }
        if (units) {
            form.querySelectorAll('[data-new-medicine]').forEach(label => { label.hidden = false; });
            units.replaceChildren(); addUnit({name: form.elements.base_unit.value, factor: 1});
        }
        if(ingredients){ingredients.replaceChildren();addIngredient();}
        dialog.showModal();
    });
    root.addEventListener('click',event=>{
        const button=event.target.closest('[data-edit-medicine]');if(!button)return;
        const profile=profiles.find(p=>String(p.product_id)===button.dataset.editMedicine);if(!profile)return;
        document.getElementById('pharmacyAdd').click();const select=form.elements.product_id;
        if(![...select.options].some(option=>option.value===String(profile.product_id))){const option=new Option(profile.name,profile.product_id);option.dataset.unit=profile.unit;select.add(option);}
        select.value=profile.product_id;select.dispatchEvent(new Event('change',{bubbles:true}));
    });
    document.getElementById('pharmacyClose').addEventListener('click', () => { if (!save.disabled) dialog.close(); });
    document.getElementById('patientEdit')?.addEventListener('change', event => {
        const patients = JSON.parse(document.getElementById('patientProfileData').textContent);
        const patient = patients.find(patient => String(patient.id) === event.target.value);
        if (!patient) return;
        document.getElementById('pharmacyAdd').click();
        for (const field of ['id','name','phone','date_of_birth','allergies']) form.elements[field].value = patient[field] || '';
        event.target.value = '';
    });
    dialog.addEventListener('cancel', event => { if (save.disabled) event.preventDefault(); });
    document.getElementById('pharmacyAddLine')?.addEventListener('click', addLine);
    lines?.addEventListener('click', event => {
        if (event.target.closest('[data-remove-line]') && lines.children.length > 1) event.target.closest('.rx-line').remove();
    });
    form.addEventListener('submit', async event => {
        event.preventDefault();
        if (save.disabled || !form.reportValidity()) return;
        const data = Object.fromEntries(new FormData(form));
        if (root.dataset.section === 'medicines') data.prescription_required = form.elements.prescription_required.checked;
        if(form.elements.clinical_reviewed)data.clinical_reviewed=form.elements.clinical_reviewed.checked;
        if (units && !data.product_id) data.creation_request_key = creationKey;
        if(ingredients)data.active_ingredients=[...ingredients.querySelectorAll('.ingredient-row')].map(row=>Object.fromEntries([...row.querySelectorAll('[data-ingredient-field]')].map(input=>[input.dataset.ingredientField,input.value]))).filter(item=>item.name.trim()||item.strength.trim());
        if (units) data.units = [...units.querySelectorAll('.medicine-unit-row')].map(row => Object.fromEntries([...row.querySelectorAll('[data-unit-field]')].map(input => [input.dataset.unitField, input.value])));
        if (lines) data.items = [...lines.querySelectorAll('.rx-line')].map(line =>
            Object.fromEntries([...line.querySelectorAll('[data-field]')].map(input => [input.dataset.field, input.value])));
        save.disabled = true;
        feedback.textContent = '';
        try {
            const response = await fetch(root.dataset.saveUrl, {
                method: 'POST', credentials: 'same-origin',
                headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Pharmacy-CSRF': root.dataset.token},
                body: JSON.stringify(data)
            });
            const result = await response.json();
            if (!response.ok || !result.success) throw new Error(result.message || `HTTP ${response.status}`);
            window.erp?.toast(result.message);
            window.location.reload();
        } catch (error) {
            feedback.textContent = error.message;
            window.erp?.toast(error.message, 'error');
        } finally { save.disabled = false; }
    });
})();
