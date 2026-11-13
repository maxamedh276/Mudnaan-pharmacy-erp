/** Run: node tests/purchase_quantity_units_smoke.cjs. No database writes. */
const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const source = fs.readFileSync('assets/js/supplier-purchases.js', 'utf8');
const quantity = {min:'0.001',step:'0.001',value:50};
const cost = {value:51};
const unit = {dataset:{},value:'loose',replaceChildren(){},add(){}};
const row = {querySelector(selector){return {'.po-prod-select':{value:1},'.po-prod-qty':quantity,'.po-prod-cost':cost,'.po-stock-mode':unit,'.po-unit-label':{}}[selector];}};
unit.closest = () => row;
const context = {availableProducts:[{id:1,unit:'kg',package_unit:'Loor',package_size:50,cost_price:1.02}],Option:function(text,value){this.text=text;this.value=value;},calculatePOTotals(){}};
vm.createContext(context);
vm.runInContext(source.slice(source.indexOf('function configurePurchaseUnit('),source.indexOf('function calculatePOTotals(')),context);
// Native number inputs validate against min + n * step, not just n * step.
function valid(value){const min=Number(quantity.min),step=Number(quantity.step);return value>=min && Math.abs((value-min)/step-Math.round((value-min)/step))<1e-8;}
context.configurePurchaseUnit(row,'sealed');
assert.equal(quantity.value,50,'Editing an existing order preserves its quantity.');
assert(valid(50),'50 sealed Loor must pass native min/step validation.');
assert(valid(1));assert(!valid(0));assert(!valid(0.5));assert(!valid(50.001));
context.configurePurchaseUnit(row,'loose');
assert(valid(0.001));assert(valid(0.2));assert(valid(0.4));assert(valid(0.5));assert(valid(50));assert(!valid(0));
unit.value='sealed';context.handlePurchaseUnit(unit);
assert(valid(50));assert.equal(quantity.value,1);assert.equal(cost.value,'51.00');
unit.value='loose';context.handlePurchaseUnit(unit);
assert(valid(0.5));assert.equal(cost.value,'1.02');
console.log('PASS: 50 Loor validates on create/edit and unit changes; sealed fractions fail, fractional kg/litres remain valid.');
