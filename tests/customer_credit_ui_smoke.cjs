const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const nodes = new Map();
for (const id of ['posCreditPanel', 'posCreditLimit', 'posCreditOutstanding', 'posCreditAvailable', 'posCreditMessage', 'posCreditReload', 'posCustomerSelect']) {
    nodes.set(id, {textContent: '', className: '', hidden: true, addEventListener() {}, value: '', selectedIndex: 0, options: [{dataset: {}}]});
}
const requests = [];
const context = {window: {addEventListener() {}}, document: {getElementById: id => nodes.get(id)}, fetch: () => new Promise((resolve, reject) => requests.push({resolve, reject})), console};
vm.runInNewContext(fs.readFileSync('assets/js/pos-credit.js', 'utf8'), context);
const credit = context.window.ERPPosCredit;
let values = {grandTotal: 40, paid: 0};
credit.init({baseUrl: '/clients', values: () => values});
assert.equal(nodes.get('posCreditPanel').hidden, true);
function select(id) {
    const element = nodes.get('posCustomerSelect'); element.value = String(id); element.options[0].dataset = {creditLimit: '100', outstanding: '80', availableCredit: '20'};
    credit.select(element);
}
const tick = () => new Promise(resolve => setImmediate(resolve));
const response = data => ({ok: true, json: async () => ({success: true, data})});
(async () => {
    assert.equal(credit.evaluate({available_credit: 20}, 40, 20).allowed, true);
    assert.equal(credit.evaluate({available_credit: 20}, 40, 19.99).allowed, false);
    assert.equal(credit.evaluate({available_credit: 0}, 40, 40).allowed, true);
    select(1);
    assert.equal(nodes.get('posCreditAvailable').textContent, '$20.00');
    assert.match(nodes.get('posCreditMessage').textContent, /Checking/);
    select(2);
    requests[1].resolve(response({customer_id: 2, credit_limit: 50, outstanding: 40, available_credit: 10, over_limit: false})); await tick();
    requests[0].resolve(response({customer_id: 1, credit_limit: 999, outstanding: 0, available_credit: 999})); await tick();
    assert.equal(nodes.get('posCreditLimit').textContent, '$50.00');
    assert.match(nodes.get('posCreditMessage').textContent, /Pay at least \$30\.00/);
    values.paid = 30; credit.render(values); assert.doesNotMatch(nodes.get('posCreditMessage').textContent, /Pay at least/);
    credit.refresh(); requests[2].reject(new Error('offline')); await tick();
    assert.match(nodes.get('posCreditMessage').textContent, /Could not refresh/);
    assert.equal(nodes.get('posCreditOutstanding').textContent, '$40.00');
    credit.refresh();
    credit.accept({customer_id: 2, credit_limit: 0, outstanding: 40, available_credit: 0, over_limit: true});
    requests[3].resolve(response({customer_id: 2, credit_limit: 999, outstanding: 0, available_credit: 999})); await tick();
    assert.equal(nodes.get('posCreditLimit').textContent, '$0.00');
    values.paid = 40; credit.render(values); assert.match(nodes.get('posCreditMessage').textContent, /paid in full/);
    select(''); assert.equal(nodes.get('posCreditPanel').hidden, true);
    console.log('PASS: POS credit handles cent boundaries, minimum payment, paid-in-full purchases, customer-switch races, refresh failure and recoverable server rejection.');
})().catch(error => { console.error(error); process.exitCode = 1; });
