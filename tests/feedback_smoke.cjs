/** Production notifications and dialog flows without browser popups or real transactions. */
const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
function setup(notices) {
    const events={},timers=[];
    function node(){return{dataset:{},attributes:{},children:[],handlers:{},textContent:'',setAttribute(key,value){this.attributes[key]=value;},addEventListener(type,handler){this.handlers[type]=handler;},append(...children){children.forEach(child=>{child.parent=this;this.children.push(child);});},remove(){if(this.parent)this.parent.children=this.parent.children.filter(child=>child!==this);}};}
    const container=node();container.open=false;container.showPopover=function(){this.open=true;};container.hidePopover=function(){this.open=false;};container.matches=function(){return this.open;};
    const context={document:{getElementById:id=>id==='toast-container'?container:id==='feedbackMessages'&&notices!==undefined?{textContent:typeof notices==='string'?notices:JSON.stringify(notices)}:null,createElement:node,addEventListener(type,handler){events[type]=handler;}},setTimeout:(handler,ms)=>timers.push({handler,ms}),fetch:async()=>({ok:false,status:422,json:async()=>({success:false,message:'Validation failed'})}),Swal:{fire:async()=>({isConfirmed:false})}};
    context.window=context;vm.runInNewContext(fs.readFileSync('assets/js/erp-feedback.js','utf8'),context);
    return{context,container,events,timers};
}
(async()=>{
    const h=setup();
    for(const kind of ['success','info','warning','error']){
        const text='<img src=x onerror=bad()> '+kind,toast=h.context.erp.toast(text,kind);
        assert.equal(toast.dataset.kind,kind);assert.equal(toast.children[1].textContent,text);assert.equal(toast.children[0].attributes['aria-hidden'],'true');assert.equal(toast.attributes.role,['warning','error'].includes(kind)?'alert':'status');
        assert.equal(h.container.open,true);toast.children[2].handlers.click();assert(!h.container.children.includes(toast));
    }
    const button={dataset:{toastMessage:'Please contact your store administrator to reset your password.',toastType:'info'}};let prevented=false;
    h.events.click({target:{closest:()=>button},preventDefault(){prevented=true;}});assert.equal(prevented,true);assert.equal(h.container.children.at(-1).dataset.kind,'info');
    await assert.rejects(h.context.erp.web('/unused'),/Validation failed/);assert.equal(h.container.children.at(-1).dataset.kind,'error');
    assert.equal(await h.context.erp.confirm('Continue?'),false);h.context.Swal.fire=async()=>({isConfirmed:true});assert.equal(await h.context.erp.confirm('Continue?'),true);
    const count=h.container.children.length;h.context.fetch=async()=>{const error=Error('Cancelled');error.name='AbortError';throw error;};await assert.rejects(h.context.erp.web('/unused'));assert.equal(h.container.children.length,count);
    const flash=setup([{message:'Wrong email or password.',type:'error'},{message:'Signed out.',type:'success'}]);assert.equal(flash.container.children.length,2);assert.equal(flash.container.children[0].dataset.kind,'error');assert.equal(flash.container.children[1].dataset.kind,'success');
    const fallback=setup('{broken');fallback.container.showPopover=()=>{throw Error('Unsupported');};assert.doesNotThrow(()=>fallback.context.erp.toast('Still usable','info'));
    h.timers.at(-1).handler();assert.equal(h.container.children.length,count-1);
    console.log('PASS: Shared feedback supports four styles, safe text, login hints/flash messages, confirmations, cancellation, dismissal and popover fallback.');

    const pos=fs.readFileSync('app/Views/web/pos/index.php','utf8'),start=pos.indexOf('        async function applyOrderDiscountPrompt()'),end=pos.indexOf('        function calculateSubtotal()',start);
    const paid={disabled:false},discount={value:'2.00'},messages=[];let response={isConfirmed:false},options,updates=0,subtotal=20;
    const context={document:{getElementById:id=>id==='payNowBtn'?paid:discount},calculateSubtotal:()=>subtotal,showToast:(...args)=>messages.push(args),handleFinancialInputChange(){updates++;},Swal:{async fire(value){options=value;return response;}}};
    vm.runInNewContext(pos.slice(start,end),context);await context.applyOrderDiscountPrompt();assert.equal(updates,0);assert.equal(discount.value,'2.00');
    for(const value of ['','bad','-1','21','1.005','Infinity'])assert.equal(typeof options.inputValidator(value),'string');assert.equal(options.inputValidator('5.25'),undefined);
    response={isConfirmed:true,value:'5.25'};await context.applyOrderDiscountPrompt();assert.equal(discount.value,'5.25');assert.equal(updates,1);
    response={isConfirmed:true,value:'99'};await context.applyOrderDiscountPrompt();assert.equal(updates,1);paid.disabled=true;response={isConfirmed:true,value:'10'};await context.applyOrderDiscountPrompt();assert.equal(updates,1);
    paid.disabled=false;subtotal=0;await context.applyOrderDiscountPrompt();assert.equal(updates,1);
    console.log('PASS: SweetAlert discount input respects Cancel, amount validation, subtotal limits and checkout in progress.');

    const supplier=fs.readFileSync('app/Views/web/suppliers/index.php','utf8');const a=supplier.indexOf('    async function toggleSupplierStatus('),b=supplier.indexOf('    function escapeStatementText',a);let allowed=false,requests=0;
    const supplierContext={erp:{confirm:async()=>allowed,api:async()=>requests++,toast(){}},setTimeout(){},location:{reload(){}}};vm.runInNewContext(supplier.slice(a,b),supplierContext);
    await supplierContext.toggleSupplierStatus(1,0);assert.equal(requests,0);allowed=true;await supplierContext.toggleSupplierStatus(1,0);assert.equal(requests,1);
    console.log('PASS: Supplier status changes wait for SweetAlert confirmation and do nothing on cancellation.');
})().catch(error=>{console.error(error);process.exitCode=1;});
