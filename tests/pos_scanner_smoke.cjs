/** Run: node tests/pos_scanner_smoke.cjs. Keyboard events and requests are isolated fixtures. */
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const search={value:''};let handler,clock=100,filtered=0,shortages=[],errors=[],requests=[];
const product={id:1201,name:'Rice',unit:'kg',quantity:50,sell_price:2,tax_rate:0,default_sale_mode:'loose',package_unit:'Loor',package_size:25,package_price:40,stock_split:{sealed:1,opened:25}};
const context={document:{currentScript:{dataset:{scanUrl:'/scan'}},getElementById:()=>search,addEventListener:(name,fn)=>{handler=fn;}},performance:{now:()=>clock},POS:{products:{},productTaxRates:{},cart:[]},cartKey:i=>i.key,renderProductFilter:()=>filtered++,poolMessage:(p,mode,q)=>q>(mode==='sealed'?p.stock_split.sealed:p.stock_split.opened)?'Shortage':'',showStockShortage:async m=>shortages.push(m),erp:{web:async url=>{requests.push(url);if(url.includes('UNKNOWN'))throw Error('Unknown code');return {data:{...product}};},toast:m=>errors.push(m)},addToCart:(id,name,price,qty,mode,unit)=>{const key=`${id}:${mode}`,item=context.POS.cart.find(i=>i.key===key);if(item)item.qty+=qty;else context.POS.cart.push({id,key,name,price,qty,mode,unit});}};
vm.createContext(context);vm.runInContext(fs.readFileSync('assets/js/pos-scanner.js','utf8'),context);
const body={closest:()=>null},editable={closest:()=>({})};
function key(value,target=body){clock+=10;let stopped=false;handler({key:value,target,preventDefault(){stopped=true;},stopImmediatePropagation(){}});return stopped;}
async function settle(){for(let i=0;i<5;i++)await new Promise(r=>setImmediate(r));}
(async()=>{
 search.value='001234';assert(key('Enter',search));await settle();assert.equal(context.POS.cart[0].qty,1);assert.equal(search.value,'');assert(requests[0].includes('code=001234'));assert(filtered);
 search.value='001234';key('Enter',search);search.value='001234';key('Tab',search);await settle();assert.equal(context.POS.cart[0].qty,3,'Every repeated scan increments once.');
 for(const c of '001234')key(c);assert(key('Enter'));await settle();assert.equal(context.POS.cart[0].qty,4,'Rapid scan works outside the search field.');
 const before=requests.length;for(const c of '001234')key(c,editable);key('Enter',editable);await settle();assert.equal(requests.length,before,'Payment and quantity fields are left alone.');
 for(const c of '001234'){clock+=150;key(c);}assert(!key('Enter'));await settle();assert.equal(requests.length,before,'Slow ordinary typing is not interpreted as a global scan.');
 product.stock_split.opened=4;search.value='001234';key('Enter',search);await settle();assert.equal(context.POS.cart[0].qty,4);assert.equal(shortages.length,1,'Shortage cannot overfill opened stock.');
 search.value='UNKNOWN';key('Enter',search);await settle();assert.equal(errors.length,1);product.stock_split.opened=25;search.value='001234';key('Enter',search);await settle();assert.equal(context.POS.cart[0].qty,5,'Unknown code does not break later scans.');
 product.default_sale_mode='sealed';search.value='SKU-RICE';key('Enter',search);await settle();assert.equal(context.POS.cart[1].price,40);assert.equal(context.POS.cart[1].mode,'sealed');
 search.value='SKU-RICE';key('Enter',search);await settle();assert.equal(context.POS.cart[1].qty,1,'Sealed scans use the sealed stock pool.');
 product.scan_mode='loose';search.value='001234';key('Enter',search);await settle();assert.equal(context.POS.cart[0].qty,6,'Single-unit barcode overrides a sealed default.');
 product.scan_mode='sealed';product.default_sale_mode='loose';product.stock_split.sealed=2;search.value='001235';key('Enter',search);await settle();assert.equal(context.POS.cart[1].qty,2,'Sealed barcode overrides a loose default and uses the sealed price.');
 console.log('PASS: Enter/Tab, leading zeros, repeated scans, background scanning, editable fields, slow typing, stock limits, error recovery and sealed defaults.');
})().catch(e=>{console.error(e);process.exitCode=1;});
