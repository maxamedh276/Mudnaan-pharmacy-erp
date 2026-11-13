/** One shared value-only workbook workflow for products and contacts. */
(() => {
    'use strict';
    const dialog = document.getElementById('workbookImportDialog');
    if (!dialog) return;
    const form = document.getElementById('workbookImportForm');
    const file = document.getElementById('workbookImportFile');
    const status = document.getElementById('workbookImportStatus');
    const errors = document.getElementById('workbookImportErrors');
    const validate = document.getElementById('workbookValidate');
    const commit = document.getElementById('workbookCommit');
    let validatedFile = null;
    let busy = false;
    const reset = () => {
        validatedFile = null;
        commit.disabled = true;
        status.textContent = '';
        errors.querySelector('tbody').replaceChildren();
        errors.classList.add('hidden');
    };
    document.querySelector('[data-open-workbook-import]').addEventListener('click', () => dialog.showModal());
    dialog.querySelectorAll('[data-close-workbook-import]').forEach(button => button.addEventListener('click', () => { if (!busy) dialog.close(); }));
    dialog.addEventListener('cancel', event => { if (busy) event.preventDefault(); });
    file.addEventListener('change', reset);
    async function upload(action) {
        if (busy || !form.reportValidity() || (action === 'import' && validatedFile !== file.files[0])) return;
        if (file.files[0].size > 5 * 1024 * 1024 || !/\.xlsx$/i.test(file.files[0].name)) {
            reset(); erp.toast('Choose an .xlsx file no larger than 5 MB.', 'error'); return;
        }
        busy = true;
        dialog.setAttribute('aria-busy', 'true');
        file.disabled = validate.disabled = commit.disabled = true;
        dialog.querySelectorAll('[data-close-workbook-import]').forEach(button => { button.disabled = true; });
        status.textContent = action === 'validate' ? 'Checking every row. No records are being saved…' : 'Rechecking and importing all rows. Please keep this page open…';
        errors.classList.add('hidden');
        const data = new FormData();
        data.append('workbook', file.files[0]);
        data.append('action', action);
        let finished = false;
        try {
            const response = await fetch(dialog.dataset.endpoint, { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, body: data });
            const payload = await response.json().catch(() => { throw new Error('Unexpected response. Check your session and validate again.'); });
            if (!response.ok || !payload.success) throw new Error(payload.message || 'Import could not finish. Validate again.');
            const result = payload.data;
            errors.querySelector('tbody').replaceChildren();
            validatedFile = result.valid ? file.files[0] : null;
            if (result.imported) {
                finished = true;
                status.textContent = `${result.imported} records imported successfully.`;
                erp.toast(status.textContent, 'success');
                // Refresh directory summaries and rows together after the atomic import.
                window.location.reload();
            } else if (result.valid) {
                status.textContent = `All ${result.count} rows are valid. Select Import all rows to save them.`;
                commit.textContent = `Import ${result.count} rows`;
            } else {
                status.textContent = `${result.errors.length} invalid rows out of ${result.count}. Nothing imported. Correct the file and upload it again.`;
                const fragment = document.createDocumentFragment();
                result.errors.forEach(error => {
                    const row = document.createElement('tr');
                    [error.row, error.message].forEach(value => { const cell = document.createElement('td'); cell.className = 'p-2 border-t align-top'; cell.textContent = value; row.append(cell); });
                    fragment.append(row);
                });
                errors.querySelector('tbody').append(fragment);
                errors.classList.remove('hidden');
                erp.toast('The workbook has errors. Nothing was imported.', 'error');
            }
        } catch (error) {
            validatedFile = null;
            status.textContent = error.message;
            erp.toast(error.message, 'error');
        } finally {
            busy = false;
            dialog.removeAttribute('aria-busy');
            file.disabled = validate.disabled = finished;
            commit.disabled = finished || validatedFile !== file.files[0];
            dialog.querySelectorAll('[data-close-workbook-import]').forEach(button => { button.disabled = finished; });
        }
    }
    form.addEventListener('submit', event => { event.preventDefault(); upload('validate'); });
    commit.addEventListener('click', () => upload('import'));
})();
