/** Shared searchable selects for initial pages, AJAX fragments and modal forms. */
(() => {
    'use strict';
    const $ = window.jQuery;
    if (!$?.fn.select2) return; // Native selects remain usable if an asset cannot load.
    const controls = new Map();
    const dirtyOptions = new WeakSet();
    const modalCleanup = new WeakMap();
    const signature = select => JSON.stringify([...select.selectedOptions].map(option => option.value)) + ':' + select.disabled;

    // Convert viewport coordinates into the scrolling dialog's content coordinates.
    function modalDropdownPlacement(control, modal, scrollTop, scrollLeft, borderTop, borderLeft, height, viewportHeight) {
        const below = Math.max(0, Math.min(modal.bottom, viewportHeight) - control.bottom - 8);
        const above = Math.max(0, control.top - Math.max(modal.top, 0) - 8);
        const direction = below >= height || below >= above ? 'below' : 'above';
        const available = direction === 'below' ? below : above;
        const actualHeight = Math.min(height, available);
        return {
            direction, available,
            top: (direction === 'below' ? control.bottom : control.top - actualHeight) - modal.top - borderTop + scrollTop,
            left: control.left - modal.left - borderLeft + scrollLeft,
        };
    }

    function supportModalScrolling(select, instance, parent) {
        if (parent === document.body) return;
        const dropdown = instance.dropdown;
        if (parent.tagName === 'DIALOG') {
            // Select2's default attachBody adapter omits dropdownParent.scrollTop().
            dropdown._positionDropdown = function () {
                const control = instance.$container[0].getBoundingClientRect();
                const modal = parent.getBoundingClientRect();
                const searchHeight = this.$dropdown.find('.select2-search--dropdown').outerHeight() || 0;
                const results = this.$dropdown.find('.select2-results__options');
                results.css('max-height', '200px');
                let placement = modalDropdownPlacement(control, modal, parent.scrollTop, parent.scrollLeft, parent.clientTop, parent.clientLeft, this.$dropdown.outerHeight(), window.innerHeight);
                results.css('max-height', Math.max(0, Math.min(200, placement.available - searchHeight - 4)) + 'px');
                placement = modalDropdownPlacement(control, modal, parent.scrollTop, parent.scrollLeft, parent.clientTop, parent.clientLeft, this.$dropdown.outerHeight(), window.innerHeight);
                this.$dropdown.add(instance.$container).removeClass('select2-dropdown--above select2-dropdown--below select2-container--above select2-container--below');
                this.$dropdown.addClass('select2-dropdown--' + placement.direction);
                instance.$container.addClass('select2-container--' + placement.direction);
                this.$dropdownContainer.css({top: placement.top, left: placement.left});
            };
        }
        const reposition = event => {
            // Results have their own scrollbar; leave option scrolling untouched.
            if (dropdown.$dropdown[0].contains(event.target)) return;
            const control = instance.$container[0].getBoundingClientRect();
            const bounds = parent.getBoundingClientRect();
            if (control.bottom <= Math.max(0,bounds.top) || control.top >= Math.min(window.innerHeight,bounds.bottom)) {
                // Select2 restores focus on close; preserve the user's scroll instead of jumping back to that field.
                const top = parent.scrollTop, left = parent.scrollLeft;
                $(select).select2('close');
                parent.scrollTop = top; parent.scrollLeft = left;
                return;
            }
            dropdown._positionDropdown();
            dropdown._resizeDropdown();
        };
        modalCleanup.set(select, () => parent.removeEventListener('scroll', reposition, true));
        $(select).on('select2:open.erpModalScroll', () => {
            // Remove only Select2's ancestor scroll lock for this control.
            instance.$container.parents().off('scroll.select2.' + instance.id);
            parent.addEventListener('scroll', reposition, true);
        }).on('select2:close.erpModalScroll', () => parent.removeEventListener('scroll', reposition, true));
    }

    function initialize(select) {
        // SweetAlert owns its temporary inputs and their keyboard/focus lifecycle.
        if (select.dataset.nativeSelect === 'true' || select.closest('.swal2-container') || controls.has(select) || select.classList.contains('select2-hidden-accessible')) return;
        // Select2 emits jQuery changes; existing forms also use native change listeners.
        // Move inline handlers to native listeners to deliver each user selection once.
        const inlineChange = select.onchange;
        if (inlineChange) {
            select.removeAttribute('onchange');
            select.onchange = null;
            select.addEventListener('change', inlineChange);
        }
        $(select).on('change.erpNativeSelect', event => {
            if (!event.originalEvent) {
                event.stopImmediatePropagation();
                select.dispatchEvent(new Event('change', { bubbles: true }));
            }
        });
        const parent = select.closest('dialog, [role="dialog"], .modal, .fixed') || document.body;
        $(select).select2({
            width: '100%',
            minimumResultsForSearch: 0,
            dropdownParent: $(parent),
        });
        const instance = $(select).data('select2');
        supportModalScrolling(select, instance, parent);
        const labelElement = select.labels?.[0]?.cloneNode(true);
        labelElement?.querySelectorAll('select, .select2-container').forEach(element => element.remove());
        const label = select.getAttribute('aria-label') || labelElement?.textContent.trim() || select.name || 'Choose an option';
        instance.$selection.attr('aria-label', label);
        $(select).on('select2:open.erpSearchFocus', () => {
            const search = instance.dropdown.$search?.[0] || instance.selection.$search?.[0];
            if (search) { search.setAttribute('aria-label', 'Search ' + label); search.focus(); }
        });
        controls.set(select, signature(select));
    }

    function discover(root) {
        if (!(root instanceof Element)) return;
        if (root.matches('select')) initialize(root);
        root.querySelectorAll('select').forEach(initialize);
    }

    function synchronize() {
        controls.forEach((previous, select) => {
            if (!select.isConnected) {
                modalCleanup.get(select)?.();
                modalCleanup.delete(select);
                if ($(select).data('select2')) $(select).select2('destroy');
                $(select).off('.erpNativeSelect .erpSearchFocus .erpModalScroll');
                controls.delete(select);
                return;
            }
            const current = signature(select);
            if (current !== previous || dirtyOptions.has(select)) {
                if (dirtyOptions.has(select)) {
                    $(select).find('option').removeData('data');
                    dirtyOptions.delete(select);
                }
                $(select).trigger('change.select2'); // Refresh presentation without resubmitting filters.
                controls.set(select, current);
            }
        });
    }

    function start() {
        discover(document.body);
        new MutationObserver(records => {
            records.forEach(record => {
                const select = record.target.closest?.('select');
                if (select) dirtyOptions.add(select);
                record.addedNodes.forEach(discover);
            });
            synchronize();
        }).observe(document.body, { childList: true, subtree: true, attributes: true, attributeFilter: ['disabled','selected','value','label'] });
        // Assigning .value and form.reset() do not emit mutation/change events.
        window.setInterval(synchronize, 250);
        document.addEventListener('reset', () => window.setTimeout(synchronize, 0), true);
        document.addEventListener('invalid', event => {
            const select = event.target;
            if (!controls.has(select) || select.disabled) return;
            event.preventDefault();
            $(select).data('select2').$selection.attr('aria-invalid', 'true');
            $(select).select2('open');
            if (window.erp?.toast) window.erp.toast(select.validationMessage, 'error');
            else if (typeof window.showToast === 'function') window.showToast(select.validationMessage, true);
        }, true);
        document.addEventListener('change', event => {
            if (controls.has(event.target)) $(event.target).data('select2').$selection.removeAttr('aria-invalid');
        });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
    else start();
})();
