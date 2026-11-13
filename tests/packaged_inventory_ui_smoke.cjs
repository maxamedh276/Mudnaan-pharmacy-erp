/** Run: node tests/packaged_inventory_ui_smoke.cjs. No browser or live database writes. */
const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const source = fs.readFileSync('app/Views/web/pos/index.php', 'utf8');
const code = source.slice(source.indexOf('        function cartKey('), source.indexOf('        function clearCart()')).replace(/<\?=[\s\S]*?\?>/g, '"/isolated-stock"');
const product = {id:21,name:'Bariis',unit:'kg',package_unit:'Loor',package_size:25,package_price:24,sell_price:1,stock_split:{sealed:3,opened:0.5}};
let dialogs = [], calls = [], renders = 0;
const context = {
    POS:{cart:[],products:{21:product}},
    renderCart:()=>renders++,
    erp:{web:async()=>({data:[product]})},
    Swal:{fire:async options=>{calls.push(options); return dialogs.shift() || {isConfirmed:false};}},
    window:{addEventListener:()=>{},open:(...args)=>calls.push({open:args})},
};
vm.createContext(context); vm.runInContext(code, context);
(async()=>{
    context.addToCart(21,'Bariis',24,1,'sealed','Loor');
    context.addToCart(21,'Bariis',1,0.4,'loose','kg');
    context.addToCart(21,'Bariis',1,0.1,'loose','kg');
    assert.equal(context.POS.cart.length,2);
    assert.equal(context.POS.cart[1].qty,0.5);
    context.updateCartQty('21:sealed',1);
    assert.equal(context.POS.cart[0].qty,2);
    assert.equal(context.POS.cart[1].qty,0.5);
    context.removeCartItem('21:sealed');
    assert.equal(context.POS.cart.length,1);
    assert.match(context.poolMessage(product,'loose',2),/opened stock is 0.5 kg; requested 2 kg/);
    assert.equal(context.poolMessage(product,'sealed',3),'');
    assert.match(context.poolMessage(product,'sealed',4),/only 3 sealed/);
    context.setCartQuantity('21:loose',2);
    assert.equal(context.POS.cart[0].qty,0.5,'Shortage preserves the ticket.');
    assert.match(calls.at(-1).text,/requested 2 kg/);
    product.stock_split.opened=25.5;
    context.setCartQuantity('21:loose',2);
    assert.equal(context.POS.cart[0].qty,2,'Inline quantity can be retried after manual opening in another tab.');
    product.quantity_shortcuts=[{label:'Rubac',quantity:0.25},{label:'Nus',quantity:0.5},{label:'Tumin',quantity:0.125}];
    context.applyQuantityShortcut('21:loose',0); assert.equal(context.POS.cart[0].qty,0.25);
    context.applyQuantityShortcut('21:loose',1); assert.equal(context.POS.cart[0].qty,0.5);
    context.applyQuantityShortcut('21:loose',2); assert.equal(context.POS.cart[0].qty,0.125,'Managed shortcuts replace, rather than accumulate, quantities.');
    context.applyQuantityShortcut('21:loose',0);
    context.updateCartQty('21:loose',1);assert.equal(context.POS.cart[0].qty,0.5,'Plus adds another configured Rubac.');
    assert.equal(context.POS.cart[0].quantity_option.label,'Rubac','Rubac stays selected when the total becomes 0.5.');
    context.updateCartQty('21:loose',-1);assert.equal(context.POS.cart[0].qty,0.25,'Minus removes one Rubac.');
    product.quantity_shortcuts[0].quantity=0.3;
    context.applyQuantityShortcut('21:loose',0);context.updateCartQty('21:loose',1);
    assert.equal(context.POS.cart[0].qty,0.6,'Increment comes from the managed option, not a hard-coded fraction.');
    product.quantity_shortcuts[0].quantity=0.25;
    context.applyQuantityShortcut('21:loose',2);
    for(let i=0;i<7;i++)context.updateCartQty('21:loose',1);
    assert.equal(context.POS.cart[0].qty,1,'Repeated eighths reach exactly 1 kg without decimal drift.');
    context.setCartQuantity('21:loose',1);context.updateCartQty('21:loose',1);
    assert.equal(context.POS.cart[0].qty,2,'Custom kg quantity restores increments of 1.');
    context.applyQuantityShortcut('21:loose',0);product.stock_split.opened=0.5;
    context.updateCartQty('21:loose',1);context.updateCartQty('21:loose',1);
    assert.equal(context.POS.cart[0].qty,0.5,'A named increment cannot exceed opened stock.');
    product.stock_split.opened=25.5;
    context.changeCartUnit('21:loose','sealed');
    assert.equal(context.POS.cart[0].qty,1);assert.equal(context.POS.cart[0].price,24);
    context.setCartQuantity('21:sealed',2);assert.equal(context.POS.cart[0].qty,2);
    context.showToast=()=>{};
    context.setCartQuantity('21:sealed',0.5);assert.equal(context.POS.cart[0].qty,2,'Sealed quantities reject fractions.');
    product.stock_split.opened=0.5;
    context.changeCartUnit('21:sealed','loose');
    assert.equal(context.POS.cart[0].qty,0.5,'A sealed row can switch to the remaining half kg without requiring a whole opened kg.');
    assert(!source.includes('async function chooseMeasuredItem('),'Product clicks no longer open measurement popups.');
    assert(source.includes('aria-label="Quantity"'));
    assert(source.includes("stock_mode: item.stock_mode || 'loose'"));
    assert(source.includes("res.code === 'PACKAGE_STOCK_SHORTAGE'"));
    assert(source.includes('Opened stock finished / Kii furnaa wuu dhammaaday'));
    const stock = fs.readFileSync('app/Views/web/inventory/stock.php','utf8');
    assert(stock.includes('request_key:button.dataset.openKey'));
    assert(stock.includes("input: 'number'"));
    assert(stock.includes('packageHistory'));
    console.log('PASS: mixed-unit cart, fractional quantities, stock-pool checks, shortage recovery, whole-package validation, empty-stock notice, manual opening confirmation and retry key.');
})().catch(error=>{console.error(error);process.exitCode=1;});
