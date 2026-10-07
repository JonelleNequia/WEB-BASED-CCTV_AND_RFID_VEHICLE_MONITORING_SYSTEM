/*
 * Phase 2 (reader without a terminal): "Move reader to this network".
 * 1. Read: the device service reads the reader's settings (nothing changes)
 *    and proposes a free address in this PC's network.
 * 2. The user checks them, may change the address or pick DHCP, confirms.
 * 3. Apply: the address is written, the reader restarts and is found again.
 */
(function () {
    const modal = document.getElementById('reader-move');
    if (!modal) {
        return;
    }
    const body = modal.querySelector('[data-reader-move-body]');
    const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content || '';
    let urls = {};
    let poll = null;
    let requestId = null;
    let login = {};

    const escape = (value) => String(value ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

    function show(html) {
        body.innerHTML = html;
    }

    function waiting(message) {
        show(`<p class="reader-move-wait" role="status" aria-live="polite"><span class="add-device-spinner" aria-hidden="true"></span> ${escape(message)}</p>`);
    }

    async function post(url, data) {
        const response = await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
            body: JSON.stringify(data),
        });
        const payload = await response.json().catch(() => ({}));
        if (!response.ok) {
            throw new Error(payload.message || 'The request was not accepted.');
        }
        return payload;
    }

    function follow(onDone) {
        window.clearInterval(poll);
        poll = window.setInterval(async () => {
            if (!modal.classList.contains('is-open')) {
                window.clearInterval(poll);
                return;
            }
            try {
                const state = await (await fetch(urls.status, { headers: { Accept: 'application/json' } })).json();
                const move = state.move || {};
                if (move.request_id !== requestId) {
                    return;
                }
                if (move.state === 'running') {
                    waiting(move.message || 'Working…');
                    return;
                }
                window.clearInterval(poll);
                onDone(move, state);
            } catch (error) {
                // The next poll tries again.
            }
        }, 1000);
    }

    async function start(button) {
        urls = { status: button.dataset.statusUrl, read: button.dataset.readUrl, apply: button.dataset.applyUrl };
        modal.querySelector('[data-reader-move-gate]').textContent = button.dataset.label || '';
        window.ui.openDrawer('reader-move', button);
        await read();
    }

    async function read() {
        waiting('Looking for the reader and reading its settings… (nothing is changed)');
        try {
            requestId = (await post(urls.read, login)).request_id;
        } catch (error) {
            return failed({ message: error.message });
        }
        follow((move) => (move.state === 'read' ? review(move) : failed(move)));
    }

    function review(move) {
        const now = move.current || {};
        const proposal = move.proposal || {};
        const row = (label, value) => `<tr><th scope="row">${escape(label)}</th><td>${escape(value)}</td></tr>`;
        if (!proposal.network) {
            return failed({ message: 'This PC\'s network card has no router network (no DHCP), so there is no network to move the reader into. Connect the PC to the router, then try again.' });
        }
        show(`
            <p>The reader was found${move.interface ? ` through <strong>${escape(move.interface)}</strong>` : ''}. Its settings now:</p>
            <table class="reader-move-table"><tbody>
                ${row('Address', `${now.ip} (${now.dhcp ? 'automatic, DHCP' : 'fixed'})`)}
                ${row('Subnet mask', now.netmask)}
                ${row('Gateway', now.gateway)}
                ${row('Work mode', `${now.work_mode}, port ${now.local_port}`)}
                ${row('Serial', `${now.baud_rate} baud, ${now.data_bits} data bits, parity ${now.parity}, ${now.stop_bits} stop bit`)}
            </tbody></table>
            <form class="stack-form" data-reader-move-form data-confirm-title="Change the reader's address?" data-confirm-label="Change address" data-confirm="">
                <fieldset class="field">
                    <legend>New address in this PC's network (${escape(proposal.network)})</legend>
                    <label class="checkbox-row"><input type="radio" name="mode" value="static" checked> Fixed address (recommended)</label>
                    <input type="text" name="ip" value="${escape(proposal.ip || '')}" inputmode="decimal" aria-label="New fixed address" required>
                    <span class="field-help">A free address; the router and this PC are skipped. Mask ${escape(proposal.netmask)}, gateway ${escape(proposal.gateway)}.</span>
                    <label class="checkbox-row"><input type="radio" name="mode" value="dhcp"> Automatic (DHCP from the router; the system finds the reader by its MAC)</label>
                </fieldset>
                <p class="field-help">Only the address changes. The work mode (${escape(now.work_mode)}), port ${escape(now.local_port)} and serial settings stay the same. The reader restarts (about 10 seconds).</p>
                <div class="button-row button-row-end">
                    <button type="button" class="button button-secondary" data-drawer-close>Cancel</button>
                    <button type="submit" class="button button-primary">Change the address…</button>
                </div>
            </form>`);

        const form = body.querySelector('[data-reader-move-form]');
        const ip = form.querySelector('[name="ip"]');
        const describe = () => {
            const mode = form.querySelector('[name="mode"]:checked').value;
            ip.disabled = mode === 'dhcp';
            form.dataset.confirm = `The reader moves from ${now.ip} to ${mode === 'dhcp' ? 'an automatic (DHCP) address' : ip.value} and restarts. Tags are not read for about 10 seconds.`;
        };
        form.addEventListener('input', describe);
        describe();
        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            const mode = form.querySelector('[name="mode"]:checked').value;
            waiting('Changing the reader\'s address…');
            try {
                requestId = (await post(urls.apply, { mode, ip: mode === 'static' ? ip.value.trim() : null, confirm: true, ...login })).request_id;
            } catch (error) {
                return failed({ message: error.message });
            }
            follow((done) => (done.state === 'done' ? finished(done) : failed(done)));
        });
    }

    function finished(move) {
        show(`<p class="reader-move-result is-good" role="status">✓ ${escape(move.message)} The system connects to it by itself; no extra address or terminal is needed any more.</p>
              <div class="button-row button-row-end"><button type="button" class="button button-primary" onclick="window.location.reload()">Done</button></div>`);
    }

    function failed(move) {
        const loginNeeded = move.code === 'wrong_login';
        show(`<p class="reader-move-result is-bad" role="alert">${escape(move.message || 'Something went wrong.')}</p>
              ${loginNeeded ? `<form class="stack-form" data-reader-login>
                    <p class="field-help">The reader's network module has its own login (often admin / admin). Enter it to try again.</p>
                    <div class="form-grid"><div class="field"><label for="reader_module_user">Module username</label><input id="reader_module_user" name="username" maxlength="5" autocomplete="off" required></div>
                    <div class="field"><label for="reader_module_password">Module password</label><input id="reader_module_password" name="password" type="password" maxlength="5" autocomplete="off"></div></div>
                    <div class="button-row button-row-end"><button type="submit" class="button button-primary">Try again</button></div></form>` : ''}
              <p class="field-help">Nothing else is changed. You can also move it once with NetModuleConfig (see “How to do it with NetModuleConfig”).</p>
              <div class="button-row button-row-end"><button type="button" class="button button-secondary" data-drawer-close>Close</button>${loginNeeded ? '' : '<button type="button" class="button button-primary" data-reader-move-retry>Try again</button>'}</div>`);
        body.querySelector('[data-reader-move-retry]')?.addEventListener('click', read);
        body.querySelector('[data-reader-login]')?.addEventListener('submit', (event) => {
            event.preventDefault();
            const data = new FormData(event.target);
            login = { username: data.get('username'), password: data.get('password') };
            read();
        });
    }

    document.addEventListener('click', (event) => {
        const button = event.target.closest('[data-reader-move]');
        if (button) {
            event.preventDefault();
            login = {};
            start(button);
        }
    });
})();
