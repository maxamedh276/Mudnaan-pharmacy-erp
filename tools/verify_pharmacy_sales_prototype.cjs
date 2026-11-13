/** Verify the offline presentation and export its complete print view. No application/database access. */
const fs=require('fs');
const path=require('path');
const assert=require('node:assert/strict');
const {pathToFileURL}=require('url');
const {chromium}=require('../.tools/browser/node_modules/playwright');
const root=path.resolve(__dirname,'..');
const out=path.join(root,'deliverables/pharmacy-sales-prototype');
(async()=>{
 const browser=await chromium.launch({executablePath:'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',headless:true});
 try{
 const context=await browser.newContext({viewport:{width:1440,height:1050}});
 const page=await context.newPage();
 const errors=[];const network=[];
 page.on('pageerror',e=>errors.push(e.message));
 page.on('request',r=>{if(/^https?:/.test(r.url()))network.push(r.url());});
 await page.goto(pathToFileURL(path.join(out,'index.html')).href,{waitUntil:'load'});
 assert.equal(await page.locator('[data-module]').count(),9);
 assert.equal(await page.locator('[data-page]').count(),11);
 await page.screenshot({path:path.join(out,'Presentation-Preview.png')});
 let visited=0;
 for(const moduleId of ['home','inventory','clinical','sales','suppliers','clients','finance','reports','users']){
  await page.locator(`[data-module="${moduleId}"]`).click();
  const routes=await page.locator('[data-page]').evaluateAll(els=>els.map(el=>el.dataset.page));
  for(const route of routes){
   await page.locator(`[data-page="${route}"]`).click();
   assert.ok((await page.locator('#pageDetail .purpose').textContent()).length>20);
   assert.equal(await page.locator('#pageDetail .info').count(),3);
   visited++;
  }
 }
 assert.equal(visited,47);
 await page.locator('#search').fill('xyz-unknown');
 assert.equal(await page.locator('[data-module]').count(),0);
 await page.locator('#search').fill('units');
 assert.ok(await page.locator('[data-module]').count()>0);
 await page.locator('#search').fill('');
 await page.locator('[data-module="inventory"]').click();
 await page.locator('[data-page="pharmacy/medicines"]').click();
 await page.locator('[data-page="pharmacy/medicines"]').press('ArrowRight');
 assert.equal(await page.locator('[data-page="inventory/products"]').getAttribute('aria-selected'),'true');
 const flows=await page.locator('#flowList [data-flow]').evaluateAll(els=>els.map(e=>e.dataset.flow));
 assert.equal(flows.length,14);
 for(const id of flows){await page.locator(`#flowList [data-flow="${id}"]`).click();assert.ok(await page.locator('#flowPanel .step').count()>=3);}
 await page.locator('#flowList [data-flow="sale"]').click();
 await page.locator('#flowPanel [data-open-page]').first().click();
 assert.equal(await page.locator('#pageDetail h3').textContent(),'Dispensing Workspace');
 assert.equal(await page.locator('#calcQty').textContent(),'15 base units');
 await page.locator('#strips').fill('2');
 await page.locator('#tablets').fill('4');
 assert.equal(await page.locator('#calcQty').textContent(),'24 base units');
 assert.equal(await page.locator('#calcPrice').textContent(),'Demo total: $20.00');
 await page.locator('#tablets').fill('101');
 assert.match(await page.locator('#calcError').textContent(),/stock/i);
 await page.locator('#strips').fill('1');await page.locator('#tablets').fill('5');
 await page.locator('[data-shot="0"]').click();
 assert.equal(await page.locator('#imageDialog').evaluate(el=>el.open),true);
 await page.keyboard.press('Escape');
 assert.equal(await page.locator('#imageDialog').evaluate(el=>el.open),false);
 await page.setViewportSize({width:390,height:844});
 await page.locator('[data-module="inventory"]').click();
 await page.locator('[data-page="pharmacy/medicines"]').click();
 assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'Mobile horizontal overflow');
 await page.evaluate(()=>scrollTo(0,0));
 await page.screenshot({path:path.join(out,'Presentation-Mobile.png'),fullPage:true});
 await page.setViewportSize({width:1440,height:1050});
 await page.evaluate(()=>{renderBook();document.querySelectorAll('#printBook img').forEach(img=>img.loading='eager');});
 await page.evaluate(()=>Promise.all([...document.querySelectorAll('#printBook img')].map(img=>img.decode())));
 assert.equal(await page.locator('[data-book-page]').count(),47);
 await page.emulateMedia({media:'print'});
 assert.equal(await page.locator('#printBook').isVisible(),true);
 await page.pdf({path:path.join(out,'Mudnaan-Pharmacy-Product-Guide.pdf'),format:'A4',printBackground:true,displayHeaderFooter:true,headerTemplate:'<span></span>',footerTemplate:'<div style="font-family:Arial;font-size:8px;color:#62747b;width:100%;margin:0 15mm;display:flex;justify-content:space-between"><span>Mudnaan Pharmacy · Product & Workflow Guide</span><span><span class="pageNumber"></span> / <span class="totalPages"></span></span></div>',margin:{top:'16mm',bottom:'18mm',left:'15mm',right:'15mm'}});
 assert.deepEqual(errors,[]);assert.deepEqual(network,[]);
 const pdf=fs.readFileSync(path.join(out,'Mudnaan-Pharmacy-Product-Guide.pdf'));
 assert.equal(pdf.subarray(0,4).toString(),'%PDF');
 const report={workspacesVerified:visited,workflowsVerified:flows.length,printWorkspaces:47,offlineNetworkRequests:network.length,browserErrors:errors.length,mobileOverflow:false,pdfBytes:pdf.length,checks:['All module and workspace tabs','Search empty/matched recovery','Keyboard tabs','Workflow links','Unit calculation and insufficient-stock state','Screenshot dialog and Escape','390px responsive layout','Complete print view and PDF export']};
 fs.writeFileSync(path.join(out,'Validation.json'),JSON.stringify(report,null,2));
 console.log(JSON.stringify(report,null,2));
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exit(1);});
