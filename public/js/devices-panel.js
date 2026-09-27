/*
 * Plug-and-detect: Settings › Stations & Readers › Devices.
 * - Polls the device list (faster while a scan runs) and re-renders it.
 * - New devices are highlighted and announced with a toast.
 * - Click a device: details, then assign it to Entrance/Exit as camera or
 *   reader. A camera login is asked only when the saved one does not work.
 */
(function () {
    const panel = document.querySelector('[data-devices-panel]');
    if (!panel) {
        return;
    }

    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const drawerBody = document.querySelector('[data-device-drawer-body]');
    const drawerTitle = document.getElementById('device-drawer-title');
    const initialNode = document.getElementById('devices-panel-data');
    let data = initialNode ? JSON.parse(initialNode.textContent || '{}') : {};
    const known = new Set();
    let firstRender = true;
    let acknowledgeTimer = null;
    let pollTimer = null;
    let openDeviceId = null;

    const STATE = {
        ready: ['success', 'Connected'],
        scanning: ['info', 'Scanning…'],
        waiting_for_lan: ['warning', 'Waiting for LAN connection'],
        link_local: ['warning', 'Direct cable (no DHCP)'],
        stopped: ['critical', 'Device service stopped'],
    };
    const STATUS_TONE = { online: 'success', offline: 'neutral', unreachable: 'warning' };
    const KIND_TONE = { camera: 'brand', rfid_reader: 'info', router: 'neutral', unknown: 'neutral' };
    const LINK_LABEL = {
        connected: 'Connected', connecting: 'Connecting…', disconnected: 'Not answering', error: 'Waiting for address', unassigned: 'Not connected',
    };

    /* ---------- helpers ---------- */

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

    function badge(tone, label) {
        return el('span', `badge badge-tone-${tone}`, label);
    }

    function button(label, className, onClick) {
        const node = el('button', className || 'button button-secondary button-sm', label);
        node.type = 'button';
        node.addEventListener('click', onClick);
        return node;
    }

    async function post(url, body) {
        const response = await fetch(url, {
            method: 'POST',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
            body: JSON.stringify(body || {}),
        });
        let json = {};
        try {
            json = await response.json();
        } catch (error) {
            json = {};
        }
        if (!response.ok && response.status !== 422) {
            throw new Error(json.message || 'The request failed.');
        }
        return { ok: response.ok, json: json };
    }

    function deviceById(id) {
        return (data.devices || []).find((device) => device.id === id);
    }

    function deviceTitle(device) {
        return [device.name, device.model && !String(device.name).includes(device.model) ? device.model : null].filter(Boolean).join(' ');
    }

    /* ---------- network bar ---------- */

    function renderNetwork() {
        const box = panel.querySelector('[data-devices-network]');
        const service = data.service || {};
        const scan = data.scan || {};
        const state = service.running ? (scan.running ? 'scanning' : service.state) : 'stopped';
        const [tone, label] = STATE[state] || ['neutral', state];

        const head = el('div', 'devices-network-head');
        head.append(badge(tone, label), el('span', null, scan.running ? (scan.progress || 'Scanning the network…') : service.message));
        panel.querySelector('[data-devices-scan]').disabled = !!scan.running;

        const chips = el('div', 'devices-interfaces');
        const interfaces = (data.network || {}).interfaces || [];
        if (!interfaces.length) {
            chips.append(el('span', 'text-muted', 'No network connected on this PC.'));
        }
        interfaces.forEach(function (item) {
            const kind = item.kind === 'ethernet' ? 'LAN' : item.kind === 'wifi' ? 'Wi-Fi' : item.label || item.name;
            const text = `${kind} · ${item.ip}/${item.prefix}${item.gateway ? ' · router ' + item.gateway : ''}${item.link_local ? ' · no DHCP' : ''}`;
            chips.append(el('span', `chip ${item.kind === 'ethernet' ? 'chip-brand' : 'chip-soft'}`, text));
        });

        const meta = el('p', 'field-help');
        const counts = data.counts || {};
        meta.textContent = `Last scan: ${scan.last_finished_display || 'not yet'} · ${counts.cameras || 0} camera(s) · ${counts.readers || 0} reader(s) · ${counts.other || 0} other`;

        box.replaceChildren(head, chips, meta);
        if (scan.error) {
            box.append(el('p', 'field-error', scan.error));
        }
    }

    /* ---------- station assignments ---------- */

    function renderStations() {
        const box = panel.querySelector('[data-devices-stations]');
        const cards = Object.entries(data.stations || {}).map(function ([station, info]) {
            const card = el('article', 'devices-station');
            card.append(el('h3', null, info.label || station));
            card.append(slot(station, 'camera', 'Camera', info.camera));
            card.append(slot(station, 'reader', 'UHF Reader', info.reader, info.manual_reader));
            return card;
        });
        box.replaceChildren(...cards);
    }

    function slot(station, role, label, assigned, manual) {
        const row = el('div', 'devices-slot');
        row.append(el('span', 'devices-slot-label', label));
        const body = el('div', 'devices-slot-body');

        if (manual && role === 'reader') {
            body.append(el('strong', null, 'Manual address (Advanced)'));
            body.append(el('span', 'text-muted', 'Switch it off below to use a detected reader.'));
            row.append(body);
            return row;
        }

        if (!assigned) {
            body.append(el('span', 'text-muted', role === 'camera' ? 'No camera assigned. Pick one below.' : 'No UHF reader assigned. Pick one below.'));
            row.append(body);
            return row;
        }

        const title = el('div', 'devices-slot-title');
        title.append(el('strong', null, assigned.name), badge(STATUS_TONE[assigned.status] || 'neutral', assigned.status));
        body.append(title, el('span', 'text-muted', `${assigned.ip || '—'} · ${assigned.mac || 'MAC unknown'}`));

        if (role === 'reader' && assigned.link) {
            const link = assigned.link;
            const state = LINK_LABEL[link.state] || link.state || 'Starting…';
            const parts = [state];
            if (link.protocol) {
                parts.push(`format ${link.protocol}`);
            }
            if (link.last_tag) {
                parts.push(`last tag ${link.last_tag} · ${link.last_tag_display || ''}`);
            }
            body.append(el('span', link.state === 'connected' ? 'devices-link devices-link-ok' : 'devices-link', parts.join(' · ')));
            if (link.last_error && link.state !== 'connected') {
                body.append(el('span', 'field-help', link.last_error));
            }
        }
        if (role === 'camera' && assigned.options && assigned.options.stream) {
            body.append(el('span', 'field-help', `${assigned.options.stream === 'sub' ? 'Sub' : 'Main'} stream · the live view follows this camera when its IP changes`));
        }

        row.append(body, button('Unassign', 'button button-secondary button-sm', () => unassign(station, role)));
        return row;
    }

    /* ---------- device tables ---------- */

    function renderLists() {
        const devices = data.devices || [];
        const main = devices.filter((device) => ['camera', 'rfid_reader'].includes(device.kind));
        const other = devices.filter((device) => !['camera', 'rfid_reader'].includes(device.kind));

        const list = panel.querySelector('[data-devices-list]');
        if (!main.length) {
            const empty = el('div', 'empty-block');
            empty.append(
                el('strong', null, (data.scan || {}).running ? 'Looking for cameras and readers…' : 'No camera or UHF reader found yet'),
                el('p', null, 'Plug the LAN cable of the camera and the reader into the router/switch (or straight into this PC). They appear here within a few seconds.')
            );
            list.replaceChildren(empty);
        } else {
            list.replaceChildren(table(main));
        }

        panel.querySelector('[data-devices-other-count]').textContent = other.length;
        panel.querySelector('[data-devices-other]').replaceChildren(other.length ? table(other) : el('p', 'text-muted', 'None.'));
    }

    function table(devices) {
        const wrap = el('div', 'table-responsive');
        const tableNode = el('table', 'devices-table');
        const head = el('thead');
        const headRow = el('tr');
        ['Type', 'Device', 'IP address', 'MAC address', 'Status', 'Station', ''].forEach((label) => headRow.append(el('th', null, label)));
        head.append(headRow);

        const body = el('tbody');
        devices.forEach(function (device) {
            const tr = el('tr', `devices-row${device.is_new ? ' is-new' : ''}`);
            tr.tabIndex = 0;
            tr.dataset.deviceId = device.id;

            const type = el('td');
            type.append(badge(KIND_TONE[device.kind] || 'neutral', device.kind_label));
            if (device.confidence === 'possible') {
                type.append(el('div', 'table-subtext', 'not confirmed'));
            }

            const name = el('td');
            name.append(el('strong', null, deviceTitle(device)));
            if (device.brand && device.brand !== device.name) {
                name.append(el('div', 'table-subtext', device.brand));
            }
            if (device.is_new) {
                name.append(badge('info', 'New'));
            }

            const ip = el('td', 'nowrap', device.ip || '—');
            if (device.ip_changed) {
                ip.append(el('div', 'table-subtext', `new IP since ${device.ip_changed}`));
            }

            const status = el('td');
            status.append(badge(STATUS_TONE[device.status] || 'neutral', device.status_label));
            if (device.guidance) {
                status.append(el('div', 'table-subtext', 'other subnet'));
            }

            const station = el('td');
            (device.assigned || []).forEach((item) => station.append(badge('success', item.label)));
            if (!(device.assigned || []).length) {
                station.append(el('span', 'text-muted', '—'));
            }

            const actions = el('td', 'row-actions');
            actions.append(button('Manage', 'button button-secondary button-sm', () => openDevice(device.id)));

            tr.append(type, name, ip, el('td', 'nowrap mono', device.mac || '—'), status, station, actions);
            tr.addEventListener('click', function (event) {
                if (!event.target.closest('button')) {
                    openDevice(device.id);
                }
            });
            tr.addEventListener('keydown', function (event) {
                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    openDevice(device.id);
                }
            });
            body.append(tr);
        });

        tableNode.append(head, body);
        wrap.append(tableNode);
        return wrap;
    }

    /* ---------- device drawer ---------- */

    function openDevice(id) {
        const device = deviceById(id);
        if (!device) {
            return;
        }
        openDeviceId = id;
        drawerTitle.textContent = deviceTitle(device);
        drawerBody.replaceChildren(...deviceDetails(device), assignForm(device), currentAssignments(device));
        window.ui.openDrawer('device-drawer');
    }

    function deviceDetails(device) {
        const nodes = [];
        const dl = el('dl', 'vehicle-panel-details');
        const rows = [
            ['Type', device.kind_label + (device.confidence === 'possible' ? ' (not confirmed yet)' : '')],
            ['Brand / maker', device.brand || device.vendor || 'Unknown'],
            ['Model', device.model],
            ['IP address', device.ip],
            ['MAC address', device.mac || 'Unknown (other subnet)'],
            ['Status', device.status_label],
            ['Last seen', device.last_seen],
        ];
        if (device.camera) {
            rows.push(['Video', `RTSP port ${device.camera.rtsp_port || '—'}${device.camera.onvif_xaddr ? ' · ONVIF' : ''}`]);
        }
        if (device.reader) {
            const r = device.reader;
            rows.push(['Connection', r.port ? `${String(r.transport || 'tcp').toUpperCase()} port ${r.port}` : 'Unknown']);
            rows.push(['Data format', r.protocol || 'Not known yet (learned from the first tag)']);
            rows.push(['Work mode', { active: 'Active (sends tags by itself)', answer: 'Answer (polled by this PC)', client: 'Client (connects to this PC)' }[r.work_mode] || 'Unknown']);
            if (r.sample_tags && r.sample_tags.length) {
                rows.push(['Tags seen', r.sample_tags.join(', ')]);
            }
        }
        if (device.open_ports && device.open_ports.length) {
            rows.push(['Open ports', device.open_ports.join(', ')]);
        }
        rows.forEach(function ([label, value]) {
            if (value) {
                dl.append(el('dt', null, label), el('dd', null, value));
            }
        });
        nodes.push(dl);

        if (device.guidance) {
            const box = el('div', 'devices-guidance');
            box.append(el('strong', null, 'Found, but not reachable from this PC'), el('p', null, device.guidance.text));
            if (device.guidance.pc_ip && device.guidance.commands) {
                box.append(el('p', null, `Or give this PC a second address on ${device.guidance.network} (for example ${device.guidance.pc_ip}) with admin rights:`));
                box.append(el('pre', 'devices-command', `${device.guidance.commands.add}\n\n# remove it afterwards:\n${device.guidance.commands.remove}`));
            }
            nodes.push(box);
        }
        return nodes;
    }

    function radioGroup(name, legend, options, selected) {
        const fieldset = el('fieldset', 'devices-choice');
        fieldset.append(el('legend', null, legend));
        options.forEach(function ([value, label]) {
            const wrap = el('label', 'devices-choice-option');
            const input = el('input');
            input.type = 'radio';
            input.name = name;
            input.value = value;
            input.checked = value === selected;
            wrap.append(input, el('span', null, label));
            fieldset.append(wrap);
        });
        return fieldset;
    }

    function assignForm(device) {
        const form = el('form', 'stack-form devices-assign');
        form.noValidate = true;
        form.append(el('h3', 'vehicle-panel-subtitle', 'Assign to a station'));

        const defaultRole = device.kind === 'rfid_reader' ? 'reader' : 'camera';
        const roles = device.kind === 'camera' ? [['camera', 'Camera']]
            : device.kind === 'rfid_reader' ? [['reader', 'UHF Reader']]
                : [['camera', 'Camera'], ['reader', 'UHF Reader']];
        form.append(radioGroup('role', 'Use as', roles, defaultRole));

        const taken = (device.assigned || [])[0];
        form.append(radioGroup('station', 'Station', [['entrance', 'Entrance'], ['exit', 'Exit']], taken ? taken.station : 'entrance'));

        const streams = el('div', 'field devices-camera-only');
        const streamLabel = el('label', null, 'Video stream');
        streamLabel.htmlFor = 'device-stream';
        const select = el('select');
        select.id = 'device-stream';
        select.name = 'stream';
        [['main', 'Main (best quality)'], ['sub', 'Sub (lighter, for a slow PC)']].forEach(function ([value, label]) {
            const option = el('option', null, label);
            option.value = value;
            select.append(option);
        });
        streams.append(streamLabel, select);
        form.append(streams);

        // Asked only when the saved login does not work (needs_credentials).
        const login = el('fieldset', 'devices-login');
        login.hidden = true;
        login.append(el('legend', null, 'Camera login'));
        login.append(field('device-username', 'username', 'Username', 'text', 'off'));
        login.append(field('device-password', 'password', 'Password', 'password', 'new-password'));
        login.append(el('p', 'field-help', 'Saved encrypted and used for both stations. You are asked only once.'));
        form.append(login);

        const message = el('p', 'devices-assign-message');
        message.setAttribute('role', 'status');
        const submit = el('button', 'button button-primary', 'Assign');
        submit.type = 'submit';
        const rowButtons = el('div', 'button-row');
        rowButtons.append(submit);
        form.append(message, rowButtons);

        function syncRole() {
            const role = form.querySelector('input[name="role"]:checked')?.value;
            form.querySelectorAll('.devices-camera-only').forEach((node) => { node.hidden = role !== 'camera'; });
            if (role !== 'camera') {
                login.hidden = true;
            }
        }
        form.addEventListener('change', syncRole);
        syncRole();

        form.addEventListener('submit', async function (event) {
            event.preventDefault();
            const body = {
                role: form.querySelector('input[name="role"]:checked')?.value,
                station: form.querySelector('input[name="station"]:checked')?.value,
                stream: select.value,
            };
            if (!login.hidden) {
                body.username = form.querySelector('[name="username"]').value.trim();
                body.password = form.querySelector('[name="password"]').value;
                if (!body.username) {
                    message.textContent = 'Enter the camera username.';
                    message.dataset.state = 'error';
                    return;
                }
            }

            submit.disabled = true;
            submit.setAttribute('aria-busy', 'true');
            message.textContent = body.role === 'camera' ? 'Checking the camera…' : 'Assigning…';
            message.dataset.state = 'pending';
            try {
                const result = await post(device.assign_url, body);
                if (result.json.needs_credentials) {
                    login.hidden = false;
                    message.textContent = result.json.message;
                    message.dataset.state = 'error';
                    form.querySelector('[name="username"]').focus();
                    return;
                }
                if (!result.ok) {
                    message.textContent = result.json.message || 'Could not assign this device.';
                    message.dataset.state = 'error';
                    return;
                }
                window.ui.toast(result.json.message, 'success');
                if (result.json.warning) {
                    window.ui.toast(result.json.warning, 'warning', { timeout: 8000 });
                }
                apply(result.json.devices);
                window.ui.closeDrawer('device-drawer');
            } catch (error) {
                message.textContent = error.message;
                message.dataset.state = 'error';
            } finally {
                submit.disabled = false;
                submit.removeAttribute('aria-busy');
            }
        });
        return form;
    }

    function field(id, name, label, type, autocomplete) {
        const wrap = el('div', 'field');
        const labelNode = el('label', null, label);
        labelNode.htmlFor = id;
        const input = el('input');
        input.id = id;
        input.name = name;
        input.type = type;
        input.autocomplete = autocomplete;
        wrap.append(labelNode, input);
        return wrap;
    }

    function currentAssignments(device) {
        const box = el('div', 'devices-current');
        if (!(device.assigned || []).length) {
            return box;
        }
        box.append(el('h3', 'vehicle-panel-subtitle', 'Currently used by'));
        device.assigned.forEach(function (item) {
            const row = el('div', 'devices-current-row');
            row.append(badge('success', item.label), button('Unassign', 'button button-secondary button-sm', () => unassign(item.station, item.role)));
            box.append(row);
        });
        return box;
    }

    /* ---------- actions ---------- */

    async function unassign(station, role) {
        try {
            const result = await post(panel.dataset.unassignUrl, { station: station, role: role });
            window.ui.toast(result.json.message, 'success');
            apply(result.json.devices);
            window.ui.closeDrawer('device-drawer');
        } catch (error) {
            window.ui.toast(error.message, 'error');
        }
    }

    panel.querySelector('[data-devices-scan]').addEventListener('click', async function () {
        const scanButton = this;
        scanButton.disabled = true;
        try {
            const result = await post(panel.dataset.scanUrl);
            window.ui.toast(result.json.message, 'info');
            data.scan = Object.assign({}, data.scan, { running: true, progress: 'Waiting for the device service…' });
            rendered.network = null;
            renderNetwork();
            schedule(1500);
        } catch (error) {
            window.ui.toast(error.message, 'error');
            scanButton.disabled = false;
        }
    });

    /* ---------- render + poll ---------- */

    function apply(payload) {
        if (!payload) {
            return;
        }
        data = payload;
        // Re-render a part only when it changed, so keyboard focus and
        // scrolling are not reset by every poll.
        renderIfChanged('network', [data.service, data.network, data.scan, data.counts], renderNetwork);
        renderIfChanged('stations', data.stations, renderStations);
        renderIfChanged('devices', data.devices, renderLists);
        announceNewDevices();
    }

    const rendered = {};

    function renderIfChanged(key, value, render) {
        const json = JSON.stringify(value);
        if (rendered[key] !== json) {
            rendered[key] = json;
            render();
        }
    }

    function announceNewDevices() {
        const fresh = [];
        (data.devices || []).forEach(function (device) {
            if (!known.has(device.id)) {
                known.add(device.id);
                if (device.is_new && (device.kind === 'camera' || device.kind === 'rfid_reader')) {
                    fresh.push(device);
                }
            }
        });

        if (fresh.length && !firstRender) {
            fresh.forEach(function (device) {
                window.ui.toast(`${device.kind_label} found: ${deviceTitle(device)} at ${device.ip}`, 'info', { title: 'New device', timeout: 7000 });
            });
        } else if (fresh.length && firstRender) {
            window.ui.toast(`${fresh.length} new device(s) found since your last visit.`, 'info');
        }
        firstRender = false;

        // Keep the "New" highlight for this visit only.
        if ((data.counts || {}).new && !acknowledgeTimer) {
            acknowledgeTimer = window.setTimeout(function () {
                acknowledgeTimer = null;
                post(panel.dataset.acknowledgeUrl).catch(function () {});
            }, 10000);
        }
    }

    async function refresh() {
        if (document.hidden) {
            schedule(5000);
            return;
        }
        try {
            const response = await window.ui.liveFetch(panel.dataset.indexUrl, { headers: { Accept: 'application/json' } });
            if (response.ok) {
                apply(await response.json());
                // Keep the open drawer's details current without resetting its form.
                if (openDeviceId && !document.getElementById('device-drawer').hidden) {
                    const device = deviceById(openDeviceId);
                    if (device) {
                        const details = drawerBody.querySelector('dl');
                        const fresh = deviceDetails(device)[0];
                        details?.replaceWith(fresh);
                    }
                }
            }
        } catch (error) {
            // Keep the last list during short hiccups.
        }
        schedule((data.scan || {}).running ? 2000 : 5000);
    }

    function schedule(delay) {
        window.clearTimeout(pollTimer);
        pollTimer = window.setTimeout(refresh, delay);
    }

    apply(data);
    schedule(2000);
})();
