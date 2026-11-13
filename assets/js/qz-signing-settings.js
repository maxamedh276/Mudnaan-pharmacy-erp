(() => {
    'use strict';
    const panel = document.getElementById('qzSigningSettings');
    if (!panel) return;
    const form = document.getElementById('qzSigningForm');
    const button = form.querySelector('button');
    const status = document.getElementById('qzSigningStatus');
    document.getElementById('qzComputerId').textContent = erpPrinting.workstation;
    erpPrinting.signingStatus().then(data => {
        status.textContent = data.configured ? 'Signing files are configured for this computer. QZ must trust the matching certificate on this PC.' :
            data.storageAvailable ? 'No signing files uploaded for this computer yet.' :
            'Server setup required: configure QZ_SIGNING_DIRECTORY outside the public website before uploading signing files.';
        button.disabled = !data.storageAvailable;
    }).catch(error => {
        const storageBlocked = error.code?.startsWith('storage_') || error.code === 'openssl_missing';
        status.textContent = error.message + (storageBlocked ? ' Save becomes available after the server setup is corrected and this page is reloaded.' : ' You can upload replacement files below.');
        button.disabled = !!storageBlocked;
    });
    form.addEventListener('submit', async event => {
        event.preventDefault();
        if (button.disabled || !form.reportValidity()) return;
        button.disabled = true;
        try {
            const response = await fetch(panel.dataset.url, {method:'POST',credentials:'same-origin',
                headers:{'X-Requested-With':'XMLHttpRequest','X-Print-Workstation':erpPrinting.workstation}, body:new FormData(form)});
            const result = await response.json();
            if (!response.ok || !result.success) throw new Error(result.message || 'Signing files could not be saved.');
            form.reset();
            status.textContent = 'Signing files saved. Reload this page before printing.';
            erp.toast('Signing files saved. Reload this page before printing.');
        } catch (error) {erp.toast(error.message,'error');}
        finally {button.disabled = false;}
    });
})();
