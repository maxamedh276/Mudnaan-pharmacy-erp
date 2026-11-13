/** Compact tabs keep each business screen mounted, including its forms and scripts. */
(() => {
    'use strict';
    if (document.body.classList.contains('workspace-embedded')) {
        // Isolate legacy screen scripts while presenting them as one workspace panel.
        const embedUrl = value => {
            const url = new URL(value, location.href);
            if (url.origin === location.origin) url.searchParams.set('workspace_panel','1');
            return url;
        };
        document.addEventListener('click', event => {
            const link = event.target.closest('a[href]');
            if (!link || link.target || link.hasAttribute('download') || event.defaultPrevented || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
            const url = new URL(link.href,location.href);
            if (url.origin !== location.origin || url.hash || /\/logout$/.test(url.pathname)) return;
            link.href = embedUrl(link.href).href;
        });
        document.querySelectorAll('form').forEach(form => {
            if (form.method.toLowerCase() !== 'get') return;
            const input=document.createElement('input'); input.type='hidden'; input.name='workspace_panel'; input.value='1'; form.append(input);
        });
        let scheduled;
        const resize = () => {
            cancelAnimationFrame(scheduled);
            scheduled=requestAnimationFrame(()=>parent.postMessage({type:'pharmacy-panel-size',height:Math.ceil(document.getElementById('app-main').scrollHeight)},location.origin));
        };
        new ResizeObserver(resize).observe(document.querySelector('.erp-page'));
        let modalOpen;
        const reportModal = () => {
            const visible = [...document.querySelectorAll('dialog[open], .fixed.inset-0, .swal2-container')].some(element =>
                (element.matches('dialog[open], .swal2-container') || element.querySelector('form')) && element.getClientRects().length && getComputedStyle(element).visibility !== 'hidden');
            if (visible === modalOpen) return;
            modalOpen = visible;
            document.body.dataset.workspaceModal = String(visible);
            parent.postMessage({type:'pharmacy-panel-modal',open:visible},location.origin);
        };
        new MutationObserver(reportModal).observe(document.body,{subtree:true,childList:true,attributes:true,attributeFilter:['class','style','open']});
        reportModal();
        window.addEventListener('load',resize); resize();
        return;
    }
    let pending;
    const main = document.getElementById('app-main');
    const modalSiblings = new Map();
    let modalFrame;
    function setPanelModal(frame, open) {
        frame.classList.toggle('workspace-modal-frame',open);
        if (open) {
            modalFrame=frame;
            // Keep background navigation out of the keyboard order while the modal owns focus.
            for (let node=frame;node && node!==document.body;node=node.parentElement) {
                for (const sibling of node.parentElement?.children || []) {
                    if(sibling===node || modalSiblings.has(sibling))continue;
                    modalSiblings.set(sibling,sibling.inert);sibling.inert=true;
                }
            }
        } else {
            if(modalFrame===frame){modalSiblings.forEach((inert,element)=>{element.inert=inert;});modalSiblings.clear();modalFrame=null;}
            fitPanel(frame);
        }
    }
    function fitPanel(frame) {
        if(frame.classList.contains('workspace-modal-frame'))return;
        // A bounded viewport keeps fixed dialogs and their action buttons reachable.
        frame.style.height=Math.max(420,window.innerHeight-frame.getBoundingClientRect().top-18)+'px';
    }
    function activatePage(button) {
        let panels=main.querySelector('[data-workspace-panels]');
        const hub=main.querySelector('[data-workspace-hub]');
        if (!panels && hub) {
            [...hub.children].forEach(child=>{child.hidden=true;});
            const status=document.createElement('div');status.className='workspace-panel-status';status.setAttribute('role','status');hub.append(status);
            panels=document.createElement('div');panels.dataset.workspacePanels='';hub.append(panels);
        }
        if (!panels) {
            const module=main.querySelector('[data-active-module]').dataset.activeModule;
            const home=document.querySelector('.sidebar-module-card[data-module="'+module+'"]');
            if (home) navigate(home.href,true,button.dataset.workspacePage);
            return;
        }
        const tabs=button.closest('[role=tablist]');
        tabs.querySelectorAll('[data-workspace-page]').forEach(tab=>{
            const selected=tab===button; tab.setAttribute('aria-selected',String(selected)); tab.tabIndex=selected?0:-1;
        });
        let frame=[...panels.querySelectorAll('iframe')].find(item=>item.dataset.page===button.dataset.workspacePage);
        if (!frame) {
            frame=document.createElement('iframe');
            frame.dataset.page=button.dataset.workspacePage;
            const url=new URL(button.dataset.workspacePage);url.searchParams.set('workspace_panel','1');
            frame.src=url.href;frame.title=button.textContent.trim();frame.className='workspace-content-frame';
            frame.setAttribute('role','tabpanel');frame.id='workspace-content-'+(panels.children.length+1);
            const status=main.querySelector('.workspace-panel-status');
            status.hidden=false;status.textContent='Loading '+frame.title+'…';
            frame.addEventListener('load',()=>{
                if (!frame.hidden) status.hidden=true;
                try {
                    setPanelModal(frame,frame.contentDocument.body.dataset.workspaceModal==='true');
                    if (frame.contentDocument.querySelector('[name=identity]')) { location.assign(frame.contentWindow.location.href);return; }
                    if (!frame.contentDocument.body.classList.contains('workspace-embedded')) {
                        const destination=new URL(frame.contentWindow.location.href);
                        if (destination.searchParams.get('workspace_panel')!=='1') {
                            destination.searchParams.set('workspace_panel','1');frame.contentWindow.location.replace(destination.href);return;
                        }
                    }
                    fitPanel(frame);
                } catch { status.hidden=false;status.textContent='Unable to load this tab. Select the tab again to retry.'; }
            });
            panels.append(frame);
        }
        button.setAttribute('aria-controls',frame.id);
        panels.querySelectorAll('iframe').forEach(item=>{item.hidden=item!==frame;});
        fitPanel(frame);
    }
    function activateGroup(button, moveFocus=false) {
        const navigation=button.closest('.module-workspace');
        navigation.querySelectorAll('[data-workspace-group]').forEach(tab=>{
            const selected=tab===button;tab.setAttribute('aria-selected',String(selected));tab.tabIndex=selected?0:-1;
            document.getElementById(tab.getAttribute('aria-controls')).hidden=!selected;
        });
        if(moveFocus)button.focus();
        const group=document.getElementById(button.getAttribute('aria-controls'));
        const page=group.querySelector('[data-workspace-page][aria-selected=true]')||group.querySelector('[data-workspace-page]');
        if(page)activatePage(page);
    }
    function initializePanels(initialPage) {
        if (!main.querySelector('[data-workspace-panels]')) return;
        const target=initialPage && [...main.querySelectorAll('[data-workspace-page]')].find(tab=>tab.dataset.workspacePage===initialPage);
        if(target){
            const group=target.closest('[role=tabpanel]');
            activateGroup(document.getElementById(group.getAttribute('aria-labelledby')));
            activatePage(target);
        } else {
            const selected=main.querySelector('[data-workspace-group][aria-selected=true]');
            if(selected)activateGroup(selected);
        }
    }
    async function navigate(url, push = true, initialPage = null) {
        pending?.abort();
        const request = new AbortController(); pending = request;
        const page = main.querySelector('.erp-page');
        page.setAttribute('aria-busy', 'true');
        document.querySelector('.workspace-loading')?.remove();
        const loader = document.createElement('div'); loader.className = 'workspace-loading'; loader.setAttribute('role','status'); loader.setAttribute('aria-label','Loading workspace'); document.body.append(loader);
        try {
            const response = await fetch(url, {signal:request.signal, credentials:'same-origin'});
            if (!response.ok) throw new Error('Unable to open this module. Please try again.');
            const documentPage = new DOMParser().parseFromString(await response.text(),'text/html');
            const replacement = documentPage.querySelector('.erp-page');
            if (!replacement?.querySelector('[data-workspace-hub]')) { window.location.assign(response.url); return; }
            page.replaceWith(replacement);
            document.title = documentPage.title;
            if (push) history.pushState({workspace:true},'',url);
            const active = replacement.querySelector('[data-active-module]')?.dataset.activeModule;
            document.querySelectorAll('.sidebar-module-card').forEach(card => {
                if (card.dataset.module === active) card.setAttribute('aria-current','true'); else card.removeAttribute('aria-current');
            });
            window.lucide?.createIcons({root:replacement});
            window.closeSearch?.();
            window.erpLayout?.closeSidebar();
            main.scrollTop = 0;
            const heading = replacement.querySelector('.module-heading h2');
            heading?.setAttribute('tabindex','-1'); heading?.focus({preventScroll:true});
            initializePanels(initialPage);
        } catch (error) {
            if (error.name !== 'AbortError') {
                window.erp?.toast?.(error.message,'error');
                let notice = main.querySelector('.workspace-load-error');
                if (!notice) { notice=document.createElement('div'); notice.className='workspace-load-error'; notice.setAttribute('role','alert'); main.prepend(notice); }
                notice.replaceChildren(document.createTextNode('Unable to load workspace. '));
                const retry=document.createElement('a'); retry.href=url; retry.dataset.moduleLink=''; retry.textContent='Try again'; notice.append(retry);
            }
        } finally {
            if (pending === request) { main.querySelector('.erp-page')?.removeAttribute('aria-busy'); loader.remove(); pending=null; }
        }
    }
    document.addEventListener('click', event => {
        const group=event.target.closest('[data-workspace-group]');
        if(group){activateGroup(group);return;}
        const tab=event.target.closest('[data-workspace-page]');
        if(tab){activatePage(tab);return;}
        const link = event.target.closest('a[data-module-link]');
        if (!link || event.defaultPrevented || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
        event.preventDefault(); main.querySelector('.workspace-load-error')?.remove(); navigate(link.href);
    });
    document.addEventListener('keydown',event=>{
        const tab=event.target.closest('[role=tab]');
        if(!tab||!['ArrowLeft','ArrowRight','Home','End'].includes(event.key))return;
        const tabs=[...tab.closest('[role=tablist]').querySelectorAll('[role=tab]')];
        const index=tabs.indexOf(tab);
        const next=event.key==='Home'?tabs[0]:event.key==='End'?tabs.at(-1):tabs[(index+(event.key==='ArrowRight'?1:tabs.length-1))%tabs.length];
        event.preventDefault();next.focus();next.click();
    });
    window.addEventListener('message',event=>{
        if(event.origin!==location.origin||!['pharmacy-panel-size','pharmacy-panel-modal'].includes(event.data?.type))return;
        const frame=[...main.querySelectorAll('.workspace-content-frame')].find(item=>item.contentWindow===event.source);
        if(frame && event.data.type==='pharmacy-panel-modal'){setPanelModal(frame,event.data.open===true);return;}
        if(frame&&!frame.hidden)fitPanel(frame);
    });
    window.addEventListener('resize',()=>main.querySelectorAll('.workspace-content-frame:not([hidden])').forEach(fitPanel));
    document.getElementById('quick-search-input')?.addEventListener('input', event => {
        const query = event.target.value.trim().toLowerCase();
        document.querySelectorAll('#search-modal a[href]').forEach(link => {
            link.hidden = !link.textContent.toLowerCase().includes(query);
            link.style.display = link.hidden ? 'none' : '';
        });
    });
    window.addEventListener('popstate', () => {
        if (/\/workspace(?:\/|$)/.test(location.pathname) || /\/dashboard$/.test(location.pathname)) navigate(location.href,false);
        else location.reload();
    });
    initializePanels();
})();
