(function () {
    function getMediaDevices() {
        return navigator.mediaDevices || null;
    }

    function getCsrfToken() {
        return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    }

    function stopTracks(stream) {
        if (!stream) {
            return;
        }

        stream.getTracks().forEach(function (track) {
            track.stop();
        });
    }

    function stopVideo(videoElement) {
        if (!videoElement || !videoElement.srcObject) {
            return;
        }

        stopTracks(videoElement.srcObject);
        videoElement.srcObject = null;
    }

    async function unlockVideoLabels() {
        const mediaDevices = getMediaDevices();

        if (!mediaDevices || !mediaDevices.getUserMedia) {
            throw new Error('This browser does not support camera access.');
        }

        const stream = await mediaDevices.getUserMedia({ video: true, audio: false });
        stopTracks(stream);
    }

    async function listVideoInputs() {
        const mediaDevices = getMediaDevices();

        if (!mediaDevices || !mediaDevices.enumerateDevices) {
            return [];
        }

        const devices = await mediaDevices.enumerateDevices();

        return devices.filter(function (device) {
            return device.kind === 'videoinput';
        });
    }

    function preferredWebcamIndex(cameraConfig) {
        const sourceValue = String(cameraConfig?.source_value ?? '').trim();

        if (cameraConfig?.source_type !== 'webcam' || !/^\d+$/.test(sourceValue)) {
            return null;
        }

        return Number.parseInt(sourceValue, 10);
    }

    function chooseDevices(cameraConfigs, devices) {
        const usedIds = new Set();
        const assignments = {};

        ['entrance', 'exit'].forEach(function (role) {
            const cameraConfig = cameraConfigs[role] || {};
            let chosenDevice = null;

            if (cameraConfig.browser_device_id) {
                chosenDevice = devices.find(function (device) {
                    return device.deviceId === cameraConfig.browser_device_id && !usedIds.has(device.deviceId);
                }) || null;
            }

            if (!chosenDevice) {
                const preferredIndex = preferredWebcamIndex(cameraConfig);

                if (preferredIndex !== null && devices[preferredIndex] && !usedIds.has(devices[preferredIndex].deviceId)) {
                    chosenDevice = devices[preferredIndex];
                }
            }

            if (!chosenDevice) {
                chosenDevice = devices.find(function (device) {
                    return !usedIds.has(device.deviceId);
                }) || null;
            }

            if (chosenDevice) {
                usedIds.add(chosenDevice.deviceId);
            }

            assignments[role] = chosenDevice;
        });

        return assignments;
    }

    async function attachDevice(videoElement, deviceId) {
        stopVideo(videoElement);

        const mediaDevices = getMediaDevices();

        if (!mediaDevices || !mediaDevices.getUserMedia) {
            throw new Error('This browser does not support camera access.');
        }

        const stream = await mediaDevices.getUserMedia({
            video: {
                deviceId: { exact: deviceId },
            },
            audio: false,
        });

        videoElement.srcObject = stream;
        await videoElement.play().catch(function () {
            return undefined;
        });

        return stream;
    }

    async function putJson(url, payload) {
        const response = await fetch(url, {
            method: 'PUT',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': getCsrfToken(),
            },
            body: JSON.stringify(payload),
        });

        const body = await response.json().catch(function () {
            return {};
        });

        if (!response.ok) {
            const error = new Error(body.message || 'Request failed.');
            error.response = body;
            throw error;
        }

        return body;
    }

    function clampRatio(value) {
        if (Number.isNaN(value)) {
            return 0;
        }

        return Math.min(Math.max(value, 0), 1);
    }

    function normaliseRect(rect, width, height) {
        if (!rect || width <= 0 || height <= 0) {
            return null;
        }

        return {
            x: clampRatio(rect.x / width),
            y: clampRatio(rect.y / height),
            width: clampRatio(rect.width / width),
            height: clampRatio(rect.height / height),
        };
    }

    function denormaliseRect(rect, width, height) {
        if (!rect) {
            return null;
        }

        return {
            x: rect.x * width,
            y: rect.y * height,
            width: rect.width * width,
            height: rect.height * height,
        };
    }

    function normaliseLine(line, width, height) {
        if (!line || width <= 0 || height <= 0) {
            return null;
        }

        return {
            x1: clampRatio(line.x1 / width),
            y1: clampRatio(line.y1 / height),
            x2: clampRatio(line.x2 / width),
            y2: clampRatio(line.y2 / height),
        };
    }

    function denormaliseLine(line, width, height) {
        if (!line) {
            return null;
        }

        return {
            x1: line.x1 * width,
            y1: line.y1 * height,
            x2: line.x2 * width,
            y2: line.y2 * height,
        };
    }

    function mediaErrorState(error, fallbackMessage) {
        const message = error?.message || fallbackMessage || 'Camera access failed.';

        switch (error?.name) {
            case 'NotAllowedError':
            case 'SecurityError':
                return {
                    status: 'denied',
                    label: 'Not connected',
                    message: 'Browser camera permission was denied.',
                };
            case 'NotFoundError':
            case 'DevicesNotFoundError':
            case 'OverconstrainedError':
            case 'TrackStartError':
                return {
                    status: 'unavailable',
                    label: 'Not connected',
                    message: 'Selected camera is unavailable or disconnected.',
                };
            case 'NotReadableError':
            case 'AbortError':
                return {
                    status: 'error',
                    label: 'Not connected',
                    message: 'Selected camera is busy or could not be opened.',
                };
            default:
                return {
                    status: 'error',
                    label: 'Not connected',
                    message: message,
                };
        }
    }

    window.PHILCSTBrowserCamera = {
        attachDevice: attachDevice,
        chooseDevices: chooseDevices,
        denormaliseLine: denormaliseLine,
        denormaliseRect: denormaliseRect,
        listVideoInputs: listVideoInputs,
        mediaErrorState: mediaErrorState,
        normaliseLine: normaliseLine,
        normaliseRect: normaliseRect,
        putJson: putJson,
        stopVideo: stopVideo,
        unlockVideoLabels: unlockVideoLabels,
    };
})();
