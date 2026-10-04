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

        // UI Phase 1: same format as the rest of the system (public/js/ui.js).
        return window.ui ? window.ui.formatDateTime(value, value) : value;
    }

    function updateClock() {
        if (!clock) {
            return;
        }

        clock.textContent = window.ui ? window.ui.formatDateTime(new Date(), '', true) : new Date().toLocaleString();
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

    // Phase 4: alert banner -------------------------------------------------
    const alertBox = document.querySelector('[data-station-alert]');

    function showAlert(title, message) {
        if (!alertBox) {
            return;
        }

        alertBox.querySelector('[data-station-alert-title]').textContent = title;
        alertBox.querySelector('[data-station-alert-message]').textContent = message || '';
        alertBox.hidden = false;
    }

    /* UI Phase 4: big VERIFIED / DENIED / ALERT banner. */
    const resultBox = document.querySelector('[data-scan-result]');
    // UI Phase 3 colors: green registered, gray unregistered, yellow needs a
    // look (unknown tag), red real problem (anomaly, lost tag, inactive vehicle).
    const RESULT_LOOK = {
        verified: { word: 'VERIFIED', icon: '✓' },
        unregistered: { word: 'UNREGISTERED', icon: '•' },
        unknown: { word: 'UNKNOWN TAG', icon: '?' },
        denied: { word: 'DENIED', icon: '✕' },
        alert: { word: 'ALERT', icon: '!' },
    };

    function showScanResult(kind, title, detail) {
        if (!resultBox) {
            return;
        }

        const look = RESULT_LOOK[kind] || RESULT_LOOK.alert;
        resultBox.className = `scan-result is-${kind} is-fresh`;
        resultBox.querySelector('[data-scan-icon]').textContent = look.icon;
        resultBox.querySelector('[data-scan-word]').textContent = look.word;
        resultBox.querySelector('[data-scan-title]').textContent = title || '';
        resultBox.querySelector('[data-scan-detail]').textContent = detail || '';
        resultBox.querySelector('[data-scan-time]').textContent = window.ui ? window.ui.formatTime(new Date(), '', true) : '';
        window.setTimeout(function () {
            resultBox.classList.remove('is-fresh');
        }, 1200);
    }

    function scanResultFor(body) {
        const status = body.scan?.verification_status || '';
        const plate = body.vehicle?.plate_number;
        const tag = body.scan?.tag_uid;

        if (body.outcome === 'recorded') {
            return ['verified', plate, [body.action_taken, body.vehicle?.owner_name].filter(Boolean).join(' · ')];
        }
        // Phase 3: registered, but the camera gives IN or OUT.
        if (body.outcome === 'pending') {
            return ['verified', plate, ['Waiting for the camera', body.vehicle?.owner_name].filter(Boolean).join(' · ')];
        }
        if (body.outcome === 'unknown_tag' || ['unassigned_tag', 'guest', 'non_recurring_category'].includes(status)) {
            return ['unknown', plate || `Tag ${tag || ''}`.trim(), body.anomaly_reason || 'Not in the registry. Ask the admin to register this tag.'];
        }

        return ['alert', plate || `Tag ${tag}`, body.anomaly_reason || body.message];
    }

    function handleScanResult(body) {
        if (!body.duplicate_ignored) {
            showScanResult(...scanResultFor(body));
        }

        if (body.outcome === 'alert') {
            showAlert('ALERT', body.message);
        } else if (body.anomaly) {
            showAlert('Needs review', body.message);
        }
    }

    alertBox?.querySelector('[data-station-alert-close]')?.addEventListener('click', function () {
        alertBox.hidden = true;
    });
    // ------------------------------------------------------------------------

    function normalizeScannedUid(uid) {
        return String(uid || '').replace(/\s+/g, '').trim().toUpperCase();
    }

    // The live view retries by itself (it used to stay hidden after one
    // failed load, e.g. when the page opened before the detector started).
    let streamBroken = false;
    let lastStreamRetry = 0;
    const frameMessage = document.querySelector('[data-frame-message]');

    function showFrameMessage(text) {
        if (frameMessage) {
            frameMessage.textContent = text || '';
            frameMessage.hidden = !text;
        }
    }

    function startLiveStream(streamUrl, forceReload) {
        const base = streamUrl || frame?.dataset.frameStream || payload.streamUrl;

        if (!frame || !base) {
            return;
        }

        frame.onload = function () {
            streamBroken = false;
            frame.classList.remove('is-hidden');
            showFrameMessage('');
        };

        frame.onerror = function () {
            streamBroken = true;
            frame.classList.add('is-hidden');
            showFrameMessage(frameProblem(lastRuntime, lastCamera));
        };

        // An error that happened before this script loaded left a broken image.
        if (!forceReload && frame.src && frame.complete && !frame.naturalWidth) {
            streamBroken = true;
            frame.classList.add('is-hidden');
        }

        if (forceReload) {
            lastStreamRetry = Date.now();
            frame.src = base + (base.includes('?') ? '&' : '?') + 'retry=' + lastStreamRetry;
        } else if (frame.src !== base) {
            frame.src = base;
        }
    }

    let lastRuntime = payload.detectorStatus || {};
    let lastCamera = payload.cameraStatus || {};

    function frameProblem(runtime, camera) {
        if (!runtime?.service_running) {
            return 'Detector not running. It starts by itself; the live view appears when it is ready.';
        }
        if (camera && !camera.camera_running && camera.last_error) {
            return camera.last_error;
        }
        return streamBroken ? 'Live view not loaded yet. Retrying…' : '';
    }

    function stationLogKey(log) {
        return [
            log.record_type || 'log',
            log.id ?? '',
            log.event_time || '',
            log.plate_number || '',
        ].join(':');
    }

    // UI Phase 4: one short line per log (plate, type, time).
    function buildLogItem(log) {
        const item = document.createElement('article');
        const badge = document.createElement('span');
        const plate = document.createElement('strong');
        const type = document.createElement('span');
        const time = document.createElement('time');

        item.className = 'station-log-item station-log-compact' + (log.tone === 'critical' ? ' is-alert' : '');
        badge.className = `station-log-badge tone-${log.tone || 'neutral'}`;
        badge.textContent = log.event_type || 'LOG';
        plate.textContent = log.plate_number || '—';
        type.className = 'station-log-type';
        type.textContent = log.verification_label || '';
        time.className = 'station-log-time';
        time.textContent = window.ui && log.event_time ? window.ui.formatTime(log.event_time) : (log.display_time || '');

        item.append(badge, plate, type, time);

        return item;
    }

    function updateLogItem(item, log) {
        const replacement = buildLogItem(log);
        item.className = replacement.className;
        item.replaceChildren(...replacement.childNodes);
    }

    // A vehicle with no registered tag (Unregistered Visitor): a short notice.
    let logsInitialized = false;
    const announcedAlerts = new Set();

    function announceNoPassAlert(log) {
        if (!log.no_pass_alert || announcedAlerts.has(log.id)) {
            return;
        }

        announcedAlerts.add(log.id);

        if (!logsInitialized || log.alert_location !== payload.location) {
            return;
        }

        // Normal traffic: a gray result, no alert banner (UI Phase 3).
        showScanResult('unregistered', log.plate_number || 'No plate', 'No registered RFID tag was read.');
    }

    function renderLogs(logs) {
        if (!logList) {
            return;
        }

        if (!Array.isArray(logs) || logs.length === 0) {
            logsInitialized = true;
            stationLogNodes.clear();
            const empty = document.createElement('div');
            empty.className = 'station-log-empty';
            empty.textContent = 'No vehicles yet';
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
                announceNoPassAlert(log);
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

        logsInitialized = true;
    }

    function updateStatus(body) {
        const runtime = body?.runtime || payload.detectorStatus || {};
        const camera = body?.camera || runtime.cameras?.[payload.location] || payload.cameraStatus || {};
        const detectorOnline = Boolean(runtime.service_running);
        const cameraOnline = Boolean(camera.camera_running);

        setStatusChip(detectorChip, detectorOnline, 'Detector', 'Detector off');
        setStatusChip(cameraChip, cameraOnline, 'Camera', 'Camera offline');
        if (cameraChip) {
            cameraChip.title = cameraOnline ? '' : (camera.last_error || '');
        }

        lastRuntime = runtime;
        lastCamera = camera;
        if (streamBroken) {
            showFrameMessage(frameProblem(runtime, camera));
            if (detectorOnline && Date.now() - lastStreamRetry > 5000) {
                startLiveStream(body?.stream_url || null, true);
            }
        } else if (!cameraOnline && camera.last_error) {
            showFrameMessage(camera.last_error);
        } else {
            showFrameMessage('');
        }

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

            if (!payload.routes?.recentLogs) {
                renderLogs(body.logs || []);
            }
        } catch (error) {
            setStatusChip(detectorChip, false, 'Detector', 'Detector offline');
        }
    }

    async function refreshLogs() {
        if (!payload.routes?.recentLogs) {
            return;
        }

        try {
            const response = await fetch(payload.routes.recentLogs, {
                headers: {
                    Accept: 'application/json',
                },
            });

            if (!response.ok) {
                throw new Error('Station logs unavailable.');
            }

            const body = await response.json();
            renderLogs(body.logs || []);
        } catch (error) {
            // Keep the last visible logs during transient polling failures.
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
                    reader_name: `${document.title.split(' |')[0] || 'Gate'} Kiosk Reader`,
                }),
            });

            const body = await response.json().catch(function () {
                return {};
            });

            if (!response.ok) {
                const errors = body.errors ? Object.values(body.errors).flat().join(' ') : '';
                throw new Error(body.message || errors || 'RFID scan was not accepted.');
            }

            // Phase 4: show the ingest message and open the matching pop-up.
            setRfidStatus(body.message || 'RFID scan recorded.');
            handleScanResult(body);
            refreshState();
            refreshLogs();
        } catch (error) {
            setRfidStatus(error.message || 'RFID scan failed');
            showScanResult('denied', `Tag ${uid}`, error.message || 'RFID scan failed');
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

            // Phase 4: typing in the pop-up is not an RFID read.
            if (['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement?.tagName)) {
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
    refreshLogs();
    window.setInterval(updateClock, 1000);
    window.setInterval(refreshState, 2000);
    window.setInterval(refreshLogs, 2000);
})();
