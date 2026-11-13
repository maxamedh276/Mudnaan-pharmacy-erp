/** Run: node tests/select2_modal_position_smoke.cjs. Pure geometry; no live writes. */
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const source=fs.readFileSync('assets/js/searchable-selects.js','utf8');
const start=source.indexOf('    function modalDropdownPlacement('),end=source.indexOf('    function supportModalScrolling(',start);
const context={};vm.createContext(context);vm.runInContext(source.slice(start,end),context);
const place=context.modalDropdownPlacement;
// A field after scrolling 400px must stay immediately beside its control, not jump up 400px.
let result=place({top:300,bottom:336,left:120},{top:20,bottom:680,left:0},400,0,1,1,100,720);
assert.equal(result.direction,'below');assert.equal(result.top,715);assert.equal(result.left,119);
assert.equal(result.top-400+20+1,336,'Dropdown viewport top equals the field bottom.');
// Near the visible bottom, open directly above the field.
result=place({top:620,bottom:656,left:120},{top:20,bottom:680,left:0},400,0,1,1,180,720);
assert.equal(result.direction,'above');assert.equal(result.top-400+20+1,440);
// A short/mobile dialog caps the dropdown to the available visible area.
result=place({top:150,bottom:186,left:30},{top:10,bottom:330,left:0},250,0,0,0,240,350);
assert.equal(result.direction,'below');assert.equal(result.available,136);
assert(source.includes("parents().off('scroll.select2.' + instance.id)"),'Remove the plugin scroll lock for this control only.');
assert(source.includes("parent.addEventListener('scroll', reposition, true)"));
assert(source.includes("parent.removeEventListener('scroll', reposition, true)"));
console.log('PASS: modal dropdowns account for scrolling, anchor above/below the field, fit small viewports, and release modal scroll locks.');
