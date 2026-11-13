/** Production logo upload handler: immediate navigation updates and recoverable failures. */
const assert = require('node:assert/strict'), fs = require('node:fs'), vm = require('node:vm');
function setup() {
    const button = {disabled:false,textContent:'Upload logo'}, status = {textContent:''};
    const form = {dataset:{url:'/settings/logo'},elements:{logo:{value:'selected.png'}},handlers:{},attributes:{},
        querySelector:()=>button,reportValidity:()=>true,setAttribute(key,value){this.attributes[key]=value;},addEventListener(type,handler){this.handlers[type]=handler;}};
    const images = [{src:'old.png'},{src:'old.png'},{src:'old.png'}], toasts = [], requests = [];
    const context = {URL,location:{href:'http://localhost/settings',origin:'http://localhost'},
        document:{getElementById:id=>id==='logoUpload'?form:status,querySelectorAll:()=>images},
        FormData:class {constructor(owner){assert.equal(owner,form);}},erp:{toast:(...args)=>toasts.push(args)},
        fetch:(url,options)=>new Promise((resolve,reject)=>requests.push({url,options,resolve,reject}))};
    vm.runInNewContext(fs.readFileSync('assets/js/workspace-logo.js','utf8'),context);
    return {button,status,form,images,toasts,requests,submit:()=>form.handlers.submit({preventDefault(){}})};
}
(async()=>{
    const h=setup(), first=h.submit();
    assert.equal(h.button.disabled,true);assert.equal(h.form.attributes['aria-busy'],'true');await h.submit();assert.equal(h.requests.length,1,'Double clicks cannot upload twice.');
    assert.equal(h.requests[0].options.credentials,'same-origin');assert.equal(h.requests[0].options.headers['Content-Type'],undefined,'Browser must set the multipart boundary.');
    h.requests[0].resolve({ok:true,json:async()=>({success:true,data:{url:'http://localhost/assets/uploads/branding/new.png?v=1'}})});await first;
    h.images.forEach(image=>assert.equal(image.src,'http://localhost/assets/uploads/branding/new.png?v=1'));
    assert.equal(h.form.elements.logo.value,'');assert.equal(h.button.disabled,false);assert.equal(h.form.attributes['aria-busy'],'false');assert.match(h.status.textContent,/Logo updated/);
    console.log('PASS: Upload updates every logo immediately without reloading or resetting unrelated settings.');
    for(const failure of [
        {ok:false,json:async()=>({success:false,message:'Upload a PNG, JPEG, or WebP logo up to 2 MB.'})},
        {ok:false,json:async()=>{throw Error('Unexpected HTML');}},
        {ok:true,json:async()=>({success:true,data:{url:'https://invalid.test/logo.png'}})},
        {ok:true,json:async()=>({success:true,data:{}})},
    ]) {
        const old=h.images.map(image=>image.src);h.form.elements.logo.value='retry.png';const request=h.submit();h.requests.at(-1).resolve(failure);await request;
        assert.deepEqual(h.images.map(image=>image.src),old);assert.equal(h.form.elements.logo.value,'retry.png');assert.equal(h.button.disabled,false);assert.equal(h.toasts.at(-1)[1],'error');
    }
    const retry=h.submit();h.requests.at(-1).resolve({ok:true,json:async()=>({success:true,data:{url:'/assets/uploads/branding/replacement.png?v=2'}})});await retry;
    h.images.forEach(image=>assert.equal(image.src,'http://localhost/assets/uploads/branding/replacement.png?v=2'));
    console.log('PASS: Rejected files, expired responses and invalid URLs preserve the prior logo and permit retry.');
    h.form.reportValidity=()=>false;const count=h.requests.length;await h.submit();assert.equal(h.requests.length,count);
    console.log('PASS: Empty or invalid upload forms do not submit.');
})().catch(error=>{console.error(error);process.exitCode=1;});
