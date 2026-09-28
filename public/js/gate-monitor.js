/*
 * UI Phase 2: Gate Monitor live refresh (both gates, one request every 3s).
 */
(function () {
    const dataNode = document.getElementById('gate-monitor-data');
    const config = dataNode ? JSON.parse(dataNode.textContent || '{}') : {};
    const POLL_MS = 3000;

    function badge(tone, label) {
        const node = document.createElement('span');
        node.className = `badge badge-tone-${tone}`;
        node.textContent = label;
        return node;
    }

    function renderLatest(card, scan) {
        const box = card.querySelector('[data-gate-latest]');
        if (!box) {
            return;
        }

        const heading = document.createElement('span');
        heading.className = 'gate-section-label';
        heading.textContent = 'Latest scan';

        if (!scan) {
            const empty = document.createElement('p');
            empty.className = 'text-muted';
            empty.textContent = 'No scans at this gate yet.';
            box.replaceChildren(heading, empty);
            return;
        }

        const row = document.createElement('div');
        row.className = 'gate-latest-row';
        const text = document.createElement('div');
        const title = document.createElement('strong');
        title.textContent = scan.title;
        const subtitle = document.createElement('span');
        subtitle.className = 'text-muted';
        subtitle.textContent = scan.subtitle || '';
        text.append(title, subtitle);
        row.append(text, badge(scan.tone, scan.result));

        const time = document.createElement('small');
        time.className = 'text-muted';
        time.textContent = scan.time + (scan.note ? ` · ${scan.note}` : '');

        box.replaceChildren(heading, row, time);
    }

    function renderLogs(card, logs) {
        const list = card.querySelector('[data-gate-logs]');
        if (!list) {
            return;
        }

        if (!Array.isArray(logs) || logs.length === 0) {
            const empty = document.createElement('li');
            empty.className = 'text-muted';
            empty.textContent = 'No activity yet.';
            list.replaceChildren(empty);
            return;
        }

        list.replaceChildren(...logs.map(function (log) {
            const item = document.createElement('li');
            const plate = document.createElement('strong');
            plate.textContent = log.plate_number || '—';
            const label = document.createElement('span');
            label.className = 'text-muted';
            label.textContent = log.verification_label || '';
            const time = document.createElement('time');
            time.textContent = window.ui ? window.ui.formatTime(log.event_time) : '';
            item.append(plate, label, time);
            return item;
        }));
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
            const detector = document.querySelector('[data-gate-detector]');
            detector?.replaceChildren(badge(body.detector_running ? 'success' : 'warning', body.detector_running ? 'Detector running' : 'Detector not running'));

            Object.entries(body.gates || {}).forEach(function ([location, gate]) {
                const card = document.querySelector(`[data-gate="${location}"]`);
                if (!card) {
                    return;
                }

                card.querySelector('[data-gate-camera]')?.replaceChildren(
                    badge(gate.camera_running ? 'success' : 'warning', gate.camera_running ? 'Live' : 'Offline')
                );

                // Why there is no picture, and retry a feed that failed to load.
                const fallback = card.querySelector('.gate-feed-fallback');
                if (fallback) {
                    fallback.textContent = !body.detector_running
                        ? 'Detector not running. It starts by itself.'
                        : (!gate.camera_running && gate.camera_error ? gate.camera_error : 'Waiting for camera…');
                }
                const feed = card.querySelector('[data-gate-feed]');
                const offline = feed?.closest('.gate-feed')?.classList.contains('is-offline');
                if (feed && gate.stream_url && (feed.dataset.stream !== gate.stream_url || (offline && body.detector_running))) {
                    feed.dataset.stream = gate.stream_url;
                    feed.src = gate.stream_url + (offline ? (gate.stream_url.includes('?') ? '&' : '?') + 'retry=' + Date.now() : '');
                }

                renderLatest(card, gate.latest_scan);
                renderLogs(card, gate.logs);
            });
        } catch (error) {
            // Keep the last known values during short network hiccups.
        }
    }

    document.querySelectorAll('[data-gate-feed]').forEach(function (img) {
        img.addEventListener('error', function () {
            img.closest('.gate-feed')?.classList.add('is-offline');
        });
        img.addEventListener('load', function () {
            img.closest('.gate-feed')?.classList.remove('is-offline');
        });
    });

    refresh();
    window.setInterval(refresh, POLL_MS);
})();
