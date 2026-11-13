const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const source=fs.readFileSync('app/Views/web/pos/index.php','utf8');
const start=source.indexOf('        function selectPaymentMethod('),end=source.indexOf('        // ==========================================',start);
const select={value:'',selectedOptions:[{value:'evc',dataset:{accountId:'42',name:'EVC Plus',type:'mobile_money'}}]};
const context={POS:{activePaymentMethod:{}},document:{getElementById:()=>select}};vm.runInNewContext(source.slice(start,end),context);context.handlePaymentMethodChange(select);
assert.equal(context.POS.activePaymentMethod.accountId,42);assert.equal(context.POS.activePaymentMethod.type,'mobile_money');assert.equal(context.POS.activePaymentMethod.name,'EVC Plus');assert.equal(select.value,'evc');
select.selectedOptions=[{value:'',dataset:{}}];context.handlePaymentMethodChange(select);assert.equal(context.POS.activePaymentMethod.accountId,null);
assert(source.indexOf('id="posInputShipping"')<source.indexOf('id="posInputPaid"'));assert(source.indexOf('id="posInputPaid"')<source.indexOf('id="cartGrandTotalLabel"'));assert(!source.includes('posMethodsGrid'));assert(source.includes('void printDirectThermal(sale.id)'));
console.log('PASS: Compact checkout order, selected payment account/type, missing-method guard, and automatic ESC/POS print hook.');
