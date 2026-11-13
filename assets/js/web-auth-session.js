/** Clear browser API credentials after server logout and refresh restored protected pages. */
(() => {
    'use strict';
    const script = document.currentScript;
    const tokenKey = 'mudnaan_erp_token';
    const loginUrl = script.dataset.loginUrl;

    if (script.dataset.authenticated === 'false') {
        window.ERP_TOKEN = '';
        try { localStorage.removeItem(tokenKey); } catch (_) { /* Storage may be disabled. */ }
        return;
    }

    window.addEventListener('storage', event => {
        if ((event.key === tokenKey && event.newValue === null) || event.key === null) {
            window.ERP_TOKEN = '';
            window.location.replace(loginUrl);
        }
    });
    window.addEventListener('pageshow', event => {
        // Back/forward cache can restore an old authenticated DOM without contacting the server.
        if (event.persisted) window.location.reload();
    });
})();
