/** Preview and reprint saved invoices. Reading and printing never submits a sale. */
(() => {
    'use strict';
    const dialog=document.getElementById('pharmacyReceiptDialog');
    if(!dialog)return;
    const frame=document.getElementById('pharmacyReceiptFrame');
    const print=document.getElementById('pharmacyPrintReceipt');
    const list=document.getElementById('receiptHistoryList');
    const search=document.getElementById('receiptHistorySearch');
    const historyStatus=document.getElementById('receiptHistoryStatus');
    const previewStatus=document.getElementById('receiptPreviewStatus');
    const printStatus=document.getElementById('receiptPrintStatus');
    const somali=document.documentElement.lang==='so';
    let activeId=null,pending,timer;
    function selectReceipt(id) {
        if(!/^\d+$/.test(String(id)) || Number(id)<1)return;
        activeId=String(id);print.disabled=true;frame.hidden=true;
        previewStatus.hidden=false;previewStatus.textContent=somali?'Receipt-ka ayaa soo degaya…':'Loading receipt…';
        printStatus.textContent='';
        list.querySelectorAll('[data-receipt-id]').forEach(button=>{
            if(button.dataset.receiptId===activeId)button.setAttribute('aria-current','true');else button.removeAttribute('aria-current');
        });
        frame.src=`${dialog.dataset.receiptUrl}/${activeId}`;
    }
    frame.addEventListener('load',()=>{
        if(!activeId)return;
        try {
            const receipt=frame.contentDocument.querySelector('[data-receipt-paper]');
            if(!receipt)throw new Error(somali?'Receipt-ka lama heli karo. Mar kale dooro.':'Receipt unavailable. Select it again to retry.');
            if(receipt.dataset.saleId!==activeId)return;
            const toolbar=frame.contentDocument.querySelector('.receipt-toolbar');
            if(toolbar)toolbar.style.display='none';
            document.getElementById('pharmacyReceiptTitle').textContent=receipt.dataset.invoice;
            previewStatus.hidden=true;frame.hidden=false;print.disabled=false;
            printStatus.textContent=somali?'Diyaar u ah print':'Ready to print';
        } catch(error) {
            previewStatus.hidden=false;previewStatus.textContent=error.message;print.disabled=true;
        }
    });
    async function loadHistory(selectLatest=false) {
        pending?.abort();const request=new AbortController();pending=request;
        historyStatus.textContent=somali?'Soo dejin…':'Loading recent receipts…';
        try {
            const url=new URL(dialog.dataset.historyUrl,location.href);url.searchParams.set('q',search.value.trim());
            const response=await fetch(url,{credentials:'same-origin',headers:{Accept:'application/json'},signal:request.signal});
            const result=await response.json();
            if(!response.ok || !result.success)throw new Error(somali?'Receipts-ka lama soo dejin karin.':'Unable to load receipts. Use refresh to retry.');
            list.replaceChildren();
            for(const sale of result.data) {
                const button=document.createElement('button');button.type='button';button.dataset.receiptId=String(sale.id);
                const title=document.createElement('strong');title.textContent=sale.invoice_no;
                const date=document.createElement('small');date.textContent=sale.sold_at;
                const customer=document.createElement('small');customer.textContent=sale.customer_name||(somali?'Macaamil socdaal ah':'Walk-in customer');
                const amount=document.createElement('span');amount.textContent=Number(sale.grand_total).toFixed(2);
                button.append(title,date,customer,amount);
                if(String(sale.id)===activeId)button.setAttribute('aria-current','true');
                list.append(button);
            }
            historyStatus.textContent=result.data.length?(somali?'Dooro receipt si aad dib u daabacdo.':'Select an invoice to view or reprint.'):(somali?'Receipt lama helin.':'No matching receipts.');
            if(selectLatest && result.data.length)selectReceipt(result.data[0].id);
        } catch(error) {if(error.name!=='AbortError')historyStatus.textContent=error.message;}
    }
    function open(id=null) {
        search.value='';activeId=null;print.disabled=true;frame.hidden=true;
        document.getElementById('pharmacyReceiptTitle').textContent=somali?'Receipt-ka iibka':'Sales receipt';
        previewStatus.hidden=false;previewStatus.textContent=somali?'Dooro receipt.':'Choose a receipt to preview.';
        printStatus.textContent='';if(!dialog.open)dialog.showModal();
        if(id)selectReceipt(id);
        loadHistory(!id);
    }
    window.pharmacyReceipts={open};
    document.addEventListener('click',event=>{
        const trigger=event.target.closest('[data-pharmacy-receipt]');
        if(trigger){event.preventDefault();open(trigger.dataset.pharmacyReceipt||null);}
        if(event.target.closest('[data-receipt-close]'))dialog.close();
    });
    list.addEventListener('click',event=>{const button=event.target.closest('[data-receipt-id]');if(button)selectReceipt(button.dataset.receiptId);});
    search.addEventListener('input',()=>{clearTimeout(timer);timer=setTimeout(()=>loadHistory(false),250);});
    document.getElementById('receiptHistoryRefresh').addEventListener('click',()=>loadHistory(!activeId));
    dialog.addEventListener('close',()=>{pending?.abort();clearTimeout(timer);});
    print.addEventListener('click',()=>{
        if(print.disabled)return;
        try {frame.contentWindow.focus();frame.contentWindow.print();printStatus.textContent=somali?'Daaqadda print-ka waa la furay.':'Print dialog opened.';}
        catch {window.erp?.toast(somali?'Print ma furmin. Mar kale isku day.':'Unable to open printing. Please try again.','error');}
    });
})();
