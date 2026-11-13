/** Exercise the production export handlers without real customer data or browser downloads. */
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const assert = require('node:assert/strict');
const nativeSetTimeout = setTimeout;
const ExcelJS = require('../assets/js/vendor/exceljs.min.js');
const pdfMake = require('../assets/js/vendor/pdfmake.min.js');
pdfMake.vfs = require('../assets/js/vendor/vfs_fonts.js');
const statement = { party: 'Temporary customer', filters: {date_from:'2090-01-01', date_to:'2090-01-31',type:'invoice',q:'TEST'}, summary:{opening_balance:0,total_debit:30,total_credit:0,closing_balance:30}, entries:Array.from({length:30},(_,index)=>({date:'2090-01-02',type:'invoice',ref:'TEST-'+(index+1),debit:1,credit:0,balance:index+1})) };
const elements = new Map();
const pending = [];
let excelBytes;
let pdfBytes;
let pdfDefinition;
function element(id) {
    if (!elements.has(id)) elements.set(id,{ textContent:id==='statementConfig'?JSON.stringify({statement,dataUrl:'/unused'}):'',addEventListener(type,handler){this[type]=handler;},elements:[] });
    return elements.get(id);
}
const urlBlobs = new Map();
const table = {clear(){return this;},rows:{add(){return {draw(){}};}}};
const context = { console,ExcelJS, Blob, URLSearchParams, AbortController,clearTimeout,setTimeout:(callback,delay,...args)=>nativeSetTimeout(callback, Math.min(delay,10),...args),
    document:{getElementById:element,createElement(){return {click(){pending.push(urlBlobs.get(this.href).arrayBuffer().then(buffer=>{excelBytes=Buffer.from(buffer);}));}};}},
    URL:{createObjectURL(blob){const id='blob:test';urlBlobs.set(id,blob);return id;},revokeObjectURL(){}},
    $:{fn:{dataTable:{render:{text:()=>({})}}}},
    erp:{initDataTable:()=>table,toast(message,type){if(type==='error')throw new Error(message);}},
    pdfMake:{createPdf(definition){pdfDefinition=definition;return {download(){pending.push(new Promise(resolve=>pdfMake.createPdf(definition).getBuffer(buffer=>{pdfBytes=Buffer.from(buffer);resolve();})));}};}}
};
Object.assign(global, context);
vm.runInThisContext(fs.readFileSync(path.join(__dirname,'../assets/js/customer-statement.js'),'utf8'));
(async()=>{
    await element('exportExcel').click();
    await element('exportPdf').click();
    await Promise.all(pending);
    const workbook = new ExcelJS.Workbook(); await workbook.xlsx.load(excelBytes);
    const sheet=workbook.getWorksheet('Statement');
    assert(sheet.rowCount===38,'Excel must include all 30 filtered rows, beyond the 25-row page.');
    assert(sheet.getRow(9).getCell(3).value==='TEST-1');
    assert(sheet.getRow(38).getCell(3).value==='TEST-30');
    assert(pdfBytes.subarray(0,5).toString()==='%PDF-');
    assert(pdfDefinition.content.at(-1).table.body.length===31,'PDF includes all filtered rows.');
    assert(sheet.getRow(2).getCell(1).value.includes('invoice'),'Excel includes filter scope.');
    console.log('PASS: Production Excel/PDF handlers create valid files containing all 30 filtered transactions and their filter scope.');
})().catch(error=>{console.error(error);process.exitCode=1;});
