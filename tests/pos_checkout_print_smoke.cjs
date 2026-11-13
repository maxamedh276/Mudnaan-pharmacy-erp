const fs=require('node:fs'), vm=require('node:vm'), assert=require('node:assert/strict');
const source=fs.readFileSync('app/Views/web/pos/index.php','utf8');
const start=source.indexOf('        async function processCheckout()');
const end=source.indexOf('        function showReceiptModal',start);
const code=source.slice(start,end).replace(/<\?=([\s\S]*?)\?>/g,(_,php)=>php.includes('thermal_printer_enabled')?'false':"'/sales/pos/checkout'");
async function scenario({paid=0,enabled=true,closed=false}={}) {
    const events=[],toasts=[],button={disabled:false,innerHTML:'Pay'},badge={textContent:'0'};
    const context={POS:{cart:[{id:1,qty:1,price:10}],selectedCustomer:7,activeTax:null,checkoutUuid:'original',activePaymentMethod:{accountId:1,type:'cash'}},
        document:{getElementById:id=>id==='payNowBtn'?button:id==='posOrdersBadge'?badge:null},
        getFinancialValues:()=>({paid,grandTotal:10,discount:0,shipping:0}),showToast:message=>toasts.push(message),
        crypto:{randomUUID:()=> 'next'},lucide:{createIcons(){}},handleFinancialInputChange(){},updatePaidStatusAndButton(){},
        showReceiptModal(){events.push('preview');},clearCart(){events.push('clear');context.POS.cart=[];},
        printDirectThermal:id=>events.push(`print:${id}`),
        fetch:async()=>{events.push('checkout');return {ok:!closed,status:closed?409:200,json:async()=>closed?
            {success:false,code:'PERIOD_CLOSED',message:'The sale date is in a closed financial period.'}:
            {success:true,data:{id:123},autoPrint:enabled}};}};
    vm.runInNewContext(code,context);await context.processCheckout();
    if(closed){assert.equal(context.POS.cart.length,1);assert.equal(context.POS.checkoutUuid,'original');assert(!events.some(event=>event.startsWith('print:')));assert(toasts.some(message=>message.includes('closed financial period')));}
    else {assert.equal(events.filter(event=>event==='print:123').length,enabled?1:0);if(enabled)assert(events.indexOf('print:123')<events.indexOf('preview'));assert.equal(context.POS.cart.length,0);}
}
(async()=>{await scenario();await scenario({paid:10});await scenario({enabled:false});await scenario({closed:true});console.log('PASS: Paid and zero-payment credit checkout print immediately once using current server configuration; disabled printing and closed-period rejection preserve safe behavior.');})().catch(error=>{console.error(error);process.exitCode=1;});
