/** Exercise the actual return UI's async state and retry behavior without touching financial data. */
const assert = require('node:assert/strict'), fs = require('node:fs'), vm = require('node:vm');
function harness() {
    const nodes = {}, timers = new Map(), calls = [], toasts = [];
    let timerId = 0, confirmation = false, failure = false, deferred = null;
    function element(id) {
        return {id,value:'',textContent:'',disabled:false,required:false,open:false,handlers:{},dataset:{},rows:[],
            addEventListener(type, handler) { this.handlers[type] = handler; },
            showModal() { this.open = true; },close() { this.open = false; },
            replaceChildren() { this.rows = []; },reportValidity() { return true; },
            reset() { source.value = ''; account.value = ''; reason.value = ''; },
            querySelectorAll(selector) {
                if (this.id === 'returnItems') return this.rows;
                if (selector === '[data-close-return]') return [cancel];
                if (this.id === 'returnForm') return [source,account,reason,save,cancel,...body.rows.flatMap(row => [row.checkbox,row.quantity])];
                return [];
            },
        };
    }
    for (const id of ['returnConfig','returnDialog','returnForm','returnSource','returnItems','returnStatus','saveReturn','returnAccount','returnTotal','returnDebt','returnRefund','returnDiscount','returnReason','addReturn','returnDetails','closeReturnDetails','returnDetailsBody','returnDetailsTitle','returnsTable','cancel']) nodes[id] = element(id);
    const source = nodes.returnSource, account = nodes.returnAccount, reason = nodes.returnReason, save = nodes.saveReturn, cancel = nodes.cancel, body = nodes.returnItems;
    nodes.returnConfig.textContent = JSON.stringify({baseUrl:'/sales/returns'});
    Object.defineProperty(body, 'innerHTML', {set(html) {
        this.html = html;
        this.rows = [...html.matchAll(/<tr data-return-line="(\d+)"[\s\S]*?<\/tr>/g)].map(match => {
            const row = {dataset:{returnLine:match[1]}};
            row.checkbox = {type:'checkbox',checked:false,disabled:/type="checkbox"[^>]* disabled/.test(match[0]),closest:()=>row};
            row.quantity = {type:'number',value:(match[0].match(/value="([^"]+)"/) || [,'0'])[1],disabled:true,closest:()=>row};
            row.querySelector = selector => selector.includes('checkbox') ? row.checkbox : row.quantity;
            return row;
        });
    }});
    const available = {id:1,reference:'INV-1',party:'QA customer',due_cents:5000,lines:[{id:1,description:'<img src=x>',quantity:2,returned:0,remaining:2},{id:2,description:'Old line',quantity:1,returned:1,remaining:0}]};
    const quote = {total:50,debt_reduction:25,refund:25,discount_reversal:0,quote_token:'a'.repeat(64)};
    const context = {document:{getElementById:id=>nodes[id]},crypto:{randomUUID:()=> '12345678-1234-4234-8234-123456789abc'},AbortController,
        setTimeout(handler) { const id=++timerId; timers.set(id,handler); return id; },clearTimeout:id=>timers.delete(id),
        location:{reload(){calls.push({reload:true});}},Swal:{async fire(options){calls.push({confirmation:options});return {isConfirmed:confirmation};}},
        erp:{initDataTable(){},toast:(...args)=>toasts.push(args),async web(url,options={}){
            calls.push({url,options});
            if (url.includes('/source/')) return {data:available};
            if (url.includes('/quote/')) return deferred ? deferred : {data:quote};
            if (failure) throw Error('Network failed');
            return {data:{return_no:'SR-1'}};
        }},
    };
    context.window=context;
    vm.runInNewContext(fs.readFileSync('assets/js/document-returns.js','utf8'),context);
    return {nodes,calls,toasts,body,source,account,reason,save,quote,available,
        confirm:value=>confirmation=value,fail:value=>failure=value,defer:value=>deferred=value,
        async open(){nodes.addReturn.handlers.click();source.value='1';await source.handlers.change();},
        async select(){body.rows[0].checkbox.checked=true;body.handlers.change({target:body.rows[0].checkbox});await this.flush();},
        async flush(){const pending=[...timers.values()];timers.clear();for(const handler of pending)await handler();},
        submit:()=>nodes.returnForm.handlers.submit({preventDefault(){}}),
    };
}
(async()=>{
    const h=harness();await h.open();
    assert(h.body.html.includes('&lt;img src=x&gt;'));assert(!h.body.html.includes('<img src=x>'));
    assert.equal(h.body.rows[1].checkbox.disabled,true);
    await h.select();assert.equal(h.save.disabled,false);assert.equal(h.account.required,true);assert.equal(h.nodes.returnTotal.textContent,'50.00');
    h.reason.value='Wrong item';h.account.value='1';
    await h.submit();assert.equal(h.calls.filter(c=>c.url?.includes('/save/')).length,0);assert.equal(h.save.disabled,false);assert.equal(h.body.rows[1].checkbox.disabled,true);
    h.confirm(true);h.fail(true);await h.submit();
    const first=h.calls.filter(c=>c.url?.includes('/save/'))[0];assert(first);assert.equal(h.nodes.returnDialog.open,true);assert.equal(h.save.disabled,false);
    h.fail(false);await h.submit();
    const saves=h.calls.filter(c=>c.url?.includes('/save/'));
    assert.equal(saves.length,2);assert.equal(saves[0].options.body,saves[1].options.body);assert.equal(JSON.parse(first.options.body).quote_token,'a'.repeat(64));assert.equal(h.nodes.returnDialog.open,false);assert.equal(h.save.disabled,true);assert(h.calls.some(c=>c.reload));
    console.log('PASS: Return UI escapes text, respects Cancel, keeps exhausted lines disabled, requires a refund account and safely retries the same request.');

    const stale=harness();await stale.open();
    let resolve;const slow=new Promise(done=>resolve=done);stale.defer(slow);
    stale.body.rows[0].checkbox.checked=true;stale.body.handlers.change({target:stale.body.rows[0].checkbox});
    const preview=stale.flush();
    stale.nodes.returnDialog.handlers.cancel({preventDefault(){throw Error('Should allow closing.');}});stale.nodes.returnDialog.close();
    resolve({data:stale.quote});await preview;assert.equal(stale.save.disabled,true);assert.equal(stale.nodes.returnTotal.textContent,'0.00');
    console.log('PASS: A late preview cannot re-enable a closed return form.');
})().catch(error=>{console.error(error);process.exitCode=1;});
