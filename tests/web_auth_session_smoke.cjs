const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync('assets/js/web-auth-session.js', 'utf8');
function run(authenticated, storageBlocked = false) {
    const events = {}, removed = [], navigations = [];
    let reloads = 0;
    const window = { ERP_TOKEN: 'old-token', addEventListener: (name, callback) => events[name] = callback,
        location: { replace: url => navigations.push(url), reload: () => reloads++ } };
    vm.runInNewContext(source, {window, document: {currentScript: {dataset: {authenticated, loginUrl: '/erp/login'}}},
        localStorage: {removeItem: key => { if (storageBlocked) throw Error('Storage blocked'); removed.push(key); }} });
    return {window, events, removed, navigations, reloads: () => reloads};
}
const login = run('false');
assert.deepEqual(login.removed, ['mudnaan_erp_token']);
assert.equal(login.window.ERP_TOKEN, '');
assert.doesNotThrow(() => run('false', true));
const app = run('true');
app.events.storage({key: 'theme', newValue: null});
app.events.storage({key: 'mudnaan_erp_token', newValue: 'new-token'});
assert.equal(app.navigations.length, 0);
app.events.storage({key: 'mudnaan_erp_token', newValue: null});
assert.equal(app.window.ERP_TOKEN, '');
assert.deepEqual(app.navigations, ['/erp/login']);
app.events.pageshow({persisted: false});
assert.equal(app.reloads(), 0);
app.events.pageshow({persisted: true});
assert.equal(app.reloads(), 1);
console.log('PASS: Login clears cached API credentials, blocked storage is safe, other tabs follow logout, and Back revalidates protected pages.');
