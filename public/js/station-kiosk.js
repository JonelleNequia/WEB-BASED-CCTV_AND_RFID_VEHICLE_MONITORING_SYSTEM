(function () {
    const payloadNode = document.getElementById('station-kiosk-data');

    if (!payloadNode) {
        return;
    }

    const payload = JSON.parse(payloadNode.textContent);
    const frame = document.querySelector('[data-station-frame]');
    const logList = document.querySelector('[data-station-log-list]');
    const clock = document.querySelector('[data-station-clock]');
    const cameraChip = document.querySelector('[data-camera-status-chip]');
    const detectorChip = document.querySelector('[data-detector-status-chip]');
    const cameraFrames = document.querySelector('[data-camera-frames]');
    const cameraDetections = document.querySelector('[data-camera-detections]');
    const rfidInput = document.querySelector('[data-rfid-input]');
    const rfidStatus = document.querySelector('[data-rfid-status]');
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    let rfidBuffer = '';
    let rfidBufferTimer = null;
    let lastSubmittedUid = '';
    let lastSubmittedAt = 0;
    const stationLogNodes = new Map();

    function formatDateTime(value, fallbackText) {
        if (!value) {
            return fallbackText;
        }

        const date = new Date(value);

        if (Number.isNaN(date.getTime())) {
            return value;
        }

        return date.toLocaleString();
    }

    function updateClock() {
        if (!clock) {
            return;
        }

        clock.textContent = new Date().toLocaleString(undefined, {
            month: 'short',
            day: '2-digit',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit',
        });
    }

    function setStatusChip(node, online, onlineText, standbyText) {
        if (!node) {
            return;
        }

        node.textContent = online ? onlineText : standbyText;
        node.classList.toggle('is-online', online);
        node.classList.toggle('is-standby', !online);
    }

    function setRfidStatus(text) {
        if (!rfidStatus) {
            return;
        }

        rfidStatus.textContent = text;
    }

    function focusRfidInput() {
        if (!rfidInput || document.activeElement === rfidInput) {
            return;
        }

        rfidInput.focus({ preventScroll: true });
    }

    function normalizeScannedUid(uid) {
        return String(uid || '').replace(/\s+/g, '').trim().toUpperCase();
    }

    function startLiveStream(streamUrl) {
        const base = streamUrl || frame?.dataset.frameStream || payload.streamUrl;

        if (!frame || !base) {
            return;
        }

        frame.onload = function () {
            frame.classList.remove('is-hidden');
        };

        frame.onerror = function () {
            frame.classList.add('is-hidden');
        };

        if (frame.src !== base) {
            frame.src = base;
        }
    }

    function stationLogKey(log) {
        return [
            log.record_type || 'log',
            log.id ?? '',
            log.event_time || '',
            log.plate_number || '',
        ].join(':');
    }

    function buildLogItem(log) {
        const item = document.createElement('article');
        const badgeRow = document.createElement('div');
        const badge = document.createElement('span');
        const loggedAt = document.createElement('span');
        const main = document.createElement('div');
        const title = document.createElement('strong');
        const meta = document.createElement('span');
        const details = document.createElement('div');
        const detailItems = [
            ['Owner', log.owner_name || 'N/A'],
            ['Vehicle', log.vehicle_type || 'Vehicle'],
            ['Entries Today', log.entries_today_count ?? 0],
            ['Exits Today', log.exits_today_count ?? 0],
            ['State', log.resulting_state || 'N/A'],
            ['Status', log.status || 'Recorded'],
        ];

        item.className = 'station-log-item';
        badgeRow.className = 'station-log-badge-row';
        main.className = 'station-log-main';
        badge.className = 'station-log-badge';
        loggedAt.className = 'station-log-time';
        details.className = 'station-log-detail-grid';

        title.textContent = log.plate_number || 'GUEST';
        meta.textContent = log.verification_label || 'N/A';
        badge.textContent = log.event_type || payload.eventType || 'LOG';
        loggedAt.textContent = log.display_time || formatDateTime(log.event_time, 'No time');

        detailItems.forEach(function ([label, value]) {
            const wrapper = document.createElement('div');
            const labelNode = document.createElement('span');
            const valueNode = document.createElement('strong');

            labelNode.textContent = label;
            valueNode.textContent = value;
            wrapper.append(labelNode, valueNode);
            details.appendChild(wrapper);
        });

        badgeRow.append(badge, loggedAt);
        main.append(title, meta);
        item.append(badgeRow, main, details);

        return item;
    }

    function updateLogItem(item, log) {
        const replacement = buildLogItem(log);
        item.className = replacement.className;
        item.replaceChildren(...replacement.childNodes);
    }

    function renderLogs(logs) {
        if (!logList) {
            return;
        }

        if (!Array.isArray(logs) || logs.length === 0) {
            stationLogNodes.clear();
            const empty = document.createElement('div');
            empty.className = 'station-log-empty';
            empty.textContent = `No ${payload.logLabel || 'station logs'} yet`;
            logList.replaceChildren(empty);
            return;
        }

        logList.querySelectorAll('.station-log-empty').forEach(function (node) {
            node.remove();
        });

        if (stationLogNodes.size === 0) {
            logList.querySelectorAll('.station-log-item').forEach(function (node) {
                node.remove();
            });
        }

        const seen = new Set();

        logs.forEach(function (log, index) {
            const key = stationLogKey(log);
            let item = stationLogNodes.get(key);

            if (!item) {
                item = buildLogItem(log);
                item.dataset.stationLogKey = key;
                stationLogNodes.set(key, item);
            } else {
                updateLogItem(item, log);
            }

            seen.add(key);

            const currentNode = logList.children[index];
            if (currentNode !== item) {
                logList.insertBefore(item, currentNode || null);
            }
        });

        Array.from(stationLogNodes.entries()).forEach(function ([key, item]) {
            if (seen.has(key)) {
                return;
            }

            item.remove();
            stationLogNodes.delete(key);
        });
    }

    function updateStatus(body) {
        const runtime = body?.runtime || payload.detectorStatus || {};
        const camera = body?.camera || runtime.cameras?.[payload.location] || payload.cameraStatus || {};
        const detectorOnline = Boolean(runtime.service_running);
        const cameraOnline = Boolean(camera.camera_running);

        setStatusChip(detectorChip, detectorOnline, 'Detector Ready', 'Detector Standby');
        setStatusChip(cameraChip, cameraOnline, 'Live', 'Standby');

        if (cameraFrames) {
            cameraFrames.textContent = `${camera.processed_frames ?? 0} frames`;
        }

        if (cameraDetections) {
            cameraDetections.textContent = `${camera.active_detections ?? 0} active / ${camera.detections_seen ?? 0} detections`;
        }
    }

    async function refreshState() {
        if (!payload.routes?.state) {
            return;
        }

        try {
            const response = await fetch(payload.routes.state, {
                headers: {
                    Accept: 'application/json',
                },
            });

            if (!response.ok) {
                throw new Error('Station state unavailable.');
            }

            const body = await response.json();
            updateStatus(body);
            if (body.stream_url && body.stream_url !== frame?.dataset.frameStream) {
                frame.dataset.frameStream = body.stream_url;
                startLiveStream(body.stream_url);
            }
            renderLogs(body.logs || []);
        } catch (error) {
            setStatusChip(detectorChip, false, 'Detector Ready', 'State Offline');
        }
    }

    async function submitRfidScan(rawUid) {
        const uid = normalizeScannedUid(rawUid);

        if (!uid || !payload.routes?.rfidScan) {
            return;
        }

        const now = Date.now();

        if (uid === lastSubmittedUid && now - lastSubmittedAt < 1500) {
            setRfidStatus(`RFID duplicate ignored: ${uid}`);
            return;
        }

        lastSubmittedUid = uid;
        lastSubmittedAt = now;
        setRfidStatus(`RFID scanning: ${uid}`);

        try {
            const response = await fetch(payload.routes.rfidScan, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                },
                body: JSON.stringify({
                    tag_uid: uid,
                    reader_name: `${payload.location || 'station'} station RFID reader`,
                }),
            });

            const body = await response.json().catch(function () {
                return {};
            });

            if (!response.ok) {
                const errors = body.errors ? Object.values(body.errors).flat().join(' ') : '';
                throw new Error(body.message || errors || 'RFID scan was not accepted.');
            }

            const status = body.scan?.verification_status || 'recorded';
            const plate = body.vehicle?.plate_number || body.scan?.vehicle_plate || uid;
            const action = body.action_taken || body.scan?.event_type || status;

            setRfidStatus(`RFID ${status}: ${plate} (${action})`);
            refreshState();
        } catch (error) {
            setRfidStatus(error.message || 'RFID scan failed');
        } finally {
            focusRfidInput();
        }
    }

    function bindRfidScanner() {
        if (!rfidInput) {
            return;
        }

        rfidInput.addEventListener('keydown', function (event) {
            if (event.key !== 'Enter') {
                return;
            }

            event.preventDefault();
            submitRfidScan(rfidInput.value);
            rfidInput.value = '';
        });

        document.addEventListener('keydown', function (event) {
            if (document.activeElement === rfidInput || event.ctrlKey || event.metaKey || event.altKey) {
                return;
            }

            if (event.key === 'Enter') {
                const uid = rfidBuffer;
                rfidBuffer = '';
                submitRfidScan(uid);
                return;
            }

            if (event.key.length !== 1) {
                return;
            }

            rfidBuffer += event.key;
            window.clearTimeout(rfidBufferTimer);
            rfidBufferTimer = window.setTimeout(function () {
                rfidBuffer = '';
            }, 500);
        });

        window.addEventListener('focus', focusRfidInput);
        document.addEventListener('click', focusRfidInput);
        window.setInterval(focusRfidInput, 2000);
        focusRfidInput();
    }

    updateClock();
    updateStatus({ runtime: payload.detectorStatus, camera: payload.cameraStatus });
    renderLogs(payload.logs || []);
    startLiveStream(payload.streamUrl);
    bindRfidScanner();
    window.setInterval(updateClock, 1000);
    window.setInterval(refreshState, 2000);
})();
