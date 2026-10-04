/*
 * UI Phase 2: Gate Monitor live refresh (both gates, one request every 3s).
 * UI Phase 4: the newest row is the big "latest vehicle"; a camera with no
 * picture shows a small placeholder instead of the detector's dark frame.
 */
(function () {
    const dataNode = document.getElementById('gate-monitor-data');
    const config = dataNode ? JSON.parse(dataNode.textContent || '{}') : {};
    const POLL_MS = 3000;
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

    function renderLatest(card, log) {
        const box = card.querySelector('[data-gate-latest]');
        if (!box) {
            return;
        }

        box.className = `gate-latest result-${log ? (TONE_LOOK[log.tone] || 'unregistered') : 'idle'}`;
        if (!log) {
            box.replaceChildren(el('p', 'gate-latest-empty', 'No vehicles have passed yet'));
            return;
        }

        const text = el('div', 'gate-latest-text');
        text.append(el('strong', null, log.plate_number || '—'), el('span', null, log.category_label || ''));
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
                card.querySelector('[data-gate-camera]')?.replaceChildren(badge(live ? 'success' : 'critical', live ? 'Live' : 'Offline'));

                const feed = card.querySelector('[data-gate-feed]');
                const broken = feed?.dataset.broken === '1';
                setOffline(card, live ? (broken ? 'Connecting to the camera…' : '') : offlineText(body.detector_running, gate));

                // Retry a feed that failed to load, or follow a new stream address.
                if (feed && gate.stream_url && (feed.dataset.stream !== gate.stream_url || (broken && live))) {
                    feed.dataset.stream = gate.stream_url;
                    feed.src = gate.stream_url + (broken ? (gate.stream_url.includes('?') ? '&' : '?') + 'retry=' + Date.now() : '');
                }

                renderLatest(card, (gate.logs || [])[0] || null);
                renderLogs(card, gate.logs);
            });
        } catch (error) {
            // Keep the last known values during short network hiccups.
        }
    }

    document.querySelectorAll('[data-gate-feed]').forEach(function (img) {
        const card = img.closest('[data-gate]');
        img.addEventListener('error', function () {
            img.dataset.broken = '1';
            setOffline(card, card.querySelector('[data-feed-offline-text]')?.textContent || 'Connecting to the camera…');
        });
        img.addEventListener('load', function () {
            img.dataset.broken = '0';
        });
        // Offline when the page was drawn: show the placeholder straight away.
        if (img.closest('.gate-feed')?.classList.contains('is-offline')) {
            setOffline(card, 'Camera offline');
        }
    });

    refresh();
    window.setInterval(refresh, POLL_MS);
})();
