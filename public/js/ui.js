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
    // B4: in a large dialog (touchscreen) instead of the browser's box.
    // Optional: data-confirm-title, data-confirm-label (the confirm button).
    // A form with a red ("danger") button gets a red confirm button.
    function confirmDialog() {
        let dialog = document.getElementById('confirm-dialog');
        if (dialog) {
            return dialog;
        }
        dialog = document.createElement('div');
        dialog.id = 'confirm-dialog';
        dialog.className = 'drawer modal modal-sm confirm-dialog';
        dialog.setAttribute('data-drawer', '');
        dialog.hidden = true;
        dialog.innerHTML = '<div class="drawer-backdrop" data-drawer-close></div>'
            + '<div class="drawer-panel" role="alertdialog" aria-modal="true" aria-labelledby="confirm-dialog-title" aria-describedby="confirm-dialog-message" tabindex="-1">'
            + '<header class="drawer-head"><h2 id="confirm-dialog-title"></h2><button type="button" class="icon-button" data-drawer-close aria-label="Close">&times;</button></header>'
            + '<div class="drawer-body"><p id="confirm-dialog-message" class="confirm-dialog-message"></p>'
            + '<div class="button-row button-row-end"><button type="button" class="button button-secondary button-lg" data-drawer-close>Cancel</button>'
            + '<button type="button" class="button button-primary button-lg" data-confirm-yes></button></div></div></div>';
        document.body.appendChild(dialog);
        return dialog;
    }

    document.addEventListener('submit', function (event) {
        const form = event.target.closest('form[data-confirm]');
        if (!form) {
            return;
        }
        if (form.dataset.confirmed === '1') {
            delete form.dataset.confirmed;
            return;
        }
        event.preventDefault();
        event.stopImmediatePropagation();

        const submitter = event.submitter || null;
        const dialog = confirmDialog();
        const danger = form.hasAttribute('data-confirm-danger') || !!form.querySelector('.menu-item-danger, .button-danger');
        const yes = dialog.querySelector('[data-confirm-yes]');
        dialog.querySelector('#confirm-dialog-title').textContent = form.dataset.confirmTitle || 'Please confirm';
        dialog.querySelector('#confirm-dialog-message').textContent = form.dataset.confirm;
        yes.textContent = form.dataset.confirmLabel || 'Yes, continue';
        yes.className = `button button-lg ${danger ? 'button-danger' : 'button-primary'}`;
        yes.onclick = function () {
            closeDrawer(dialog);
            form.dataset.confirmed = '1';
            if (form.requestSubmit) {
                form.requestSubmit(submitter && submitter.form === form ? submitter : undefined);
            } else {
                form.submit();
            }
        };
        openDrawer(dialog, submitter);
        window.setTimeout(() => yes.focus(), 50);
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

    /* ---------- UI Phase 5: collapsible sidebar ---------- */

    function syncSidebarToggle() {
        const collapsed = document.documentElement.classList.contains('sidebar-collapsed');
        document.querySelectorAll('[data-sidebar-toggle]').forEach(function (button) {
            button.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
            button.setAttribute('aria-label', collapsed ? 'Expand sidebar' : 'Collapse sidebar');
            button.title = collapsed ? 'Expand sidebar' : 'Collapse sidebar';
        });
    }

    document.addEventListener('click', function (event) {
        if (!event.target.closest('[data-sidebar-toggle]')) {
            return;
        }

        const collapsed = document.documentElement.classList.toggle('sidebar-collapsed');
        try {
            localStorage.setItem('ui.sidebar', collapsed ? 'collapsed' : 'expanded');
        } catch (error) {
            // Private mode: the choice just is not remembered.
        }
        syncSidebarToggle();
    });

    /* ---------- UI Phase 5: loading states ---------- */

    function startNavigation(table) {
        document.body.classList.add('is-navigating');
        table?.classList.add('is-loading');
        table?.setAttribute('aria-busy', 'true');
    }

    // Page links (sidebar, tabs, chips, pagination): thin progress bar at the top.
    document.addEventListener('click', function (event) {
        const link = event.target.closest('a[href]');
        if (!link || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
            return;
        }
        const href = link.getAttribute('href');
        if (!href || href.startsWith('#') || link.target === '_blank' || link.hasAttribute('download') || link.origin !== window.location.origin || link.pathname.includes('/export/')) {
            return;
        }
        startNavigation(link.closest('.data-table'));
    });

    // Forms: filters dim their table; saves disable the button so it is not pressed twice.
    document.addEventListener('submit', function (event) {
        const form = event.target;
        if (event.defaultPrevented || !(form instanceof HTMLFormElement) || form.hasAttribute('data-no-busy')) {
            return;
        }

        if ((form.method || 'get').toLowerCase() === 'get') {
            startNavigation(form.closest('.data-table'));
            return;
        }

        document.body.classList.add('is-navigating');
        window.setTimeout(function () {
            form.setAttribute('aria-busy', 'true');
            form.querySelectorAll('button[type="submit"], button:not([type])').forEach(function (button) {
                button.disabled = true;
                button.dataset.idleLabel = button.textContent;
                button.textContent = button.dataset.busyLabel || 'Saving…';
            });
        }, 0);
    });

    // Back/forward cache: undo loading states.
    window.addEventListener('pageshow', function () {
        document.body.classList.remove('is-navigating');
        document.querySelectorAll('.is-loading').forEach((node) => node.classList.remove('is-loading'));
        document.querySelectorAll('[aria-busy="true"]').forEach(function (node) {
            node.removeAttribute('aria-busy');
            node.querySelectorAll('button[data-idle-label]').forEach(function (button) {
                button.disabled = false;
                button.textContent = button.dataset.idleLabel;
            });
        });
    });

    /* Live data indicator: <span data-live-indicator> shows Live / Updating / Offline. */
    function setLive(state) {
        document.querySelectorAll('[data-live-indicator]').forEach(function (node) {
            node.dataset.state = state;
            const label = node.querySelector('[data-live-label]');
            if (label) {
                label.textContent = state === 'offline'
                    ? 'Offline · retrying'
                    : state === 'updating'
                        ? 'Updating…'
                        : `Live · ${formatTime(new Date(), '', true)}`;
            }
        });
    }

    /* Wrap a polling fetch so the indicator follows it. */
    async function liveFetch(url, options) {
        setLive('updating');
        try {
            const response = await fetch(url, options);
            setLive(response.ok ? 'live' : 'offline');
            return response;
        } catch (error) {
            setLive('offline');
            throw error;
        }
    }

    /* ---------- UI Phase 4: sidebar status dots (Detector, Cameras, Readers) ---------- */

    async function refreshHealth() {
        const box = document.querySelector('[data-health-url]');
        if (!box || document.hidden) {
            return;
        }
        try {
            const response = await fetch(box.dataset.healthUrl, { headers: { Accept: 'application/json' } });
            if (!response.ok) {
                return;
            }
            ((await response.json()).health || []).forEach(function (item) {
                const node = box.querySelector(`[data-health="${item.key}"]`);
                if (!node) {
                    return;
                }
                node.title = `${item.label}: ${item.detail}`;
                node.querySelector('.health-dot').className = `health-dot ${item.ok ? 'is-ok' : 'is-down'}`;
                const detail = node.querySelector('[data-health-detail]');
                if (detail) {
                    detail.textContent = `${item.ok ? 'OK' : 'Needs attention'}: ${item.detail}`;
                }
            });
        } catch (error) {
            // Offline for a moment; the next refresh tries again.
        }
    }

    window.setInterval(refreshHealth, 5000);

    /* ---------- UI Phase 4: segmented control (Today / Week / Month / Year, ranking tabs) ---------- */
    // <div data-segments="key"> with buttons [data-segment="x"] and panels [data-segment-panel="x"].

    function showSegment(root, key) {
        root.querySelectorAll('[data-segment]').forEach(function (button) {
            const active = button.dataset.segment === key;
            button.classList.toggle('is-active', active);
            button.setAttribute('aria-selected', active ? 'true' : 'false');
        });
        root.querySelectorAll('[data-segment-panel]').forEach(function (panel) {
            panel.hidden = panel.dataset.segmentPanel !== key;
        });
    }

    document.addEventListener('click', function (event) {
        const button = event.target.closest('[data-segment]');
        const root = button?.closest('[data-segments]');
        if (!root) {
            return;
        }
        showSegment(root, button.dataset.segment);
        try {
            localStorage.setItem(`ui.segment.${root.dataset.segments}`, button.dataset.segment);
        } catch (error) {
            // Not remembered in private mode.
        }
    });

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-segments]').forEach(function (root) {
            let saved = null;
            try {
                saved = localStorage.getItem(`ui.segment.${root.dataset.segments}`);
            } catch (error) {
                saved = null;
            }
            if (saved && root.querySelector(`[data-segment="${saved}"]`)) {
                showSegment(root, saved);
            }
        });
    });

    /* ---------- UI Phase 4: thumbnails open larger ([data-zoom="image url"]) ---------- */

    function closeLightbox() {
        document.querySelector('[data-lightbox]')?.remove();
    }

    document.addEventListener('click', function (event) {
        const trigger = event.target.closest('[data-zoom]');
        if (!trigger || event.metaKey || event.ctrlKey) {
            return;
        }
        event.preventDefault();
        closeLightbox();
        const box = document.createElement('div');
        box.className = 'lightbox';
        box.dataset.lightbox = '';
        box.setAttribute('role', 'dialog');
        box.setAttribute('aria-label', trigger.dataset.zoomLabel || 'Image');
        const image = document.createElement('img');
        image.src = trigger.dataset.zoom || trigger.getAttribute('href');
        image.alt = trigger.dataset.zoomLabel || '';
        const close = document.createElement('button');
        close.type = 'button';
        close.className = 'lightbox-close';
        close.setAttribute('aria-label', 'Close');
        close.textContent = '×';
        box.append(image, close);
        box.addEventListener('click', closeLightbox);
        document.body.append(box);
        close.focus();
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            closeLightbox();
        }
    });

    /* Row "⋯" menus: a click elsewhere closes the open one. */
    document.addEventListener('click', function (event) {
        document.querySelectorAll('details.menu[open]').forEach(function (menu) {
            if (!menu.contains(event.target)) {
                menu.open = false;
            }
        });
    });

    document.addEventListener('DOMContentLoaded', syncSidebarToggle);

    window.ui = {
        setLive: setLive,
        liveFetch: liveFetch,
        toast: toast,
        openDrawer: function (id) { openDrawer(document.getElementById(id)); },
        closeDrawer: function (id) { closeDrawer(document.getElementById(id)); },
        formatDate: formatDate,
        formatTime: formatTime,
        formatDateTime: formatDateTime,
    };
})();
