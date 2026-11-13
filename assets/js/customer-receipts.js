/** One customer collection, allocated to selected orders or FIFO, with safe retry. */
(() => {
    'use strict';
    const manager=document.getElementById('customerReceiptManager'); if(!manager) return;
    let invoices=JSON.parse(document.getElementById('receiptInvoiceData').textContent);
    const form=document.getElementById('customerReceiptForm'), dialog=document.getElementById('customerReceiptDialog');
    const details=document.getElementById('receiptOrderDetails'), detailBody=document.getElementById('receiptOrderDetailsBody');
    let orders=[], manualSelection=null, saving=false, pending=null, legacyPendingKey=null, requestId, opener;
    const cents=value=>Math.round(Number(value||0)*100), money=value=>(value/100).toFixed(2);
    const key=()=>`collection:${manager.dataset.scope}`;
    const textCell=(tr,value)=>{const td=document.createElement('td');td.textContent=String(value??'');td.className='p-2';tr.append(td);return td;};
    function renderOrders() {
        const body=document.getElementById('receiptDueOrders');body.replaceChildren();
        orders.forEach(order=>{
            const tr=document.createElement('tr');
            const check=document.createElement('input');check.type='checkbox';check.checked=manualSelection?manualSelection.has(Number(order.id)):order.allocated>0;check.dataset.selectOrder=order.id;check.setAttribute('aria-label',`Select ${order.invoice_no}`);check.disabled=!!pending;
            textCell(tr,'').append(check);
            [order.invoice_no,order.invoice_date,money(cents(order.total)),money(cents(order.paid_total)),money(order.due)].forEach(v=>textCell(tr,v));
            const input=document.createElement('input');input.type='number';input.min=0;input.max=money(order.due);input.step='.01';input.value=money(order.allocated);input.className='border rounded p-1 w-28';input.dataset.collectOrder=order.id;input.setAttribute('aria-label',`Collect for ${order.invoice_no}`);input.disabled=!!pending;
            textCell(tr,'').append(input);textCell(tr,money(order.due-order.allocated));
            const eye=document.createElement('button');eye.type='button';eye.textContent='◉';eye.setAttribute('aria-label',`View ${order.invoice_no}`);eye.dataset.orderId=order.id;textCell(tr,'').append(eye);
            check.addEventListener('change',()=>{if(!manualSelection)manualSelection=new Set(orders.filter(o=>o.allocated>0).map(o=>Number(o.id)));if(check.checked)manualSelection.add(Number(order.id));else manualSelection.delete(Number(order.id));order.allocated=check.checked?order.due:0;syncAmount();renderOrders();document.querySelector(`[data-select-order="${order.id}"]`)?.focus();});
            input.addEventListener('change',()=>{const value=cents(input.value);if(!Number.isFinite(value)||value<0||value>order.due){erp.toast('Collection must be between zero and the order balance.','error');renderOrders();return;}order.allocated=value;if(!manualSelection)manualSelection=new Set(orders.filter(o=>o.allocated>0).map(o=>Number(o.id)));if(value>0)manualSelection.add(Number(order.id));else manualSelection.delete(Number(order.id));syncAmount();renderOrders();document.querySelector(`[data-collect-order="${order.id}"]`)?.focus();});
            body.append(tr);
        });
        if(!orders.length){const tr=document.createElement('tr');const td=textCell(tr,'No outstanding orders for this customer.');td.colSpan=9;body.append(tr);}
        summary();
    }
    function summary(){const due=orders.reduce((sum,o)=>sum+o.due,0), settled=orders.reduce((sum,o)=>sum+o.allocated,0);document.getElementById('receiptRemaining').textContent=(pending?'Previous collection awaiting confirmation. Save receipt to check its status. ':'')+`Outstanding: USD ${money(due)} · Allocated: USD ${money(settled)} · Balance: USD ${money(due-settled)}`;}
    function syncAmount(){form.elements.amount.value=money(Math.max(0,orders.reduce((sum,o)=>sum+o.allocated,0)-cents(form.elements.discount.value)));}
    function fifo(){const total=cents(form.elements.amount.value)+cents(form.elements.discount.value);const eligible=manualSelection?.size?orders.filter(o=>manualSelection.has(Number(o.id))):orders;const allocated=receiptAllocation.allocate(eligible,total);orders=orders.map(o=>({...o,allocated:allocated.find(a=>Number(a.id)===Number(o.id))?.allocated||0}));renderOrders();}
    function chooseCustomer(){manualSelection=null;orders=invoices.filter(i=>Number(i.customer_id)===Number(form.elements.customer_id.value)&&['unpaid','partial'].includes(i.status)&&cents(i.total)>cents(i.paid_total)).sort((a,b)=>a.invoice_date.localeCompare(b.invoice_date)||Number(a.id)-Number(b.id)).map(i=>({...i,due:cents(i.total)-cents(i.paid_total),allocated:0}));form.elements.amount.value='';renderOrders();}
    window.openReceivePaymentModal=id=>{
        if(!form||saving)return;opener=document.activeElement;form.reset();pending=null;requestId=crypto.randomUUID();
        legacyPendingKey=null;let saved=sessionStorage.getItem(key());if(!saved){const prefix=`customer-receipt:${manager.dataset.scope}:${manager.dataset.saveUrl}:`;for(let index=0;index<sessionStorage.length;index++){const candidate=sessionStorage.key(index);if(candidate.startsWith(prefix)){legacyPendingKey=candidate;saved=sessionStorage.getItem(candidate);break;}}}if(saved){try{pending=JSON.parse(saved);}catch(_){sessionStorage.removeItem(key());}}
        const invoice=invoices.find(i=>Number(i.id)===Number(id));form.elements.customer_id.value=pending?.customer_id||invoice?.customer_id||'';chooseCustomer();
        if(pending){requestId=pending.uuid;['amount','discount','account_id','reference','notes'].forEach(name=>{form.elements[name].value=pending[name]??'';});orders.forEach(o=>{o.allocated=cents(pending.allocations?.find(a=>Number(a.invoice_id)===Number(o.id))?.amount??(Number(pending.invoice_id)===Number(o.id)?Number(pending.amount)+Number(pending.discount||0):0));});renderOrders();}
        else if(invoice){const row=orders.find(o=>Number(o.id)===Number(id));if(row){manualSelection=new Set([Number(row.id)]);row.allocated=row.due;syncAmount();renderOrders();}}
        [...form.querySelectorAll('input,select,textarea')].forEach(field=>{field.disabled=!!pending;});
        dialog.showModal();form.elements.customer_id.focus();
    };
    async function refresh(){try{const response=await fetch(location.href,{credentials:'same-origin'});if(!response.ok||response.redirected)throw Error();const page=new DOMParser().parseFromString(await response.text(),'text/html');invoices=JSON.parse(page.getElementById('receiptInvoiceData').textContent);['receiptsTable','invoicesTable'].forEach(id=>{const current=document.getElementById(id),fresh=page.getElementById(id);if(current&&fresh){if($.fn.dataTable.isDataTable(current))$(current).DataTable().clear().rows.add([...fresh.tBodies[0].rows]).draw(false);else current.tBodies[0].replaceWith(fresh.tBodies[0]);}});}catch(_){erp.toast('Saved. Refresh the page to see updated balances.','error');}}
    document.addEventListener('click',async event=>{
        const print=event.target.closest('[data-print-receipt]');
        if(print){if(print.disabled)return;print.disabled=true;try{const result={data:await window.erpPrinting.receipt(print.dataset.printReceipt)};erp.toast(result.data.message);}catch(error){erp.toast(error.message,'error');}finally{print.disabled=false;}return;}

        const eye=event.target.closest('[data-order-id],[data-view-receipt]');
        if(eye){try{const receipt=eye.dataset.viewReceipt;const result=await erp.web((receipt?manager.dataset.detailsUrl:manager.dataset.orderUrl)+'/'+(receipt||eye.dataset.orderId));detailBody.replaceChildren();const pre=document.createElement('pre');pre.className='whitespace-pre-wrap text-sm';const data=result.data;pre.textContent=receipt?data.map(a=>`${a.invoice_no} | Cash ${a.amount} | Discount ${a.discount}`).join('\n'):[data.invoice_no,...data.items.map(i=>`${i.description||i.product_name||'Item'} × ${i.quantity} — ${i.line_total}`)].join('\n');detailBody.append(pre);if(dialog?.open){detailBody.prepend(document.createTextNode('Order details'));dialog.close();details.addEventListener('close',()=>dialog.showModal(),{once:true});}details.showModal();}catch(_){}return;}
        const button=event.target.closest('[data-void-receipt]');if(!button||button.disabled)return;button.disabled=true;
        try{const result=await Swal.fire({title:'Void customer receipt?',text:'This restores the amounts owed on every allocated order.',input:'text',inputLabel:'Reason',inputAttributes:{maxlength:'255'},showCancelButton:true,confirmButtonText:'Void receipt'});if(!result.isConfirmed)return;await erp.web(`${manager.dataset.voidUrl}/${button.dataset.voidReceipt}/void`,{method:'POST',body:JSON.stringify({reason:result.value||''})});erp.toast('Receipt voided.');await refresh();}catch(_){}finally{button.disabled=false;}
    });
    if(!form)return;
    document.getElementById('addCustomerReceipt')?.addEventListener('click',()=>window.openReceivePaymentModal());
    form.elements.customer_id.addEventListener('change',chooseCustomer);['amount','discount'].forEach(name=>form.elements[name].addEventListener('input',fifo));
    document.getElementById('closeCustomerReceipt').addEventListener('click',()=>{if(!saving)dialog.close();});dialog.addEventListener('cancel',e=>{if(saving)e.preventDefault();});dialog.addEventListener('close',()=>opener?.focus());
    form.addEventListener('submit',async event=>{
        event.preventDefault();if(saving||!form.reportValidity())return;
        const payload=pending||{uuid:requestId,customer_id:Number(form.elements.customer_id.value),amount:Number(form.elements.amount.value),discount:Number(form.elements.discount.value),account_id:Number(form.elements.account_id.value),reference:form.elements.reference.value.trim()||null,notes:form.elements.notes.value.trim()||null,allocations:orders.filter(o=>o.allocated>0).map(o=>({invoice_id:Number(o.id),amount:o.allocated/100}))};
        if(!pending&&(cents(payload.amount)+cents(payload.discount)!==orders.reduce((sum,o)=>sum+o.allocated,0)||payload.amount<=0)){erp.toast('Collection exceeds the selected orders’ balances.','error');return;}
        saving=true;const save=document.getElementById('saveCustomerReceipt');save.disabled=true;
        try{if(!(await Swal.fire({target:dialog,title:'Save customer receipt?',text:`Collect USD ${Number(payload.amount).toFixed(2)}?`,showCancelButton:true,confirmButtonText:'Save receipt'})).isConfirmed)return;sessionStorage.setItem(key(),JSON.stringify(payload));pending=payload;[...form.querySelectorAll('input,select,textarea')].forEach(f=>f.disabled=true);const result=await erp.web(manager.dataset.saveUrl,{method:'POST',body:JSON.stringify(payload)});sessionStorage.removeItem(key());if(legacyPendingKey)sessionStorage.removeItem(legacyPendingKey);pending=null;dialog.close();erp.toast(result.data.status==='cancelled'?'This receipt was already voided. No new collection was made.':'Receipt saved.');await refresh();if(manager.dataset.autoPrint==='1'&&result.data.status!=='cancelled'){try{const printed={data:await window.erpPrinting.receipt(result.data.id)};erp.toast(printed.data.message);}catch(error){erp.toast('Receipt saved. '+error.message,'error');}}}catch(error){if(error.status===422){sessionStorage.removeItem(key());if(legacyPendingKey)sessionStorage.removeItem(legacyPendingKey);pending=null;requestId=crypto.randomUUID();}}finally{saving=false;save.disabled=false;[...form.querySelectorAll('input,select,textarea')].forEach(f=>f.disabled=!!pending);}
    });
})();
