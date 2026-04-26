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
            this.selectedDevice = null;
            this.availableDevices = [];
            this.currentTool = 'mask';
            this.pointerStart = null;
            this.pointerId = null;
            this.draftShape = null;
            this.maskShape = camera.calibration_mask || null;
            this.lineShape = camera.calibration_line || null;

            this.bindEvents();
            this.updateCalibrationSummary();
            this.setActiveTool('mask');
            this.render();
        }

        bindEvents() {
            this.deviceSelect.addEventListener('change', async () => {
                const deviceId = this.deviceSelect.value;
                const device = this.availableDevices.find((item) => item.deviceId === deviceId) || null;
                await this.connectDevice(device);
            });

            this.element.querySelectorAll('[data-tool]').forEach((button) => {
                button.addEventListener('click', () => {
                    this.setActiveTool(button.dataset.tool);
                });
            });

            this.element.querySelector('[data-clear]').addEventListener('click', () => {
                this.maskShape = null;
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
            this.canvas.addEventListener('pointercancel', () => this.cancelDraft());
            this.canvas.addEventListener('pointerleave', () => this.cancelDraft());
            this.video.addEventListener('loadedmetadata', () => {
                this.resizeCanvas();
                this.render();
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

        updateConnection(status, label, message) {
            this.connectionStatus = status;
            this.statusValue.textContent = label;
            this.messageValue.textContent = message;
            this.sourceValue.textContent = `${this.camera.source_type} | ${this.camera.source_value}`;
            this.browserValue.textContent = this.selectedDevice?.label || this.camera.browser_label || 'No saved browser device';
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

            if (this.currentTool === 'mask') {
                this.draftShape = {
                    type: 'mask',
                    value: {
                        x: Math.min(this.pointerStart.x, currentPoint.x),
                        y: Math.min(this.pointerStart.y, currentPoint.y),
                        width: Math.abs(currentPoint.x - this.pointerStart.x),
                        height: Math.abs(currentPoint.y - this.pointerStart.y),
                    },
                };
            }

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

            if (this.draftShape.type === 'mask') {
                this.maskShape = cameraApi.normaliseRect(this.draftShape.value, width, height);
            }

            if (this.draftShape.type === 'line') {
                this.lineShape = cameraApi.normaliseLine(this.draftShape.value, width, height);
            }

            this.pointerStart = null;
            this.draftShape = null;
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
            this.maskValue.textContent = this.maskShape ? 'Mask saved or drawn' : 'No mask yet';
            this.lineValue.textContent = this.lineShape ? 'Line saved or drawn' : 'No line yet';
        }

        async saveCalibration() {
            this.saveButton.disabled = true;
            this.saveButton.textContent = 'Saving...';

            try {
                const response = await cameraApi.putJson(this.routes.save, {
                    camera_id: this.camera.id,
                    browser_device_id: this.selectedDevice?.deviceId || this.camera.browser_device_id,
                    browser_label: this.selectedDevice?.label || this.camera.browser_label,
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
                    browser_device_id: this.selectedDevice?.deviceId || this.camera.browser_device_id,
                    browser_label: this.selectedDevice?.label || this.camera.browser_label,
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
            this.lineShape = camera.calibration_line || null;
            this.sourceValue.textContent = `${camera.source_type} | ${camera.source_value}`;
            this.browserValue.textContent = this.selectedDevice?.label || camera.browser_label || 'No saved browser device';
            this.updateCalibrationSummary();
            this.render();
        }

        drawRect(rect) {
            this.ctx.fillStyle = 'rgba(192, 132, 42, 0.2)';
            this.ctx.strokeStyle = '#f59e0b';
            this.ctx.lineWidth = 3;
            this.ctx.fillRect(rect.x, rect.y, rect.width, rect.height);
            this.ctx.strokeRect(rect.x, rect.y, rect.width, rect.height);
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

            const savedMask = cameraApi.denormaliseRect(this.maskShape, this.canvas.width, this.canvas.height);
            const savedLine = cameraApi.denormaliseLine(this.lineShape, this.canvas.width, this.canvas.height);

            if (savedMask) {
                this.drawRect(savedMask);
            }

            if (savedLine) {
                this.drawLine(savedLine);
            }

            if (this.draftShape?.type === 'mask') {
                this.drawRect(this.draftShape.value);
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
            await cameraApi.unlockVideoLabels();
            await refreshDevices();

            if (navigator.mediaDevices?.addEventListener) {
                navigator.mediaDevices.addEventListener('devicechange', refreshDevices);
            }

            window.setInterval(refreshDevices, 5000);
        } catch (error) {
            const errorState = cameraApi.mediaErrorState(error, 'Unable to access browser cameras.');

            for (const card of Object.values(cards)) {
                card.showFallback('Not connected', errorState.message);
                card.updateConnection(errorState.status, errorState.label, errorState.message);
                await card.syncState();
            }
        }
    }

    boot();
})();
