/** Presentation only: one cart and one checkout remain shared by all screen sizes. */
(() => {
    'use strict';
    const root = document.getElementById('posWorkspace');
    if (!root) return;
    const catalog = document.getElementById('posCatalogPanel'), ticket = document.getElementById('posTicketPanel');
    const buttons = [...document.querySelectorAll('[data-pos-view-button]')];
    const desktop = window.matchMedia('(min-width: 1024px)');
    function show(view, focus = false) {
        if (!['catalog','ticket'].includes(view)) return;
        if (window.jQuery?.fn?.select2) window.jQuery('.select2-hidden-accessible').select2('close');
        root.dataset.posView = view;
        catalog.inert = !desktop.matches && view !== 'catalog';
        ticket.inert = !desktop.matches && view !== 'ticket';
        buttons.forEach(button => button.setAttribute('aria-pressed',String(button.dataset.posViewButton === view)));
        if (focus && !desktop.matches) {
            const target = view === 'ticket' ? document.getElementById('posTicketTitle') : buttons.find(button => button.dataset.posViewButton === 'catalog');
            target?.focus({preventScroll:true});
        }
    }
    buttons.forEach(button => button.addEventListener('click',() => show(button.dataset.posViewButton,true)));
    window.posLayout = {showCatalog:() => show('catalog')};
    document.getElementById('mobilePosReview').addEventListener('click',() => show('ticket',true));
    desktop.addEventListener('change',() => {
        show(root.dataset.posView);
        if (document.activeElement?.closest('[inert]')) buttons.find(button => button.dataset.posViewButton === root.dataset.posView)?.focus();
    });
    function refreshSummary() {
        const total = document.getElementById('cartGrandTotalLabel').textContent;
        const count = document.getElementById('posItemsCountBadge').textContent.replace(' in ticket','');
        document.getElementById('mobilePosTotal').textContent = total;
        document.getElementById('mobilePosItems').textContent = count;
        document.getElementById('mobilePosCount').textContent = count.split(' ')[0];
    }
    const observer = new MutationObserver(refreshSummary);
    ['cartGrandTotalLabel','posItemsCountBadge'].forEach(id => observer.observe(document.getElementById(id),{childList:true,characterData:true,subtree:true}));
    document.getElementById('productsGrid').addEventListener('keydown',event => {
        const card = event.target.closest('.pos-product-card');
        if (card !== event.target || card?.dataset.available !== '1' || !['Enter',' '].includes(event.key)) return;
        event.preventDefault(); card.click();
    });
    show(root.dataset.posView || 'catalog'); refreshSummary();
})();
