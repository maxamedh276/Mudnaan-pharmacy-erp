/* Shared browser-to-QZ transport. Never retry a submitted job automatically. */
(() => {
    'use strict';
    const base = document.currentScript.dataset.printBase;
    const pending = new Map();
    let queue = Promise.resolve();

    const workstationKey = 'mudnaan_qz_workstation';
    let workstation;
    try {
        workstation = localStorage.getItem(workstationKey);
        if (!/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/i.test(workstation || '')) {
            workstation = crypto.randomUUID(); localStorage.setItem(workstationKey, workstation);
        }
    } catch (_) { workstation = crypto.randomUUID(); }
    let signingSetup;
    async function signingStatus() {
        const response = await fetch(`${base}/signing`, {credentials:'same-origin', cache:'no-store',
            headers:{'X-Requested-With':'XMLHttpRequest','X-Print-Workstation':workstation}});
        const result = await response.json();
        if (!response.ok || !result.success) throw Object.assign(new Error(result.message || 'QZ signing configuration could not be loaded.'), {code:result.code});
        return result.data;
    }
    async function prepareSigning() {
        if (!signingSetup) signingSetup = signingStatus().then(status => {
            if (status.configured) {
                qz.security.setSignatureAlgorithm('SHA512');
                qz.security.setCertificatePromise(resolve => resolve(status.certificate));
            }
            return status;
        }).catch(error => {signingSetup = null; throw error;});
        return signingSetup;
    }
    async function signature(call, params) {
        const status = await prepareSigning();
        if (!status.configured) return [];
        const timestamp = Date.now();
        const message = JSON.stringify({call, params, timestamp});
        const response = await fetch(`${base}/sign`, {method:'POST', credentials:'same-origin',
            headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest','X-Print-Workstation':workstation},
            body:JSON.stringify({message})});
        const result = await response.json();
        if (!response.ok || !result.success) throw new Error(result.message || 'QZ signing failed.');
        return [result.data.signature, timestamp];
    }

    function failureCode(error) {
        const message = String(error?.message || error || '').toLowerCase();
        if (/denied|blocked|reject|permission/.test(message)) return 'permission_denied';
        if (/certificate|ssl|tls/.test(message)) return 'certificate_error';
        if (/timeout|timed out/.test(message)) return 'timeout';
        if (/not found/.test(message)) return 'printer_not_found';
        return 'unavailable';
    }

    function report(attempt, stage, success, code = 'accepted') {
        // Telemetry must never block printing or trigger a second print submission.
        console[success ? 'info' : 'error']('QZ_PRINT', {attempt, stage, success, code});
        fetch(`${base}/events`, {
            method: 'POST', credentials: 'same-origin', keepalive: true,
            headers: {'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
            body: JSON.stringify({attempt, stage, success, code})
        }).then(response => {
            if (!response.ok) console.warn('QZ_PRINT diagnostic delivery failed', {attempt});
        }).catch(() => console.warn('QZ_PRINT diagnostic delivery unavailable', {attempt}));
    }

    async function send(path) {
        const attempt = crypto.randomUUID();
        let stage = 'generation', generated = false;
        try {
        const response = await fetch(`${base}/${path}`, {
            method: 'POST', credentials: 'same-origin',
            headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-Print-Attempt': attempt, 'X-Print-Workstation': workstation}
        });
        const payload = await response.json().catch(() => null);
        if (!response.ok || !payload?.success || response.redirected) {
            throw new Error(payload?.message || 'Unable to load the receipt. Sign in again and retry.');
        }
        const job = payload.data;
        generated = true;
        stage = 'configuration';
        if (!job?.bytes || !job.printer || !['windows', 'network'].includes(job.type)) {
            throw new Error('Activate a Windows or network receipt printer in Settings.');
        }
        stage = 'library';
        if (!window.qz) throw new Error('The QZ printing library did not load. Reload this page.');
        stage = 'signing';
        await prepareSigning();
        stage = 'connection';
        try {
            if (!qz.websocket.isActive()) await qz.websocket.connect();
        } catch (cause) {
            throw Object.assign(new Error('Cannot connect to QZ Tray. Start QZ Tray on this computer and allow the connection.'), {diagnosticCode: failureCode(cause)});
        }
        stage = 'discovery';
        let printer;
        if (job.type === 'network') {
            printer = {host: job.host, port: job.port};
        } else {
            const names = await qz.printers.find(undefined, ...await signature('printers.find', {}));
            printer = names.find(name => name === job.printer);
            if (!printer) throw new Error(`Printer "${job.printer}" was not found on this computer. Check its exact Windows name in Settings.`);
        }
        stage = 'submission';
        try {
            const config = qz.configs.create(printer);
            const data = [{type:'raw',format:'command',flavor:'base64',data:job.bytes}];
            const status = await prepareSigning();
            const signed = status.configured ? await signature('print', {printer:config.getPrinter(),options:config.getOptions(),data}) : [];
            await qz.print(config, data, ...signed);
        } catch (cause) {
            throw Object.assign(new Error('QZ did not confirm the print job. Check the printer and QZ permission prompt before manually retrying; some paper may already have printed.'), {diagnosticCode: failureCode(cause)});
        }
        report(attempt, 'accepted', true);
        return {success: true, message: 'Receipt sent to QZ Tray. Check the printer for your receipt.'};
        } catch (error) {
            if (generated) report(attempt, stage, false, error.diagnosticCode || failureCode(error));
            else console.error('QZ_PRINT', {attempt, stage, success: false});
            throw new Error(`${error.message || 'Printing failed.'} Reference: ${attempt}`);
        }
    }

    function print(path) {
        if (pending.has(path)) return pending.get(path);
        const task = queue.then(() => send(path));
        queue = task.catch(() => {});
        pending.set(path, task);
        task.then(() => pending.delete(path), () => pending.delete(path));
        return task;
    }
    window.erpPrinting = {
        sale: id => print(`sales/${Number(id)}`),
        receipt: id => print(`receipts/${Number(id)}`),
        test: () => print('test'),
        workstation, signingStatus
    };
})();
