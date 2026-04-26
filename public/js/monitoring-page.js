(function () {
    const cameraApi = window.PHILCSTBrowserCamera;
    const payloadNode = document.getElementById('camera-monitoring-data');

    if (!cameraApi || !payloadNode) {
        return;
    }

    const payload = JSON.parse(payloadNode.textContent);
    const cardElements = document.querySelectorAll('[data-monitor-camera]');
    const cards = {};
    const detectorBindings = {
        running: document.querySelector('[data-detector-running]'),
        message: document.querySelector('[data-detector-message]'),
        updated: document.querySelector('[data-detector-updated]'),
        entranceCrossings: document.querySelector('[data-detector-entrance-crossings]'),
        exitCrossings: document.querySelector('[data-detector-exit-crossings]'),
    };

    class MonitoringCard {
        constructor(element, camera, stateUrl) {
            this.element = element;
            this.camera = camera;
            this.stateUrl = stateUrl;
            this.video = element.querySelector('[data-video]');
            this.fallback = element.querySelector('[data-fallback]');
            this.statusTexts = element.querySelectorAll('[data-status-value]');
            this.sourceText = element.querySelector('[data-source-value]');
            this.browserText = element.querySelector('[data-browser-value]');
            this.messageText = element.querySelector('[data-message-value]');
            this.lastSeenText = element.querySelector('[data-last-seen-value]');
            this.statusDot = element.querySelector('[data-status-dot]');
            this.selectedDevice = null;
            this.status = camera.last_connection_status || 'unknown';
        }

        async connectDevice(device) {
            this.selectedDevice = device;

            if (!device) {
                this.showFallback('Not connected');
                this.updateState('not_connected', 'Not connected', 'No browser camera source is currently available.');
                await this.syncState();
                return;
            }

            try {
                await cameraApi.attachDevice(this.video, device.deviceId);
                this.hideFallback();
                this.updateState('connected', 'Connected', 'Live browser preview is active.');
                await this.syncState();
            } catch (error) {
                const errorState = cameraApi.mediaErrorState(error, 'Unable to open this camera.');
                this.showFallback('Not connected');
                this.updateState(errorState.status, errorState.label, errorState.message);
                await this.syncState();
            }
        }

        updateState(status, label, message) {
            this.status = status;
            this.statusTexts.forEach((node) => {
                node.textContent = label;
            });
            this.sourceText.textContent = `${this.camera.source_type} | ${this.camera.source_value}`;
            this.browserText.textContent = this.selectedDevice?.label || this.camera.browser_label || 'No saved browser device';
            this.messageText.textContent = message;
            this.lastSeenText.textContent = status === 'connected'
                ? new Date().toLocaleString()
                : (this.camera.last_connected_at_display || 'Not connected yet');

            this.statusDot.classList.remove('is-connected', 'is-warning', 'is-error');

            if (status === 'connected') {
                this.statusDot.classList.add('is-connected');
            } else if (status === 'denied' || status === 'unavailable') {
                this.statusDot.classList.add('is-warning');
            } else {
                this.statusDot.classList.add('is-error');
            }
        }

        showFallback(message) {
            cameraApi.stopVideo(this.video);
            this.video.classList.add('is-hidden');
            this.fallback.textContent = message;
            this.fallback.classList.remove('is-hidden');
        }

        hideFallback() {
            this.video.classList.remove('is-hidden');
            this.fallback.classList.add('is-hidden');
        }

        async syncState() {
            try {
                const response = await cameraApi.putJson(this.stateUrl, {
                    camera_id: this.camera.id,
                    browser_device_id: this.selectedDevice?.deviceId || this.camera.browser_device_id,
                    browser_label: this.selectedDevice?.label || this.camera.browser_label,
                    last_connection_status: this.status || 'unknown',
                    last_connection_message: this.messageText.textContent,
                });

                this.applyServerCamera(response.camera);
            } catch (error) {
                this.messageText.textContent = error.message || this.messageText.textContent;
            }
        }

        applyServerCamera(camera) {
            if (!camera) {
                return;
            }

            this.camera = camera;
            payload.cameras[this.camera.camera_role] = camera;
            this.sourceText.textContent = `${camera.source_type} | ${camera.source_value}`;
            this.browserText.textContent = this.selectedDevice?.label || camera.browser_label || 'No saved browser device';
        }
    }

    cardElements.forEach((element) => {
        const role = element.dataset.role;
        cards[role] = new MonitoringCard(element, payload.cameras[role], payload.routes.state);
    });

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

    function updateDetectorCard(role, cameraStatus) {
        const statusNode = document.querySelector(`[data-detector-${role}-status]`);
        const readyNode = document.querySelector(`[data-detector-${role}-ready]`);
        const framesNode = document.querySelector(`[data-detector-${role}-frames]`);
        const detectionsNode = document.querySelector(`[data-detector-${role}-detections]`);
        const captureNode = document.querySelector(`[data-detector-${role}-capture]`);
        const retriesNode = document.querySelector(`[data-detector-${role}-retries]`);
        const messageNode = document.querySelector(`[data-detector-${role}-message]`);

        if (!cameraStatus) {
            return;
        }

        if (statusNode) {
            statusNode.textContent = cameraStatus.camera_running ? 'Ready' : 'Standby';
            statusNode.classList.toggle('badge-matched', Boolean(cameraStatus.camera_running));
            statusNode.classList.toggle('badge-secondary', !cameraStatus.camera_running);
            statusNode.classList.remove('badge-unmatched');
        }

        if (readyNode) {
            readyNode.textContent = cameraStatus.detection_ready ? 'Yes' : 'No';
        }

        if (framesNode) {
            framesNode.textContent = cameraStatus.processed_frames ?? 0;
        }

        if (detectionsNode) {
            detectionsNode.textContent = cameraStatus.detections_seen ?? 0;
        }

        if (captureNode) {
            captureNode.textContent = formatDateTime(cameraStatus.last_capture_time, 'No capture yet');
        }

        if (retriesNode) {
            retriesNode.textContent = cameraStatus.retry_count ?? 0;
        }

        if (messageNode) {
            messageNode.textContent = cameraStatus.last_error || 'No additional message.';
        }
    }

    function updateDetectorStatus(runtime) {
        if (!runtime) {
            return;
        }

        if (detectorBindings.running) {
            detectorBindings.running.textContent = runtime.service_running ? 'Ready' : 'Standby';
        }

        if (detectorBindings.message) {
            detectorBindings.message.textContent = runtime.auto_start_message || runtime.service_message || 'No camera trigger status available.';
        }

        if (detectorBindings.updated) {
            detectorBindings.updated.textContent = formatDateTime(runtime.updated_at, 'No update yet');
        }

        if (detectorBindings.entranceCrossings) {
            detectorBindings.entranceCrossings.textContent = runtime.cameras?.entrance?.crossings_logged ?? 0;
        }

        if (detectorBindings.exitCrossings) {
            detectorBindings.exitCrossings.textContent = runtime.cameras?.exit?.crossings_logged ?? 0;
        }

        updateDetectorCard('entrance', runtime.cameras?.entrance);
        updateDetectorCard('exit', runtime.cameras?.exit);
    }

    async function refreshDetectorStatus() {
        if (!payload.routes.detectorStatus) {
            return;
        }

        try {
            const response = await fetch(payload.routes.detectorStatus, {
                headers: {
                    Accept: 'application/json',
                },
            });

            if (!response.ok) {
                throw new Error('Camera trigger status is unavailable.');
            }

            const body = await response.json();
            updateDetectorStatus(body.runtime || payload.detectorStatus);
        } catch (error) {
            if (detectorBindings.message) {
                detectorBindings.message.textContent = error.message || 'Unable to refresh camera trigger status.';
            }
        }
    }

    async function refreshDevices() {
        try {
            const devices = await cameraApi.listVideoInputs();
            const assignments = cameraApi.chooseDevices(payload.cameras, devices);

            for (const role of Object.keys(cards)) {
                const card = cards[role];
                const activeDevice = devices.find((device) => device.deviceId === card.selectedDevice?.deviceId) || null;
                const preferredDevice = activeDevice || assignments[role] || null;

                if (!activeDevice || card.status !== 'connected') {
                    await card.connectDevice(preferredDevice);
                }
            }
        } catch (error) {
            const errorState = cameraApi.mediaErrorState(error, 'Unable to refresh browser cameras.');

            for (const card of Object.values(cards)) {
                card.showFallback('Not connected');
                card.updateState(errorState.status, errorState.label, errorState.message);
                await card.syncState();
            }
        }
    }

    async function boot() {
        try {
            await cameraApi.unlockVideoLabels();
            await refreshDevices();

            if (navigator.mediaDevices?.addEventListener) {
                navigator.mediaDevices.addEventListener('devicechange', refreshDevices);
            }

            updateDetectorStatus(payload.detectorStatus);
            await refreshDetectorStatus();
            window.setInterval(refreshDevices, 5000);
            window.setInterval(refreshDetectorStatus, 10000);
        } catch (error) {
            const errorState = cameraApi.mediaErrorState(error, 'Unable to access browser cameras.');

            for (const card of Object.values(cards)) {
                card.showFallback('Not connected');
                card.updateState(errorState.status, errorState.label, errorState.message);
                await card.syncState();
            }

            updateDetectorStatus(payload.detectorStatus);
            await refreshDetectorStatus();
            window.setInterval(refreshDetectorStatus, 10000);
        }
    }

    boot();
})();
