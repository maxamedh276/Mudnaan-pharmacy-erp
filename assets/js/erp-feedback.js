/** Shared web requests, confirmation dialogs, and safe text-only toast messages. */
(() => {
    'use strict';

    // Login and standalone POS do not load the authenticated layout's API object.
    window.erp = window.erp || {};

    window.erp.web = async function requestWeb(endpoint, options = {}) {
        try {
            const response = await fetch(endpoint, {
                credentials: 'same-origin',
                ...options,
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', ...(options.headers || {}) },
            });
            const result = await response.json().catch(error => {
                // Cancelled live searches must remain silent, even while reading JSON.
                if (error.name === 'AbortError') throw error;
                if (response.redirected || response.status === 401) {
                    throw new Error('Your session may have expired. Refresh the page and sign in again.');
                }
                throw new Error('The server returned an unexpected response. Please try again.');
            });
            if (!response.ok || result.success === false) {
                const error = new Error(result.message || result.error?.message || 'The request could not be completed.');
                error.status = response.status;
                throw error;
            }
            return result;
        } catch (error) {
            if (error.name !== 'AbortError') {
                erp.toast(error.message, 'error');
                error.reported = true;
            }
            throw error;
        }
    };

    window.erp.confirm = async function confirmAction(message, options = {}) {
        const result = await Swal.fire({
            title: options.title || 'Please confirm',
            text: message,
            icon: options.icon || 'warning',
            showCancelButton: true,
            confirmButtonText: options.confirmButtonText || 'Confirm',
            cancelButtonText: 'Cancel',
            confirmButtonColor: options.destructive ? '#e11d48' : '#1967d2',
            focusCancel: true,
            returnFocus: true,
        });
        return result.isConfirmed;
    };

    // A manual popover keeps toasts visible above native dialog forms as well.
    window.erp.toast = function showToast(message, type = 'success') {
        const container = document.getElementById('toast-container');
        if (!container) return;
        const toast = document.createElement('div');
        const kind = ['success','error','warning','info'].includes(type) ? type : 'info';
        toast.className = 'erp-toast';
        toast.dataset.kind = kind;
        toast.setAttribute('role', kind === 'error' || kind === 'warning' ? 'alert' : 'status');
        const icon = document.createElement('span');
        icon.className = 'erp-toast-icon';
        icon.setAttribute('aria-hidden','true');
        icon.textContent = {success:'✓',error:'!',warning:'!',info:'i'}[kind];
        const label = document.createElement('span');
        label.className = 'erp-toast-message';
        label.textContent = String(message);
        const dismissButton = document.createElement('button');
        dismissButton.type = 'button';
        dismissButton.className = 'erp-toast-dismiss';
        dismissButton.setAttribute('aria-label', 'Dismiss notification');
        dismissButton.textContent = '×';
        dismissButton.addEventListener('click', () => toast.remove());
        toast.append(icon, label, dismissButton);
        container.append(toast);
        if (container.showPopover) {
            try {
                // Reopening puts new feedback above a subsequently opened native dialog.
                if (container.matches(':popover-open')) container.hidePopover();
                container.showPopover();
            } catch (_) { /* Fixed-position notifications still work without popover support. */ }
        }
        window.setTimeout(() => toast.remove(), kind === 'success' ? 4500 : 7000);
        return toast;
    };

    document.addEventListener('click',event => {
        const control = event.target.closest?.('[data-toast-message]');
        if (!control) return;
        event.preventDefault();
        erp.toast(control.dataset.toastMessage,control.dataset.toastType || 'info');
    });
    const messages = document.getElementById('feedbackMessages');
    if (messages) {
        try {
            const notices = JSON.parse(messages.textContent);
            if (Array.isArray(notices)) notices.forEach(notice => {
                if (typeof notice?.message === 'string') erp.toast(notice.message,notice.type || 'info');
            });
        } catch (_) { /* A malformed optional flash message must never prevent signing in. */ }
    }
})();
