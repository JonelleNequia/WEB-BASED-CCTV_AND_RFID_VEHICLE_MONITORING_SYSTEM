/*
 * Manual Add RFID Tag keeps sticker text separate from UID. Bulk Register Tags
 * retains automatic numbering and saves each UID scan with its result.
 */
(function () {
    const manual = document.querySelector('[data-manual-register]');
    if (manual) initManual(manual);

    function initManual(form) {
        const number = form.querySelector('[data-sticker-number]');
        const uid = form.querySelector('[data-sticker-uid]');
        const save = form.querySelector('[data-sticker-save]');
        const fields = [number, uid].map(input => ({ input,
            error: document.getElementById(input.getAttribute('aria-describedby').split(' ').at(-1)),
            touched: input.getAttribute('aria-invalid') === 'true', serverError: '' }));
        fields.forEach(field => { field.serverError = field.error.textContent.trim(); });
        const composing = new Set();
        let busy = false;

        function problem(input) {
            const value = input.value.trim();
            if (input === number) {
                const significant = value.replace(/^0+/, '');
                return /^[0-9]+$/.test(value) && value.length <= Number(form.dataset.numberMaxLength)
                    && significant.length > 0 && significant.length <= form.dataset.numberMaxValue.length
                    && Number(significant) <= Number(form.dataset.numberMaxValue) ? '' : form.dataset.numberError;
            }
            return !value || !value.replace(/\s+/g, '') ? 'Scan or enter the electronic RFID UID.'
                : value.length > 100 ? 'The electronic RFID UID must not exceed 100 characters.' : '';
        }

        function sync() {
            fields.forEach(field => {
                const message = field.serverError || problem(field.input);
                const show = field.touched && Boolean(message) && !composing.has(field.input);
                field.error.textContent = show ? message : '';
                field.error.hidden = !show;
                field.input.setAttribute('aria-invalid', show ? 'true' : 'false');
            });
            save.disabled = busy || composing.size > 0 || fields.some(field => Boolean(problem(field.input)));
        }

        fields.forEach(field => {
            field.input.addEventListener('input', () => {
                if (!composing.has(field.input)) {
                    field.serverError = '';
                    field.touched = true;
                    sync();
                }
            });
            field.input.addEventListener('blur', () => { field.touched = true; sync(); });
            field.input.addEventListener('compositionstart', () => { composing.add(field.input); sync(); });
            field.input.addEventListener('compositionend', () => {
                composing.delete(field.input); field.serverError = ''; field.touched = true; sync();
            });
        });
        form.addEventListener('submit', event => {
            fields.forEach(field => { field.touched = true; });
            sync();
            if (save.disabled) {
                event.preventDefault();
                fields.find(field => problem(field.input))?.input.focus();
                return;
            }
            busy = true;
            sync();
        });
        form.addEventListener('keydown', event => {
            if (event.key === 'Enter' && (event.isComposing || composing.size)) event.preventDefault();
        });
        window.addEventListener('pageshow', () => { busy = false; sync(); });
        form.closest('[data-drawer]').addEventListener('drawer:open', () => number.focus({ preventScroll: true }));

        // Reuse the existing reader listener; reading fills only UID and never registers automatically.
        const reader = form.querySelector('[data-sticker-uhf]');
        const status = form.querySelector('[data-sticker-reader-status]');
        uid.addEventListener('keydown', event => {
            if (event.key === 'Enter') {
                event.preventDefault(); // A keyboard reader's terminator must not enroll a tag by itself.
                if (!problem(uid)) status.textContent = 'UID captured. Check the printed tag number, then save.';
            }
        });
        let stop = null;
        reader.addEventListener('click', () => {
            if (stop) { stop(); return; }
            reader.textContent = 'Stop listening';
            reader.setAttribute('aria-pressed', 'true');
            stop = window.uhfTagReader.listen(reader.dataset.stickerUhf, {
                seconds: 30,
                onStatus: text => { status.textContent = text; },
                onRead: read => {
                    stop?.();
                    uid.value = read.epc;
                    uid.dispatchEvent(new Event('input', { bubbles: true }));
                    status.textContent = 'UID read. Check the printed tag number, then save.';
                },
                onEnd: () => {
                    stop = null;
                    reader.textContent = 'Read with UHF reader';
                    reader.setAttribute('aria-pressed', 'false');
                },
            });
        });
        form.closest('[data-drawer]').addEventListener('drawer:close', () => stop?.());
        sync();
    }

    const box = document.querySelector('[data-bulk-register]');
    if (!box) {
        return;
    }

    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const scan = box.querySelector('[data-bulk-scan]');
    const list = box.querySelector('[data-bulk-list]');
    const summary = box.querySelector('[data-bulk-summary]');
    const numberHelp = box.querySelector('[data-bulk-number-help]');
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
            const nextNumber = result.next_tag_number ?? ((result.tag_number || 0) + 1);
            if (nextNumber > 999999) {
                numberHelp.textContent = 'Automatic numbering reached 999999. Use Add RFID Tag to enter an unused number.';
            } else {
                let next = numberHelp.querySelector('[data-bulk-next]');
                if (!next) {
                    next = document.createElement('span');
                    next.dataset.bulkNext = '';
                    numberHelp.replaceChildren('Tag numbers are assigned automatically, starting at #', next, '.');
                }
                next.textContent = String(nextNumber);
            }
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
