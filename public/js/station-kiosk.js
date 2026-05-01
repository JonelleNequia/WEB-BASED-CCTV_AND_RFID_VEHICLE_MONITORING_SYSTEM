(function () {
    const payloadNode = document.getElementById('station-kiosk-data');

    if (!payloadNode) {
        return;
    }

    const payload = JSON.parse(payloadNode.textContent);
    const frame = document.querySelector('[data-station-frame]');
    const fallback = document.querySelector('[data-frame-fallback]');
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
            fallback?.classList.add('is-hidden');
        };

        frame.onerror = function () {
            frame.classList.add('is-hidden');
            fallback?.classList.remove('is-hidden');
        };

        if (frame.src !== base) {
            frame.src = base;
        }
    }

    function renderLogs(logs) {
        if (!logList) {
            return;
        }

        logList.innerHTML = '';

        if (!Array.isArray(logs) || logs.length === 0) {
            const empty = document.createElement('div');
            empty.className = 'station-log-empty';
            empty.textContent = `No ${payload.eventType || 'station'} logs yet`;
            logList.appendChild(empty);
            return;
        }

        logs.forEach(function (log) {
            const item = document.createElement('article');
            const main = document.createElement('div');
            const title = document.createElement('strong');
            const meta = document.createElement('span');
            const time = document.createElement('small');
            const badge = document.createElement('span');

            item.className = 'station-log-item';
            main.className = 'station-log-main';
            badge.className = 'station-log-badge';

            title.textContent = `${log.event_type || payload.eventType} - ${log.plate_number || 'UNKNOWN'}`;
            meta.textContent = `${log.verification_label || 'N/A'} | ${log.resulting_state || 'N/A'}`;
            time.textContent = log.display_time || formatDateTime(log.event_time, 'No time');
            badge.textContent = log.event_type || payload.eventType || 'LOG';

            main.append(title, meta, time);
            item.append(main, badge);
            logList.appendChild(item);
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
            cameraDetections.textContent = `${camera.detections_seen ?? 0} detections`;
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
