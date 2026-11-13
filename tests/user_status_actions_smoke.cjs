/** Run: node tests/user_status_actions_smoke.cjs. No live requests or account changes. */
const fs=require('node:fs'), vm=require('node:vm'), assert=require('node:assert/strict');
const source=fs.readFileSync('app/Views/web/users/index.php','utf8');
const code=source.slice(source.indexOf('    async function toggleUserStatus('),source.indexOf('</script>',source.indexOf('    async function toggleUserStatus(')));
let confirms=0,calls=0,toasts=0,reloads=0,confirmed=false,fail=false;
const context={erp:{confirm:async()=>{confirms++;return confirmed;},api:async()=>{calls++;if(fail)throw Error('Network');},toast:()=>toasts++},setTimeout:fn=>fn(),window:{location:{reload:()=>reloads++}}};
vm.createContext(context);vm.runInContext(code,context);
const button=()=>({disabled:false,setAttribute(){},removeAttribute(){}});
(async()=>{
 let b=button();await context.toggleUserStatus(2,false,b);assert.equal(calls,0);assert.equal(b.disabled,false);
 confirmed=true;fail=true;await context.toggleUserStatus(2,false,b);assert.equal(b.disabled,false);assert.equal(reloads,0);
 fail=false;await context.toggleUserStatus(2,false,b);assert(b.disabled);assert.equal(toasts,1);assert.equal(reloads,1);
 const before=calls;await context.toggleUserStatus(2,false,b);assert.equal(calls,before);
 b=button();await context.toggleUserStatus(2,true,b);assert.equal(toasts,2);assert.equal(reloads,2);
 assert(source.includes("$isActive ? 'Deactivate' : 'Activate'"));
 console.log('PASS: Activate/deactivate labels, confirmation cancellation, network recovery, duplicate-click prevention and successful status changes.');
})().catch(e=>{console.error(e);process.exitCode=1;});
