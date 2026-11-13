/* Display is advisory. The posting service checks current credit again under row locks. */
(function (root) {
    'use strict';
    let config, selectedId = null, status = null, requestVersion = 0;
    const cents = value => Math.round(Number(value) * 100);
    const money = value => '$' + Number(value).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});

    function evaluate(credit, total, paid) {
        const totalCents = Math.max(0, cents(total));
        const unpaid = Math.max(0, totalCents - Math.max(0, cents(paid)));
        const available = Math.max(0, cents(credit.available_credit));
        return {allowed: unpaid === 0 || unpaid <= available, unpaid: unpaid / 100,
            minimumPayment: Math.max(0, totalCents - available) / 100};
    }

    function render(values) {
        if (!config) return;
        document.getElementById('posCreditPanel').hidden = !selectedId;
        if (!selectedId || !status) return;
        for (const [id, key] of [['posCreditLimit', 'credit_limit'], ['posCreditOutstanding', 'outstanding'], ['posCreditAvailable', 'available_credit']]) {
            document.getElementById(id).textContent = money(status[key]);
        }
        const result = evaluate(status, values.grandTotal, values.paid);
        const message = document.getElementById('posCreditMessage');
        message.textContent = !result.allowed ? `Pay at least ${money(result.minimumPayment)} on this order, or collect an outstanding receipt first.`
            : status.over_limit ? 'Existing debt exceeds the limit. New orders must be paid in full.'
            : 'Credit is checked again when the sale is saved. Unused deposits are separate.';
        message.className = 'text-[11px] mt-2 ' + (!result.allowed || status.over_limit ? 'text-amber-800' : 'text-slate-500');
    }

    function accept(data) {
        if (Number(data?.customer_id) !== selectedId) return;
        ++requestVersion;
        status = data;
        render(config.values());
    }

    async function refresh() {
        if (!config || !selectedId) return;
        const version = ++requestVersion;
        const id = selectedId;
        const message = document.getElementById('posCreditMessage');
        message.textContent = 'Checking current credit…';
        try {
            const response = await fetch(config.baseUrl + '/' + id + '/credit', {credentials: 'same-origin', cache: 'no-store', headers: {Accept: 'application/json'}});
            const result = await response.json();
            if (!response.ok || !result.success) throw new Error('Credit lookup failed.');
            if (version === requestVersion && id === selectedId) accept(result.data);
        } catch (_error) {
            if (version === requestVersion && id === selectedId) {
                message.textContent = 'Could not refresh credit. Try Refresh; checkout will still validate the latest balance.';
                message.className = 'text-[11px] mt-2 text-amber-800';
            }
        }
    }

    function select(selectElement) {
        ++requestVersion;
        selectedId = Number(selectElement.value) || null;
        const option = selectElement.options[selectElement.selectedIndex];
        status = selectedId ? {customer_id: selectedId, credit_limit: Number(option.dataset.creditLimit || 0),
            outstanding: Number(option.dataset.outstanding || 0), available_credit: Number(option.dataset.availableCredit || 0)} : null;
        if (status) status.over_limit = status.outstanding > status.credit_limit;
        render(config.values());
        void refresh();
    }

    function init(settings) {
        config = settings;
        document.getElementById('posCreditReload').addEventListener('click', refresh);
        root.addEventListener('focus', refresh);
        select(document.getElementById('posCustomerSelect'));
    }

    root.ERPPosCredit = {init, select, refresh, accept, render, evaluate};
})(window);
