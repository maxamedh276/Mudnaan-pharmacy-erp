/** Logo upload stays independent of settings previews and preserves unsaved settings. */
(() => {
    'use strict';
    const form = document.getElementById('logoUpload');
    if (!form) return;
    const button = form.querySelector('button[type="submit"]');
    const status = document.getElementById('logoUploadStatus');
    form.addEventListener('submit', async event => {
        event.preventDefault();
        if (button.disabled || !form.reportValidity()) return;
        const label = button.textContent;
        button.disabled = true; button.textContent = 'Uploading…'; form.setAttribute('aria-busy','true');
        status.textContent = 'Uploading logo…';
        try {
            const response = await fetch(form.dataset.url, {
                method:'POST', body:new FormData(form), credentials:'same-origin',
                headers:{Accept:'application/json','X-Requested-With':'XMLHttpRequest'},
            });
            const result = await response.json().catch(() => { throw new Error('Logo upload failed. Refresh the page and try again.'); });
            if (!response.ok || !result.success) throw new Error(result.message || 'Logo upload failed.');
            if (typeof result.data?.url !== 'string' || !result.data.url) throw new Error('The server did not return the updated logo.');
            const logoUrl = new URL(result.data.url,location.href);
            if (logoUrl.origin !== location.origin) throw new Error('The server returned an invalid logo URL.');
            document.querySelectorAll('[data-workspace-logo]').forEach(image => { image.src = logoUrl.href; });
            form.elements.logo.value = '';
            status.textContent = 'Logo updated in the sidebar and workspace.';
            erp.toast('Logo updated.');
        } catch (error) {
            status.textContent = error.message || 'Logo upload failed. Please try again.';
            erp.toast(status.textContent,'error');
        } finally {
            button.disabled = false; button.textContent = label; form.setAttribute('aria-busy','false');
        }
    });
})();
