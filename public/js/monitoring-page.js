(function () {
    const payloadNode = document.getElementById('camera-monitoring-data');

    if (!payloadNode) {
        return;
    }

    const payload = JSON.parse(payloadNode.textContent);
    const activityStream = document.querySelector('[data-activity-stream]');
    const detectorRunning = document.querySelector('[data-detector-running]');
    const detectorBadge = document.querySelector('[data-detector-status-badge]');
    const detectorMessage = document.querySelector('[data-detector-message]');
    const detectorUpdated = document.querySelector('[data-detector-updated]');
    const frameCards = document.querySelectorAll('[data-monitor-frame-card]');

    function formatDateTime(value, fallback) {
        if (!value) {
            return fallback;
        }

        const date = new Date(value);

        if (Number.isNaN(date.getTime())) {
            return value;
        }

        return date.toLocaleString();
    }

    function setBadgeState(node, ready) {
        if (!node) {
            return;
        }

        node.classList.toggle('badge-matched', ready);
        node.classList.toggle('badge-secondary', !ready);
    }

    function updateRuntime(runtime) {
        if (!runtime) {
            return;
        }

        const running = Boolean(runtime.service_running);

        if (detectorRunning) {
            detectorRunning.textContent = running ? 'Detector Ready' : 'Detector Standby';
        }

        if (detectorBadge) {
            detectorBadge.textContent = running ? 'Running' : 'Standby';
            setBadgeState(detectorBadge, running);
        }

        if (detectorMessage) {
            detectorMessage.textContent = runtime.auto_start_message || runtime.service_message || 'Waiting for detector status.';
        }

        if (detectorUpdated) {
            detectorUpdated.textContent = formatDateTime(runtime.updated_at, 'No update yet');
        }

        ['entrance', 'exit'].forEach(function (role) {
            const cameraStatus = runtime.cameras?.[role] || {};
            const statusNode = document.querySelector(`[data-detector-${role}-status]`);
            const readyNode = document.querySelector(`[data-detector-${role}-ready]`);
            const framesNode = document.querySelector(`[data-detector-${role}-frames]`);
            const detectionsNode = document.querySelector(`[data-detector-${role}-detections]`);
            const crossingsNode = document.querySelector(`[data-detector-${role}-crossings]`);
            const cameraRunning = Boolean(cameraStatus.camera_running);

            if (statusNode) {
                statusNode.textContent = cameraRunning ? 'Ready' : 'Standby';
                setBadgeState(statusNode, cameraRunning);
            }

            if (readyNode) {
                readyNode.textContent = cameraStatus.calibration_ready ? 'Ready' : 'Needs setup';
            }

            if (framesNode) {
                framesNode.textContent = cameraStatus.processed_frames ?? 0;
            }

            if (detectionsNode) {
                detectionsNode.textContent = cameraStatus.detections_seen ?? 0;
            }

            if (crossingsNode) {
                crossingsNode.textContent = cameraStatus.crossings_logged ?? 0;
            }
        });
    }

    function activityBadgeClass(activity) {
        switch (activity.badge) {
            case 'matched':
            case 'closed':
            case 'manual-review':
                return `badge-${activity.badge}`;
            default:
                return 'badge-secondary';
        }
    }

    function renderActivities(activities) {
        if (!activityStream) {
            return;
        }

        activityStream.innerHTML = '';

        if (!Array.isArray(activities) || activities.length === 0) {
            const empty = document.createElement('div');
            empty.className = 'empty-state';
            empty.innerHTML = '<h4>No activity yet</h4><p>Vehicle movements will appear after RFID, CCTV, or guest records are logged.</p>';
            activityStream.appendChild(empty);
            return;
        }

        activities.forEach(function (activity) {
            const item = document.createElement('article');
            item.className = 'stream-item';

            const body = document.createElement('div');
            const title = document.createElement('strong');
            const subtitle = document.createElement('p');
            const time = document.createElement('small');
            const badge = document.createElement('span');

            title.textContent = activity.title || 'Activity';
            subtitle.textContent = activity.subtitle || '';
            time.textContent = activity.display_time || formatDateTime(activity.occurred_at, 'No time');
            badge.className = `badge ${activityBadgeClass(activity)}`;
            badge.textContent = activity.kind || 'LOG';

            body.append(title, subtitle, time);
            item.append(body, badge);
            activityStream.appendChild(item);
        });
    }

    function refreshFrames() {
        const cacheKey = Date.now();

        frameCards.forEach(function (card) {
            const image = card.querySelector('[data-live-frame]');
            const fallback = card.querySelector('[data-frame-fallback]');
            const base = image?.dataset.frameBase;

            if (!image || !base) {
                return;
            }

            image.onload = function () {
                image.classList.remove('is-hidden');
                fallback?.classList.add('is-hidden');
            };

            image.onerror = function () {
                image.classList.add('is-hidden');
                fallback?.classList.remove('is-hidden');
            };

            image.src = `${base}?v=${cacheKey}`;
        });
    }

    async function refreshLiveState() {
        if (!payload.routes.liveState) {
            return;
        }

        try {
            const response = await fetch(payload.routes.liveState, {
                headers: {
                    Accept: 'application/json',
                },
            });

            if (!response.ok) {
                throw new Error('Live monitor state is unavailable.');
            }

            const body = await response.json();
            updateRuntime(body.runtime || payload.detectorStatus);
            renderActivities(body.activities || []);
        } catch (error) {
            if (detectorMessage) {
                detectorMessage.textContent = error.message || 'Unable to refresh live monitor state.';
            }
        }
    }

    updateRuntime(payload.detectorStatus);
    renderActivities(payload.activities || []);
    refreshFrames();
    window.setInterval(refreshFrames, 1000);
    window.setInterval(refreshLiveState, 3000);
})();
