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
        // Phase 4: do not steal focus from the guest pass pop-up fields.
        if (!rfidInput || document.activeElement === rfidInput || modalOpen()) {
            return;
        }

        rfidInput.focus({ preventScroll: true });
    }

    // Phase 4: guest pass pop-ups and alert banner ---------------------------
    const alertBox = document.querySelector('[data-station-alert]');
    const issueModal = document.querySelector('[data-issue-modal]');
    const issueForm = document.querySelector('[data-issue-form]');
    const cardReturnModal = document.querySelector('[data-card-return-modal]');
    let activeIssue = null;
    let activeCardReturn = null;

    function modalOpen() {
        return Boolean((issueModal && !issueModal.hidden) || (cardReturnModal && !cardReturnModal.hidden));
    }

    function showAlert(title, message) {
        if (!alertBox) {
            return;
        }

        alertBox.querySelector('[data-station-alert-title]').textContent = title;
        alertBox.querySelector('[data-station-alert-message]').textContent = message || '';
        alertBox.hidden = false;
    }

    function openIssueModal(issue) {
        if (!issueModal || !issueForm || !issue?.url) {
            return;
        }

        activeIssue = issue;
        issueForm.reset();
        issueModal.querySelector('[data-issue-pass-label]').textContent = issue.pass_label || 'Guest Pass';
        issueModal.querySelector('[data-issue-snapshot]').src = issue.prefill?.snapshot_url || '';
        issueModal.querySelector('[data-issue-id-required]').hidden = !issue.requires_id;
        issueForm.querySelectorAll('[data-issue-field]').forEach(function (input) {
            input.value = issue.prefill?.[input.dataset.issueField] || '';
        });

        const validSelect = issueModal.querySelector('[data-issue-valid]');
        if (validSelect && issue.valid_minutes) {
            const exists = Array.from(validSelect.options).some(function (option) {
                return Number(option.value) === Number(issue.valid_minutes);
            });
            if (!exists) {
                validSelect.add(new Option(`${issue.valid_minutes} minutes (default)`, issue.valid_minutes));
            }
            validSelect.value = String(issue.valid_minutes);
        }

        issueModal.querySelector('[data-issue-error]').hidden = true;
        issueModal.hidden = false;
        issueForm.querySelector('[name="plate"]').focus();
    }

    function closeIssueModal() {
        if (issueModal) {
            issueModal.hidden = true;
        }
        activeIssue = null;
        focusRfidInput();
    }

    async function submitIssueForm(event) {
        event.preventDefault();

        if (!activeIssue) {
            return;
        }

        const errorNode = issueModal.querySelector('[data-issue-error]');
        const data = Object.fromEntries(new FormData(issueForm).entries());
        data.rfid_scan_log_id = activeIssue.rfid_scan_log_id;

        if (activeIssue.requires_id && !String(data.id_presented || '').trim()) {
            errorNode.textContent = 'Record the ID the guest left at the gate.';
            errorNode.hidden = false;
            return;
        }

        try {
            const response = await fetch(activeIssue.url, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                },
                body: JSON.stringify(data),
            });
            const body = await response.json().catch(function () {
                return {};
            });

            if (!response.ok) {
                const errors = body.errors ? Object.values(body.errors).flat().join(' ') : '';
                throw new Error(errors || body.message || 'The pass could not be issued.');
            }

            setRfidStatus(body.message || 'Guest pass issued.');
            closeIssueModal();
            refreshLogs();
        } catch (error) {
            errorNode.textContent = error.message;
            errorNode.hidden = false;
        }
    }

    function openCardReturnModal(cardReturn) {
        if (!cardReturnModal || !cardReturn?.url) {
            return;
        }

        activeCardReturn = cardReturn;
        cardReturnModal.querySelector('[data-card-return-label]').textContent = `Collect ${cardReturn.pass_label || 'the guest pass'}`;
        cardReturnModal.querySelector('[data-card-return-plate]').textContent = cardReturn.plate || 'N/A';
        cardReturnModal.querySelector('[data-card-return-driver]').textContent = cardReturn.driver_name || 'N/A';
        cardReturnModal.querySelector('[data-card-return-id]').textContent = cardReturn.id_presented || 'None recorded';
        cardReturnModal.hidden = false;
    }

    async function confirmCardReturned() {
        const cardReturn = activeCardReturn;
        cardReturnModal.hidden = true;
        activeCardReturn = null;
        focusRfidInput();

        if (!cardReturn?.url) {
            return;
        }

        try {
            await fetch(cardReturn.url, {
                method: 'POST',
                headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrfToken },
            });
            setRfidStatus('Card return confirmed.');
        } catch (error) {
            setRfidStatus('Card return could not be saved.');
        }
    }

    function handleScanResult(body) {
        if (body.issue) {
            openIssueModal(body.issue);
        }

        if (body.card_return) {
            openCardReturnModal(body.card_return);
        }

        if (body.outcome === 'alert') {
            showAlert('ALERT', body.message);
        } else if (body.anomaly) {
            showAlert('Needs review', body.message);
        }
    }

    issueForm?.addEventListener('submit', submitIssueForm);
    issueModal?.querySelectorAll('[data-issue-cancel]').forEach(function (button) {
        button.addEventListener('click', closeIssueModal);
    });
    cardReturnModal?.querySelector('[data-card-returned]')?.addEventListener('click', confirmCardReturned);
    alertBox?.querySelector('[data-station-alert-close]')?.addEventListener('click', function () {
        alertBox.hidden = true;
    });
    // ------------------------------------------------------------------------

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

    // Phase 5: red banner when the camera reports a vehicle with no pass.
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

        const plate = log.plate_number || 'Unknown plate';
        const hint = payload.location === 'entrance'
            ? 'Tap a guest pass to issue it, or check the vehicle.'
            : 'No tag or guest pass was read. Check the vehicle.';

        showAlert('Vehicle with no pass', `${plate}: ${hint}`);
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

            if (!payload.routes?.recentLogs) {
                renderLogs(body.logs || []);
            }
        } catch (error) {
            setStatusChip(detectorChip, false, 'Detector Ready', 'State Offline');
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

            // Phase 4: show the ingest message and open the matching pop-up.
            setRfidStatus(body.message || 'RFID scan recorded.');
            handleScanResult(body);
            refreshState();
            refreshLogs();
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

            // Phase 4: typing in the pop-up is not an RFID read.
            if (modalOpen() || ['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement?.tagName)) {
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
