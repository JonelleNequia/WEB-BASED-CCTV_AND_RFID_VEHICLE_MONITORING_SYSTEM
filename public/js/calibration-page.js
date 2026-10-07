/*
 * Settings › Calibration. Calibration work: each gate is drawn on its own
 * camera, the same live stream as its kiosk and Gate Monitor (live-video.js);
 * this page never asks for the browser's camera. The status comes from the
 * detector (as on System status). While the camera is offline the detector's
 * last picture is shown, so the zone and line can still be drawn.
 */
(function () {
    const payloadNode = document.getElementById('camera-calibration-data');
    const HEARTBEAT_INTERVAL_MS = 4000;
    const LIVE_RECONNECT_INTERVAL_MS = 5000;

    if (!payloadNode) {
        return;
    }

    const payload = JSON.parse(payloadNode.textContent);
    const cards = {};

    function csrfToken() {
        return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    }

    async function putJson(url, body) {
        const response = await fetch(url, {
            method: 'PUT',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
            },
            body: JSON.stringify(body),
        });
        const result = await response.json().catch(() => ({}));

        if (!response.ok) {
            throw new Error(result.message || 'Request failed.');
        }

        return result;
    }

    function clampRatio(value) {
        return Number.isNaN(value) ? 0 : Math.min(Math.max(value, 0), 1);
    }

    /* Saved points are normalized (0-1) to the camera picture, as the detector uses them. */
    function normalisePolygon(points, width, height) {
        if (!Array.isArray(points) || points.length < 3 || width <= 0 || height <= 0) {
            return null;
        }

        return points.map((point) => ({ x: clampRatio(point.x / width), y: clampRatio(point.y / height) }));
    }

    function denormalisePolygon(points, width, height) {
        return Array.isArray(points) ? points.map((point) => ({ x: point.x * width, y: point.y * height })) : null;
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
        return line ? { x1: line.x1 * width, y1: line.y1 * height, x2: line.x2 * width, y2: line.y2 * height } : null;
    }

    class CalibrationCard {
        constructor(element, camera, routes) {
            this.element = element;
            this.camera = camera;
            this.routes = routes;
            this.stage = element.querySelector('.camera-stage');
            this.video = element.querySelector('[data-video]');
            this.lastPicture = element.querySelector('[data-last-picture]');
            this.pictureBadge = element.querySelector('[data-picture-badge]');
            // Not [data-overlay]: the live player's own root has data-overlay="0".
            this.canvas = element.querySelector('[data-calibration-canvas]');
            this.fallbackContainer = element.querySelector('[data-fallback-wrapper]');
            this.fallback = element.querySelector('[data-fallback]');
            this.fallbackDetail = element.querySelector('[data-fallback-detail]');
            this.statusBadge = element.querySelector('[data-status-badge]');
            this.statusValue = element.querySelector('[data-status-value]');
            this.messageValue = element.querySelector('[data-message-value]');
            this.maskValue = element.querySelector('[data-mask-value]');
            this.lineValue = element.querySelector('[data-line-value]');
            this.directionValue = element.querySelector('[data-direction-value]');
            this.crossingList = element.querySelector('[data-crossings]');
            this.saveButton = element.querySelector('[data-save]');
            this.ctx = this.canvas?.getContext('2d');
            this.connection = camera.connection || { state: 'offline', label: 'Offline', reason: '', tone: 'critical' };
            this.snapshotUrl = camera.snapshot_url || null;
            this.snapshotAt = camera.snapshot_at || null;
            this.liveReady = false;
            this.shown = null;
            this.lastReconnectAt = 0;
            this.currentTool = 'mask';
            this.pointerStart = null;
            this.pointerId = null;
            this.draftShape = null;
            // The saved zone and line, drawn as soon as there is a picture.
            this.maskShape = camera.calibration_mask || null;
            this.maskDraftPoints = [];
            this.lineShape = camera.calibration_line || null;

            if (!this.canvas) {
                return; // No camera at this gate: only the "Add camera" message.
            }

            this.bindEvents();
            this.updateCalibrationSummary();
            this.setActiveTool('mask');
            this.applyConnection(this.connection);
        }

        bindEvents() {
            this.element.querySelectorAll('[data-tool]').forEach((button) => {
                button.addEventListener('click', () => this.setActiveTool(button.dataset.tool));
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
            this.element.querySelector('[data-flip-direction]').addEventListener('click', () => {
                if (!this.lineShape) {
                    this.messageValue.textContent = 'Draw the trigger line first.';
                    return;
                }

                this.lineShape = { ...this.lineShape, in_side: this.inSide() * -1 };
                this.messageValue.textContent = 'IN direction flipped. Save to apply it.';
                this.updateCalibrationSummary();
                this.render();
            });

            this.saveButton.addEventListener('click', () => this.saveCalibration());

            this.canvas.addEventListener('pointerdown', (event) => this.handlePointerDown(event));
            this.canvas.addEventListener('pointermove', (event) => this.handlePointerMove(event));
            this.canvas.addEventListener('pointerup', (event) => this.handlePointerUp(event));
            this.canvas.addEventListener('dblclick', (event) => event.preventDefault());
            this.canvas.addEventListener('pointercancel', () => this.cancelDraft());
            this.canvas.addEventListener('pointerleave', () => this.cancelDraft());

            // The live player says when its picture is ready, which mode it uses, or that it failed.
            this.video.addEventListener('live:mode', () => this.refresh());
            this.video.addEventListener('live:ready', () => {
                this.liveReady = true;
                this.showPicture();
            });
            this.video.addEventListener('live:error', () => {
                this.liveReady = false;
                this.showPicture();
            });
            this.lastPicture?.addEventListener('load', () => this.refresh());

            window.addEventListener('resize', () => this.refresh());
        }

        /*
         * Connected / Reconnecting / Offline from the detector (heartbeat);
         * the live view is opened again when the camera comes back.
         */
        applyConnection(connection) {
            const wasConnected = this.connection.state === 'connected';
            this.connection = connection;

            this.statusBadge.textContent = connection.label;
            this.statusBadge.className = `badge badge-tone-${connection.tone || 'neutral'}`;
            this.statusValue.textContent = `${connection.label} · ${connection.reason}`;

            if (connection.state === 'connected' && (!wasConnected || !this.liveReady)) {
                const now = Date.now();
                if (now - this.lastReconnectAt >= LIVE_RECONNECT_INTERVAL_MS) {
                    this.lastReconnectAt = now;
                    this.video.liveVideo?.reconnect();
                }
            }

            this.showPicture();
        }

        applySnapshot(url, at) {
            this.snapshotUrl = url || null;
            this.snapshotAt = at || null;
        }

        /* Live video when the camera is connected, else its last picture, else the reason. */
        showPicture() {
            const live = this.connection.state === 'connected' && this.liveReady;
            const still = !live && !!this.snapshotUrl;

            this.video.classList.toggle('is-hidden', !live);
            if (this.lastPicture) {
                this.lastPicture.hidden = !still;
            }
            this.fallbackContainer.classList.toggle('is-hidden', live || still);
            this.canvas.classList.toggle('is-hidden', !(live || still));

            if (still) {
                // Loaded only when shown (not every heartbeat behind the live video).
                if (this.lastPicture.getAttribute('src') !== this.snapshotUrl) {
                    this.lastPicture.src = this.snapshotUrl;
                }
                const time = this.snapshotAt ? ` (${this.snapshotAt})` : '';
                const waiting = this.connection.state === 'connected';
                this.pictureBadge.textContent = waiting
                    ? `Last picture${time} · opening the live view…`
                    : `${this.connection.label}: last picture${time}`;
                this.pictureBadge.classList.toggle('is-waiting', waiting || this.connection.state === 'reconnecting');
            } else if (!live) {
                this.fallback.textContent = this.connection.label;
                this.fallbackDetail.textContent = this.connection.state === 'connected'
                    ? 'Opening the live view…'
                    : this.connection.reason;
            }
            this.pictureBadge.hidden = !still;

            this.shown = live ? 'live' : (still ? 'still' : null);
            this.refresh();
        }

        refresh() {
            this.resizeCanvas();
            this.render();
        }

        setActiveTool(tool) {
            this.currentTool = tool;
            this.element.querySelectorAll('[data-tool]').forEach((button) => {
                button.classList.toggle('is-active', button.dataset.tool === tool);
            });
        }

        resizeCanvas() {
            const width = this.stage.clientWidth;
            const height = this.stage.clientHeight;

            if (width && height && (this.canvas.width !== width || this.canvas.height !== height)) {
                this.canvas.width = width;
                this.canvas.height = height;
            }
        }

        /* The element showing the picture right now: the live video, or the last picture. */
        pictureElement() {
            if (this.shown === 'still') {
                return this.lastPicture;
            }

            return this.video.liveVideo?.activeElement() || this.video;
        }

        /*
         * Where the camera image really is inside the canvas. The picture is
         * shown with object-fit (contain letterboxes, cover crops), so a
         * picture whose shape differs from the 16:9 box is not the whole
         * canvas. Saved points are normalized (0-1) to the IMAGE, the same
         * frame the detector scales them to.
         */
        contentRect() {
            const width = this.canvas.width;
            const height = this.canvas.height;
            const media = this.pictureElement();
            const naturalWidth = media.naturalWidth || media.videoWidth || 0;
            const naturalHeight = media.naturalHeight || media.videoHeight || 0;
            const fit = window.getComputedStyle(media).objectFit;

            if (!naturalWidth || !naturalHeight || !width || !height || fit === 'fill') {
                return { x: 0, y: 0, width: width, height: height };
            }

            const scale = fit === 'cover'
                ? Math.max(width / naturalWidth, height / naturalHeight)
                : Math.min(width / naturalWidth, height / naturalHeight);
            const shownWidth = naturalWidth * scale;
            const shownHeight = naturalHeight * scale;

            return { x: (width - shownWidth) / 2, y: (height - shownHeight) / 2, width: shownWidth, height: shownHeight };
        }

        toImagePolygon(points) {
            const rect = this.contentRect();
            const shifted = (points || []).map((point) => ({ x: point.x - rect.x, y: point.y - rect.y }));

            return normalisePolygon(shifted, rect.width, rect.height);
        }

        toCanvasPolygon(shape) {
            const rect = this.contentRect();
            const points = denormalisePolygon(shape, rect.width, rect.height);

            return points ? points.map((point) => ({ x: point.x + rect.x, y: point.y + rect.y })) : null;
        }

        toImageLine(line) {
            const rect = this.contentRect();

            return line ? normaliseLine({
                x1: line.x1 - rect.x, y1: line.y1 - rect.y, x2: line.x2 - rect.x, y2: line.y2 - rect.y,
            }, rect.width, rect.height) : null;
        }

        toCanvasLine(line) {
            const rect = this.contentRect();
            const scaled = denormaliseLine(line, rect.width, rect.height);

            return scaled ? {
                x1: scaled.x1 + rect.x, y1: scaled.y1 + rect.y, x2: scaled.x2 + rect.x, y2: scaled.y2 + rect.y,
            } : null;
        }

        getCanvasPoint(event) {
            const bounds = this.canvas.getBoundingClientRect();

            return { x: event.clientX - bounds.left, y: event.clientY - bounds.top };
        }

        handlePointerDown(event) {
            // Draw on the live video or on the last picture, never on nothing.
            if (!this.shown) {
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
                    value: { x1: this.pointerStart.x, y1: this.pointerStart.y, x2: currentPoint.x, y2: currentPoint.y },
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
            this.maskShape = points.length >= 3 ? this.toImagePolygon(points) : null;
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
            const pointCount = Array.isArray(this.maskShape) ? this.maskShape.length : this.maskDraftPoints.length;

            this.maskValue.textContent = pointCount >= 3 ? `${pointCount}-point zone` : 'No zone yet';
            this.lineValue.textContent = this.lineShape ? 'Line drawn' : 'No line yet';
            this.directionValue.textContent = this.lineShape ? 'IN = the side the arrow points to' : 'Draw a line first';
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
            if (this.maskDraftPoints.length > 0 && !this.maskShape) {
                this.messageValue.textContent = 'Add at least 3 zone points before saving.';
                return;
            }

            this.saveButton.disabled = true;
            this.saveButton.textContent = 'Saving...';

            try {
                const response = await putJson(this.routes.save, {
                    camera_id: this.camera.id,
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

        applyServerCamera(camera) {
            if (!camera) {
                return;
            }

            this.camera = { ...this.camera, ...camera };
            this.maskShape = camera.calibration_mask || null;
            this.maskDraftPoints = [];
            this.lineShape = camera.calibration_line || null;
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
            points.slice(1).forEach((point) => this.ctx.lineTo(point.x, point.y));

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
            if (!this.ctx) {
                return;
            }

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

    document.querySelectorAll('[data-calibration-camera]').forEach((element) => {
        const role = element.dataset.role;
        cards[role] = new CalibrationCard(element, payload.cameras[role], payload.routes);
    });

    /* Status, last picture and recent crossings from the detector, every few seconds. */
    async function sendCalibrationHeartbeat() {
        try {
            const response = await fetch(payload.routes.heartbeat, {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });

            if (!response.ok) {
                return;
            }

            const body = await response.json().catch(() => ({}));

            for (const [role, crossings] of Object.entries(body.crossings || {})) {
                cards[role]?.renderCrossings(crossings);
            }

            for (const [role, gate] of Object.entries(body.gates || {})) {
                const card = cards[role];
                if (!card) {
                    continue;
                }
                // A camera was added or removed in another tab: show the new setup.
                if (gate.has_camera !== (card.element.dataset.hasCamera === '1')) {
                    window.location.reload();
                    return;
                }
                if (card.canvas) {
                    card.applySnapshot(gate.snapshot_url, gate.snapshot_at);
                    card.applyConnection(gate.connection);
                }
            }
        } catch (error) {
            // Next heartbeat tries again.
        }
    }

    window.setInterval(() => Object.values(cards).forEach((card) => card.canvas && card.refresh()), 2000);
    sendCalibrationHeartbeat();
    window.setInterval(sendCalibrationHeartbeat, HEARTBEAT_INTERVAL_MS);
})();
