const fs = require('node:fs'), vm = require('node:vm'), assert = require('node:assert/strict');
const source = fs.readFileSync('assets/js/qz-printing.js', 'utf8');
let deliveries = 0, connections = 0, active = 0, maxActive = 0, failPrint = false, printerMissing = false, denied = false;
const diagnostics = [];
let signingMode=false, signingDenied=false; const signedMessages=[];
const context = {
    localStorage: {getItem:()=>null,setItem:()=>{}},
    crypto: require('node:crypto'), console,
    document: {currentScript: {dataset: {printBase: '/web/printing'}}}, window: {},
    fetch: async (url, options) => {
        if(url.endsWith('/signing')) return {ok:true,json:async()=>({success:true,data:{configured:signingMode,certificate:signingMode?'TEST CERT':null}})};
        if(url.endsWith('/sign')) { signedMessages.push(JSON.parse(JSON.parse(options.body).message)); return {ok:!signingDenied,json:async()=> signingDenied?{success:false,message:'Signing failed'}:{success:true,data:{signature:'TEST SIGNATURE'}}}; }
        if(url.endsWith('/events')) { diagnostics.push(JSON.parse(options.body)); return {ok:true}; }
        assert.equal(options.method, 'POST');
        assert.equal(options.credentials, 'same-origin');
        assert.equal(options.headers['X-Requested-With'], 'XMLHttpRequest');
        return {ok: !denied, json: async () => denied ? {success: false, message: 'Forbidden'} :
            {success: true, data: {bytes: 'G0BUZXN0Cg==', printer: 'POS-58C', type: 'windows'}}};
    },
    qz: {
        websocket: {isActive: () => connections > 0, connect: async () => { connections++; }},
        printers: {find: async (query,signature,timestamp) => { if(signingMode){assert.equal(signature,'TEST SIGNATURE');assert.equal(typeof timestamp,'number');} return printerMissing ? ['POS-76C'] : ['POS-58C', 'POS-76C']; }},
        security:{setSignatureAlgorithm:algorithm=>assert.equal(algorithm,'SHA512'),setCertificatePromise:callback=>callback(certificate=>assert.equal(certificate,'TEST CERT'))},
        configs: {create: name => { assert.equal(name, 'POS-58C'); return {name,getPrinter:()=>({name}),getOptions:()=>({copies:1})}; }},
        print: async (_, data, signature, timestamp) => {
            if(signingMode){assert.equal(signature,'TEST SIGNATURE');assert.equal(typeof timestamp,'number');}
            deliveries++; maxActive = Math.max(maxActive, ++active);
            assert.equal(data[0].flavor, 'base64'); assert.equal(data[0].data, 'G0BUZXN0Cg==');
            await new Promise(resolve => setTimeout(resolve, 5)); active--;
            if (failPrint) throw Error('Connection lost');
        }
    }
};
context.window.qz = context.qz;
vm.runInNewContext(source, context);
(async () => {
    const printing = context.window.erpPrinting;
    await Promise.all([printing.sale(1), printing.sale(1), printing.receipt(2)]);
    assert.equal(deliveries, 2); assert.equal(maxActive, 1); assert.equal(connections, 1);
    failPrint = true;
    await assert.rejects(printing.test(), /some paper may already have printed/);
    assert.equal(deliveries, 3, 'Failed submissions must not be retried automatically');
    failPrint = false; await printing.test(); assert.equal(deliveries, 4);
    printerMissing = true; await assert.rejects(printing.sale(3), /not found/); assert.equal(deliveries, 4);
    denied = true; await assert.rejects(printing.sale(4), /Forbidden/); assert.equal(deliveries, 4);
    assert(diagnostics.some(event => event.stage === 'submission' && !event.success));
    assert(diagnostics.some(event => event.stage === 'discovery' && !event.success));
    assert(diagnostics.every(event => Object.keys(event).sort().join(',') === 'attempt,code,stage,success'));
    denied=false; printerMissing=false; signingMode=true;
    vm.runInNewContext(source,context);
    await context.window.erpPrinting.test();
    assert(signedMessages.some(message=>message.call==='print'&&message.params.data[0].data==='G0BUZXN0Cg=='));
    const beforeDenied=deliveries; signingDenied=true;
    await assert.rejects(context.window.erpPrinting.test(),/Signing failed/);
    assert.equal(deliveries,beforeDenied,'Signing failure must never fall back to unsigned printing.');
    console.log('PASS: QZ sends exact base64 bytes, finds the exact queue, serializes/deduplicates jobs, rejects unauthorized jobs, and permits manual recovery without automatic resubmission.');
})().catch(error => {console.error(error); process.exitCode = 1;});
