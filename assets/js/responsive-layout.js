/** Accessible mobile navigation and contained tables, including AJAX-inserted content. */
(() => {
    'use strict';
    const sidebar = document.getElementById('app-sidebar'), content = document.getElementById('app-content');
    const toggle = document.getElementById('mobile-menu-toggle'), backdrop = document.getElementById('mobile-backdrop');
    const desktop = window.matchMedia('(min-width: 1024px)');
    let open = false;
    function setOpen(value, moveFocus = true) {
        open = !desktop.matches && value;
        sidebar.classList.toggle('-translate-x-full',!open);
        backdrop.classList.toggle('hidden',!open);
        toggle.setAttribute('aria-expanded',String(open));
        sidebar.inert = !desktop.matches && !open; content.inert = open;
        if (open) {
            sidebar.setAttribute('role','dialog'); sidebar.setAttribute('aria-modal','true');
            if (moveFocus) sidebar.querySelector('button, a[href]')?.focus();
        } else {
            sidebar.removeAttribute('role'); sidebar.removeAttribute('aria-modal');
            if (moveFocus && !desktop.matches) toggle.focus();
        }
    }
    window.erpLayout = {toggleSidebar:() => setOpen(!open),closeSidebar:() => setOpen(false,false)};
    desktop.addEventListener('change',() => setOpen(false,false));
    sidebar.addEventListener('click',event => { if (event.target.closest('a[href]') && open) setOpen(false,false); });
    document.addEventListener('keydown',event => {
        if (!open) return;
        if (event.key === 'Escape') { event.preventDefault(); setOpen(false); return; }
        if (event.key !== 'Tab') return;
        const targets = [...sidebar.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), [tabindex="0"]')].filter(element => element.getClientRects().length);
        const first = targets[0], last = targets.at(-1);
        if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last?.focus(); }
        else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus(); }
    });
    setOpen(false,false);
    const main = document.getElementById('app-main');
    function containTables() {
        main.querySelectorAll('table').forEach(table => {
            if (table.closest('.overflow-x-auto, .report-table-wrap, .erp-table-scroll, .dataTables_scrollBody')) return;
            const wrapper = document.createElement('div'); wrapper.className = 'erp-table-scroll';
            wrapper.tabIndex = 0; wrapper.setAttribute('role','region');
            wrapper.setAttribute('aria-label',table.getAttribute('aria-label') || 'Scrollable table');
            table.parentNode.insertBefore(wrapper,table); wrapper.append(table);
        });
    }
    containTables();
    let pending = false;
    new MutationObserver(() => {
        if (pending) return; pending = true;
        queueMicrotask(() => { pending = false; containTables(); });
    }).observe(main,{childList:true,subtree:true});
})();
