/*
 * UI Phase 4: Visitors "⋯" menus. Each item opens one shared dialog
 * (Correct plate, Add note, Dismiss, Merge) filled with that row's values.
 */
(function () {
    document.addEventListener('click', function (event) {
        const item = event.target.closest('[data-visitor-action]');
        if (!item) {
            return;
        }

        const action = item.dataset.visitorAction;
        const form = document.querySelector(`[data-visitor-form="${action}"]`);
        if (!form) {
            return;
        }

        item.closest('details.menu')?.removeAttribute('open');
        form.action = item.dataset.actionUrl;
        const value = form.querySelector('[data-visitor-value]');
        if (value) {
            value.value = item.dataset.value || '';
        }
        // Correct type: the current type is selected.
        form.querySelectorAll('input[type="radio"]').forEach(function (radio) {
            radio.checked = radio.value === item.dataset.value;
        });
        form.querySelectorAll('[data-visitor-label]').forEach(function (node) {
            node.textContent = item.dataset.label || '';
        });
        // Merge: a plate cannot be merged into itself.
        form.querySelectorAll('option[data-profile-id]').forEach(function (option) {
            option.hidden = option.dataset.profileId === item.dataset.profileId;
        });

        window.ui.openDrawer(`visitor-${action}-modal`);
        window.setTimeout(function () {
            value?.focus();
        }, 50);
    });
})();
