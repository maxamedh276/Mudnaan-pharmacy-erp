/** Exercise production mobile navigation, cart mirroring and breakpoint changes without transactions. */
const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
function element(text='') { return {textContent:text,dataset:{},attributes:{},handlers:{},inert:false,focused:false,
    classList:{values:new Set(),toggle(key,on){if(on)this.values.add(key);else this.values.delete(key);}},
    addEventListener(type,handler){this.handlers[type]=handler;},setAttribute(key,value){this.attributes[key]=value;},removeAttribute(key){delete this.attributes[key];},
    focus(){this.focused=true;},getClientRects(){return[{}];}}; }
function media() { return {matches:false,addEventListener(_,handler){this.change=handler;}}; }
{
    const ids=Object.fromEntries(['posWorkspace','posCatalogPanel','posTicketPanel','posTicketTitle','mobilePosReview','cartGrandTotalLabel','posItemsCountBadge','mobilePosTotal','mobilePosItems','mobilePosCount','productsGrid'].map(id=>[id,element()]));
    ids.cartGrandTotalLabel.textContent='$0.00';ids.posItemsCountBadge.textContent='0 items in ticket';
    const catalog=element(),ticket=element();catalog.dataset.posViewButton='catalog';ticket.dataset.posViewButton='ticket';
    const desktop=media(),observers=[];
    const context={window:{matchMedia:()=>desktop},document:{getElementById:id=>ids[id],querySelectorAll:()=>[catalog,ticket]},MutationObserver:class {constructor(handler){observers.push(handler);}observe(){}}};
    vm.runInNewContext(fs.readFileSync('assets/js/pos-layout.js','utf8'),context);
    assert.equal(ids.posCatalogPanel.inert,false);assert.equal(ids.posTicketPanel.inert,true);
    ids.mobilePosReview.handlers.click();assert.equal(ids.posWorkspace.dataset.posView,'ticket');assert.equal(ids.posCatalogPanel.inert,true);assert.equal(ids.posTicketPanel.inert,false);assert.equal(ids.posTicketTitle.focused,true);
    desktop.matches=true;desktop.change();assert.equal(ids.posCatalogPanel.inert,false);assert.equal(ids.posTicketPanel.inert,false);
    desktop.matches=false;desktop.change();assert.equal(ids.posCatalogPanel.inert,true,'Returning to mobile retains the current order view.');
    context.window.posLayout.showCatalog();assert.equal(ids.posCatalogPanel.inert,false,'Search shortcut can reveal its target on mobile.');
    catalog.handlers.click();assert.equal(ids.posWorkspace.dataset.posView,'catalog');assert.equal(catalog.attributes['aria-pressed'],'true');
    ids.cartGrandTotalLabel.textContent='$1,234.56';ids.posItemsCountBadge.textContent='3 items in ticket';observers[0]();assert.equal(ids.mobilePosTotal.textContent,'$1,234.56');assert.equal(ids.mobilePosItems.textContent,'3 items');assert.equal(ids.mobilePosCount.textContent,'3');
    ids.cartGrandTotalLabel.textContent='$0.00';ids.posItemsCountBadge.textContent='0 items in ticket';observers[0]();assert.equal(ids.mobilePosTotal.textContent,'$0.00');assert.equal(ids.mobilePosCount.textContent,'0');
    let clicks=0,prevented=0;const card={dataset:{available:'1'},closest(){return this;},click(){clicks++;}};
    ids.productsGrid.handlers.keydown({target:card,key:'Enter',preventDefault(){prevented++;}});card.dataset.available='0';ids.productsGrid.handlers.keydown({target:card,key:' ',preventDefault(){prevented++;}});
    assert.equal(clicks,1);assert.equal(prevented,1);
    console.log('PASS: POS switches views, restores desktop access, mirrors existing totals and supports keyboard products.');
}
{
    const sidebar=element(),content=element(),toggle=element(),backdrop=element(),main=element();const first=element(),last=element();
    sidebar.querySelector=()=>first;sidebar.querySelectorAll=()=>[first,last];
    let wrappers=0;const tables=[];
    main.querySelectorAll=()=>tables;
    const desktop=media(),observers=[],handlers={};
    const document={activeElement:first,getElementById:id=>({'app-sidebar':sidebar,'app-content':content,'mobile-menu-toggle':toggle,'mobile-backdrop':backdrop,'app-main':main}[id]),
        addEventListener(type,handler){handlers[type]=handler;},createElement(){const wrapper=element();wrapper.append=table=>table.wrapper=wrapper;return wrapper;}};
    function newTable(contained=false){const table={wrapper:contained,closest(){return this.wrapper;},getAttribute(){return null;},parentNode:{insertBefore(){wrappers++;}}};tables.push(table);return table;}
    newTable(true);newTable();
    const context={window:{matchMedia:()=>desktop},document,queueMicrotask:handler=>handler(),MutationObserver:class{constructor(handler){observers.push(handler);}observe(){}}};
    vm.runInNewContext(fs.readFileSync('assets/js/responsive-layout.js','utf8'),context);
    assert.equal(sidebar.inert,true);assert.equal(content.inert,false);assert.equal(wrappers,1);
    context.window.erpLayout.toggleSidebar();assert.equal(sidebar.inert,false);assert.equal(content.inert,true);assert.equal(toggle.attributes['aria-expanded'],'true');
    let prevented=false;document.activeElement=last;handlers.keydown({key:'Tab',shiftKey:false,preventDefault(){prevented=true;}});assert.equal(prevented,true);assert.equal(first.focused,true);
    handlers.keydown({key:'Escape',preventDefault(){}});assert.equal(sidebar.inert,true);assert.equal(content.inert,false);assert.equal(toggle.focused,true);
    context.window.erpLayout.toggleSidebar();desktop.matches=true;desktop.change();assert.equal(sidebar.inert,false);assert.equal(content.inert,false);assert.equal(sidebar.attributes['aria-modal'],undefined);
    const added=newTable();observers[0]();assert.equal(wrappers,2);assert.equal(added.wrapper.tabIndex,0);observers[0]();assert.equal(wrappers,2,'AJAX updates must not nest or duplicate scroll wrappers.');
    console.log('PASS: Mobile drawer traps/restores focus, handles Escape and resize, and contains new tables once.');
}
