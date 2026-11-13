/** Dashboard request races, recovery, filters and previews, without browser or network access. */
const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
function deferred(){let resolve,reject;const promise=new Promise((a,b)=>{resolve=a;reject=b;});return {promise,resolve,reject};}
function harness(){
    function element(id){return {id,value:'',dataset:{},handlers:{},attrs:{},textContent:'',innerHTML:'',childElementCount:1,disabled:false,open:false,hidden:false,classList:{values:new Set(['hidden']),add(v){this.values.add(v);},remove(v){this.values.delete(v);}},addEventListener(type,fn){this.handlers[type]=fn;},setAttribute(k,v){this.attrs[k]=v;},querySelector(){return null;},querySelectorAll(){return [];},replaceChildren(){this.innerHTML='';this.childElementCount=0;},showModal(){this.open=true;},close(){this.open=false;},focus(){this.focused=true;}};}
    const nodes={};for(const id of ['roleDashboard','dashboardFilters','dashboardRange','dashboardPanels','dashboardLoading','dashboardOrderDialog','dashboardOrderBody','dashboardOrderTitle','closeDashboardOrder','dashboardDates','dashboardOrderSearch','dashboardNoOrders'])nodes[id]=element(id);
    nodes.roleDashboard.dataset={profile:'cashier',url:'/dashboard/data',orderUrl:'/dashboard/orders'};nodes.dashboardRange.value='today';nodes.dashboardPanels.innerHTML='original';
    const rows=[{dataset:{due:'1',returned:'0'},textContent:'INV-1 Ali',hidden:false},{dataset:{due:'0',returned:'1'},textContent:'INV-2 Nur',hidden:false}];
    nodes.dashboardPanels.querySelector=s=>s==='#dashboardOrderSearch'?nodes.dashboardOrderSearch:s==='#dashboardNoOrders'?nodes.dashboardNoOrders:null;
    nodes.dashboardPanels.querySelectorAll=s=>s==='[data-dashboard-order]'?rows:[];
    const calls=[],history=[],queue=[];let reloads=0;
    const context={document:{getElementById:id=>nodes[id]},AbortController,URL,location:{href:'http://localhost/dashboard',reload(){reloads++;}},history:{replaceState(...args){history.push(args);}},erp:{toast(){},web(url,options){calls.push({url,options});const next=queue.shift();if(next instanceof Error)return Promise.reject(next);return next?.promise||Promise.resolve(next);}}};context.window=context;
    vm.runInNewContext(fs.readFileSync('assets/js/dashboard.js','utf8'),context);
    return {nodes,calls,history,queue,rows,reloads:()=>reloads,refresh:()=>nodes.dashboardRange.handlers.change({preventDefault(){}}),snapshot:(range,html='updated')=>({data:{profile:'cashier',range,html,from:'2026-11-01',to:'2026-11-06'}})};
}
(async()=>{
    const h=harness();h.nodes.dashboardRange.value='month';h.queue.push(h.snapshot('month'));await h.refresh();assert.equal(h.nodes.dashboardPanels.innerHTML,'updated');assert.equal(h.nodes.dashboardPanels.attrs['aria-busy'],'false');assert(h.history[0][2].searchParams.get('range')==='month');
    h.nodes.dashboardRange.value='7days';h.queue.push(Error('Failed'));await h.refresh();assert.equal(h.nodes.dashboardRange.value,'month');assert.equal(h.nodes.dashboardPanels.innerHTML,'updated');assert(!h.nodes.dashboardLoading.classList.values.has('hidden'));
    console.log('PASS: Date refresh replaces only dashboard panels; failed requests keep the last good snapshot and restore the applied range.');
    const a=deferred(),b=deferred();h.queue.push(a,b);h.nodes.dashboardRange.value='today';const first=h.refresh();h.nodes.dashboardRange.value='yesterday';const second=h.refresh();b.resolve(h.snapshot('yesterday','newest'));await second;a.resolve(h.snapshot('today','stale'));await first;assert.equal(h.nodes.dashboardPanels.innerHTML,'newest');assert.equal(h.nodes.dashboardRange.value,'yesterday');assert.equal(h.calls.at(-2).options.signal.aborted,true);
    console.log('PASS: Older responses and cancelled requests cannot overwrite the latest reporting period.');
    const filter={dataset:{dashboardFilter:'due'}};await h.nodes.dashboardPanels.handlers.click({target:{closest:s=>s==='[data-dashboard-filter]'?filter:null}});assert.equal(h.rows[0].hidden,false);assert.equal(h.rows[1].hidden,true);h.nodes.dashboardOrderSearch.value='missing';h.nodes.dashboardPanels.handlers.input({target:{id:'dashboardOrderSearch'}});assert(h.rows.every(r=>r.hidden));assert.equal(h.nodes.dashboardNoOrders.hidden,false);
    console.log('PASS: Recent-order status and text filters combine and display a clear empty state.');
    const view={dataset:{dashboardView:'1'},disabled:false,focus(){}};h.queue.push({data:{reference:'INV-1',customer:'<script>bad()</script>',date:'2026-11-06',original_total:100,returned:50,refunds:20,net_total:50,settled:50,due:0,items:[{description:'<img src=x>',quantity:1,unit_price:100,total:100}]}});await h.nodes.dashboardPanels.handlers.click({target:{closest:s=>s==='[data-dashboard-view]'?view:null}});assert(h.nodes.dashboardOrderBody.innerHTML.includes('&lt;script&gt;'));assert(!h.nodes.dashboardOrderBody.innerHTML.includes('<img src=x>'));assert.equal(view.disabled,false);assert.equal(h.nodes.dashboardOrderDialog.open,true);
    h.nodes.closeDashboardOrder.handlers.click();assert.equal(h.nodes.dashboardOrderDialog.open,false);
    console.log('PASS: Order previews show persisted totals, safely escape names/items and close correctly.');
    const denied=Error('Forbidden');denied.status=403;h.queue.push(denied);h.nodes.dashboardRange.value='month';await h.refresh();assert.equal(h.nodes.dashboardPanels.childElementCount,0);assert(h.nodes.dashboardLoading.textContent.includes('access changed'));
    const changed=harness();changed.queue.push({data:{profile:'accountant'}});await changed.refresh();assert.equal(changed.nodes.dashboardPanels.childElementCount,0);assert.equal(changed.reloads(),1);
    console.log('PASS: Revoked access or a changed profile clears old dashboard content before reload.');
})().catch(error=>{console.error(error);process.exitCode=1;});
