/** In-place dashboard refreshes retain the last good snapshot and ignore stale responses. */
(() => {
    'use strict';
    const root = document.getElementById('roleDashboard');
    if (!root) return;
    const form = document.getElementById('dashboardFilters');
    const range = document.getElementById('dashboardRange');
    const panels = document.getElementById('dashboardPanels');
    const loading = document.getElementById('dashboardLoading');
    const dialog = document.getElementById('dashboardOrderDialog');
    const orderBody = document.getElementById('dashboardOrderBody');
    let appliedRange = range.value, filter = 'all', revision = 0, pending, orderRequest, orderRevision = 0, lastOrderButton;
    const money = value => '$' + Number(value || 0).toFixed(2);
    const escape = value => String(value ?? '').replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
    function filterOrders() {
        const search = (panels.querySelector('#dashboardOrderSearch')?.value || '').trim().toLocaleLowerCase();
        let visible = 0;
        panels.querySelectorAll('[data-dashboard-order]').forEach(row => {
            const matches = (filter === 'all' || row.dataset[filter] === '1') && row.textContent.toLocaleLowerCase().includes(search);
            row.hidden = !matches;
            if (matches) visible++;
        });
        const empty = panels.querySelector('#dashboardNoOrders');
        if (empty) empty.hidden = visible > 0;
        panels.querySelectorAll('[data-dashboard-filter]').forEach(button => button.setAttribute('aria-pressed', String(button.dataset.dashboardFilter === filter)));
    }
    async function refresh(event) {
        event?.preventDefault();
        const current = ++revision, requested = range.value;
        let succeeded = false;
        pending?.abort(); pending = new AbortController();
        panels.setAttribute('aria-busy', 'true'); loading.classList.remove('hidden'); loading.textContent = 'Updating dashboard…';
        try {
            const {data} = await erp.web(`${root.dataset.url}?range=${encodeURIComponent(requested)}`, {signal:pending.signal});
            if (current !== revision) return;
            if (data.profile !== root.dataset.profile) { panels.replaceChildren(); loading.textContent = 'Your role changed. Reloading dashboard…'; location.reload(); return; }
            panels.innerHTML = data.html;
            appliedRange = data.range;
            range.value = appliedRange;
            filter = 'all'; filterOrders();
            document.getElementById('dashboardDates').textContent = `${data.from} — ${data.to}`;
            window.lucide?.createIcons();
            const url = new URL(location.href); url.searchParams.set('range', appliedRange); history.replaceState(null, '', url);
            succeeded = true;
        } catch (error) {
            if (current !== revision || error.name === 'AbortError') return;
            range.value = appliedRange;
            window.jQuery?.(range).trigger('change.select2');
            if (error.status === 403) {
                panels.replaceChildren();
                loading.textContent = 'Your dashboard access changed. Refresh the page to continue.';
            } else loading.textContent = 'The last successful dashboard is still shown. Change the period to retry.';
        } finally {
            if (current === revision) {
                panels.setAttribute('aria-busy', 'false');
                if (succeeded) loading.classList.add('hidden');
            }
        }
    }
    range.addEventListener('change', refresh);
    form.addEventListener('submit', refresh);
    panels.addEventListener('input', event => { if (event.target.id === 'dashboardOrderSearch') filterOrders(); });
    panels.addEventListener('click', async event => {
        const button = event.target.closest('[data-dashboard-filter]');
        if (button) { filter = button.dataset.dashboardFilter; filterOrders(); return; }
        const view = event.target.closest('[data-dashboard-view]');
        if (!view || view.disabled) return;
        const current = ++orderRevision;
        orderRequest?.abort(); orderRequest = new AbortController();
        lastOrderButton = view; view.disabled = true;
        document.getElementById('dashboardOrderTitle').textContent = 'Order details';
        orderBody.textContent = 'Loading order…';
        dialog.showModal();
        try {
            const {data:r} = await erp.web(`${root.dataset.orderUrl}/${view.dataset.dashboardView}`, {signal:orderRequest.signal});
            if (current !== orderRevision || !dialog.open) return;
            document.getElementById('dashboardOrderTitle').textContent = r.reference;
            orderBody.innerHTML = `<p class="text-xs text-slate-500 mb-3">${escape(r.customer)} · ${escape(r.date)}</p><div class="overflow-x-auto"><table class="w-full text-left text-xs"><thead class="bg-slate-50"><tr><th class="p-2">Original item</th><th class="p-2 text-right">Qty</th><th class="p-2 text-right">Price</th><th class="p-2 text-right">Total</th></tr></thead><tbody>${r.items.map(i=>`<tr class="border-b border-slate-100"><td class="p-2">${escape(i.description)}</td><td class="p-2 text-right">${Number(i.quantity)}</td><td class="p-2 text-right">${money(i.unit_price)}</td><td class="p-2 text-right">${money(i.total)}</td></tr>`).join('')}</tbody></table></div><dl class="grid grid-cols-2 gap-2 text-xs mt-4">${[['Original total',r.original_total],['Returned',r.returned],['Net total',r.net_total],['Settled (includes waived discounts)',r.settled],['Refunds',r.refunds],['Balance due',r.due]].map(([label,value])=>`<dt class="text-slate-500">${label}</dt><dd class="text-right font-semibold tabular-nums">${money(value)}</dd>`).join('')}</dl><p class="mt-3 text-[10px] text-slate-400">Order details show current balances.</p>`;
        } catch (error) { if (error.name !== 'AbortError' && current === orderRevision) orderBody.textContent = 'Order details could not be loaded. Close this window and try again.'; }
        finally { view.disabled = false; }
    });
    function closeOrder() { orderRevision++; orderRequest?.abort(); dialog.close(); lastOrderButton?.focus(); }
    document.getElementById('closeDashboardOrder').addEventListener('click', closeOrder);
    dialog.addEventListener('cancel', event => { event.preventDefault(); closeOrder(); });
    if (root.dataset.notice) erp.toast(root.dataset.notice, 'warning');
})();
