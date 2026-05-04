(function () {
    const cameraApi = window.PHILCSTBrowserCamera;
    const payloadNode = document.getElementById('camera-calibration-data');

    if (!cameraApi || !payloadNode) {
        return;
    }

    const payload = JSON.parse(payloadNode.textContent);
    const cardElements = document.querySelectorAll('[data-calibration-camera]');
    const cards = {};

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
            this.saveButton = element.querySelector('[data-save]');
            this.ctx = this.canvas.getContext('2d');
            this.streamUrl = camera.stream_url || this.video?.dataset.streamUrl || `http://127.0.0.1:8765/stream/${camera.camera_role}`;
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

            this.saveButton.addEventListener('click', async () => {
                await this.saveCalibration();
            });

            this.canvas.addEventListener('pointerdown', (event) => this.handlePointerDown(event));
            this.canvas.addEventListener('pointermove', (event) => this.handlePointerMove(event));
            this.canvas.addEventListener('pointerup', (event) => this.handlePointerUp(event));
            this.canvas.addEventListener('dblclick', (event) => event.preventDefault());
            this.canvas.addEventListener('pointercancel', () => this.cancelDraft());
            this.canvas.addEventListener('pointerleave', () => this.cancelDraft());
            this.video.addEventListener('loadedmetadata', () => {
                this.resizeCanvas();
                this.render();
            });
            this.video.addEventListener('load', () => {
                this.resizeCanvas();
                this.hideFallback();
                this.updateConnection('connected', 'Connected', 'Detector MJPEG stream connected.');
                this.syncState();
                this.render();
            });
            this.video.addEventListener('error', () => {
                this.showFallback('Stream unavailable', 'Start the Python detector and confirm this camera source is connected.');
                this.updateConnection('unavailable', 'Not connected', 'Detector MJPEG stream is unavailable.');
                this.syncState();
            });

            window.addEventListener('resize', () => {
                this.resizeCanvas();
                this.render();
            });
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

            const separator = this.streamUrl.includes('?') ? '&' : '?';
            this.video.src = `${this.streamUrl}${separator}calibration=${Date.now()}`;
            this.resizeCanvas();
            this.updateConnection('not_connected', 'Connecting', 'Opening detector MJPEG stream...');
            this.render();
        }

        updateConnection(status, label, message) {
            this.connectionStatus = status;
            this.statusValue.textContent = label;
            this.messageValue.textContent = message;
            this.sourceValue.textContent = `${this.camera.source_type} | ${this.camera.source_value}`;
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
            const width = this.canvas.width;
            const height = this.canvas.height;

            if (this.draftShape.type === 'line') {
                this.lineShape = cameraApi.normaliseLine(this.draftShape.value, width, height);
            }

            this.pointerStart = null;
            this.draftShape = null;
            this.updateCalibrationSummary();
            this.render();
        }

        addPolygonPoint(point) {
            const width = this.canvas.width;
            const height = this.canvas.height;
            const points = cameraApi.denormalisePolygon(this.maskShape, width, height) || this.maskDraftPoints;

            points.push(point);
            this.maskDraftPoints = points;
            this.maskShape = points.length >= 3
                ? cameraApi.normalisePolygon(points, width, height)
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
        }

        async saveCalibration() {
            if (this.maskShape && !Array.isArray(this.maskShape)) {
                const points = cameraApi.denormalisePolygon(this.maskShape, this.canvas.width, this.canvas.height);
                this.maskShape = cameraApi.normalisePolygon(points, this.canvas.width, this.canvas.height);
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
            this.sourceValue.textContent = `${camera.source_type} | ${camera.source_value}`;
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

        render() {
            this.ctx.clearRect(0, 0, this.canvas.width, this.canvas.height);

            const savedMask = cameraApi.denormalisePolygon(this.maskShape, this.canvas.width, this.canvas.height);
            const savedLine = cameraApi.denormaliseLine(this.lineShape, this.canvas.width, this.canvas.height);

            if (savedMask) {
                this.drawPolygon(savedMask);
            } else if (this.maskDraftPoints.length > 0) {
                this.drawPolygon(this.maskDraftPoints);
            }

            if (savedLine) {
                this.drawLine(savedLine);
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
        } catch (error) {
            const errorState = cameraApi.mediaErrorState(error, 'Unable to access detector streams.');

            for (const card of Object.values(cards)) {
                card.showFallback('Not connected', errorState.message);
                card.updateConnection(errorState.status, errorState.label, errorState.message);
                await card.syncState();
            }
        }
    }

    boot();
})();
