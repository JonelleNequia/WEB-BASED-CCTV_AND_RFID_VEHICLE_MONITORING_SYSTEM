(function () {
    const cameraApi = window.PHILCSTBrowserCamera;
    const payloadNode = document.getElementById('camera-calibration-data');
    const HEARTBEAT_INTERVAL_MS = 4000;
    const STREAM_RECONNECT_INTERVAL_MS = 5000;

    if (!cameraApi || !payloadNode) {
        return;
    }

    const payload = JSON.parse(payloadNode.textContent);
    const cardElements = document.querySelectorAll('[data-calibration-camera]');
    const cards = {};
    const lastStreamReconnectAt = {};

    class CalibrationCard {
        constructor(element, camera, routes) {
            this.element = element;
            this.camera = camera;
            this.routes = routes;
            this.video = element.querySelector('[data-video]');
            this.canvas = element.querySelector('[data-overlay]');
            this.fallbackContainer = element.querySelector('.camera-fallback');
            this.fallback = element.querySelector('[data-fallback]');
            this.fallbackDetail = element.querySelector('[data-fallback-detail]');
            this.deviceSelect = element.querySelector('[data-device-select]');
            this.statusBadge = element.querySelector('[data-status-badge]');
            this.statusValue = element.querySelector('[data-status-value]');
            this.sourceValue = element.querySelector('[data-source-value]');
            this.browserValue = element.querySelector('[data-browser-value]');
            this.messageValue = element.querySelector('[data-message-value]');
            this.maskValue = element.querySelector('[data-mask-value]');
            this.lineValue = element.querySelector('[data-line-value]');
            this.directionValue = element.querySelector('[data-direction-value]');
            this.crossingList = element.querySelector('[data-crossings]');
            this.saveButton = element.querySelector('[data-save]');
            this.ctx = this.canvas.getContext('2d');
            this.streamUrl = camera.stream_url || this.video?.dataset.streamUrl || "";
            this.selectedDevice = null;
            this.availableDevices = [];
            this.currentTool = 'mask';
            this.pointerStart = null;
            this.pointerId = null;
            this.draftShape = null;
            this.maskShape = camera.calibration_mask || null;
            this.maskDraftPoints = [];
            this.lineShape = camera.calibration_line || null;

            this.bindEvents();
            this.updateCalibrationSummary();
            this.setActiveTool('mask');
            this.render();
        }

        bindEvents() {
            this.deviceSelect?.addEventListener('change', async () => {
                this.streamUrl = this.deviceSelect.value || this.streamUrl;
                await this.connectStream();
            });

            this.element.querySelectorAll('[data-tool]').forEach((button) => {
                button.addEventListener('click', () => {
                    this.setActiveTool(button.dataset.tool);
                });
            });

            this.element.querySelector('[data-clear]').addEventListener('click', () => {
                this.maskShape = null;
                this.maskDraftPoints = [];
                this.lineShape = null;
                this.draftShape = null;
                this.updateCalibrationSummary();
                this.render();
            });

            // Phase 2: which side of the line is IN (the arrow on the canvas).
            this.element.querySelector('[data-flip-direction]')?.addEventListener('click', () => {
                if (!this.lineShape) {
                    this.messageValue.textContent = 'Draw the trigger line first.';
                    return;
                }

                this.lineShape = { ...this.lineShape, in_side: this.inSide() * -1 };
                this.messageValue.textContent = 'IN direction flipped. Save to apply it.';
                this.updateCalibrationSummary();
                this.render();
            });

            this.saveButton.addEventListener('click', async () => {
                await this.saveCalibration();
            });

            this.canvas.addEventListener('pointerdown', (event) => this.handlePointerDown(event));
            this.canvas.addEventListener('pointermove', (event) => this.handlePointerMove(event));
            this.canvas.addEventListener('pointerup', (event) => this.handlePointerUp(event));
            this.canvas.addEventListener('dblclick', (event) => event.preventDefault());
            this.canvas.addEventListener('pointercancel', () => this.cancelDraft());
            this.canvas.addEventListener('pointerleave', () => this.cancelDraft());
            // Live view work: this.video is the live player (live-video.js); it
            // says when its picture is ready, which mode it uses, or that it failed.
            this.video.addEventListener('live:mode', () => {
                this.resizeCanvas();
                this.render();
            });
            this.video.addEventListener('live:ready', () => {
                this.resizeCanvas();
                this.hideFallback();
                const mode = this.video.dataset.mode;
                this.updateConnection('connected', 'Connected', mode === 'mjpeg' ? 'Basic live view (detector stream).' : 'Full-quality live view (camera main stream).');
                this.syncState();
                this.render();
            });
            this.video.addEventListener('live:error', () => {
                // Say why, from the detector status, instead of a generic message.
                const problem = this.streamProblem();
                this.showFallback(problem.title, problem.detail);
                this.updateConnection('unavailable', 'Not connected', problem.detail);
                this.syncState();
            });

            window.addEventListener('resize', () => {
                this.resizeCanvas();
                this.render();
            });
        }

        streamProblem() {
            const detector = this.detectorState || {};
            const camera = detector.camera || this.camera.detector_status || {};

            if (detector.running === false || (!detector.running && !camera.camera_running && !camera.last_capture_time)) {
                return {
                    title: 'Detector not running',
                    detail: detector.message || 'The vehicle detector is starting. This view connects by itself when it is ready.',
                };
            }
            if (camera.last_error && !camera.camera_running) {
                return { title: 'Camera not connected', detail: camera.last_error };
            }
            return { title: 'Stream unavailable', detail: 'The live view could not be loaded. Retrying…' };
        }

        setAvailableDevices(devices, preferredDevice) {
            this.availableDevices = devices;
            this.deviceSelect.innerHTML = '';
            this.deviceSelect.disabled = devices.length === 0;

            const emptyOption = document.createElement('option');
            emptyOption.value = '';
            emptyOption.textContent = devices.length ? 'Select a camera source' : 'No camera source detected';
            this.deviceSelect.appendChild(emptyOption);

            devices.forEach((device, index) => {
                const option = document.createElement('option');
                option.value = device.deviceId;
                option.textContent = device.label || `Camera ${index + 1}`;
                this.deviceSelect.appendChild(option);
            });

            this.deviceSelect.value = preferredDevice?.deviceId || this.selectedDevice?.deviceId || '';
        }

        async connectDevice(device) {
            this.selectedDevice = device;

            if (!device) {
                this.deviceSelect.value = '';
                this.showFallback('Not connected', 'Select or reconnect a browser camera source to continue calibration.');
                this.updateConnection('not_connected', 'Not connected', 'No camera source selected.');
                await this.syncState();
                return;
            }

            try {
                await cameraApi.attachDevice(this.video, device.deviceId);
                this.resizeCanvas();
                this.hideFallback();
                this.updateConnection('connected', 'Connected', 'Browser preview connected.');
                await this.syncState();
            } catch (error) {
                const errorState = cameraApi.mediaErrorState(error, 'Unable to open the selected camera.');
                this.showFallback('Not connected', errorState.message);
                this.updateConnection(errorState.status, errorState.label, errorState.message);
                await this.syncState();
            }
        }

        async connectStream() {
            if (!this.video || !this.streamUrl) {
                this.showFallback('Stream unavailable', 'No detector stream URL is configured for this camera.');
                this.updateConnection('unavailable', 'Not connected', 'No detector stream URL is configured.');
                await this.syncState();
                return;
            }

            this.video.liveVideo?.reconnect();
            this.resizeCanvas();
            this.updateConnection('not_connected', 'Connecting', 'Opening the live view…');
            this.render();
        }

        updateConnection(status, label, message) {
            this.connectionStatus = status;
            this.statusValue.textContent = label;
            this.messageValue.textContent = message;
            this.sourceValue.textContent = this.camera.source_display || this.camera.source_type;
            this.browserValue.textContent = this.streamUrl || this.camera.browser_label || 'No detector stream URL';
            this.statusBadge.textContent = label;
            this.statusBadge.className = `badge ${
                status === 'connected'
                    ? 'badge-matched'
                    : (status === 'denied' || status === 'unavailable' ? 'badge-manual-review' : 'badge-unmatched')
            }`;
        }

        showFallback(message, detailMessage = null) {
            cameraApi.stopVideo(this.video);
            this.video.classList.add('is-hidden');
            this.fallback.textContent = message;
            if (this.fallbackDetail) {
                this.fallbackDetail.textContent = detailMessage || 'Allow browser camera access or reconnect this device to continue calibration.';
            }
            this.fallbackContainer?.classList.remove('is-hidden');
        }

        hideFallback() {
            this.video.classList.remove('is-hidden');
            this.fallbackContainer?.classList.add('is-hidden');
        }

        setActiveTool(tool) {
            this.currentTool = tool;

            this.element.querySelectorAll('[data-tool]').forEach((button) => {
                button.classList.toggle('is-active', button.dataset.tool === tool);
            });
        }

        resizeCanvas() {
            const width = this.video.clientWidth || this.element.querySelector('.camera-stage').clientWidth;
            const height = this.video.clientHeight || this.element.querySelector('.camera-stage').clientHeight;

            if (!width || !height) {
                return;
            }

            this.canvas.width = width;
            this.canvas.height = height;
        }

        /*
         * Where the camera image really is inside the canvas. The stream is
         * shown with object-fit (cover crops, contain letterboxes), so a
         * stream whose shape differs from the 16:9 box is not the whole
         * canvas. Saved points are normalized (0-1) to the IMAGE, the same
         * frame the detector scales them to.
         */
        contentRect() {
            const width = this.canvas.width;
            const height = this.canvas.height;
            const media = this.video.liveVideo?.activeElement() || this.video;
            const naturalWidth = media.naturalWidth || media.videoWidth || 0;
            const naturalHeight = media.naturalHeight || media.videoHeight || 0;
            const fit = window.getComputedStyle(media).objectFit;

            if (!naturalWidth || !naturalHeight || !width || !height || fit === 'fill') {
                return { x: 0, y: 0, width: width, height: height };
            }

            const scale = fit === 'contain'
                ? Math.min(width / naturalWidth, height / naturalHeight)
                : Math.max(width / naturalWidth, height / naturalHeight);
            const shownWidth = naturalWidth * scale;
            const shownHeight = naturalHeight * scale;

            return { x: (width - shownWidth) / 2, y: (height - shownHeight) / 2, width: shownWidth, height: shownHeight };
        }

        toImagePolygon(points) {
            const rect = this.contentRect();
            const shifted = (points || []).map((point) => ({ x: point.x - rect.x, y: point.y - rect.y }));

            return cameraApi.normalisePolygon(shifted, rect.width, rect.height);
        }

        toCanvasPolygon(shape) {
            const rect = this.contentRect();
            const points = cameraApi.denormalisePolygon(shape, rect.width, rect.height);

            return points ? points.map((point) => ({ x: point.x + rect.x, y: point.y + rect.y })) : null;
        }

        toImageLine(line) {
            const rect = this.contentRect();

            return line ? cameraApi.normaliseLine({
                x1: line.x1 - rect.x, y1: line.y1 - rect.y, x2: line.x2 - rect.x, y2: line.y2 - rect.y,
            }, rect.width, rect.height) : null;
        }

        toCanvasLine(line) {
            const rect = this.contentRect();
            const scaled = cameraApi.denormaliseLine(line, rect.width, rect.height);

            return scaled ? {
                x1: scaled.x1 + rect.x, y1: scaled.y1 + rect.y, x2: scaled.x2 + rect.x, y2: scaled.y2 + rect.y,
            } : null;
        }

        getCanvasPoint(event) {
            const bounds = this.canvas.getBoundingClientRect();

            return {
                x: event.clientX - bounds.left,
                y: event.clientY - bounds.top,
            };
        }

        handlePointerDown(event) {
            if (this.connectionStatus !== 'connected') {
                return;
            }

            event.preventDefault();

            if (this.currentTool === 'mask') {
                this.addPolygonPoint(this.getCanvasPoint(event));
                return;
            }

            this.pointerId = event.pointerId;
            this.canvas.setPointerCapture?.(event.pointerId);
            this.pointerStart = this.getCanvasPoint(event);
        }

        handlePointerMove(event) {
            if (!this.pointerStart || (this.pointerId !== null && event.pointerId !== this.pointerId)) {
                return;
            }

            event.preventDefault();
            const currentPoint = this.getCanvasPoint(event);

            if (this.currentTool === 'line') {
                this.draftShape = {
                    type: 'line',
                    value: {
                        x1: this.pointerStart.x,
                        y1: this.pointerStart.y,
                        x2: currentPoint.x,
                        y2: currentPoint.y,
                    },
                };
            }

            this.render();
        }

        handlePointerUp(event) {
            if (!this.pointerStart || !this.draftShape || (this.pointerId !== null && event.pointerId !== this.pointerId)) {
                return;
            }

            event.preventDefault();

            if (this.draftShape.type === 'line') {
                const inSide = this.inSide();
                this.lineShape = this.toImageLine(this.draftShape.value);
                if (this.lineShape) {
                    this.lineShape.in_side = inSide;
                }
            }

            this.pointerStart = null;
            this.draftShape = null;
            this.updateCalibrationSummary();
            this.render();
        }

        addPolygonPoint(point) {
            const points = this.toCanvasPolygon(this.maskShape) || this.maskDraftPoints;

            points.push(point);
            this.maskDraftPoints = points;
            this.maskShape = points.length >= 3
                ? this.toImagePolygon(points)
                : null;
            this.updateCalibrationSummary();
            this.render();
        }

        cancelDraft() {
            this.pointerStart = null;
            this.pointerId = null;
            this.draftShape = null;
            this.render();
        }

        updateCalibrationSummary() {
            const pointCount = Array.isArray(this.maskShape)
                ? this.maskShape.length
                : this.maskDraftPoints.length;

            this.maskValue.textContent = pointCount >= 3
                ? `${pointCount}-point polygon saved or drawn`
                : 'No polygon yet';
            this.lineValue.textContent = this.lineShape ? 'Line saved or drawn' : 'No line yet';
            if (this.directionValue) {
                this.directionValue.textContent = this.lineShape
                    ? 'IN = the side the arrow points to'
                    : 'Draw a line first';
            }
        }

        inSide() {
            return Number(this.lineShape?.in_side) < 0 ? -1 : 1;
        }

        renderCrossings(crossings) {
            if (!this.crossingList || !Array.isArray(crossings)) {
                return;
            }

            this.crossingList.innerHTML = '';

            if (crossings.length === 0) {
                const empty = document.createElement('li');
                empty.className = 'field-help';
                empty.textContent = 'No crossing recorded yet.';
                this.crossingList.appendChild(empty);
                return;
            }

            crossings.forEach((crossing) => {
                const item = document.createElement('li');
                const badge = document.createElement('span');
                badge.className = `badge ${['IN', 'OUT'].includes(crossing.direction) ? 'badge-open' : 'badge-manual-review'}`;
                badge.textContent = crossing.direction_label;
                item.appendChild(badge);
                const details = [crossing.time, `track #${crossing.track_id ?? '—'}`];
                if (crossing.confidence !== null && crossing.confidence !== undefined) {
                    details.push(Number(crossing.confidence).toFixed(2));
                }
                if (crossing.reason) {
                    details.push(crossing.reason);
                }
                item.appendChild(document.createTextNode(` ${details.join(' · ')}`));
                this.crossingList.appendChild(item);
            });
        }

        async saveCalibration() {
            if (this.maskShape && !Array.isArray(this.maskShape)) {
                this.maskShape = this.toImagePolygon(this.toCanvasPolygon(this.maskShape));
            }

            if (!this.maskShape && this.maskDraftPoints.length > 0) {
                this.messageValue.textContent = 'Add at least 3 ROI points before saving.';
                return;
            }

            if (this.maskShape && (!Array.isArray(this.maskShape) || this.maskShape.length < 3)) {
                this.messageValue.textContent = 'Add at least 3 ROI points before saving.';
                return;
            }

            this.saveButton.disabled = true;
            this.saveButton.textContent = 'Saving...';

            try {
                const response = await cameraApi.putJson(this.routes.save, {
                    camera_id: this.camera.id,
                    browser_device_id: this.camera.browser_device_id,
                    browser_label: this.streamUrl,
                    last_connection_status: this.connectionStatus || 'unknown',
                    last_connection_message: this.messageValue.textContent,
                    calibration_mask: this.maskShape,
                    calibration_line: this.lineShape,
                });

                this.applyServerCamera(response.camera);
                this.messageValue.textContent = response.message || `${this.camera.camera_name} calibration saved.`;
            } catch (error) {
                this.messageValue.textContent = error.message || 'Calibration save failed.';
            } finally {
                this.saveButton.disabled = false;
                this.saveButton.textContent = 'Save Calibration';
            }
        }

        async syncState() {
            try {
                const response = await cameraApi.putJson(this.routes.state, {
                    camera_id: this.camera.id,
                    browser_device_id: this.camera.browser_device_id,
                    browser_label: this.streamUrl,
                    last_connection_status: this.connectionStatus || 'unknown',
                    last_connection_message: this.messageValue.textContent,
                });

                this.applyServerCamera(response.camera);
            } catch (error) {
                this.messageValue.textContent = error.message || this.messageValue.textContent;
            }
        }

        applyServerCamera(camera) {
            if (!camera) {
                return;
            }

            this.camera = camera;
            payload.cameras[this.camera.camera_role] = camera;
            this.maskShape = camera.calibration_mask || null;
            this.maskDraftPoints = [];
            this.lineShape = camera.calibration_line || null;
            this.streamUrl = camera.stream_url || this.streamUrl;
            this.sourceValue.textContent = camera.source_display || camera.source_type;
            this.browserValue.textContent = this.streamUrl;
            this.updateCalibrationSummary();
            this.render();
        }

        drawPolygon(points) {
            if (!Array.isArray(points) || points.length === 0) {
                return;
            }

            this.ctx.fillStyle = 'rgba(192, 132, 42, 0.2)';
            this.ctx.strokeStyle = '#f59e0b';
            this.ctx.lineWidth = 3;
            this.ctx.beginPath();
            this.ctx.moveTo(points[0].x, points[0].y);

            points.slice(1).forEach((point) => {
                this.ctx.lineTo(point.x, point.y);
            });

            if (points.length >= 3) {
                this.ctx.closePath();
                this.ctx.fill();
            }

            this.ctx.stroke();

            points.forEach((point, index) => {
                this.ctx.beginPath();
                this.ctx.fillStyle = '#f59e0b';
                this.ctx.arc(point.x, point.y, 5, 0, Math.PI * 2);
                this.ctx.fill();
                this.ctx.fillStyle = '#ffffff';
                this.ctx.font = '700 11px system-ui, sans-serif';
                this.ctx.fillText(String(index + 1), point.x + 8, point.y - 8);
            });
        }

        drawLine(line) {
            this.ctx.strokeStyle = '#22c55e';
            this.ctx.lineWidth = 4;
            this.ctx.beginPath();
            this.ctx.moveTo(line.x1, line.y1);
            this.ctx.lineTo(line.x2, line.y2);
            this.ctx.stroke();
        }

        /*
         * Phase 2: arrow from the middle of the line toward the IN side.
         * Side +1 is to the right of the line's direction in image
         * coordinates (below a line drawn left to right), as in the detector.
         */
        drawDirectionArrow(line, inSide) {
            const dx = line.x2 - line.x1;
            const dy = line.y2 - line.y1;
            const length = Math.hypot(dx, dy);

            if (length < 4) {
                return;
            }

            const size = Math.max(28, Math.min(70, length * 0.25));
            const nx = (-dy / length) * inSide;
            const ny = (dx / length) * inSide;
            const midX = (line.x1 + line.x2) / 2;
            const midY = (line.y1 + line.y2) / 2;
            const tipX = midX + nx * size;
            const tipY = midY + ny * size;
            const head = 10;

            this.ctx.strokeStyle = '#38bdf8';
            this.ctx.fillStyle = '#38bdf8';
            this.ctx.lineWidth = 4;
            this.ctx.beginPath();
            this.ctx.moveTo(midX, midY);
            this.ctx.lineTo(tipX, tipY);
            this.ctx.stroke();
            this.ctx.beginPath();
            this.ctx.moveTo(tipX + nx * head, tipY + ny * head);
            this.ctx.lineTo(tipX - ny * head, tipY + nx * head);
            this.ctx.lineTo(tipX + ny * head, tipY - nx * head);
            this.ctx.closePath();
            this.ctx.fill();
            this.ctx.font = '700 14px system-ui, sans-serif';
            this.ctx.lineWidth = 3;
            this.ctx.strokeStyle = 'rgba(0, 0, 0, 0.7)';
            this.ctx.strokeText('IN', tipX + nx * (head + 8) - 8, tipY + ny * (head + 8) + 5);
            this.ctx.fillText('IN', tipX + nx * (head + 8) - 8, tipY + ny * (head + 8) + 5);
        }

        render() {
            this.ctx.clearRect(0, 0, this.canvas.width, this.canvas.height);

            const savedMask = this.toCanvasPolygon(this.maskShape);
            const savedLine = this.toCanvasLine(this.lineShape);

            if (savedMask) {
                this.drawPolygon(savedMask);
            } else if (this.maskDraftPoints.length > 0) {
                this.drawPolygon(this.maskDraftPoints);
            }

            if (savedLine) {
                this.drawLine(savedLine);
                this.drawDirectionArrow(savedLine, this.inSide());
            }

            if (this.draftShape?.type === 'line') {
                this.drawLine(this.draftShape.value);
            }
        }
    }

    cardElements.forEach((element) => {
        const role = element.dataset.role;
        cards[role] = new CalibrationCard(element, payload.cameras[role], payload.routes);
    });

    async function refreshDevices() {
        try {
            const devices = await cameraApi.listVideoInputs();
            const assignments = cameraApi.chooseDevices(payload.cameras, devices);

            for (const role of Object.keys(cards)) {
                const card = cards[role];
                const activeDeviceId = card.selectedDevice?.deviceId;
                const existingDevice = devices.find((device) => device.deviceId === activeDeviceId) || null;
                const preferredDevice = existingDevice || assignments[role] || null;

                card.setAvailableDevices(devices, preferredDevice);
                if (!existingDevice || card.connectionStatus !== 'connected') {
                    await card.connectDevice(preferredDevice);
                }
            }
        } catch (error) {
                const errorState = cameraApi.mediaErrorState(error, 'Unable to refresh browser cameras.');

            for (const card of Object.values(cards)) {
                card.setAvailableDevices([], null);
                card.showFallback('Not connected', errorState.message);
                card.updateConnection(errorState.status, errorState.label, errorState.message);
                await card.syncState();
            }
        }
    }

    async function boot() {
        try {
            for (const card of Object.values(cards)) {
                await card.connectStream();
            }

            window.setInterval(() => {
                for (const card of Object.values(cards)) {
                    card.resizeCanvas();
                    card.render();
                }
            }, 2000);

            await sendCalibrationHeartbeat();
            window.setInterval(sendCalibrationHeartbeat, HEARTBEAT_INTERVAL_MS);
        } catch (error) {
            const errorState = cameraApi.mediaErrorState(error, 'Unable to access detector streams.');

            for (const card of Object.values(cards)) {
                card.showFallback('Not connected', errorState.message);
                card.updateConnection(errorState.status, errorState.label, errorState.message);
                await card.syncState();
            }
        }
    }

    async function sendCalibrationHeartbeat() {
        if (!payload.routes.heartbeat) {
            return;
        }

        try {
            const response = await fetch(payload.routes.heartbeat, {
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });

            if (!response.ok) {
                return;
            }

            const body = await response.json().catch(function () {
                return {};
            });
            const cameras = body.runtime?.cameras || {};

            for (const [role, crossings] of Object.entries(body.crossings || {})) {
                cards[role]?.renderCrossings(crossings);
            }

            for (const [role, cameraStatus] of Object.entries(cameras)) {
                if (cards[role]) {
                    cards[role].detectorState = {
                        running: !!body.runtime?.service_running,
                        message: body.runtime?.service_message,
                        camera: cameraStatus,
                    };
                }
                if (cards[role] && cameraStatus.stream_url) {
                    cards[role].streamUrl = cameraStatus.stream_url;

                    if (cards[role].connectionStatus !== 'connected') {
                        const now = Date.now();

                        if (!lastStreamReconnectAt[role] || now - lastStreamReconnectAt[role] >= STREAM_RECONNECT_INTERVAL_MS) {
                            lastStreamReconnectAt[role] = now;
                            await cards[role].connectStream();
                        }
                    }
                }
            }
        } catch (error) {
            return;
        }
    }

    boot();
})();
