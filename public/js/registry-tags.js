/*
 * UI Phase 3: bulk "Register Tags" drawer. Every scan (reader types the UID
 * and Enter) is saved right away and listed with its result.
 */
(function () {
    const box = document.querySelector('[data-bulk-register]');
    if (!box) {
        return;
    }

    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const scan = box.querySelector('[data-bulk-scan]');
    const list = box.querySelector('[data-bulk-list]');
    const summary = box.querySelector('[data-bulk-summary]');
    const next = box.querySelector('[data-bulk-next]');
    let added = 0;
    let busy = Promise.resolve();

    function addRow(state, left, right) {
        const item = document.createElement('li');
        item.dataset.state = state;
        const a = document.createElement('span');
        a.textContent = left;
        const b = document.createElement('strong');
        b.textContent = right;
        item.append(a, b);
        list.prepend(item);
    }

    async function register(rawUid) {
        const uid = String(rawUid || '').replace(/\s+/g, '').toUpperCase();
        if (!uid) {
            return;
        }

        const body = new FormData();
        body.append('uid', uid);
        body.append('auto_number', '1');

        try {
            const response = await fetch(box.dataset.storeUrl, {
                method: 'POST',
                headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
                body: body,
            });
            const result = await response.json().catch(() => ({}));

            if (!response.ok) {
                const message = result.errors ? Object.values(result.errors).flat().join(' ') : (result.message || 'Could not register.');
                addRow('error', uid, message);
                return;
            }

            added += 1;
            next.textContent = String((result.tag_number || 0) + 1);
            addRow('ok', `${uid} · #${result.tag_number}`, 'Vehicle tag');
            summary.textContent = `${added} tag(s) added. Keep scanning, or press Done.`;
        } catch (error) {
            addRow('error', uid, 'Could not reach the server.');
        }
    }

    scan.addEventListener('keydown', function (event) {
        if (event.key !== 'Enter') {
            return;
        }
        event.preventDefault();
        const value = scan.value;
        scan.value = '';
        // Keep order when tags are tapped quickly.
        busy = busy.then(() => register(value));
    });

    // UHF: every new EPC the network reader reads is registered, once.
    const uhf = box.querySelector('[data-bulk-uhf]');
    const seen = new Set();
    let stopUhf = null;
    uhf?.addEventListener('click', function () {
        if (stopUhf) {
            stopUhf();
            return;
        }
        uhf.textContent = 'Stop listening';
        uhf.setAttribute('aria-pressed', 'true');
        stopUhf = window.uhfTagReader.listen(uhf.dataset.bulkUhf, {
            seconds: 600,
            onStatus: function (text, state) {
                if (state === 'error') {
                    addRow('error', 'UHF reader', text);
                } else {
                    summary.textContent = text;
                }
            },
            onRead: function (read) {
                if (seen.has(read.epc)) {
                    return;
                }
                seen.add(read.epc);
                busy = busy.then(() => register(read.epc));
            },
            onEnd: function () {
                stopUhf = null;
                uhf.textContent = 'Listen to UHF reader';
                uhf.setAttribute('aria-pressed', 'false');
            },
        });
    });

    box.querySelector('[data-bulk-done]').addEventListener('click', function () {
        window.location.reload();
    });

    document.getElementById('register-tag-drawer')?.addEventListener('drawer:open', function () {
        scan.focus({ preventScroll: true });
    });
    document.getElementById('register-tag-drawer')?.addEventListener('drawer:close', function () {
        stopUhf?.();
    });
})();
