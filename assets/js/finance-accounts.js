/** Native HTML tabs; AJAX is only needed after creating an account. */
(() => {
    'use strict';
    const workspace = document.getElementById('chartOfAccounts');
    if (!workspace) return;
    const groupTabs = Array.from(workspace.querySelectorAll('[data-account-group]'));
    const groupPanels = Array.from(workspace.querySelectorAll('[data-account-panel]'));
    const accountDialog = document.getElementById('accountDialog');
    const accountForm = document.getElementById('accountForm');
    let selectedGroup = workspace.dataset.selectedGroup;
    let categoryKey;

    function selectGroup(groupCode) {
        selectedGroup = groupCode;
        groupPanels.forEach(panel => panel.hidden = panel.dataset.accountPanel !== groupCode);
        groupTabs.forEach(tab => {
            const selected = tab.dataset.accountGroup === groupCode;
            tab.setAttribute('aria-selected', String(selected));
            tab.tabIndex = selected ? 0 : -1;
            tab.classList.toggle('border-brand-600', selected);
            tab.classList.toggle('text-brand-600', selected);
            tab.classList.toggle('border-transparent', !selected);
            tab.classList.toggle('text-slate-500', !selected);
        });
    }
    // Remove the former bookmark parameter; tab selection never changes the URL.
    const pageUrl = new URL(location.href);
    if (pageUrl.searchParams.has('group')) {
        pageUrl.searchParams.delete('group');
        history.replaceState(null, '', pageUrl);
    }
    groupTabs.forEach((tab, tabIndex) => {
        tab.addEventListener('click', () => selectGroup(tab.dataset.accountGroup));
        tab.addEventListener('keydown', event => {
            const offsets = { ArrowRight: 1, ArrowLeft: -1 };
            if (!(event.key in offsets) && !['Home', 'End'].includes(event.key)) return;
            event.preventDefault();
            const targetIndex = event.key === 'Home' ? 0 : event.key === 'End' ? groupTabs.length - 1 : (tabIndex + offsets[event.key] + groupTabs.length) % groupTabs.length;
            groupTabs[targetIndex].focus();
            selectGroup(groupTabs[targetIndex].dataset.accountGroup);
        });
    });
    workspace.addEventListener('click', event => {
        const addButton = event.target.closest('[data-add-account]');
        if (!addButton) return;
        accountForm.reset();
        categoryKey = addButton.closest('details').dataset.categoryKey;
        document.getElementById('accountGroup').value = addButton.dataset.group;
        document.getElementById('accountParent').value = addButton.dataset.parent;
        document.getElementById('accountDialogTitle').textContent = 'Add account · ' + addButton.dataset.label;
        accountDialog.showModal();
    });
    accountForm.addEventListener('submit', async event => {
        event.preventDefault();
        const saveButton = document.getElementById('accountSave');
        const submissionGroup = selectedGroup;
        saveButton.disabled = true;
        saveButton.textContent = 'Saving…';
        try {
            await erp.web(workspace.dataset.createUrl, { method: 'POST', body: JSON.stringify({
                name: document.getElementById('accountName').value.trim(),
                account_group_id: Number(document.getElementById('accountGroup').value),
                parent_id: Number(document.getElementById('accountParent').value) || null,
                description: document.getElementById('accountDescription').value.trim(),
            }) });
            accountDialog.close();
            erp.toast('Account created.');
            const panel = document.getElementById('account-panel-' + submissionGroup);
            const expandedKeys = Array.from(panel.querySelectorAll('details[open]')).map(category => category.dataset.categoryKey);
            const result = await erp.web(workspace.dataset.groupUrl + '/' + encodeURIComponent(submissionGroup));
            panel.innerHTML = result.data.html;
            panel.querySelectorAll('details').forEach(category => category.open = expandedKeys.includes(category.dataset.categoryKey));
            lucide.createIcons({ root: panel });
            panel.querySelector(`[data-category-key="${categoryKey}"] [data-add-account]`)?.focus();
        } catch (_) { /* Shared request helper keeps validation errors in the toaster. */ }
        finally { saveButton.disabled = false; saveButton.textContent = 'Save account'; }
    });
})();
