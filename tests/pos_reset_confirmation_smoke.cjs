const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const source=fs.readFileSync('app/Views/web/pos/index.php','utf8');
const start=source.indexOf('        async function confirmResetTicket()');
let depth=0,end=start,started=false;
for(;end<source.length;end++){if(source[end]==='{'){depth++;started=true;}else if(source[end]==='}'&&--depth===0&&started){end++;break;}}
let confirmed=false,resets=0,dialogs=0;
const button={disabled:false};
const context={document:{getElementById:()=>button},POS:{cart:[{id:1}]},Swal:{fire:async()=>{dialogs++;return{isConfirmed:confirmed};}},clearCart:()=>resets++,showToast:()=>{}};
vm.runInNewContext(source.slice(start,end),context);
(async()=>{
    await context.confirmResetTicket();assert.equal(resets,0);
    confirmed=true;await context.confirmResetTicket();assert.equal(resets,1);
    button.disabled=true;await context.confirmResetTicket();assert.equal(resets,1);assert.equal(dialogs,2);
    assert(source.includes('sweetalert2.all.min.js'));assert(!source.includes("if (confirm('Are you sure you want to reset"));
    console.log('PASS: Ticket reset uses SweetAlert2, respects cancel and confirmation, and cannot clear an in-flight checkout.');
})().catch(error=>{console.error(error);process.exitCode=1;});
