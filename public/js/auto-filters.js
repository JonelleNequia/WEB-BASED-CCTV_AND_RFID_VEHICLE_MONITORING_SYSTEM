/* Existing GET lists only. Controls and edit dialogs stay mounted; the server
 * renders results, pagination, summaries and export links for every request. */
(function () {
    const root = document.querySelector('[data-auto-list]');
    const form = root?.querySelector('form[data-auto-filter][method="GET"]');
    if (!form) return;

    const status = root.querySelector('[data-auto-status]');
    const retry = root.querySelector('[data-auto-retry]');
    const results = root.querySelector('[data-auto-update="results"]');
    const textInput = (input) => input instanceof HTMLInputElement && ['text', 'search'].includes(input.type);
    const key = (value) => {
        const url = new URL(value, location.href);
        url.searchParams.sort();
        return url.origin + url.pathname + '?' + url.searchParams.toString();
    };
    const state = { applied: new URL(location.href), desired: new URL(location.href), version: 0,
        controller: null, mode: null, timer: null, poll: null, composing: false, dirty: false, failed: null };

    function feedback(kind, message) {
        root.dataset.autoState = kind;
        if (status.textContent !== message) status.textContent = message;
        retry.hidden = kind !== 'error';
        results.setAttribute('aria-busy', kind === 'loading' || kind === 'refreshing' ? 'true' : 'false');
        root.querySelectorAll('[data-auto-export]').forEach((link) => {
            if (state.dirty || kind === 'error') link.setAttribute('aria-disabled', 'true');
            else link.removeAttribute('aria-disabled');
        });
    }

    function invalidate() {
        ++state.version;
        clearTimeout(state.timer);
        state.timer = null;
        state.controller?.abort();
        state.controller = null;
    }

    function formURL() {
        const url = new URL(state.applied);
        const values = new FormData(form);
        new Set(Array.from(form.elements, (input) => input.name).filter(Boolean)).forEach((name) => url.searchParams.delete(name));
        for (const [name, value] of values) {
            if (typeof value === 'string' && value !== '') url.searchParams.append(name, value);
        }
        // A newly entered explicit range replaces a quick timeframe, matching
        // the timeframe links' existing behavior of clearing explicit dates.
        if (['date_from', 'date_to'].some((name) => form.elements.namedItem(name)
            && (url.searchParams.get(name) || '') !== (state.applied.searchParams.get(name) || ''))) url.searchParams.delete('period');
        url.searchParams.delete('page');
        return url;
    }

    function valid() {
        const from = form.querySelector('[name="date_from"]');
        const to = form.querySelector('[name="date_to"]');
        from?.setCustomValidity('');
        to?.setCustomValidity('');
        if (from?.value && to?.value && from.value > to.value) to.setCustomValidity('To date must be on or after From.');
        if (form.checkValidity()) return true;
        const invalid = Array.from(form.elements).find((input) => input.validity && !input.validity.valid);
        invalid?.setAttribute('aria-invalid', 'true');
        feedback('error', (invalid?.validationMessage || 'Check the selected filters.') + ' Previous results are kept.');
        return false;
    }

    function syncControls(next) {
        const incoming = next.querySelector('form[data-auto-filter]');
        for (const input of form.elements) {
            if (!input.name) continue;
            const source = Array.from(incoming.elements).find((other) => other.name === input.name && other.type === input.type
                && (!['checkbox', 'radio'].includes(input.type) || other.value === input.value));
            if (!source) continue;
            if (['checkbox', 'radio'].includes(input.type)) input.checked = source.checked;
            else input.value = source.value;
            input.removeAttribute('aria-invalid');
            if (input instanceof HTMLInputElement) input.setCustomValidity('');
        }
    }

    function apply(next, url, options) {
        // Check every region before touching successful results. In particular,
        // expired-session/error HTML must never replace this page's data.
        const regions = Array.from(root.querySelectorAll('[data-auto-update]'), (current) => {
            const incoming = Array.from(next.querySelectorAll('[data-auto-update]')).find((node) => node.dataset.autoUpdate === current.dataset.autoUpdate);
            if (!incoming) throw new Error('The results could not be read. Reload this page and try again.');
            if (incoming.matches('script[type="application/json"]')) JSON.parse(incoming.textContent);
            return [current, incoming];
        });
        const links = Array.from(root.querySelectorAll('[data-auto-link]'), (current) => {
            const incoming = Array.from(next.querySelectorAll('[data-auto-link]')).find((node) => node.dataset.autoLink === current.dataset.autoLink);
            if (!incoming) throw new Error('The export links could not be read. Reload this page and try again.');
            return [current, incoming];
        });
        const oldFocus = document.activeElement;
        if (options.sync) syncControls(next);
        for (const [current, incoming] of regions) {
            current.replaceChildren(...Array.from(incoming.childNodes, (node) => node.cloneNode(true)));
            if (incoming.hasAttribute('data-auto-empty')) current.dataset.autoEmpty = incoming.dataset.autoEmpty;
        }
        for (const [current, incoming] of links) current.href = incoming.href;
        state.applied = url;
        state.desired = url;
        state.dirty = false;
        state.failed = null;
        document.querySelector('[data-list-filter-errors]')?.remove();
        if (options.mode === 'push' && key(location.href) !== key(url)) history.pushState(null, '', url);
        document.querySelectorAll('[data-auto-live]').forEach((indicator) => {
            const incoming = next.ownerDocument.querySelector('[data-auto-live]');
            indicator.hidden = Number(url.searchParams.get('page') || 1) !== 1;
            if (incoming) indicator.replaceChildren(...Array.from(incoming.childNodes, (node) => node.cloneNode(true)));
        });
        document.dispatchEvent(new CustomEvent('auto-list:updated', { detail: { id: root.dataset.autoList, url: url.href, reason: options.mode } }));
        if (oldFocus && !oldFocus.isConnected && options.mode !== 'poll') {
            results.tabIndex = -1;
            results.focus({ preventScroll: true });
        }
        feedback('ready', results.dataset.autoEmpty === 'true' ? 'No matching results.' : 'Results updated.');
    }

    async function go(url, options = { mode: 'push', sync: false }) {
        if (state.controller && state.mode === options.mode && key(state.desired) === key(url)) return;
        invalidate();
        const version = state.version;
        const controller = new AbortController();
        state.controller = controller;
        state.mode = options.mode;
        state.desired = new URL(url);
        state.failed = { url: new URL(url), options };
        feedback(options.mode === 'poll' ? 'refreshing' : 'loading', options.mode === 'poll' ? status.textContent : 'Updating results…');
        const timeout = setTimeout(() => controller.abort(), 15000);
        try {
            const fetcher = options.mode === 'poll' && window.ui?.liveFetch ? window.ui.liveFetch : window.fetch;
            const response = await fetcher(url, { cache: 'no-store', signal: controller.signal, headers: { Accept: 'text/html', 'X-Auto-Filter': '1' } });
            if (version !== state.version) return;
            if (!response.ok) {
                if (response.status === 422) {
                    const body = await response.json();
                    if (version !== state.version) return;
                    for (const input of form.elements) {
                        if (body.errors?.[input.name]) input.setAttribute('aria-invalid', 'true');
                    }
                    throw new Error(Object.values(body.errors || {}).flat().join(' ') || 'Check the selected filters.');
                }
                throw new Error(response.status === 401 || response.status === 403
                    ? 'Your session or permissions changed. Reload the page to continue.' : 'Results could not be updated.');
            }
            const html = await response.text();
            if (version !== state.version || state.composing) return;
            const doc = new DOMParser().parseFromString(html, 'text/html');
            const next = Array.from(doc.querySelectorAll('[data-auto-list]')).find((node) => node.dataset.autoList === root.dataset.autoList);
            const resolved = new URL(response.url || url, location.href);
            if (!next || !next.querySelector('form[data-auto-filter]') || resolved.origin !== location.origin || resolved.pathname !== state.applied.pathname) {
                throw new Error('The results could not be read. Reload this page and try again.');
            }
            apply(next, resolved, options);
        } catch (error) {
            if (version !== state.version) return;
            feedback('error', (error.name === 'AbortError' ? 'The request timed out.' : error.message) + ' Previous results are kept. Retry when ready.');
        } finally {
            clearTimeout(timeout);
            if (version === state.version) state.controller = null;
        }
    }

    function changed(delay) {
        const url = formURL();
        if (state.controller && key(url) === key(state.desired)) return;
        invalidate(); // A keystroke invalidates old responses before debounce fires.
        state.dirty = true;
        state.desired = url;
        state.failed = { url, options: { mode: 'push', sync: false } };
        for (const input of form.elements) input.removeAttribute('aria-invalid');
        if (!valid()) return;
        if (key(url) === key(state.applied)) {
            state.dirty = false;
            state.failed = null;
            feedback('ready', 'Results updated.');
            return;
        }
        feedback('loading', 'Updating results…');
        state.timer = setTimeout(() => { state.timer = null; go(url); }, delay);
    }

    form.addEventListener('compositionstart', () => {
        state.composing = true;
        invalidate();
        state.dirty = true;
        feedback('loading', 'Finish typing to update results.');
    });
    form.addEventListener('compositionend', () => { state.composing = false; changed(300); });
    form.addEventListener('input', (event) => {
        if (state.composing || event.isComposing) return;
        if (textInput(event.target)) changed(300);
        else if (event.target.type === 'date') changed(0);
    });
    form.addEventListener('change', (event) => {
        if (!state.composing && !textInput(event.target)) changed(0);
    });
    form.addEventListener('submit', (event) => {
        event.preventDefault();
        if (!state.composing) changed(0);
    });

    document.addEventListener('click', (event) => {
        const link = event.target.closest('a[href]');
        if (!link || !root.contains(link) || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
        if (link.matches('[data-auto-export]')) {
            if (link.getAttribute('aria-disabled') === 'true') event.preventDefault();
            return;
        }
        const url = new URL(link.href);
        const tab = url.searchParams.get('tab') || root.dataset.autoDefaultTab;
        if (link.target === '_blank' || link.hasAttribute('download') || url.origin !== location.origin || url.pathname !== state.applied.pathname
            || tab !== form.elements.namedItem('tab').value || link.getAttribute('href').startsWith('#')) return;
        event.preventDefault();
        let target = url;
        if (!link.hasAttribute('data-auto-reset') && !link.closest('.pagination-links')) {
            target = formURL();
            // Links change only their own criteria; keep text typed before its debounce.
            const names = new Set([...state.applied.searchParams.keys(), ...url.searchParams.keys()]);
            for (const name of names) {
                if (state.applied.searchParams.get(name) !== url.searchParams.get(name)) {
                    target.searchParams.delete(name);
                    for (const value of url.searchParams.getAll(name)) target.searchParams.append(name, value);
                }
            }
        } else if (link.closest('.pagination-links') && state.dirty) target = formURL();
        const clearsRange = link.closest('[aria-label="Timeframe"]') && !target.searchParams.has('date_from') && !target.searchParams.has('date_to');
        if (!link.hasAttribute('data-auto-reset') && !clearsRange && !valid()) return;
        state.dirty = true;
        go(target, { mode: 'push', sync: true });
    }, true);

    retry.addEventListener('click', () => {
        if (state.failed && valid()) go(state.failed.url, state.failed.options);
    });
    document.addEventListener('drawer:open', () => {
        if (!state.controller && !state.timer) return;
        invalidate();
        feedback(state.dirty ? 'error' : 'ready', state.dirty
            ? 'Finish the current action, then retry the filters. Previous results are kept.' : 'Results updated.');
    });
    window.addEventListener('popstate', () => {
        state.composing = false;
        state.dirty = true;
        go(new URL(location.href), { mode: 'pop', sync: true });
    });

    function startPolling() {
        if (!root.dataset.autoPoll) return;
        state.poll = setInterval(() => {
            if (state.dirty || state.controller || state.timer || state.composing || document.hidden
                || Number(state.applied.searchParams.get('page') || 1) !== 1
                || document.querySelector('[data-drawer].is-open, details.menu[open]')
                || (results.contains(document.activeElement) && document.activeElement.matches('input, textarea, select, button, a, [contenteditable="true"]'))) return;
            go(state.applied, { mode: 'poll', sync: false });
        }, Number(root.dataset.autoPoll));
    }
    window.addEventListener('pagehide', () => { invalidate(); clearInterval(state.poll); });
    window.addEventListener('pageshow', (event) => {
        if (event.persisted) {
            startPolling();
            state.dirty = true;
            go(new URL(location.href), { mode: 'pop', sync: true });
        }
    });
    startPolling();
})();
