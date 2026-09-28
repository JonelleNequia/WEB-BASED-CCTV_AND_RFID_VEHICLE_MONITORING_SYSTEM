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
        if (role === 'camera') {
            const stream = (assigned.options || {}).stream === 'sub' ? 'Sub' : 'Main';
            const shared = assigned.shared_with
                ? ` · same physical camera as the ${capitalize(assigned.shared_with.station)} (${assigned.shared_with.stream === 'sub' ? 'sub' : 'main'} stream)`
                : '';
            body.append(el('span', 'field-help', `${stream} stream${shared}`));

            // Live state from the detector, with the real reason when there is no video.
            if (assigned.camera_running) {
                body.append(el('span', 'devices-link devices-link-ok', 'Live video'));
            } else if (!assigned.detector_running) {
                body.append(el('span', 'devices-link', 'No video: the detector is not running yet (it starts by itself).'));
            } else if (assigned.camera_error) {
                body.append(el('span', 'devices-link', `No video: ${assigned.camera_error}`));
            }
            body.append(cameraLoginForm(station, assigned));
        }

        row.append(body, button('Unassign', 'button button-secondary button-sm', () => unassign(station, role)));
        return row;
    }

    function capitalize(text) {
        return String(text || '').charAt(0).toUpperCase() + String(text || '').slice(1);
    }

    // Camera login: opened by itself when the camera rejects the saved one.
    function cameraLoginForm(station, assigned) {
        const details = el('details', 'devices-login-inline');
        details.open = assigned.error_code === 'unauthorized';
        details.append(el('summary', null, assigned.error_code === 'unauthorized' ? 'Enter the camera login' : 'Change camera login'));
        const form = el('form', 'devices-login-form');
        form.noValidate = true;
        form.append(
            field(`login-${station}-user`, 'username', 'Username', 'text', 'off'),
            field(`login-${station}-pass`, 'password', 'Password', 'password', 'new-password')
        );
        const message = el('p', 'devices-assign-message');
        const submit = el('button', 'button button-primary button-sm', 'Save and retry');
        submit.type = 'submit';
        form.append(message, submit);
        form.addEventListener('submit', async function (event) {
            event.preventDefault();
            const username = form.querySelector('[name="username"]').value.trim();
            if (!username) {
                message.textContent = 'Enter the camera username.';
                message.dataset.state = 'error';
                return;
            }
            submit.disabled = true;
            message.textContent = 'Checking the camera…';
            message.dataset.state = 'pending';
            try {
                const result = await post(assigned.assign_url, {
                    station: station,
                    role: 'camera',
                    stream: (assigned.options || {}).stream || 'main',
                    username: username,
                    password: form.querySelector('[name="password"]').value,
                });
                if (!result.ok) {
                    message.textContent = result.json.message || 'The camera rejected this login.';
                    message.dataset.state = 'error';
                    return;
                }
                window.ui.toast('Camera login saved (encrypted). Connecting…', 'success');
                apply(result.json.devices);
            } catch (error) {
                message.textContent = error.message;
                message.dataset.state = 'error';
            } finally {
                submit.disabled = false;
            }
        });
        details.append(form);
        return details;
    }

    /* ---------- device tables ---------- */

    /* ---------- diagnostics ---------- */

    const WARNING_TONE = { critical: 'critical', warning: 'warning', info: 'info' };

    function renderDiagnostics() {
        const diag = data.diagnostics || {};
        const box = panel.querySelector('[data-devices-diagnostics]');
        const details = panel.querySelector('[data-devices-diagnostics-box]');
        const warnings = diag.warnings || [];
        const serious = warnings.filter((warning) => warning.level !== 'info');
        const found = ((data.counts || {}).cameras || 0) + ((data.counts || {}).readers || 0);

        panel.querySelector('[data-devices-diagnostics-count]').textContent = serious.length
            ? `(${serious.length} warning${serious.length === 1 ? '' : 's'})` : '(no problems)';
        // Open by itself when nothing was found and there is a reason to show.
        if (serious.length && !found && !details.dataset.touched) {
            details.open = true;
        }

        const nodes = [];
        if (warnings.length) {
            const list = el('ul', 'devices-warnings');
            warnings.forEach(function (warning) {
                const item = el('li');
                item.append(badge(WARNING_TONE[warning.level] || 'neutral', warning.level), el('span', null, warning.message));
                list.append(item);
            });
            nodes.push(list);
        }

        const interfaces = diag.interfaces || [];
        const wrap = el('div', 'table-responsive');
        const tableNode = el('table', 'devices-table');
        const headRow = el('tr');
        ['Interface', 'Type', 'This PC', 'Router', 'Addresses scanned', 'Seen by the OS'].forEach((label) => headRow.append(el('th', null, label)));
        const head = el('thead');
        head.append(headRow);
        const body = el('tbody');
        if (!interfaces.length) {
            const tr = el('tr');
            const td = el('td', 'text-muted', 'No scan yet, or no connected interface.');
            td.colSpan = 6;
            tr.append(td);
            body.append(tr);
        }
        interfaces.forEach(function (item) {
            const tr = el('tr');
            const hosts = item.os_hosts || [];
            const seen = el('td');
            seen.append(el('strong', null, String(hosts.length)));
            if (hosts.length) {
                seen.append(el('div', 'table-subtext mono', hosts.map((host) => `${host.ip} · ${host.mac}`).join('\n')));
            }
            tr.append(
                el('td', null, `${item.label || item.name} (${item.name})`),
                el('td', null, item.kind === 'ethernet' ? 'LAN' : item.kind === 'wifi' ? 'Wi-Fi' : item.kind),
                el('td', 'nowrap', `${item.ip} · ${item.network}${item.link_local ? ' · no DHCP' : ''}`),
                el('td', 'nowrap', item.gateway || '—'),
                el('td', null, String(item.hosts_swept)),
                seen
            );
            body.append(tr);
        });
        tableNode.append(head, body);
        wrap.append(tableNode);
        nodes.push(wrap);

        const firewall = diag.firewall || {};
        const checks = el('p', 'field-help');
        checks.textContent = [
            `Last scan: ${diag.scanned_display || '—'}${diag.trigger ? ` (${diag.trigger})` : ''}${diag.duration ? ` · ${diag.duration}s` : ''}`,
            `Local network access: ${diag.local_network || 'unknown'}`,
            `Firewall: ${firewall.enabled === true ? 'on' : firewall.enabled === false ? 'off' : 'unknown'}${firewall.python_allowed === true ? ' (Python allowed)' : firewall.python_allowed === false ? ' (Python NOT allowed)' : ''}`,
            `Admin rights: ${diag.admin ? 'yes' : 'no'}`,
            `ONVIF replies: ${diag.onvif_replies || 0}`,
            `Reader-module replies: ${diag.module_replies || 0}`,
        ].join(' · ');
        nodes.push(checks);

        box.replaceChildren(...nodes);
    }

    panel.querySelector('[data-devices-diagnostics-box]').addEventListener('toggle', function () {
        this.dataset.touched = '1';
    });

    function renderLists() {
        const devices = data.devices || [];
        const main = devices.filter((device) => ['camera', 'rfid_reader'].includes(device.kind));
        const other = devices.filter((device) => !['camera', 'rfid_reader'].includes(device.kind));

        // "Other" devices: only the networks this PC is on now, unless asked.
        const networks = ((data.network || {}).interfaces || []).map((item) => item.network);
        const onCurrentNetwork = (device) => device.status === 'online' || networks.includes(device.subnet);
        const showOld = panel.querySelector('[data-devices-show-old]')?.checked;
        const oldCount = other.filter((device) => !onCurrentNetwork(device)).length;
        panel.querySelector('[data-devices-old-count]').textContent = oldCount;
        const otherShown = showOld ? other : other.filter(onCurrentNetwork);

        const list = panel.querySelector('[data-devices-list]');
        if (!main.length) {
            const empty = el('div', 'empty-block');
            empty.append(
                el('strong', null, (data.scan || {}).running ? 'Looking for cameras and readers…' : 'No camera or UHF reader found yet'),
                el('p', null, 'Plug the LAN cable of the camera and the reader into the router/switch (or straight into this PC). They appear here within a few seconds.'),
                el('p', null, 'Still nothing? Open Diagnostics below: it shows which network was scanned and what this PC can see.')
            );
            list.replaceChildren(empty);
        } else {
            list.replaceChildren(table(main));
        }

        panel.querySelector('[data-devices-other-count]').textContent = otherShown.length;
        panel.querySelector('[data-devices-other]').replaceChildren(otherShown.length ? table(otherShown) : el('p', 'text-muted', 'None on the current network.'));
    }

    function table(devices) {
        const wrap = el('div', 'table-responsive');
        const tableNode = el('table', 'devices-table');
        const head = el('thead');
        const headRow = el('tr');
        ['Type', 'Device', 'IP address', 'MAC address', 'Maker', 'Open ports', 'Status', 'Station', ''].forEach((label) => headRow.append(el('th', null, label)));
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

            tr.append(
                type, name, ip, el('td', 'nowrap mono', device.mac || '—'),
                el('td', null, device.vendor || '—'),
                el('td', 'mono', (device.open_ports || []).join(', ') || '—'),
                status, station, actions
            );
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
        const freeStation = taken ? (taken.station === 'entrance' ? 'exit' : 'entrance') : 'entrance';
        form.append(radioGroup('station', 'Station', [['entrance', 'Entrance'], ['exit', 'Exit']], freeStation));

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

        // One camera for both stations (testing): the other station gets the other stream.
        const cameraUse = (device.assigned || []).find((item) => item.role === 'camera');
        if (device.kind === 'camera' && cameraUse) {
            select.value = cameraUse.stream === 'sub' ? 'main' : 'sub';
            streams.append(el('span', 'field-help', `Already the ${capitalize(cameraUse.station)} camera (${cameraUse.stream === 'sub' ? 'sub' : 'main'} stream). You can use it for the other station too; it will show as the same device.`));
        }

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
        // Do not redraw the station cards while someone types a camera login there.
        if (!panel.querySelector('[data-devices-stations]').contains(document.activeElement)) {
            renderIfChanged('stations', data.stations, renderStations);
        }
        renderIfChanged('devices', data.devices, renderLists);
        renderIfChanged('diagnostics', data.diagnostics, renderDiagnostics);
        renderIdentify();
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
        schedule((data.scan || {}).running || (data.identify || {}).running || identifyPending ? 1500 : 5000);
    }

    /* ---------- identify reader ---------- */

    function renderIdentify() {
        const box = panel.querySelector('[data-devices-identify-box]');
        const identify = data.identify;
        const identifyButton = panel.querySelector('[data-devices-identify]');
        identifyButton.disabled = !!(identify && identify.running) || identifyPending;
        if (!identify && !identifyPending) {
            box.hidden = true;
            return;
        }
        box.hidden = false;
        const nodes = [];
        const title = el('div', 'devices-network-head');

        if (identifyPending && !(identify && identify.running)) {
            title.append(badge('info', 'Identify reader'), el('span', null, 'Starting… Hold a UHF tag close to the reader.'));
            box.replaceChildren(title);
            return;
        }

        if (identify.running) {
            const ends = new Date(identify.started_at).getTime() + (identify.seconds || 45) * 1000;
            const left = Math.max(0, Math.round((ends - Date.now()) / 1000));
            title.append(badge('info', `Listening · ${left}s`), el('strong', null, 'Hold a UHF tag close to the reader now.'));
            nodes.push(title, el('p', 'field-help', `${identify.phase || ''}. Checking ${(identify.candidates || []).length} device(s): ${(identify.candidates || []).join(', ') || 'none found on this network'}.`));
        } else if ((identify.found || []).length) {
            const found = identify.found[0];
            title.append(badge('success', 'Reader found'), el('strong', null, `${found.ip} · ${String(found.transport).toUpperCase()} port ${found.port || '—'} · format ${found.protocol || '—'}`));
            nodes.push(title, el('p', 'field-help', `Tag read: ${(found.tags || []).join(', ') || '—'}. It is now listed as an RFID Reader: open it below and assign it to a station.`));
        } else {
            title.append(badge('warning', 'No reader found'), el('span', null, identify.message || ''));
            nodes.push(title);
            nodes.push(el('p', 'field-help', `Checked: ${(identify.candidates || []).join(', ') || 'no candidate devices on this network'}. If the reader is not in the device list at all, it is not on this network (power, cable, or a fixed IP on another network).`));
        }

        if ((identify.unknown_data || []).length) {
            const list = el('ul', 'devices-warnings');
            identify.unknown_data.forEach(function (item) {
                const li = el('li');
                li.append(badge('warning', 'data'), el('span', 'mono', `${item.ip} ${item.transport}/${item.port}: ${item.bytes} bytes in an unknown format: ${item.raw_hex}`));
                list.append(li);
            });
            nodes.push(el('p', 'field-help', 'These ports sent data the system cannot read yet (send this to the developer):'), list);
        }
        box.replaceChildren(...nodes);
    }

    let identifyPending = false;
    panel.querySelector('[data-devices-identify]').addEventListener('click', async function () {
        identifyPending = true;
        renderIdentify();
        try {
            const result = await post(panel.dataset.identifyUrl);
            window.ui.toast(result.json.message, 'info', { timeout: 8000 });
            schedule(1500);
        } catch (error) {
            window.ui.toast(error.message, 'error');
        } finally {
            window.setTimeout(function () { identifyPending = false; }, 6000);
        }
    });

    panel.querySelector('[data-devices-show-old]')?.addEventListener('change', function () {
        rendered.devices = null;
        renderIfChanged('devices', data.devices, renderLists);
    });

    function schedule(delay) {
        window.clearTimeout(pollTimer);
        pollTimer = window.setTimeout(refresh, delay);
    }

    apply(data);
    schedule(2000);
})();
