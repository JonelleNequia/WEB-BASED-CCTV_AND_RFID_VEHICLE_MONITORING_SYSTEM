/*
 * UI Phase 2: Gate Monitor live refresh (both gates, one request every 3s).
 * UI Phase 4: the newest row is the big "latest vehicle"; a camera with no
 * picture shows a small placeholder instead of the detector's dark frame.
 */
(function () {
    const dataNode = document.getElementById('gate-monitor-data');
    const config = dataNode ? JSON.parse(dataNode.textContent || '{}') : {};
    const POLL_MS = 2000;  // ROI work, Phase 3: "checking RFID…" shows within about 2 s
    const TONE_LOOK = { info: 'verified', neutral: 'unregistered', warning: 'unknown', critical: 'alert' };
    let refreshSequence = 0;

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

    function occurrenceThumb(log) {
        const url = log.snapshot_url || log.image_url;
        if (!url) return null;
        const link = el('a', 'occurrence-thumb');
        link.href = url;
        link.target = '_blank';
        link.rel = 'noopener';
        link.setAttribute('aria-label', 'View occurrence snapshot');
        const img = el('img', 'thumb thumb-xs');
        img.src = url;
        img.alt = 'Vehicle occurrence snapshot';
        img.loading = 'lazy';
        link.append(img);
        return link;
    }

    function time(value) {
        if (!value) return '';
        return new Intl.DateTimeFormat('en-PH', { timeZone: 'Asia/Manila', month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' }).format(new Date(value));
    }

    function renderLatest(card, log, checking) {
        const box = card.querySelector('[data-gate-latest]');
        if (!box) {
            return;
        }

        // ROI work, Phase 3: a vehicle Detected and not Recorded yet (its RFID check runs).
        const recorded = new Set((card.lastLogs || []).map((row) => row.pass_key).filter(Boolean));
        const open = (Array.isArray(checking) ? checking : []).find((pass) => !recorded.has(pass.event_key));
        if (open) {
            box.className = 'gate-latest result-checking';
            const text = el('div', 'gate-latest-text');
            text.append(el('strong', null, 'Vehicle detected'), el('span', null, 'Checking RFID…'));
            box.replaceChildren(el('span', 'gate-latest-direction', '…'), text, el('time', null, time(open.detected_at)));
            return;
        }

        box.className = `gate-latest result-${log ? (TONE_LOOK[log.tone] || 'unregistered') : 'idle'}`;
        if (!log) {
            box.replaceChildren(el('p', 'gate-latest-empty', 'No vehicles have passed yet'));
            return;
        }

        // "Registered: plate" (owner) or "Unregistered Visitor" (plate).
        const text = el('div', 'gate-latest-text');
        text.append(el('strong', null, log.result_title || log.plate_number || '—'), el('span', null, log.result_detail ?? (log.category_label || '')));
        const photo = occurrenceThumb(log);
        if (photo) text.querySelector('strong').prepend(photo);
        box.replaceChildren(el('span', 'gate-latest-direction', log.direction_label || '—'), text, el('time', null, time(log.event_time)));
    }

    function renderLogs(card, logs) {
        const list = card.querySelector('[data-gate-logs]');
        if (!list) {
            return;
        }

        if (!Array.isArray(logs) || logs.length === 0) {
            list.replaceChildren(el('li', 'gate-logs-empty', 'No activity yet.'));
            return;
        }

        list.replaceChildren(...logs.map(function (log) {
            const item = el('li');
            item.append(
                badge(log.tone || 'neutral', log.direction_label || '—'),
                el('strong', null, log.plate_number || '—'),
                el('span', 'text-muted', log.category_label || ''),
                el('time', null, time(log.event_time))
            );
            const photo = occurrenceThumb(log);
            if (photo) item.querySelector('strong').prepend(photo);
            return item;
        }));
    }

    // One short line; the full reason is in Settings › System Status.
    function offlineText(detectorRunning, gate) {
        if (!detectorRunning) {
            return 'Detector starting…';
        }
        return gate.camera_error ? String(gate.camera_error).split(/(?<=\.)\s/)[0] : 'Camera offline';
    }

    function setOffline(card, text) {
        const feed = card.querySelector('.gate-feed');
        const placeholder = card.querySelector('[data-gate-feed-offline]');
        feed?.classList.toggle('is-offline', !!text);
        if (placeholder) {
            placeholder.hidden = !text;
            placeholder.querySelector('[data-feed-offline-text]').textContent = text || '';
        }
    }

    // MJPEG can deliver the detector's placeholder or an old still. A decoded
    // image alone is not source-health evidence. Independent video can remain
    // healthy while the OCR process restarts.
    function renderFeed(card) {
        const state = card.lastState || { detectorRunning: false, gate: { camera_running: card.dataset.cameraRunning === '1', source_configured: card.dataset.sourceConfigured === '0' ? false : null } };
        const root = card.querySelector('[data-live-video]');
        const player = root?.liveVideo;
        const picture = Boolean(player?.hasPicture?.());
        const missing = state.gate.source_configured === false;
        const live = !card.sourceStatusStale && state.detectorRunning && state.gate.camera_running;
        const mode = player?.mode || root?.dataset.mode;
        const healthy = picture && !missing && (mode !== 'mjpeg' || live);
        card.querySelector('[data-gate-camera]')?.replaceChildren(
            healthy ? badge('success', 'Live') : badge(missing ? 'critical' : 'warning', missing ? 'Source unavailable' : picture ? 'Source unverified' : live ? 'Connecting' : 'Offline')
        );
        setOffline(card, healthy ? '' : missing ? 'No camera added for this gate.' : card.sourceStatusStale ? 'Camera status unavailable' : live ? '' : offlineText(state.detectorRunning, state.gate));
    }

    document.querySelectorAll('[data-gate] [data-live-video]').forEach(function (root) {
        ['live:ready', 'live:error', 'live:stalled'].forEach(function (name) {
            root.addEventListener(name, function () {
                // The player's own root has data-gate too: the card is its parent's.
                const card = root.parentElement?.closest('[data-gate]');
                if (card) {
                    renderFeed(card);
                }
            });
        });
    });

    async function refresh() {
        if (!config.stateUrl) {
            return;
        }

        const sequence = ++refreshSequence;
        try {
            const response = await window.ui.liveFetch(config.stateUrl, { cache: 'no-store', headers: { Accept: 'application/json' } });
            if (sequence !== refreshSequence) return;
            if (!response.ok) {
                document.querySelectorAll('.gate-card[data-gate]').forEach(card => { card.sourceStatusStale = true; renderFeed(card); });
                return;
            }

            const body = await response.json();
            if (sequence !== refreshSequence) return;
            document.querySelector('[data-gate-detector]')?.replaceChildren(
                badge(body.detector_running ? 'success' : 'warning', body.detector_running ? 'Detector running' : 'Detector starting')
            );

            Object.entries(body.gates || {}).forEach(function ([location, gate]) {
                const card = document.querySelector(`[data-gate="${location}"]`);
                if (!card) {
                    return;
                }

                const live = body.detector_running && gate.camera_running;
                card.sourceStatusStale = false;
                card.lastState = { detectorRunning: body.detector_running, gate: gate };
                renderFeed(card);

                // Live view work: the camera is back: the player connects again
                // (WebRTC first), unless it already shows the picture.
                const player = card.querySelector('[data-live-video]')?.liveVideo;
                if (live && card.dataset.wasLive === '0' && !player?.hasPicture?.()) {
                    player?.reconnect();
                }
                card.dataset.wasLive = live ? '1' : '0';

                card.lastLogs = gate.logs || [];
                renderLatest(card, (gate.logs || [])[0] || null, live && gate.source_configured !== false ? gate.checking : []);
                renderLogs(card, gate.logs);
            });
        } catch (error) {
            // Keep the last known values during short network hiccups.
            if (sequence === refreshSequence) document.querySelectorAll('.gate-card[data-gate]').forEach(card => { card.sourceStatusStale = true; renderFeed(card); });
        }
    }

    // Offline when the page was drawn: show the placeholder straight away.
    document.querySelectorAll('.gate-feed.is-offline').forEach(function (feed) {
        const card = feed.closest('[data-gate]');
        card.dataset.wasLive = '0';
        setOffline(card, 'Camera offline');
    });

    refresh();
    window.setInterval(refresh, POLL_MS);
})();
