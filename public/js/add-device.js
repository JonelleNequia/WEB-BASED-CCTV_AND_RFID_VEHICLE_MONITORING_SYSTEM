/*
 * B2 (Settings › Gates): the "+ Add camera" / "+ Add reader" wizard.
 * 1. Scans the network and lists the cameras (or readers) it finds.
 * 2. The user picks one and presses Add.
 * 3. Camera: asks for its login only when needed, then shows the live view.
 *    Reader: "hold a tag near the reader" and shows the tag it reads.
 * 4. Done: back to the gate card.
 * Nothing found: a short checklist, "Scan again" and "Enter manually".
 */
(function () {
    const dataNode = document.getElementById('add-device-data');
    const box = document.querySelector('[data-add-device-box]');
    if (!dataNode || !box) {
        return;
    }

    const config = JSON.parse(dataNode.textContent || '{}');
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const title = document.getElementById('add-device-modal-title');
    const KIND = { camera: 'camera', reader: 'rfid_reader' };
    let flow = null;   // {gate, role, devices, chosen, scanStartedAt, sawScan, timers}

    function el(tag, className, text) {
        const node = document.createElement(tag);
        if (className) {
            node.className = className;
        }
        if (text !== undefined && text !== null) {
            node.textContent = text;
        }
        return node;
    }

    function button(label, className, onClick) {
        const node = el('button', className, label);
        node.type = 'button';
        node.addEventListener('click', onClick);
        return node;
    }

    function gateName() {
        return config.gates?.[flow.gate]?.name || flow.gate;
    }

    function thing() {
        return flow.role === 'camera' ? 'camera' : 'RFID reader';
    }

    function stopTimers() {
        (flow?.timers || []).forEach((id) => window.clearInterval(id));
        if (flow) {
            flow.timers = [];
        }
    }

    function render(...nodes) {
        box.replaceChildren(...nodes.filter(Boolean));
    }

    function actions(...buttons) {
        const row = el('div', 'button-row button-row-end add-device-actions');
        row.append(...buttons.filter(Boolean));
        return row;
    }

    /* ---------- 1. scan and list ---------- */

    async function start(gate, role) {
        stopTimers();
        flow = { gate, role, devices: [], chosen: null, scanStartedAt: Date.now(), sawScan: false, timers: [] };
        title.textContent = `Add ${thing()} to ${gateName()}`;
        renderList(null);
        window.ui.openDrawer('add-device-modal');
        try {
            await fetch(config.scanUrl, { method: 'POST', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf } });
        } catch (error) {
            // The list below still polls; the scan may already be running.
        }
        poll();
        flow.timers.push(window.setInterval(poll, 2000));
    }

    async function poll() {
        if (!flow || !document.getElementById('add-device-modal')?.classList.contains('is-open')) {
            stopTimers();
            return;
        }
        try {
            const response = await fetch(config.indexUrl, { headers: { Accept: 'application/json' } });
            const payload = await response.json();
            if (flow.step && flow.step !== 'list') {
                return;
            }
            renderList(payload);
        } catch (error) {
            // Try again on the next poll.
        }
    }

    function scanning(payload) {
        if (!payload) {
            return true;
        }
        const running = !!payload.scan?.running;
        flow.sawScan = flow.sawScan || running;
        // Still looking: the scan runs, or it has not started yet (up to 25 s).
        return running || (!flow.sawScan && Date.now() - flow.scanStartedAt < 25000);
    }

    function renderList(payload) {
        flow.step = 'list';
        const devices = (payload?.devices || []).filter((device) => device.kind === KIND[flow.role]);
        const looking = scanning(payload);
        const nodes = [];

        if (looking) {
            const status = el('p', 'add-device-scanning');
            status.append(el('span', 'add-device-spinner'), document.createTextNode(`Looking for ${flow.role === 'camera' ? 'cameras' : 'RFID readers'} on your network…`));
            nodes.push(status);
        }
        if (payload?.service?.state === 'waiting_for_lan') {
            nodes.push(el('p', 'add-device-note', 'Only Wi-Fi is connected on this PC. Plug in the LAN cable from the cameras and readers.'));
        }

        if (devices.length) {
            const list = el('div', 'add-device-list');
            list.setAttribute('role', 'radiogroup');
            devices.forEach((device) => list.append(deviceOption(device)));
            nodes.push(list);
            const addButton = button('Add', 'button button-primary button-lg', () => add(false));
            addButton.disabled = !flow.chosen || !devices.some((device) => device.id === flow.chosen.id);
            nodes.push(actions(button('Scan again', 'button button-secondary', () => start(flow.gate, flow.role)), addButton));
        } else if (!looking) {
            nodes.push(nothingFound());
        }

        render(...nodes);
    }

    function deviceOption(device) {
        const label = el('label', 'add-device-option');
        const radio = el('input');
        radio.type = 'radio';
        radio.name = 'add_device_choice';
        radio.value = device.id;
        radio.checked = flow.chosen?.id === device.id;
        radio.addEventListener('change', function () {
            flow.chosen = device;
            box.querySelector('.add-device-actions .button-primary').disabled = false;
        });

        const icon = el('span', `add-device-icon is-${flow.role}`);
        icon.setAttribute('aria-hidden', 'true');
        const text = el('span', 'add-device-text');
        text.append(el('strong', null, device.friendly_name || device.name));

        const online = device.status === 'online';
        const line = el('span', 'status-line');
        const dot = el('span', `status-dot${online ? ' is-online' : ''}`);
        line.append(dot, document.createTextNode(online ? 'Online' : 'Not answering'));
        text.append(line);

        const usedBy = (device.assigned || []).filter((item) => item.station !== flow.gate || item.role !== (flow.role === 'camera' ? 'camera' : 'reader'));
        if (usedBy.length) {
            text.append(el('small', 'add-device-used', `Already used by ${usedBy.map((item) => item.label).join(', ')}`));
        }
        if ((device.assigned || []).some((item) => item.station === flow.gate && item.role === (flow.role === 'camera' ? 'camera' : 'reader'))) {
            text.append(el('small', 'add-device-used', `Already the ${thing()} of ${gateName()}`));
        }

        // A device on another network: one line, the fix under "How to fix".
        const warning = device.network_warning || device.guidance;
        if (warning) {
            text.append(el('small', 'add-device-warning', device.network_warning ? `On a different network: ${warning.summary || ''}` : 'On a different network: this PC cannot reach it.'));
            const fix = el('details', 'how-to-fix');
            fix.append(el('summary', null, 'How to fix'), el('p', null, warning.text || ''));
            if (warning.steps?.length) {
                const steps = el('ol');
                warning.steps.forEach((step) => steps.append(el('li', null, step)));
                fix.append(steps);
            }
            text.append(fix);
        }

        label.append(radio, icon, text);
        return label;
    }

    function nothingFound() {
        const wrap = el('div', 'add-device-none');
        wrap.append(el('strong', null, `No ${thing()} found yet`));
        const checks = el('ul', 'add-device-checklist');
        [
            `Is the ${thing()} switched on (a light is on)?`,
            `Is its LAN cable plugged into the same router or switch as this PC?`,
            'Wait a minute after switching it on, then press Scan again.',
        ].forEach((item) => checks.append(el('li', null, item)));
        wrap.append(checks);
        wrap.append(actions(button('Scan again', 'button button-primary button-lg', () => start(flow.gate, flow.role))));
        const manual = el('a', 'add-device-manual', 'Enter manually (technician)');
        manual.href = config.manualUrl;
        wrap.append(manual);
        return wrap;
    }

    /* ---------- 2. add ---------- */

    async function add(withLogin) {
        stopTimers();
        flow.step = 'adding';
        const device = flow.chosen;
        const body = { station: flow.gate, role: flow.role === 'camera' ? 'camera' : 'reader' };
        if (flow.role === 'camera') {
            body.stream = 'sub';
        }
        if (withLogin) {
            body.username = box.querySelector('[name="add_username"]').value;
            body.password = box.querySelector('[name="add_password"]').value;
        }
        render(el('p', 'add-device-scanning', `Adding ${device.friendly_name || device.name}…`));

        let result = {};
        try {
            const response = await fetch(device.assign_url, {
                method: 'POST',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
                body: JSON.stringify(body),
            });
            result = await response.json();
        } catch (error) {
            result = { ok: false, message: 'The device could not be added. Try again.' };
        }

        if (result.needs_credentials) {
            return loginStep(result.message);
        }
        if (!result.ok) {
            return render(el('p', 'add-device-error', result.message || 'The device could not be added.'),
                actions(button('Back', 'button button-secondary', () => start(flow.gate, flow.role))));
        }
        return flow.role === 'camera' ? cameraCheck() : readerCheck();
    }

    /* ---------- 3a. camera: login once, then the live view ---------- */

    function loginStep(message) {
        flow.step = 'login';
        const form = el('form', 'stack-form');
        form.autocomplete = 'off';
        form.append(el('p', 'field-help', message || 'Enter the camera username and password once. They are saved encrypted.'));
        [['add_username', 'Username', 'text', 'off'], ['add_password', 'Password', 'password', 'new-password']].forEach(([name, text, type, autocomplete]) => {
            const field = el('div', 'field');
            const label = el('label', null, text);
            label.htmlFor = name;
            const input = el('input');
            Object.assign(input, { id: name, name, type, autocomplete, required: name === 'add_username' });
            field.append(label, input);
            form.append(field);
        });
        form.append(actions(button('Back', 'button button-secondary', () => start(flow.gate, flow.role)), el('button', 'button button-primary button-lg', 'Save and test')));
        form.addEventListener('submit', (event) => {
            event.preventDefault();
            add(true);
        });
        render(form);
        form.querySelector('input')?.focus();
    }

    function cameraCheck() {
        flow.step = 'camera-check';
        const url = config.gates?.[flow.gate]?.stream_url;
        const preview = el('div', 'add-device-preview');
        const img = el('img');
        img.alt = `${gateName()} live view`;
        if (url) {
            img.src = `${url}${url.includes('?') ? '&' : '?'}t=${Date.now()}`;
            img.addEventListener('error', () => window.setTimeout(() => {
                img.src = `${url}${url.includes('?') ? '&' : '?'}t=${Date.now()}`;
            }, 3000));
        }
        preview.append(img);
        const state = el('p', 'add-device-result', 'Camera added. Connecting to its live view…');
        const done = button('Done', 'button button-primary button-lg', finish);
        render(preview, state, actions(done));

        const started = Date.now();
        flow.timers.push(window.setInterval(async () => {
            try {
                const response = await fetch(config.gatesStateUrl, { headers: { Accept: 'application/json' } });
                const gate = (await response.json()).gates?.[flow.gate] || {};
                if (gate.camera_running) {
                    state.textContent = '✓ The camera works. This is its live view.';
                    state.className = 'add-device-result is-good';
                    stopTimers();
                } else if (Date.now() - started > 30000) {
                    state.textContent = `Saved. No video yet: ${gate.camera_error || 'the camera is still connecting'}. It keeps trying by itself.`;
                    state.className = 'add-device-result is-bad';
                    stopTimers();
                }
            } catch (error) {
                // Try again.
            }
        }, 2000));
    }

    /* ---------- 3b. reader: hold a tag near it ---------- */

    function readerCheck() {
        flow.step = 'reader-check';
        const openedAt = Date.now();
        const state = el('p', 'add-device-result', 'Reader added. Hold an RFID tag near the reader to test it…');
        render(el('p', 'add-device-step', `Hold an RFID tag near the reader of ${gateName()}.`), state, actions(button('Done', 'button button-primary button-lg', finish)));

        flow.timers.push(window.setInterval(async () => {
            try {
                const response = await fetch(config.uhfStatusUrl, { headers: { Accept: 'application/json' } });
                const reader = ((await response.json()).readers || []).find((row) => row.station === flow.gate);
                if (reader?.epc && reader.read_at && Date.parse(reader.read_at) >= openedAt - 1000) {
                    state.textContent = `✓ Tag read: …${String(reader.epc).slice(-8)}. The reader works.`;
                    state.className = 'add-device-result is-good';
                    stopTimers();
                } else if (reader && !reader.ok) {
                    state.textContent = `Reader added. Connecting… (${reader.detail})`;
                }
            } catch (error) {
                // Try again.
            }
        }, 1000));
    }

    /* ---------- 4. done ---------- */

    function finish() {
        stopTimers();
        window.ui.closeDrawer('add-device-modal');
        window.location.reload();
    }

    document.addEventListener('click', function (event) {
        const trigger = event.target.closest('[data-add-device]');
        if (!trigger || event.metaKey || event.ctrlKey) {
            return;
        }
        event.preventDefault();
        start(trigger.dataset.gate, trigger.dataset.addDevice);
    }, true);  // capture: before ui.js starts the page-loading bar for a link
})();
