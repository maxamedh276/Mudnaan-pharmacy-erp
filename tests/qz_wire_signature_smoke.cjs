// Exercise the actual vendored connector, including its WebSocket serialization.
const assert = require('node:assert/strict');
const crypto = require('node:crypto');
const qz = require('../assets/js/vendor/qz-tray.js');
const {privateKey,publicKey} = crypto.generateKeyPairSync('rsa',{modulusLength:2048});
const captured = [];
class Socket {
    static OPEN=1; static CONNECTING=0; static CLOSED=3; static CLOSING=2;
    constructor(url) {
        this.url=url; this.readyState=0; this.listeners=new Map();
        setImmediate(()=>{this.readyState=1; this.listeners.get('open')?.();});
    }
    addEventListener(name,handler){this.listeners.set(name,handler);}
    removeEventListener(name){this.listeners.delete(name);}
    send(value) {
        const message=JSON.parse(value); captured.push(message);
        if(message.signature) {
            assert.equal(message.signAlgorithm,'SHA512','Pre-signed requests must declare SHA512 instead of falling back to SHA1.');
            const canonical=JSON.stringify({call:message.call,params:message.params,timestamp:message.timestamp});
            const digest=crypto.createHash('sha256').update(canonical).digest('hex');
            assert(crypto.verify('RSA-SHA512',Buffer.from(digest),publicKey,Buffer.from(message.signature,'base64')),'Wire request must match the signed canonical payload.');
        }
        const result=message.call==='getVersion'?'2.3.0':message.call==='printers.find'?['POS-58C']:null;
        setImmediate(()=>this.onmessage?.({data:JSON.stringify({uid:message.uid,result})}));
    }
    close(){this.readyState=3;setImmediate(()=>this.onclose?.({code:1000}));}
}
function sign(call,params){
    const timestamp=Date.now();
    const digest=crypto.createHash('sha256').update(JSON.stringify({call,params,timestamp})).digest('hex');
    return [crypto.sign('RSA-SHA512',Buffer.from(digest),privateKey).toString('base64'),timestamp];
}
(async()=>{
    qz.api.setWebSocketType(Socket);
    qz.security.setSignatureAlgorithm('SHA512');
    qz.security.setCertificatePromise(resolve=>resolve('TEST PUBLIC CERTIFICATE'));
    await qz.websocket.connect({keepAlive:0});
    await qz.printers.find(undefined,...sign('printers.find',{}));
    const config=qz.configs.create('POS-58C');
    const data=[{type:'raw',format:'command',flavor:'base64',data:'VGVzdAo='}];
    const params={printer:config.getPrinter(),options:config.getOptions(),data};
    await qz.print(config,data,...sign('print',params));
    assert.equal(captured.filter(message=>message.signature).length,2);
    await qz.websocket.disconnect();
    console.log('PASS: Real QZ connector transmits SHA512 metadata and cryptographically valid discovery/print signatures. No actual printer or certificate store was used.');
})().catch(error=>{console.error(error);process.exitCode=1;});
