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

    function time(value) {
        return window.ui && value ? window.ui.formatTime(value) : '';
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

    /*
     * "Offline" only when the player has no picture: the detector's status
     * can be down for a moment (restarting, its own stream hiccuped) while
     * the camera's live view is fine; hiding it then made the feed go black.
     */
    function renderFeed(card) {
        const state = card.lastState;
        const player = card.querySelector('[data-live-video]')?.liveVideo;
        const picture = Boolean(player?.hasPicture?.());
        const live = state ? state.detectorRunning && state.gate.camera_running : false;
        card.querySelector('[data-gate-camera]')?.replaceChildren(
            picture ? badge('success', 'Live') : badge(live ? 'warning' : 'critical', live ? 'Connecting' : 'Offline')
        );
        setOffline(card, picture || !state ? '' : (live ? '' : offlineText(state.detectorRunning, state.gate)));
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

        try {
            const response = await window.ui.liveFetch(config.stateUrl, { headers: { Accept: 'application/json' } });
            if (!response.ok) {
                return;
            }

            const body = await response.json();
            document.querySelector('[data-gate-detector]')?.replaceChildren(
                badge(body.detector_running ? 'success' : 'warning', body.detector_running ? 'Detector running' : 'Detector starting')
            );

            Object.entries(body.gates || {}).forEach(function ([location, gate]) {
                const card = document.querySelector(`[data-gate="${location}"]`);
                if (!card) {
                    return;
                }

                const live = body.detector_running && gate.camera_running;
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
                renderLatest(card, (gate.logs || [])[0] || null, gate.checking);
                renderLogs(card, gate.logs);
            });
        } catch (error) {
            // Keep the last known values during short network hiccups.
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
