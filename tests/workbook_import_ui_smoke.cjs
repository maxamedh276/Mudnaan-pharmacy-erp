/** Run: node tests/workbook_import_ui_smoke.cjs. Exercises the actual import script; no live requests. */
const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const source = fs.readFileSync('assets/js/workbook-import.js', 'utf8');
let checks = 0;
function check(condition, message) { assert(condition, message); checks++; console.log('PASS: ' + message); }
const settle = () => new Promise(resolve => setImmediate(resolve));

class Element {
    constructor() { this.handlers = {}; this.disabled = false; this.textContent = ''; this.children = []; this.attributes = {}; this.classes = new Set(); this.classList = { add: value => this.classes.add(value), remove: value => this.classes.delete(value) }; }
    addEventListener(name, callback) { this.handlers[name] = callback; }
    emit(name) { return this.handlers[name]?.({ preventDefault() {} }); }
    append(...items) { this.children.push(...items.flatMap(item => item.fragment ? item.children : [item])); }
    replaceChildren(...items) { this.children = items; }
    setAttribute(key, value) { this.attributes[key] = value; }
    removeAttribute(key) { delete this.attributes[key]; }
    showModal() { this.open = true; }
    close() { this.open = false; }
}
function setup() {
    const names = ['workbookImportDialog', 'workbookImportForm', 'workbookImportFile', 'workbookImportStatus', 'workbookImportErrors', 'workbookValidate', 'workbookCommit'];
    const elements = Object.fromEntries(names.map(name => [name, new Element()]));
    const [dialog, form, file, status, errors, validate, commit] = names.map(name => elements[name]);
    const opener = new Element(), cancel = new Element(), tbody = new Element();
    file.files = []; commit.disabled = true;
    dialog.dataset = { endpoint: '/isolated-import' };
    dialog.querySelectorAll = () => [cancel];
    errors.querySelector = () => tbody;
    form.reportValidity = () => file.files.length > 0;
    const requests = [], responses = [], toasts = [];
    let reloads = 0;
    const context = {
        document: { getElementById: id => elements[id], querySelector: () => opener, createElement: () => new Element(), createDocumentFragment: () => Object.assign(new Element(), { fragment: true }) },
        FormData: class { constructor() { this.values = {}; } append(key, value) { this.values[key] = value; } },
        fetch: async (url, options) => {
            requests.push({ url, action: options.body.values.action, file: options.body.values.workbook });
            const next = responses.shift();
            if (typeof next === 'function') return next();
            if (next instanceof Error) throw next;
            return { ok: true, json: async () => next || { success: true, data: { valid: true, count: 2, errors: [] } } };
        },
        erp: { toast: (message, kind) => toasts.push({ message, kind }) },
        window: { location: { reload: () => reloads++ } },
    };
    vm.createContext(context); vm.runInContext(source, context);
    const choose = (name = 'products.xlsx', size = 100) => { file.files = [{ name, size }]; file.emit('change'); };
    return { dialog, form, file, status, errors, validate, commit, opener, cancel, tbody, requests, responses, toasts, choose, reloads: () => reloads };
}
(async () => {
    const ui = setup();
    ui.opener.emit('click'); check(ui.dialog.open, 'Import button opens the dialog.');
    ui.form.emit('submit'); await settle(); check(ui.requests.length === 0, 'Submitting with no file makes no request.');
    ui.choose(); ui.commit.emit('click'); await settle(); check(ui.requests.length === 0, 'Import cannot run before successful validation.');
    ui.choose('mistake.xls'); ui.form.emit('submit'); await settle(); check(ui.requests.length === 0 && ui.commit.disabled, 'Wrong Excel format is rejected locally.');
    ui.choose('too-large.xlsx', 5242881); ui.form.emit('submit'); await settle(); check(ui.requests.length === 0, 'Oversized upload is rejected locally.');
    ui.choose();
    let release;
    ui.responses.push(() => new Promise(resolve => { release = resolve; }));
    ui.form.emit('submit'); ui.form.emit('submit'); ui.commit.emit('click');
    check(ui.requests.length === 1 && ui.file.disabled && ui.cancel.disabled, 'Repeated clicks during validation send only one request and lock controls.');
    release({ ok: true, json: async () => ({ success: true, data: { valid: true, count: 2, errors: [] } }) });
    await settle();
    check(!ui.commit.disabled && !ui.file.disabled, 'Successful validation enables import and restores file selection.');
    ui.choose('replacement.xlsx'); check(ui.commit.disabled, 'Replacing the file invalidates the previous validation.');
    ui.responses.push({ success: true, data: { valid: false, count: 2, errors: [{ row: 3, message: '<img src=x onerror=bad> missing name' }] } });
    ui.form.emit('submit'); await settle();
    check(ui.commit.disabled && ui.tbody.children[0].children[1].textContent.includes('<img'), 'Invalid rows block import and render error text without HTML execution.');
    check(!ui.errors.classes.has('hidden') && ui.status.textContent.includes('Nothing imported'), 'Validation errors remain visible with a no-import message.');
    ui.responses.push(new Error('Network disconnected')); ui.form.emit('submit'); await settle();
    check(ui.commit.disabled && !ui.validate.disabled && !ui.file.disabled && ui.status.textContent === 'Network disconnected', 'Network failure restores controls and requires validation again.');
    ui.form.emit('submit'); await settle();
    ui.responses.push(() => Promise.resolve({ ok: false, json: async () => ({ success: false, message: 'Your session expired.' }) }));
    ui.commit.emit('click'); await settle();
    check(ui.commit.disabled && !ui.cancel.disabled && ui.reloads() === 0, 'Expired session keeps the dialog recoverable and does not claim success.');
    ui.form.emit('submit'); await settle();
    let finish;
    ui.responses.push(() => new Promise(resolve => { finish = resolve; }));
    const before = ui.requests.length;
    ui.commit.emit('click'); ui.commit.emit('click');
    check(ui.requests.length === before + 1 && ui.requests.at(-1).action === 'import', 'Double-clicking Import sends one import request.');
    finish({ ok: true, json: async () => ({ success: true, data: { valid: true, count: 2, errors: [], imported: 2 } }) });
    await settle();
    check(ui.reloads() === 1 && ui.commit.disabled && ui.toasts.at(-1).kind === 'success', 'Successful import refreshes the directory once and prevents further clicks.');
    console.log(`Completed ${checks} import UI checks.`);
})().catch(error => { console.error(error); process.exitCode = 1; });
