/*
 * UI Phase 1: shared behaviour for the design-system components.
 * - Drawers / modals: [data-drawer-open="id"], [data-drawer-close], Esc, focus trap.
 * - Toasts: window.ui.toast('Saved', 'success').
 * - Dates: window.ui.formatDateTime(iso) -> "Sep 26, 2026 · 12:45 PM" (Asia/Manila),
 *   the same format as App\Support\DisplayTime.
 */
(function () {
    const TIME_ZONE = 'Asia/Manila';
    const focusableSelector = 'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';
    let lastTrigger = null;

    function parts(value) {
        const date = value instanceof Date ? value : new Date(value);

        if (!value || Number.isNaN(date.getTime())) {
            return null;
        }

        const map = {};
        new Intl.DateTimeFormat('en-US', {
            timeZone: TIME_ZONE,
            month: 'short',
            day: 'numeric',
            year: 'numeric',
            hour: 'numeric',
            minute: '2-digit',
            second: '2-digit',
            hour12: true,
        }).formatToParts(date).forEach(function (part) {
            map[part.type] = part.value;
        });

        return map;
    }

    function formatDate(value, fallback) {
        const p = parts(value);
        return p ? `${p.month} ${p.day}, ${p.year}` : (fallback ?? '—');
    }

    function formatTime(value, fallback, withSeconds) {
        const p = parts(value);
        if (!p) {
            return fallback ?? '—';
        }
        return `${p.hour}:${p.minute}${withSeconds ? ':' + p.second : ''} ${p.dayPeriod}`;
    }

    function formatDateTime(value, fallback, withSeconds) {
        const p = parts(value);
        return p ? `${formatDate(value)} · ${formatTime(value, '', withSeconds)}` : (fallback ?? '—');
    }

    /* ---------- Toasts ---------- */

    function toastStack() {
        let stack = document.querySelector('[data-toast-stack]');
        if (!stack) {
            stack = document.createElement('div');
            stack.className = 'toast-stack';
            stack.setAttribute('data-toast-stack', '');
            stack.setAttribute('aria-live', 'polite');
            document.body.appendChild(stack);
        }
        return stack;
    }

    function toast(message, type, options) {
        const settings = Object.assign({ title: null, timeout: type === 'error' ? 7000 : 4000 }, options || {});
        const node = document.createElement('div');
        const body = document.createElement('div');
        const close = document.createElement('button');

        node.className = `toast toast-${type || 'info'}`;
        node.setAttribute('role', type === 'error' ? 'alert' : 'status');
        body.className = 'toast-body';

        if (settings.title) {
            const title = document.createElement('strong');
            title.textContent = settings.title;
            body.appendChild(title);
        }

        const text = document.createElement('span');
        text.textContent = message || '';
        body.appendChild(text);

        close.type = 'button';
        close.className = 'toast-close';
        close.setAttribute('aria-label', 'Dismiss');
        close.innerHTML = '&times;';
        close.addEventListener('click', function () {
            node.remove();
        });

        node.append(body, close);
        toastStack().appendChild(node);

        if (settings.timeout > 0) {
            window.setTimeout(function () {
                node.classList.add('is-leaving');
                window.setTimeout(function () { node.remove(); }, 250);
            }, settings.timeout);
        }

        return node;
    }

    /* ---------- Drawers / modals ---------- */

    function openDrawer(drawer, trigger) {
        if (!drawer) {
            return;
        }

        lastTrigger = trigger || document.activeElement;
        drawer.hidden = false;
        document.body.classList.add('has-drawer');
        requestAnimationFrame(function () {
            drawer.classList.add('is-open');
            const autofocus = drawer.querySelector('[autofocus]') || drawer.querySelector('.drawer-body ' + focusableSelector.split(', ').join(', .drawer-body '));
            (autofocus || drawer.querySelector('.drawer-panel')).focus({ preventScroll: true });
        });
        drawer.dispatchEvent(new CustomEvent('drawer:open', { bubbles: true }));
    }

    function closeDrawer(drawer) {
        if (!drawer || drawer.hidden) {
            return;
        }

        drawer.classList.remove('is-open');
        drawer.hidden = true;

        if (!document.querySelector('[data-drawer].is-open')) {
            document.body.classList.remove('has-drawer');
        }

        drawer.dispatchEvent(new CustomEvent('drawer:close', { bubbles: true }));

        if (lastTrigger && document.contains(lastTrigger)) {
            lastTrigger.focus({ preventScroll: true });
        }
    }

    document.addEventListener('click', function (event) {
        const opener = event.target.closest('[data-drawer-open]');
        if (opener) {
            event.preventDefault();
            openDrawer(document.getElementById(opener.dataset.drawerOpen), opener);
            return;
        }

        const closer = event.target.closest('[data-drawer-close]');
        if (closer) {
            closeDrawer(closer.closest('[data-drawer]'));
        }
    });

    // UI Phase 3: <form data-confirm="Are you sure?"> asks before submitting.
    document.addEventListener('submit', function (event) {
        const form = event.target.closest('form[data-confirm]');
        if (form && !window.confirm(form.dataset.confirm)) {
            event.preventDefault();
        }
    }, true);

    document.addEventListener('keydown', function (event) {
        const open = Array.from(document.querySelectorAll('[data-drawer].is-open')).pop();
        if (!open) {
            return;
        }

        if (event.key === 'Escape') {
            closeDrawer(open);
            return;
        }

        if (event.key !== 'Tab') {
            return;
        }

        const focusable = Array.from(open.querySelectorAll(focusableSelector)).filter(function (node) {
            return node.offsetParent !== null;
        });

        if (focusable.length === 0) {
            return;
        }

        const first = focusable[0];
        const last = focusable[focusable.length - 1];

        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    });

    document.addEventListener('DOMContentLoaded', function () {
        const initial = document.querySelector('[data-initial-toasts]');
        if (initial) {
            try {
                JSON.parse(initial.textContent || '[]').forEach(function (item) {
                    toast(item.message, item.type, { title: item.title || null });
                });
            } catch (error) {
                // Ignore malformed toast payloads.
            }
        }

        document.querySelectorAll('[data-drawer-autoopen]').forEach(function (drawer) {
            openDrawer(drawer);
        });

        document.querySelectorAll('time[data-live-format]').forEach(function (node) {
            node.textContent = formatDateTime(node.getAttribute('datetime'), node.textContent);
        });
    });

    window.ui = {
        toast: toast,
        openDrawer: function (id) { openDrawer(document.getElementById(id)); },
        closeDrawer: function (id) { closeDrawer(document.getElementById(id)); },
        formatDate: formatDate,
        formatTime: formatTime,
        formatDateTime: formatDateTime,
    };
})();
