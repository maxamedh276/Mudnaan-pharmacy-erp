/** Real Excel/PDF bytes from every production report snapshot and export handler. */
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict'),path=require('node:path');
const ExcelJS=require('../assets/js/vendor/exceljs.min.js'),pdfMake=require('../assets/js/vendor/pdfmake.min.js');pdfMake.vfs=require('../assets/js/vendor/vfs_fonts.js');
const nativeSetTimeout=setTimeout;
const fixtures=JSON.parse(fs.readFileSync(path.join(__dirname,'../build/erp-report-fixtures.json'),'utf8'));
const emptyFixtures=fixtures.map(report=>({...report,rows:[],rowKeys:[],...(report.details?{details:{}}:{}),...(report.sections?{sections:report.sections.map(section=>({...section,groups:[]}))}:{})}));
const untrustedText=structuredClone(fixtures.find(report=>report.type==='inventory'));untrustedText.testCase='untrusted-text';untrustedText.rows=[['SKU','=HYPERLINK("https://invalid.test")','<img src=x onerror=bad()>',0.001,10,0.01]];
(async()=>{
 for(const report of [...fixtures,...emptyFixtures,untrustedText]){
  const elements=new Map(),blobs=new Map(),pending=[],created=[];let excelBytes,pdfBytes,pdfDefinition;
  function makeElement(){return{textContent:'',dataset:{},attributes:{},handlers:{},children:[],disabled:false,addEventListener(type,handler){this.handlers[type]=handler;},setAttribute(key,value){this.attributes[key]=value;},replaceChildren(){this.children=[];},append(child){this.children.push(child);},reportValidity(){return true;}};}
  function element(id){if(!elements.has(id)){const value=makeElement();if(id==='reportConfig')value.textContent=JSON.stringify({report,dataUrl:'/unused'});elements.set(id,value);}return elements.get(id);}
  const context={window:{},ExcelJS,Blob,AbortController,URLSearchParams,document:{getElementById:element,querySelector:element,querySelectorAll:()=>[],createElement(tag){const value=makeElement();value.tag=tag;created.push(value);if(tag==='a')value.click=function(){pending.push(blobs.get(this.href).arrayBuffer().then(bytes=>excelBytes=Buffer.from(bytes)));};return value;}},erp:{toast(message){throw Error(message);}},setTimeout:(fn,ms,...args)=>nativeSetTimeout(fn,Math.min(ms,10),...args),URL:{createObjectURL(blob){const id='blob:'+blobs.size;blobs.set(id,blob);return id;},revokeObjectURL(){}},pdfMake:{createPdf(definition){pdfDefinition=definition;return{download(){pending.push(new Promise(resolve=>pdfMake.createPdf(definition).getBuffer(bytes=>{pdfBytes=Buffer.from(bytes);resolve();})));}};}}};
  Object.assign(global,context);vm.runInThisContext(fs.readFileSync(path.join(__dirname,'../assets/js/reports.js'),'utf8'));
  if(report.details){const eyes=created.filter(value=>value.className==='report-eye');assert.equal(eyes.length,report.rows.length);eyes.forEach(button=>{assert.equal(button.attributes['aria-expanded'],'false');button.handlers.click();assert.equal(button.attributes['aria-expanded'],'true');button.handlers.click();assert.equal(button.attributes['aria-expanded'],'false');});}
  if(report.sections)assert.equal(created.filter(value=>value.className==='report-card report-statement-section').length,report.sections.length);
  await element('reportExcel').handlers.click();element('reportPdf').handlers.click();await Promise.all(pending);
  const book=new ExcelJS.Workbook();await book.xlsx.load(excelBytes);const sheet=book.getWorksheet('Report');
  assert.equal(sheet.rowCount,report.rows.length+Object.keys(report.summary).length+4);assert.equal(sheet.getRow(1).getCell(1).value,report.title);
  assert.equal(pdfBytes.subarray(0,5).toString(),'%PDF-');
  const pdfTables=pdfDefinition.content.filter(value=>value.table);
  if(!report.sections)assert.equal(pdfTables[0].table.body.length,Math.max(report.rows.length,1)+1);
  if(report.sections){assert.ok(book.getWorksheet('Statement'));for(const section of report.sections){assert.ok(JSON.stringify(pdfDefinition).includes('Total '+section.label));for(const group of section.groups)for(const category of group.categories)for(const row of category.rows)assert.ok(JSON.stringify(pdfDefinition).includes(row[1]),'PDF includes every statement account.');}}
  if(report.details){assert.ok(book.getWorksheet('Details'));const detailTables=Object.values(report.details).flatMap(detail=>detail.tables);assert.equal(pdfTables.length,1+detailTables.length);detailTables.forEach((data,index)=>assert.equal(pdfTables[index+1].table.body.length,Math.max(data.rows.length,1)+1));}
  if(report.testCase==='untrusted-text'){const dataRow=sheet.getRow(Object.keys(report.summary).length+5);assert.equal(dataRow.getCell(2).value,"'"+report.rows[0][1]);assert.equal(dataRow.getCell(4).value,0.001);assert.ok(JSON.stringify(pdfDefinition).includes('0.001'));assert.ok(created.some(element=>element.tag==='td'&&element.textContent===report.rows[0][2]),'Untrusted names render as text.');}
  const previous=blobs.size;element('reportFilters').handlers.input();await element('reportExcel').handlers.click();assert.equal(blobs.size,previous,'Do not export stale results after filters change.');
  if(report.type==='sales'&&report.rows.length){
   const NativeFormData=global.FormData;global.FormData=class{*[Symbol.iterator](){yield['q','test'];}};
   const submit=()=>element('reportFilters').handlers.submit({preventDefault(){}});
   let finish;context.erp.web=()=>new Promise(resolve=>finish=resolve);
   const loading=submit();element('reportFilters').handlers.input();finish({data:report});await loading;
   assert.equal(element('reportExcel').disabled,true,'Editing filters during a request must not enable a stale export.');
   context.erp.web=async()=>({data:report});await submit();assert.equal(element('reportExcel').disabled,false,'A successful retry restores exports.');
   context.erp.web=async()=>{throw Error('Failed request');};await submit();assert.equal(element('reportExcel').disabled,true);assert.match(element('reportStatus').textContent,/Previous results remain visible/);
   const requests=[];context.erp.web=(_,options)=>new Promise(resolve=>requests.push({resolve,signal:options.signal}));
   const older=submit(),newer=submit();assert.equal(requests[0].signal.aborted,true);requests[1].resolve({data:{...report,rows:[]}});await newer;requests[0].resolve({data:report});await older;
   assert.equal(element('reportStatus').textContent,'0 records shown','A late response must not replace the newest snapshot.');global.FormData=NativeFormData;
   console.log('PASS: filter edits, failed requests, retries and out-of-order responses preserve safe export state.');
  }
  console.log('PASS: '+report.type+' produces valid Excel/PDF containing every filtered row, scope, and totals.');
 }
})().catch(error=>{console.error(error);process.exitCode=1;});
