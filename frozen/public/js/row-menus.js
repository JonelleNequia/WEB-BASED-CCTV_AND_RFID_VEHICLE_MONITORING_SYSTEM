/* Offline row menus: top-layer popovers retain their original action/form nodes. */
(function () {
    const initialized = new WeakSet();
    let current = null;
    let sequence = 0;
    const isRowMenu = (node) => node?.matches?.('details.menu') && node.closest('.table-responsive');
    const items = (panel) => [...panel.querySelectorAll('a[href], button, input, [tabindex="0"]')]
        .filter((node) => !node.disabled && node.getAttribute('aria-disabled') !== 'true' && node.getClientRects().length);

    function initialize(details) {
        if (!isRowMenu(details) || initialized.has(details)) return;
        const trigger = details.querySelector(':scope > summary');
        const panel = details.querySelector(':scope > .menu-panel');
        if (!trigger || !panel) return;
        initialized.add(details);
        details.open = false; // Restored/cloned rows must never inherit another row's open state.
        while (document.getElementById('row-menu-panel-' + ++sequence)) {}
        panel.id ||= 'row-menu-panel-' + sequence;
        trigger.id ||= panel.id + '-trigger';
        trigger.setAttribute('aria-controls', panel.id);
        trigger.setAttribute('aria-haspopup', 'menu');
        trigger.setAttribute('aria-expanded', 'false');
        panel.setAttribute('aria-labelledby', trigger.id);
        panel.dataset.rowMenuPanel = '';
        panel.setAttribute('aria-hidden', 'true');
        if (typeof panel.showPopover === 'function') panel.setAttribute('popover', 'manual');
    }

    function close(restoreFocus = false) {
        const state = current;
        if (!state) return;
        current = null;
        if (state.native && state.panel.matches(':popover-open')) state.panel.hidePopover();
        state.details.open = false;
        state.trigger.setAttribute('aria-expanded', 'false');
        state.panel.setAttribute('aria-hidden', 'true');
        if (state.placeholder) {
            state.placeholder.replaceWith(state.panel);
        }
        if (restoreFocus && state.trigger.isConnected) state.trigger.focus({ preventScroll: true });
    }

    function position(state) {
        const { panel, trigger } = state;
        const margin = 8;
        const width = document.documentElement.clientWidth;
        const height = window.innerHeight;
        const anchor = trigger.getBoundingClientRect();
        Object.assign(panel.style, {
            position: 'fixed', margin: '0', inset: 'auto', bottom: 'auto', right: 'auto',
            minWidth: Math.min(200, width - 2 * margin) + 'px',
            maxWidth: Math.max(1, width - 2 * margin) + 'px',
            maxHeight: Math.max(1, height - 2 * margin) + 'px',
            width: 'max-content', left: '0px', top: '0px',
        });
        let rect = panel.getBoundingClientRect();
        const below = height - anchor.bottom - margin - 4;
        const above = anchor.top - margin - 4;
        const flip = rect.height > below && above > below;
        panel.style.maxHeight = Math.max(1, flip ? above : below) + 'px';
        rect = panel.getBoundingClientRect();
        panel.style.left = Math.max(margin, Math.min(anchor.right - rect.width, width - rect.width - margin)) + 'px';
        panel.style.top = Math.max(margin, Math.min(flip ? anchor.top - rect.height - 4 : anchor.bottom + 4,
            height - rect.height - margin)) + 'px';
    }

    function open(details, keyboard = false, last = false) {
        initialize(details);
        if (!initialized.has(details)) return;
        close();
        const trigger = details.querySelector(':scope > summary');
        const panel = details.querySelector(':scope > .menu-panel');
        const native = typeof panel.showPopover === 'function';
        const placeholder = native ? null : document.createComment('row menu');
        if (placeholder) {
            panel.before(placeholder);
            document.body.append(panel); // Same nodes: delegated actions and CSRF forms stay intact.
        }
        details.open = true;
        trigger.setAttribute('aria-expanded', 'true');
        panel.setAttribute('aria-hidden', 'false');
        current = { details, trigger, panel, native, placeholder };
        if (native) panel.showPopover();
        position(current);
        if (keyboard) (last ? items(panel).at(-1) : items(panel)[0])?.focus({ preventScroll: true });
    }

    document.addEventListener('click', function (event) {
        const trigger = event.target.closest('summary');
        if (trigger && isRowMenu(trigger.parentElement)) {
            event.preventDefault();
            if (current?.details === trigger.parentElement) close(true);
            else open(trigger.parentElement, event.detail === 0);
            return;
        }
        if (!current) return;
        if (current.panel.contains(event.target)) {
            if (!current.details.isConnected || !current.trigger.isConnected) {
                event.preventDefault();
                event.stopImmediatePropagation();
                close();
                return;
            }
            if (event.target.closest('a[href], button, input[type="submit"]')) {
                // Before existing drawer/confirmation handlers run, return focus
                // to the surviving trigger so their own focus return stays valid.
                close(true);
            }
        } else if (!current.details.contains(event.target)) close();
    }, true);

    document.addEventListener('keydown', function (event) {
        const trigger = event.target.closest('summary');
        if (trigger && isRowMenu(trigger.parentElement) && ['ArrowDown', 'ArrowUp'].includes(event.key)) {
            event.preventDefault();
            open(trigger.parentElement, true, event.key === 'ArrowUp');
            return;
        }
        if (!current) return;
        if (event.key === 'Escape') {
            event.preventDefault();
            event.stopImmediatePropagation();
            close(true);
        } else if (event.key === 'Tab') close(true);
        else if (current.panel.contains(event.target) && ['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) {
            event.preventDefault();
            const choices = items(current.panel);
            const index = choices.indexOf(document.activeElement);
            const next = event.key === 'Home' ? 0 : event.key === 'End' ? choices.length - 1
                : (index + (event.key === 'ArrowDown' ? 1 : -1) + choices.length) % choices.length;
            choices[next]?.focus({ preventScroll: true });
        }
    }, true);

    document.addEventListener('toggle', function (event) {
        if (!isRowMenu(event.target)) return;
        if (current?.details === event.target && !event.target.open) close();
        else if (event.target.open && current?.details !== event.target) open(event.target);
    }, true);
    document.addEventListener('beforetoggle', function (event) {
        if (current?.panel === event.target && event.newState === 'closed') close();
    }, true);
    const followScroll = (event) => {
        if (!current || (event?.target instanceof Node && current.panel.contains(event.target))) return;
        const table = current.details.closest('.table-responsive');
        if (!current.trigger.isConnected || !table) { close(); return; }
        const anchor = current.trigger.getBoundingClientRect();
        const clip = table.getBoundingClientRect();
        if (anchor.bottom <= Math.max(0, clip.top)
            || anchor.top >= Math.min(innerHeight, clip.bottom)
            || anchor.right <= Math.max(0, clip.left)
            || anchor.left >= Math.min(document.documentElement.clientWidth, clip.right)) close();
        else position(current);
    };
    // Follow focus/automatic scrolling too; dismiss once its row leaves view.
    window.addEventListener('scroll', followScroll, true);
    window.addEventListener('resize', () => close());
    window.visualViewport?.addEventListener('resize', () => close());
    window.visualViewport?.addEventListener('scroll', () => close());
    window.addEventListener('pagehide', () => close());

    function scan(node) {
        if (node.nodeType !== Node.ELEMENT_NODE && node !== document) return;
        if (isRowMenu(node)) initialize(node);
        node.querySelectorAll?.('.table-responsive details.menu').forEach(initialize);
    }
    new MutationObserver((records) => {
        if (current && (!current.details.isConnected || !current.trigger.isConnected || !current.details.open)) close();
        records.forEach((record) => record.addedNodes.forEach(scan));
    }).observe(document.documentElement, { childList: true, subtree: true, attributes: true, attributeFilter: ['open'] });
    scan(document);
})();
