(function () {
    const cameraApi = window.PHILCSTBrowserCamera;
    const payloadNode = document.getElementById('portal-camera-data');
    const liveClockNodes = document.querySelectorAll('[data-live-clock]');

    function refreshClock() {
        if (!liveClockNodes.length) {
            return;
        }

        const now = new Date();
        const formatted = now.toLocaleString(undefined, {
            month: 'short',
            day: '2-digit',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit',
        });

        liveClockNodes.forEach(function (node) {
            node.textContent = formatted;
        });
    }

    refreshClock();
    window.setInterval(refreshClock, 1000);

    function currentDateTimeLocal() {
        const now = new Date();
        now.setMinutes(now.getMinutes() - now.getTimezoneOffset());

        return now.toISOString().slice(0, 16);
    }

    function initRfidScanForm() {
        const form = document.querySelector('[data-rfid-scan-form]');
        const resultBox = document.querySelector('[data-rfid-scan-result]');
        const registeredTagSelect = form?.querySelector('select[name="vehicle_rfid_tag_id"]') || null;
        const manualTagInput = form?.querySelector('[data-rfid-scan-input]') || null;
        const scanTimeInput = form?.querySelector('input[name="scan_time"]') || null;

        if (!form || !resultBox || !manualTagInput) {
            return;
        }

        function focusScannerInput() {
            if (registeredTagSelect?.value) {
                return;
            }

            const activeElement = document.activeElement;
            const activeTag = activeElement?.tagName;

            if (['SELECT', 'TEXTAREA', 'BUTTON'].includes(activeTag) || activeElement?.type === 'datetime-local') {
                return;
            }

            manualTagInput.focus({ preventScroll: true });
        }

        window.setTimeout(focusScannerInput, 100);
        window.addEventListener('focus', focusScannerInput);

        registeredTagSelect?.addEventListener('change', function () {
            if (registeredTagSelect.value) {
                manualTagInput.value = '';
            } else {
                focusScannerInput();
            }
        });

        manualTagInput.addEventListener('input', function () {
            if (manualTagInput.value.trim() !== '' && registeredTagSelect) {
                registeredTagSelect.value = '';
            }
        });

        manualTagInput.addEventListener('keydown', function (event) {
            if (event.key !== 'Enter' || manualTagInput.value.trim() === '') {
                return;
            }

            event.preventDefault();

            if (registeredTagSelect) {
                registeredTagSelect.value = '';
            }

            form.requestSubmit();
        });

        form.addEventListener('submit', async function (event) {
            event.preventDefault();
            resultBox.hidden = false;
            resultBox.textContent = 'Recording RFID scan...';

            if (!registeredTagSelect?.value && manualTagInput.value.trim() === '') {
                resultBox.textContent = 'Scan or select an RFID tag first.';
                focusScannerInput();
                return;
            }

            if (scanTimeInput) {
                scanTimeInput.value = currentDateTimeLocal();
            }

            try {
                const response = await fetch(form.action, {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                    },
                    body: new FormData(form),
                });
                const payload = await response.json().catch(function () {
                    return {};
                });

                if (!response.ok) {
                    resultBox.textContent = payload.message || 'RFID scan could not be recorded.';
                    focusScannerInput();
                    return;
                }

                const action = payload.action_taken || payload.scan?.event_type || 'SCAN';
                const state = payload.new_state || payload.scan?.resulting_state || 'N/A';
                const plate = payload.vehicle?.plate_number || payload.scan?.vehicle_plate || 'Unknown vehicle';
                resultBox.textContent = `${action} recorded for ${plate}. Current state: ${state}. Refreshing...`;

                window.setTimeout(function () {
                    window.location.reload();
                }, 500);
            } catch (error) {
                resultBox.textContent = 'RFID scan could not reach the server.';
                focusScannerInput();
            }
        });
    }

    initRfidScanForm();

    if (!cameraApi || !payloadNode) {
        return;
    }

    const payload = JSON.parse(payloadNode.textContent);
    const card = document.querySelector('[data-portal-camera]');

    if (!card) {
        return;
    }

    const video = card.querySelector('[data-video]');
    const fallback = card.querySelector('[data-fallback]');
    const statusTexts = card.querySelectorAll('[data-status-value]');
    const sourceText = card.querySelector('[data-source-value]');
    const browserText = card.querySelector('[data-browser-value]');
    const messageText = card.querySelector('[data-message-value]');
    const lastSeenText = card.querySelector('[data-last-seen-value]');
    const statusDot = card.querySelector('[data-status-dot]');
    const deviceSelect = card.querySelector('[data-camera-device-select]');
    let selectedDevice = null;
    let status = payload.camera.last_connection_status || 'unknown';

    function deviceLabel(device, index) {
        return device.label || `Camera ${index + 1}`;
    }

    function renderDeviceOptions(devices) {
        if (!deviceSelect) {
            return;
        }

        deviceSelect.innerHTML = '';

        if (!Array.isArray(devices) || devices.length === 0) {
            const option = document.createElement('option');
            option.value = '';
            option.textContent = 'No browser camera found';
            deviceSelect.appendChild(option);
            deviceSelect.disabled = true;
            return;
        }

        deviceSelect.disabled = false;

        devices.forEach(function (device, index) {
            const option = document.createElement('option');
            option.value = device.deviceId;
            option.textContent = deviceLabel(device, index);
            deviceSelect.appendChild(option);
        });

        const selectedId = selectedDevice?.deviceId || payload.camera.browser_device_id || '';
        const hasSelectedOption = Array.from(deviceSelect.options).some(function (option) {
            return option.value === selectedId;
        });

        if (hasSelectedOption) {
            deviceSelect.value = selectedId;
        }
    }

    function updateState(nextStatus, label, message) {
        status = nextStatus;

        statusTexts.forEach(function (node) {
            node.textContent = label;
        });

        sourceText.textContent = `${payload.camera.source_type} | ${payload.camera.source_value}`;
        browserText.textContent = selectedDevice?.label || payload.camera.browser_label || 'No saved browser device';
        if (deviceSelect && selectedDevice?.deviceId) {
            deviceSelect.value = selectedDevice.deviceId;
        }
        messageText.textContent = message;
        lastSeenText.textContent = nextStatus === 'connected'
            ? new Date().toLocaleString()
            : (payload.camera.last_connected_at_display || 'Not connected yet');

        statusDot.classList.remove('is-connected', 'is-warning', 'is-error');

        if (nextStatus === 'connected') {
            statusDot.classList.add('is-connected');
            return;
        }

        if (nextStatus === 'denied' || nextStatus === 'unavailable') {
            statusDot.classList.add('is-warning');
            return;
        }

        statusDot.classList.add('is-error');
    }

    function showFallback(message) {
        cameraApi.stopVideo(video);
        video.classList.add('is-hidden');
        fallback.textContent = message;
        fallback.classList.remove('is-hidden');
    }

    function hideFallback() {
        video.classList.remove('is-hidden');
        fallback.classList.add('is-hidden');
    }

    async function syncState() {
        try {
            const response = await cameraApi.putJson(payload.routes.state, {
                camera_id: payload.camera.id,
                browser_device_id: selectedDevice?.deviceId || payload.camera.browser_device_id,
                browser_label: selectedDevice?.label || payload.camera.browser_label,
                last_connection_status: status || 'unknown',
                last_connection_message: messageText.textContent,
            });

            if (response.camera) {
                payload.camera = response.camera;
                sourceText.textContent = `${payload.camera.source_type} | ${payload.camera.source_value}`;
                browserText.textContent = selectedDevice?.label || payload.camera.browser_label || 'No saved browser device';
                if (deviceSelect && selectedDevice?.deviceId) {
                    deviceSelect.value = selectedDevice.deviceId;
                }
            }
        } catch (error) {
            messageText.textContent = error.message || messageText.textContent;
        }
    }

    function preferredDevice(devices) {
        if (!Array.isArray(devices) || devices.length === 0) {
            return null;
        }

        if (payload.camera.browser_device_id) {
            const savedDevice = devices.find(function (device) {
                return device.deviceId === payload.camera.browser_device_id;
            });

            if (savedDevice) {
                return savedDevice;
            }
        }

        if (payload.camera.source_type === 'webcam' && /^\d+$/.test(String(payload.camera.source_value))) {
            const webcamIndex = Number.parseInt(String(payload.camera.source_value), 10);

            if (devices[webcamIndex]) {
                return devices[webcamIndex];
            }
        }

        return devices[0];
    }

    async function connect(device) {
        selectedDevice = device;

        if (!device) {
            showFallback('Not connected');
            updateState('not_connected', 'Not connected', 'No browser camera source is currently available.');
            await syncState();
            return;
        }

        try {
            await cameraApi.attachDevice(video, device.deviceId);
            hideFallback();
            updateState('connected', 'Connected', 'Portal browser preview is active.');
            await syncState();
        } catch (error) {
            const errorState = cameraApi.mediaErrorState(error, 'Unable to open this camera.');
            showFallback('Not connected');
            updateState(errorState.status, errorState.label, errorState.message);
            await syncState();
        }
    }

    async function refreshDevices() {
        try {
            const devices = await cameraApi.listVideoInputs();
            renderDeviceOptions(devices);

            const activeDevice = devices.find(function (device) {
                return device.deviceId === selectedDevice?.deviceId;
            }) || null;

            if (activeDevice && status === 'connected') {
                return;
            }

            await connect(activeDevice || preferredDevice(devices));
        } catch (error) {
            const errorState = cameraApi.mediaErrorState(error, 'Unable to refresh browser cameras.');
            showFallback('Not connected');
            updateState(errorState.status, errorState.label, errorState.message);
            await syncState();
        }
    }

    deviceSelect?.addEventListener('change', async function () {
        try {
            const devices = await cameraApi.listVideoInputs();
            renderDeviceOptions(devices);
            const selectedOptionDevice = devices.find(function (device) {
                return device.deviceId === deviceSelect.value;
            }) || null;

            await connect(selectedOptionDevice);
        } catch (error) {
            const errorState = cameraApi.mediaErrorState(error, 'Unable to switch station camera.');
            showFallback('Not connected');
            updateState(errorState.status, errorState.label, errorState.message);
            await syncState();
        }
    });

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
            showFallback('Not connected');
            updateState(errorState.status, errorState.label, errorState.message);
            await syncState();
        }
    }

    boot();
})();
