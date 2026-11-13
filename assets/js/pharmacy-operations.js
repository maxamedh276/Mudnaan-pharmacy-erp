(() => {
    'use strict';
    const root = document.getElementById('pharmacyOperations');
    if (!root) return;
    const medicines = JSON.parse(document.getElementById('pharmacyMedicineData')?.textContent || '[]');
    const byId = new Map(medicines.map(medicine => [String(medicine.product_id), medicine]));
    const cart = document.getElementById('pharmacyCart');
    const somali = document.documentElement.lang === 'so';
    const unitLabel=unit=>`${unit.name} \u00d7 ${unit.factor} · ${Number(unit.sell_price).toFixed(2)}${unit.available_quantity!==undefined?' · '+unit.available_quantity+' available':''}`;
    let mixedSummary;
    if (cart) {
        const hint = document.createElement('p');
        hint.className = 'text-sm text-slate-500';
        hint.textContent = somali ? 'Isla daawada ku dar sadar strip ah iyo sadar xabo ah. Qiimaha halbeeg kasta si gaar ah ayaa loo xisaabiyaa.' : 'Sell strips and individual tablets together by adding the same medicine in separate rows. Each selling unit uses its own price.';
        if (!document.getElementById('pharmacyQuickMedicines')) cart.before(hint);
        mixedSummary = document.createElement('div');
        mixedSummary.className = 'pos-mixed-summary rounded-lg border border-emerald-100 bg-emerald-50 p-3 text-sm text-emerald-800';
        mixedSummary.setAttribute('role','status'); mixedSummary.setAttribute('aria-live','polite'); mixedSummary.hidden=true;
        cart.after(mixedSummary);
    }
    const stockForm = root.querySelector('form[data-operation="stock"]');
    function stockFields() {
        if (!stockForm) return;
        const action = stockForm.elements.action.value;
        for (const field of ['batch_no','expiry_date','mfg_date','unit_cost','batch_id']) {
            const input = stockForm.elements[field];
            const visible = field === 'batch_id' ? action === 'dispose' : field === 'unit_cost' ? action === 'receive' : action !== 'dispose';
            input.closest('label').hidden = !visible;
            input.required = visible && ['batch_no','expiry_date','unit_cost','batch_id'].includes(field);
        }
    }
    stockForm?.elements.action.addEventListener('change', stockFields);
    stockFields();
    function updateTotal() {
        let cents = 0;
        const quantities = new Map();
        cart?.querySelectorAll('.cart-line').forEach(line => {
            const medicine = byId.get(line.querySelector('[data-medicine]').value);
            const unit = medicine?.units?.find(unit => String(unit.id) === line.querySelector('[data-unit]').value);
            const gross = Math.round(Number(unit?.sell_price || 0) * Number(line.querySelector('[data-field="quantity"]').value || 0) * 100);
            const discount=line.querySelector('[data-field="discount"]');
            const reduction=Math.round(Number(discount?.value||0)*100);
            discount?.setCustomValidity(reduction>gross ? 'Discount exceeds the medicine line amount.' : '');
            const reason=line.querySelector('[data-field="pharmacy_discount_reason"]');if(reason)reason.required=reduction>0;
            const net=Math.max(0,gross-reduction);
            cents += net + Math.round(net * Number(medicine?.tax_rate || 0) / 100);
            const lineTotal=line.querySelector('[data-pos-line-total]');
            if(lineTotal)lineTotal.textContent=((net+Math.round(net*Number(medicine?.tax_rate||0)/100))/100).toFixed(2);
            const quantity = Number(line.querySelector('[data-field="quantity"]').value);
            const baseButton = line.querySelector('[data-add-base-unit]');
            if (baseButton) baseButton.disabled = !medicine;
            if (medicine && unit && Number.isFinite(quantity) && quantity > 0) {
                const total = quantities.get(String(medicine.product_id)) || {medicine,base:0,parts:[]};
                total.base += quantity * Number(unit.factor);
                total.parts.push(`${quantity} ${unit.name}`);
                quantities.set(String(medicine.product_id),total);
            }
        });
        if (mixedSummary) {
            mixedSummary.replaceChildren();
            for (const total of quantities.values()) {
                const row=document.createElement('p');
                row.textContent=`${total.medicine.name}: ${total.parts.join(' + ')} = ${Number(total.base.toFixed(3))} ${total.medicine.unit}`;
                mixedSummary.append(row);
            }
            mixedSummary.hidden=quantities.size===0;
        }
        const target = document.getElementById('pharmacyCartTotal');
        const count=document.getElementById('pharmacyLineCount');
        if(count)count.textContent=String([...cart.querySelectorAll('[data-medicine]')].filter(select=>select.value).length);
        if (target) target.textContent = (cents / 100).toFixed(2);
        const paid = document.querySelector('#pharmacyCheckout [name="paid"]');
        const split = document.getElementById('pharmacySplitPayment')?.checked;
        const second = split ? Number(document.querySelector('#pharmacyCheckout [name="second_paid"]')?.value || 0) : 0;
        if (paid && !paid.dataset.manual) paid.value = Math.max(0,cents / 100-second).toFixed(2);
        const balance = document.getElementById('pharmacyPaymentBalance');
        if (balance) {
            const difference = Math.round((Number(paid?.value || 0)+second)*100)-cents;
            balance.textContent = `${difference >= 0 ? (somali?'Lacag celin':'Change') : (somali?'Hadhay':'Due')}: ${(Math.abs(difference)/100).toFixed(2)}`;
        }
    }
    root.addEventListener('change', event => {
        if (event.target.matches('[data-medicine]')) {
            const container = event.target.closest('.cart-line') || event.target.closest('form');
            const select = container.querySelector('[data-unit]');
            select.replaceChildren();
            for (const unit of byId.get(event.target.value)?.units || []) {
                const option = new Option(unitLabel(unit), unit.id);
                select.add(option);
            }
        }
        if (event.target.matches('[data-medicine], [data-unit]')) {
            const container = event.target.closest('.cart-line') || event.target.closest('form');
            const medicine = byId.get(container.querySelector('[data-medicine]').value);
            const unit = medicine?.units?.find(unit => String(unit.id) === container.querySelector('[data-unit]').value);
            const quantity = container.querySelector('[data-field="quantity"], [name="quantity"]');
            if (quantity) { quantity.min = quantity.step = Number(unit?.fractional) ? '0.001' : '1'; }
        }
        updateTotal();
    });
    root.addEventListener('input', event => { if (event.target.name === 'paid') event.target.dataset.manual = '1'; updateTotal(); });
    document.getElementById('pharmacySplitPayment')?.addEventListener('change', event => {
        document.getElementById('pharmacySecondPayment').hidden = !event.target.checked;
        const input=document.querySelector('#pharmacyCheckout [name="second_paid"]');input.required=event.target.checked;
        delete document.querySelector('#pharmacyCheckout [name="paid"]').dataset.manual;
        updateTotal();
    });
    const rxSelect=document.querySelector('#pharmacyCheckout [name="prescription_id"]');
    const patientSafety=document.getElementById('pharmacyPatientSafety');
    const updatePatientSafety=()=>{if(!patientSafety||!rxSelect)return;const selected=rxSelect.selectedOptions[0];patientSafety.hidden=!rxSelect.value;const recorded=selected?.dataset.allergyAccess==='0'?'Patient-record permission is required for allergy review.':selected?.dataset.allergies||(somali?'Lama diiwaangelin':'Not recorded');patientSafety.textContent=rxSelect.value?`${somali?'Allergies la diiwaangeliyey':'Recorded allergies'}: ${recorded}. ${somali?'Pharmacist-ka ha dib u eego ka hor dispensing.':'Pharmacist review is required; this is recorded information, not an automated interaction check.'}`:'';};
    rxSelect?.addEventListener('change',updatePatientSafety);
    function addCartLine() {
        if (!cart || cart.children.length >= 100) return null;
        cart.append(document.getElementById('pharmacyCartTemplate').content.cloneNode(true));
        const row=cart.lastElementChild;
        const baseButton=document.createElement('button');baseButton.type='button';baseButton.dataset.addBaseUnit='';baseButton.disabled=true;
        baseButton.className='text-emerald-700 text-sm text-left font-semibold disabled:opacity-40';
        baseButton.textContent=somali ? '+ Ku dar xabo / halbeeg aasaasi ah' : '+ Add loose / base units';
        row.append(baseButton);
        return row;
    }
    document.getElementById('pharmacyCartAdd')?.addEventListener('click', addCartLine);
    cart?.addEventListener('click', event => {
        if (event.target.closest('[data-add-base-unit]')) {
            const source=event.target.closest('.cart-line');
            const medicine=byId.get(source.querySelector('[data-medicine]').value);
            const unit=medicine?.units?.find(item=>Number(item.factor)===1);
            if (!unit) return;
            const row=addCartLine();
            if (!row) {window.erp?.toast(somali?'Ugu badnaan 100 sadar ayaa la oggol yahay.':'A sale can contain up to 100 rows.','error');return;}
            const select=row.querySelector('[data-medicine]');select.value=medicine.product_id;select.dispatchEvent(new Event('change',{bubbles:true}));
            const unitSelect=row.querySelector('[data-unit]');unitSelect.value=unit.id;unitSelect.dispatchEvent(new Event('change',{bubbles:true}));
            const quantity=row.querySelector('[data-field="quantity"]');quantity.focus();quantity.select();
            updateTotal();return;
        }
        if (event.target.closest('[data-remove-cart]')) {
            event.target.closest('.cart-line').remove();
            if (!cart.children.length) {
                addCartLine();
                const paid=document.querySelector('#pharmacyCheckout [name=paid]');
                if(paid)delete paid.dataset.manual;
            }
            updateTotal();
        }
    });
    addCartLine();
    const quickMedicines=document.getElementById('pharmacyQuickMedicines');
    const medicineSearch=document.getElementById('pharmacyCatalogSearch');
    function renderQuickMedicines() {
        if(!quickMedicines)return;
        const query=(medicineSearch.value||'').trim().toLowerCase();
        const matches=medicines.filter(medicine=>(medicine.is_active===undefined||Number(medicine.is_active))&&(medicine.clinical_verified===undefined||Number(medicine.clinical_verified))&&[medicine.name,medicine.generic_name,medicine.strength,medicine.sku,medicine.barcode,...(medicine.units||[]).map(unit=>unit.barcode)].some(value=>String(value||'').toLowerCase().includes(query)));
        quickMedicines.replaceChildren();
        document.getElementById('pharmacyCatalogCount').textContent=`${Math.min(12,matches.length)} / ${matches.length}`;
        matches.slice(0,12).forEach(medicine=>{
            const unit=medicine.units?.find(unit=>Number(unit.factor)===1&&Number(unit.available_quantity)>0)||medicine.units?.find(unit=>Number(unit.available_quantity)>0)||medicine.units?.find(unit=>Number(unit.factor)===1);
            if(!unit)return;
            const button=document.createElement('button');button.type='button';button.dataset.posAdd=medicine.product_id;
            button.dataset.posUnit=unit.id;button.dataset.availableBase=medicine.available_quantity;
            button.disabled=Number(medicine.available_quantity)<=0;
            const title=document.createElement('strong');title.textContent=medicine.name;
            const detail=document.createElement('small');detail.textContent=[medicine.strength,medicine.dosage_form,Number(medicine.prescription_required)?'Rx':''].filter(Boolean).join(' · ');
            const price=document.createElement('span');price.className='pos-medicine-price';price.textContent=`${Number(unit.sell_price).toFixed(2)} / ${unit.name} +`;
            const stock=document.createElement('small');stock.textContent=`${medicine.available_quantity??'—'} ${medicine.unit} ${somali?'la heli karo':'available'}`;
            button.append(title,detail,price,stock);quickMedicines.append(button);
        });
        if(!quickMedicines.children.length){const empty=document.createElement('p');empty.className='pos-catalog-empty';empty.textContent=somali?'Daawo lama helin.':'No medicines found. Try another name or barcode.';quickMedicines.append(empty);}
    }
    medicineSearch?.addEventListener('input',renderQuickMedicines);
    quickMedicines?.addEventListener('click',event=>{
        const button=event.target.closest('[data-pos-add]');if(!button)return;
        const medicine=byId.get(button.dataset.posAdd);const unit=medicine?.units?.find(unit=>String(unit.id)===button.dataset.posUnit);if(!unit)return;
        let row=[...cart.querySelectorAll('.cart-line')].find(row=>row.querySelector('[data-unit]').value===String(unit.id));
        if(row){const input=row.querySelector('[data-field=quantity]');input.value=Number(input.value||0)+1;}
        else {
            row=[...cart.querySelectorAll('.cart-line')].find(row=>!row.querySelector('[data-medicine]').value)||addCartLine();
            if(!row){window.erp?.toast(somali?'Ugu badnaan 100 sadar ayaa la oggol yahay.':'A sale can contain up to 100 rows.','error');return;}
            const select=row.querySelector('[data-medicine]');select.value=medicine.product_id;select.dispatchEvent(new Event('change',{bubbles:true}));
            const units=row.querySelector('[data-unit]');units.value=unit.id;units.dispatchEvent(new Event('change',{bubbles:true}));
        }
        updateTotal();
    });
    renderQuickMedicines();
    let availabilitySequence=0;
    const availabilityRequests=new Map();
    async function refreshAvailability(productIds) {
        const ids=[...new Set(productIds.map(String))];if(!ids.length)return;
        const sequence=++availabilitySequence;ids.forEach(id=>availabilityRequests.set(id,sequence));
        for(let offset=0;offset<ids.length;offset+=100){
            const requested=ids.slice(offset,offset+100);
            const response=await fetch(`${root.dataset.baseUrl}/availability?products=${requested.join(',')}`,{credentials:'same-origin',headers:{Accept:'application/json'}});
            const result=await response.json();if(!response.ok||!result.success)throw new Error(result.message||'Could not refresh medicine availability.');
            const returned=new Set();
            for(const medicine of result.data){const id=String(medicine.product_id);returned.add(id);if(availabilityRequests.get(id)!==sequence)continue;byId.set(id,medicine);const index=medicines.findIndex(m=>String(m.product_id)===id);if(index>=0)medicines[index]=medicine;}
            for(const id of requested)if(!returned.has(id)&&availabilityRequests.get(id)===sequence){const previous=byId.get(id);if(previous){previous.is_active=0;previous.available_quantity=0;}}
        }
        cart?.querySelectorAll('.cart-line').forEach(line=>{
            const select=line.querySelector('[data-medicine]'),medicine=byId.get(select.value);if(!medicine)return;
            const units=line.querySelector('[data-unit]'),previous=units.value;units.replaceChildren();for(const unit of medicine.units||[])units.add(new Option(unitLabel(unit),unit.id));units.value=previous;
        });
        renderQuickMedicines();updateTotal();
    }
    const visibleProducts=()=>[...quickMedicines?.querySelectorAll('[data-pos-add]')||[]].map(button=>button.dataset.posAdd).concat([...cart?.querySelectorAll('[data-medicine]')||[]].map(select=>select.value).filter(Boolean));
    document.getElementById('pharmacyRefreshStock')?.addEventListener('click',async event=>{const button=event.currentTarget;if(button.disabled)return;button.disabled=true;try{await refreshAvailability(visibleProducts());window.erp?.toast(somali?'Stock-ka waa la cusboonaysiiyey.':'Visible medicine stock refreshed.');}catch(error){window.erp?.toast(error.message,'error');}finally{button.disabled=false;}});
    let stockSearchTimer;
    medicineSearch?.addEventListener('input',()=>{clearTimeout(stockSearchTimer);stockSearchTimer=setTimeout(()=>refreshAvailability(visibleProducts()).catch(error=>window.erp?.toast(error.message,'error')),400);});
    document.getElementById('pharmacyExactPaid')?.addEventListener('click',()=>{
        const input=document.querySelector('#pharmacyCheckout [name=paid]');delete input.dataset.manual;updateTotal();input.focus();
    });
    document.addEventListener('keydown',event=>{
        if(event.key==='F2'&&medicineSearch){event.preventDefault();medicineSearch.focus();medicineSearch.select();}
        if(event.key==='F4'&&document.getElementById('pharmacyScan')){event.preventDefault();document.getElementById('pharmacyScan').focus();}
    });
    document.getElementById('pharmacyScan')?.addEventListener('keydown', event => {
        if (event.key !== 'Enter') return;
        event.preventDefault();
        const code = event.target.value.trim();
        const matches = [];
        for (const medicine of medicines) {
            for (const unit of medicine.units || []) {
                if (unit.barcode === code || ((medicine.barcode === code || medicine.sku === code) && Number(unit.factor) === 1)) matches.push({medicine, unit});
            }
        }
        const unique = [...new Map(matches.map(match => [match.unit.id, match])).values()];
        const feedback = document.getElementById('pharmacyScanFeedback');
        if (!code || unique.length !== 1) { feedback.textContent = document.documentElement.lang === 'so' ? 'Barcode gaar ah lama helin.' : 'No unique medicine unit matches this barcode.'; return; }
        const {medicine, unit} = unique[0];
        let row = [...cart.querySelectorAll('.cart-line')].find(row => row.querySelector('[data-unit]').value === String(unit.id));
        if (row) { const quantity = row.querySelector('[data-field="quantity"]'); quantity.value = Number(quantity.value) + 1; }
        else {
            row = [...cart.querySelectorAll('.cart-line')].find(row => !row.querySelector('[data-medicine]').value);
            if (!row) row = addCartLine();
            if (!row) {window.erp?.toast(somali?'Ugu badnaan 100 sadar ayaa la oggol yahay.':'A sale can contain up to 100 rows.','error');return;}
            const select = row.querySelector('[data-medicine]'); select.value = medicine.product_id; select.dispatchEvent(new Event('change', {bubbles: true}));
            row.querySelector('[data-unit]').value = unit.id;
        }
        feedback.textContent = ''; event.target.value = ''; updateTotal();
    });
    root.querySelectorAll('form[data-operation]').forEach(form => {
        let requestKey = crypto.randomUUID();
        let holdKey = crypto.randomUUID();
        let heldRevision = 0;
        const ticketItems = () => [...cart.querySelectorAll('.cart-line')].map(line => Object.fromEntries([...line.querySelectorAll('[data-field]')].map(input => [input.dataset.field,input.value])));
        const resetTicket = () => {
            delete form.elements.paid.dataset.manual;
            form.reset();cart.replaceChildren();addCartLine();
            document.getElementById('pharmacySecondPayment').hidden=true;
            form.elements.second_paid.required=false;updateTotal();
            updatePatientSafety();
        };
        const ticketRequest = async (operation,data) => {
            const response=await fetch(`${root.dataset.baseUrl}/${operation}/save`,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','Accept':'application/json','X-Pharmacy-CSRF':root.dataset.token},body:JSON.stringify(data)});
            const result=await response.json();if(!response.ok||!result.success)throw new Error(result.message||`HTTP ${response.status}`);return result;
        };
        if(form.dataset.operation==='checkout') {
            const dialog=document.getElementById('pharmacyHeldDialog'), list=document.getElementById('pharmacyHeldList');
            document.getElementById('pharmacyHeldClose')?.addEventListener('click',()=>dialog.close());
            const loadTickets=async()=>{
                list.textContent=somali?'Waa la soo dejinayaa…':'Loading…';
                try {
                    const response=await fetch(`${root.dataset.baseUrl}/held-sales`,{credentials:'same-origin',headers:{Accept:'application/json'}});
                    const result=await response.json();if(!response.ok||!result.success)throw new Error(result.message||'Could not load held tickets.');
                    list.replaceChildren();
                    if(!result.data.length)list.textContent=somali?'Iib la hakiyey ma jiro.':'No held tickets.';
                    for(const ticket of result.data){
                        const row=document.createElement('div');row.className='border rounded-lg p-3 mb-2 flex flex-wrap gap-3 items-center';
                        const label=document.createElement('span');label.textContent=`#${ticket.id} · ${ticket.label} · ${ticket.cart.items.length} lines · ${ticket.created_at}`;row.append(label);
                        const resume=document.createElement('button');resume.type='button';resume.className='text-emerald-700 underline';resume.textContent=somali?'Sii wad':'Resume';
                        resume.addEventListener('click',async()=>{
                            if(ticket.cart.items.some(item=>!byId.get(String(item.product_id))?.units?.some(unit=>Number(unit.id)===Number(item.medicine_unit_id)))){window.erp?.toast('A medicine or unit on this ticket is no longer available.','error');return;}
                            if([...cart.querySelectorAll('[data-medicine]')].some(select=>select.value)){
                                const result=await Swal.fire({target:dialog,title:somali?'Beddel cart-ka hadda?':'Replace the current cart?',text:somali?'Marka hore hakii iibka haddii loo baahan yahay.':'Hold the current sale first if you need to keep it.',icon:'question',showCancelButton:true,confirmButtonText:somali?'Beddel':'Replace'});if(!result.isConfirmed)return;
                            }
                            resetTicket();cart.replaceChildren();
                            for(const item of ticket.cart.items){const row=addCartLine();row.querySelector('[data-medicine]').value=item.product_id;row.querySelector('[data-medicine]').dispatchEvent(new Event('change',{bubbles:true}));row.querySelector('[data-unit]').value=item.medicine_unit_id;row.querySelector('[data-unit]').dispatchEvent(new Event('change',{bubbles:true}));row.querySelector('[data-field="quantity"]').value=item.quantity;for(const name of ['discount','pharmacy_discount_reason']){const field=row.querySelector(`[data-field="${name}"]`);if(field)field.value=item[name]||(name==='discount'?'0':'');}}
                            for(const field of ['customer_id','prescription_id'])form.elements[field].value=ticket.cart[field]||'';
                            updatePatientSafety();
                            requestKey=ticket.request_key;holdKey=ticket.request_key;heldRevision=Number(ticket.revision);updateTotal();dialog.close();window.erp?.toast(somali?'Iibkii waa la soo celiyey.':'Ticket resumed. Live prices apply.');
                        });row.append(resume);
                        const discard=document.createElement('button');discard.type='button';discard.className='text-red-600 underline';discard.textContent=somali?'Ka saar':'Discard';
                        discard.addEventListener('click',async()=>{const answer=await Swal.fire({target:dialog,title:'Discard held ticket?',icon:'warning',showCancelButton:true,confirmButtonText:'Discard'});if(!answer.isConfirmed)return;discard.disabled=true;try{await ticketRequest('discard-hold',{id:ticket.id});await loadTickets();}catch(error){window.erp?.toast(error.message,'error');discard.disabled=false;}});row.append(discard);list.append(row);
                    }
                }catch(error){list.textContent=error.message;const retry=document.createElement('button');retry.type='button';retry.textContent='Retry';retry.className='block underline';retry.addEventListener('click',loadTickets);list.append(retry);}
            };
            document.getElementById('pharmacyHeldSales')?.addEventListener('click',()=>{dialog.showModal();loadTickets();});
            document.getElementById('pharmacyHoldSale')?.addEventListener('click',async event=>{
                const button=event.currentTarget;if(button.disabled)return;
                if(!ticketItems().length||ticketItems().some(item=>!item.product_id||!item.medicine_unit_id||!(Number(item.quantity)>0))){window.erp?.toast('Select medicines and valid quantities before holding.','error');return;}
                button.disabled=true;
                try{await ticketRequest('hold',{request_key:holdKey,revision:heldRevision,items:ticketItems(),customer_id:form.elements.customer_id.value,prescription_id:form.elements.prescription_id.value});holdKey=crypto.randomUUID();heldRevision=0;requestKey=crypto.randomUUID();resetTicket();window.erp?.toast(somali?'Iibkii waa la hakiyey.':'Sale held. No stock or money was posted.');}
                catch(error){window.erp?.toast(error.message,'error');}finally{button.disabled=false;}
            });
        }
        form.addEventListener('submit', async event => {
            event.preventDefault();
            const submit = form.querySelector('button:not([type="button"])');
            if (submit.disabled || !form.reportValidity()) return;
            const data = Object.fromEntries(new FormData(form));
            data.request_key = requestKey;
            if (form.dataset.operation === 'checkout') {
                data.uuid = requestKey;
                if (!data.prescription_id) delete data.prescription_id;
                if (!data.customer_id) delete data.customer_id;
                if (document.getElementById('pharmacySplitPayment').checked) data.payment_entries=[{method_id:data.payment_method_id,amount:data.paid},{method_id:data.second_method_id,amount:data.second_paid}];
                data.items = [...cart.querySelectorAll('.cart-line')].map(line => Object.fromEntries([...line.querySelectorAll('[data-field]')].map(input => [input.dataset.field, input.value])));
            }
            const feedback = form.querySelector('[data-feedback]');
            feedback.textContent = '';
            submit.disabled = true;
            try {
                const response = await fetch(`${root.dataset.baseUrl}/${form.dataset.operation}/save`, {
                    method: 'POST', credentials: 'same-origin',
                    headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Pharmacy-CSRF': root.dataset.token},
                    body: JSON.stringify(data)
                });
                const result = await response.json();
                if (!response.ok || !result.success) throw new Error(result.message || `HTTP ${response.status}`);
                window.erp?.toast(result.message);
                requestKey = crypto.randomUUID();
                if (form.dataset.operation === 'checkout') {
                    holdKey=crypto.randomUUID();heldRevision=0;resetTicket();
                    feedback.textContent = `✓ #${result.id}`;
                    const link = document.createElement('a');
                    link.href = `${root.dataset.baseUrl}/labels/${result.id}`;
                    link.target = '_blank'; link.rel = 'noopener'; link.className = 'block underline text-emerald-700 mt-2';
                    link.textContent = document.documentElement.lang === 'so' ? 'Summadaha daawada / Print' : 'Dispensing labels / Print';
                    feedback.append(link);
                    const receipt=document.createElement('button');receipt.type='button';receipt.dataset.pharmacyReceipt=String(result.id);
                    receipt.textContent=somali?'Receipt / Dib u daabac':'Receipt / Reprint';feedback.append(receipt);
                    window.pharmacyReceipts?.open(result.id);
                    // The sale is already committed. A refresh failure must never suggest
                    // retrying the financial POST; retry only this read request instead.
                    const soldProducts=data.items.map(item=>item.product_id);
                    try { await refreshAvailability(soldProducts); }
                    catch(error) {
                        const notice=document.createElement('div');notice.className='text-sm mt-2';
                        const text=document.createElement('span');text.textContent=somali?'Iibku waa keydsan yahay; stock refresh-ku wuu fashilmay. ':'Sale saved; stock refresh failed. ';
                        const retry=document.createElement('button');retry.type='button';retry.className='underline';retry.textContent=somali?'Cusboonaysii stock':'Retry stock refresh';
                        retry.addEventListener('click',async()=>{retry.disabled=true;try{await refreshAvailability(soldProducts);notice.remove();}catch(error){window.erp?.toast(error.message,'error');retry.disabled=false;}});
                        notice.append(text,retry);feedback.append(notice);
                    }
                } else window.location.reload();
            } catch (error) { feedback.textContent = error.message; window.erp?.toast(error.message, 'error'); }
            finally { submit.disabled = false; }
        });
    });
})();
