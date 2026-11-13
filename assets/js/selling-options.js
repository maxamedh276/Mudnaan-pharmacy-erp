/** Reusable, user-managed quantity names. No prices are stored in these groups. */
(() => {
    const workspace = document.getElementById('sellingOptionsWorkspace');
    const dialog = document.getElementById('sellingGroupDialog');
    const list = document.getElementById('sellingOptionRows');
    let groups = JSON.parse(document.getElementById('sellingGroupsData').textContent);
    let saving = false;
    function optionRow(option = {}) {
        const row = document.createElement('div'); row.className = 'grid grid-cols-2 gap-2';
        row.innerHTML = '<label class="text-xs">Name<input required maxlength="40" class="block w-full p-2 border rounded-lg" data-label></label><div class="flex gap-2 items-end"><label class="flex-1 text-xs">Quantity<input required type="number" min="0.001" step="0.001" max="999999999.999" class="block w-full p-2 border rounded-lg" data-quantity></label><button type="button" aria-label="Remove quantity" class="p-2 border rounded-lg">×</button></div>';
        row.querySelector('[data-label]').value = option.label || '';
        row.querySelector('[data-quantity]').value = option.quantity || '';
        row.querySelector('button').onclick = () => row.remove(); list.append(row);
    }
    function render() {
        const tbody = document.getElementById('sellingGroupRows'); tbody.replaceChildren();
        groups.forEach(group => {
            const row = document.createElement('tr');
            const options = group.options || JSON.parse(group.options_json);
            [group.name,group.unit,options.map(o=>`${o.label} = ${o.quantity}`).join(' · '),Number(group.is_active) ? 'Active' : 'Inactive'].forEach(text => {const td=document.createElement('td');td.className='p-3 border-t';td.textContent=text;row.append(td);});
            const action = document.createElement('td'); action.className='p-3 border-t';
            if (workspace.dataset.canEdit === 'true') {const button=document.createElement('button');button.textContent='Edit';button.className='text-brand-600 p-2';button.onclick=()=>open(group);action.append(button);}
            row.append(action);tbody.append(row);
        });
        document.getElementById('sellingGroupsEmpty').classList.toggle('hidden',groups.length>0);
    }
    function open(group = null) {
        document.getElementById('sellingGroupForm').reset(); list.replaceChildren();
        document.getElementById('sellingGroupId').value=group?.id || '';
        document.getElementById('sellingGroupName').value=group?.name || '';
        document.getElementById('sellingGroupUnit').value=group?.unit || 'kg';
        document.getElementById('sellingGroupActive').checked=group ? Boolean(Number(group.is_active)) : true;
        (group ? group.options || JSON.parse(group.options_json) : [{}]).forEach(optionRow);
        dialog.showModal();
    }
    document.getElementById('newSellingGroup')?.addEventListener('click',()=>open());
    document.getElementById('addSellingOption').onclick=()=>optionRow();
    document.getElementById('closeSellingGroup').onclick=()=>{if(!saving)dialog.close();};
    document.getElementById('sellingGroupForm').onsubmit=async event=>{
        event.preventDefault();if(saving)return;
        const options=[...list.children].map(row=>({label:row.querySelector('[data-label]').value.trim(),quantity:Number(row.querySelector('[data-quantity]').value)}));
        if(!options.length){erp.toast('Add at least one quantity.','error');return;}
        saving=true;const button=document.getElementById('saveSellingGroup');button.disabled=true;
        try {const result=await erp.web(workspace.dataset.saveUrl,{method:'POST',body:JSON.stringify({id:Number(document.getElementById('sellingGroupId').value)||null,name:document.getElementById('sellingGroupName').value.trim(),unit:document.getElementById('sellingGroupUnit').value,is_active:document.getElementById('sellingGroupActive').checked,options})});groups=groups.filter(g=>Number(g.id)!==Number(result.data.id));groups.push(result.data);render();dialog.close();erp.toast('Selling options saved.');}
        catch(_){}finally{saving=false;button.disabled=false;}
    };
    dialog.addEventListener('cancel',event=>{if(saving)event.preventDefault();});render();
})();
