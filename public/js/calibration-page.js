/*
 * Settings › Calibration. Calibration work: each gate is drawn on its own
 * camera, the same live stream as its kiosk and Gate Monitor (live-video.js);
 * this page never asks for the browser's camera. The status comes from the
 * detector (as on System status). While the camera is offline the detector's
 * last picture is shown, so the zone and line can still be drawn.
 *
 * Editing (drag points, line ends, whole shapes, "+" on edges, remove a
 * point, flip IN) is in calibration-editor.js; this file connects it to the
 * page: the picture, the pointer, the buttons and saving.
 */
(function () {
    const payloadNode = document.getElementById('camera-calibration-data');
    const Calib = window.CalibrationEditor;
    const HEARTBEAT_INTERVAL_MS = 4000;
    const LIVE_RECONNECT_INTERVAL_MS = 5000;
    const NEW_CROSSING_MS = 10000;

    if (!payloadNode || !Calib) {
        return;
    }

    const payload = JSON.parse(payloadNode.textContent);
    const cards = {};
    let activeCard = null;

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
            this.pointMenu = element.querySelector('[data-point-menu]');
            this.fallbackContainer = element.querySelector('[data-fallback-wrapper]');
            this.fallback = element.querySelector('[data-fallback]');
            this.fallbackDetail = element.querySelector('[data-fallback-detail]');
            this.statusBadge = element.querySelector('[data-status-badge]');
            this.statusValue = element.querySelector('[data-status-value]');
            this.messageValue = element.querySelector('[data-message-value]');
            this.problemsList = element.querySelector('[data-problems]');
            this.maskValue = element.querySelector('[data-mask-value]');
            this.lineValue = element.querySelector('[data-line-value]');
            this.directionValue = element.querySelector('[data-direction-value]');
            this.crossingList = element.querySelector('[data-crossings]');
            this.saveButton = element.querySelector('[data-save]');
            this.doneButton = element.querySelector('[data-done]');
            this.undoButton = element.querySelector('[data-undo]');
            this.redoButton = element.querySelector('[data-redo]');
            this.unsavedBar = element.querySelector('[data-unsaved]');
            this.ctx = this.canvas?.getContext('2d');
            this.connection = camera.connection || { state: 'offline', label: 'Offline', reason: '', tone: 'critical' };
            this.snapshotUrl = camera.snapshot_url || null;
            this.snapshotAt = camera.snapshot_at || null;
            this.liveReady = false;
            this.shown = null;
            this.lastReconnectAt = 0;
            this.touch = false;
            this.cssWidth = 0;
            this.cssHeight = 0;
            // The saved zone and line, drawn as soon as there is a picture.
            this.saved = { mask: camera.calibration_mask, line: camera.calibration_line };
            this.editor = new Calib.Editor(this.saved);
            this.saving = false;
            this.lastSaveAt = 0;
            // When each crossing was first seen here (those on the page at load: long ago).
            this.crossingSeenAt = new Map([...element.querySelectorAll('[data-crossing-id]')].map((item) => [item.dataset.crossingId, 0]));

            if (!this.canvas) {
                return; // No camera at this gate: only the "Add camera" message.
            }

            this.bindEvents();
            // Saved shapes are only dragged; the Line tool is chosen when the line is missing.
            this.setActiveTool(this.editor.closed && !this.editor.line ? 'line' : 'mask');
            this.updateSummary();
            this.applyConnection(this.connection);
        }

        bindEvents() {
            this.element.querySelectorAll('[data-tool]').forEach((button) => {
                button.addEventListener('click', () => this.setActiveTool(button.dataset.tool));
            });
            this.doneButton.addEventListener('click', () => {
                this.edited(this.editor.closeMask(), 'Zone closed. Drag its points to adjust it.');
                if (!this.editor.line) {
                    this.setActiveTool('line');
                }
            });
            this.element.querySelector('[data-clear]').addEventListener('click', () => {
                this.edited(this.editor.clear(), 'Cleared. Draw the zone again, then the line.');
                this.setActiveTool('mask');
            });
            // Phase 2: which side of the line is IN (also: click the arrow).
            this.element.querySelector('[data-flip-direction]').addEventListener('click', () => {
                if (!this.editor.line) {
                    this.message('Draw the trigger line first.');
                    return;
                }
                this.edited(this.editor.flip(), 'IN direction flipped. Save to apply it.');
            });
            this.saveButton.addEventListener('click', () => this.saveCalibration());
            this.undoButton.addEventListener('click', () => this.undo());
            this.redoButton.addEventListener('click', () => this.redo());
            this.element.querySelector('[data-discard]').addEventListener('click', () => {
                this.edited(this.editor.replace(this.saved), 'Changes discarded: back to the saved zone and line (Undo brings them back).');
            });
            this.element.querySelector('[data-reset]').addEventListener('click', () => this.resetToSaved());
            // Keyboard shortcuts go to the card used last.
            this.element.addEventListener('pointerdown', () => (activeCard = this));
            this.element.addEventListener('focusin', () => (activeCard = this));

            this.canvas.addEventListener('pointerdown', (event) => this.handlePointerDown(event));
            this.canvas.addEventListener('pointermove', (event) => this.handlePointerMove(event));
            this.canvas.addEventListener('pointerup', (event) => this.handlePointerUp(event));
            this.canvas.addEventListener('pointercancel', () => this.edited(this.editor.pointerCancel()));
            this.canvas.addEventListener('pointerleave', () => {
                if (!this.editor.drag) {
                    this.editor.hover = null;
                    this.editor.cursor = null;
                    this.render();
                }
            });
            this.canvas.addEventListener('dblclick', (event) => {
                event.preventDefault();
                this.edited(this.editor.doubleClick(this.point(event), this.view(), { touch: this.touch }), 'Point added.');
            });
            this.canvas.addEventListener('contextmenu', (event) => this.openPointMenu(event));
            this.canvas.addEventListener('keydown', (event) => this.handleKey(event));
            this.pointMenu?.querySelector('[data-remove-point]').addEventListener('click', () => {
                const index = Number(this.pointMenu.dataset.index);
                this.closePointMenu();
                this.edited(this.editor.removeVertex(index), 'Point removed.');
            });
            document.addEventListener('pointerdown', (event) => {
                if (this.pointMenu && !this.pointMenu.hidden && !this.pointMenu.contains(event.target)) {
                    this.closePointMenu();
                }
            });

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
            this.video.querySelector('video')?.addEventListener('loadedmetadata', () => this.refresh());
            this.lastPicture?.addEventListener('load', () => this.refresh());

            // The card changes size with the window and the layout.
            if (window.ResizeObserver) {
                new ResizeObserver(() => this.refresh()).observe(this.stage);
            }
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
            this.editor.tool = tool;
            this.element.querySelectorAll('[data-tool]').forEach((button) => {
                const active = button.dataset.tool === tool;
                button.classList.toggle('is-active', active);
                button.setAttribute('aria-pressed', active ? 'true' : 'false');
            });
            this.updateSummary();
            this.render();
        }

        /* Sharp on high-density screens: the canvas has device pixels, drawing uses CSS pixels. */
        resizeCanvas() {
            const width = this.stage.clientWidth;
            const height = this.stage.clientHeight;
            const ratio = window.devicePixelRatio || 1;

            if (!width || !height) {
                return;
            }
            this.cssWidth = width;
            this.cssHeight = height;
            if (this.canvas.width !== Math.round(width * ratio) || this.canvas.height !== Math.round(height * ratio)) {
                this.canvas.width = Math.round(width * ratio);
                this.canvas.height = Math.round(height * ratio);
            }
            this.ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
        }

        /* The element showing the picture right now: the live video, or the last picture. */
        pictureElement() {
            if (this.shown === 'still') {
                return this.lastPicture;
            }

            return this.video.liveVideo?.activeElement() || this.video;
        }

        /*
         * Where the camera picture really is inside the canvas (CSS pixels).
         * The picture is letterboxed (object-fit: contain), so a picture
         * whose shape differs from the 16:9 box is not the whole canvas.
         * Shapes are normalized to the PICTURE, the same frame the detector
         * scales them to, so they stay put at every screen size.
         */
        view() {
            const width = this.cssWidth;
            const height = this.cssHeight;
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

        point(event) {
            const bounds = this.canvas.getBoundingClientRect();

            return { x: event.clientX - bounds.left, y: event.clientY - bounds.top };
        }

        handlePointerDown(event) {
            // Draw on the live video or on the last picture, never on nothing.
            if (!this.shown || event.button === 2) {
                return;
            }

            event.preventDefault();
            this.canvas.focus({ preventScroll: true });
            this.closePointMenu();
            this.touch = event.pointerType === 'touch' || event.pointerType === 'pen';

            const result = this.editor.pointerDown(this.point(event), this.view(), { touch: this.touch });
            if (this.editor.drag) {
                this.canvas.setPointerCapture?.(event.pointerId);
                this.canvas.style.cursor = 'grabbing';
            }

            const messages = {
                close: 'Zone closed. Drag its points to adjust it; use the Line tool for the trigger line.',
                flip: 'IN direction flipped. Save to apply it.',
                'add-point': this.editor.mask.length >= 3 ? 'Click point 1 or press Done to close the zone.' : 'Click the next point of the zone.',
            };
            if (result.action === 'close' && !this.editor.line) {
                this.setActiveTool('line');
            }
            this.edited(true, messages[result.action]);
        }

        handlePointerMove(event) {
            if (!this.shown) {
                return;
            }
            const point = this.point(event);
            this.editor.pointerMove(point, this.view(), { touch: event.pointerType === 'touch' });
            if (!this.editor.drag) {
                this.canvas.style.cursor = Calib.cursorFor(this.editor.hover, this.editor.tool, this.editor.closed);
            }
            this.render();
        }

        handlePointerUp(event) {
            if (this.canvas.hasPointerCapture?.(event.pointerId)) {
                this.canvas.releasePointerCapture(event.pointerId);
            }
            this.edited(this.editor.pointerUp(this.view()));
            this.canvas.style.cursor = Calib.cursorFor(this.editor.hover, this.editor.tool, this.editor.closed);
        }

        handleKey(event) {
            if (event.key === 'Delete' || event.key === 'Backspace') {
                event.preventDefault();
                if (this.editor.selected?.type === 'vertex' && !this.editor.canRemoveVertex()) {
                    this.message('A zone needs at least 3 points.');
                    return;
                }
                this.edited(this.editor.removeSelected(), 'Removed.');
            } else if (event.key === 'Enter') {
                this.edited(this.editor.closeMask(), 'Zone closed.');
            } else if (event.key === 'Escape') {
                this.editor.selected = null;
                this.closePointMenu();
                this.render();
            }
        }

        /* Right-click a point: "Remove point". */
        openPointMenu(event) {
            if (!this.pointMenu || !this.shown) {
                return;
            }
            const point = this.point(event);
            const hit = this.editor.hitTest(point, this.view(), this.touch);
            if (hit?.type !== 'vertex') {
                return;
            }

            event.preventDefault();
            this.editor.selected = { type: 'vertex', index: hit.index };
            const button = this.pointMenu.querySelector('[data-remove-point]');
            button.disabled = !this.editor.canRemoveVertex();
            button.title = button.disabled ? 'A zone needs at least 3 points.' : '';
            this.pointMenu.dataset.index = String(hit.index);
            this.pointMenu.style.left = `${Math.min(point.x, this.cssWidth - 150)}px`;
            this.pointMenu.style.top = `${Math.min(point.y, this.cssHeight - 50)}px`;
            this.pointMenu.hidden = false;
            this.render();
        }

        closePointMenu() {
            if (this.pointMenu) {
                this.pointMenu.hidden = true;
            }
        }

        /* After every change: summary, warnings, buttons, picture. */
        edited(changed, text) {
            if (text) {
                this.message(text);
            }
            this.updateSummary();
            this.render();

            return changed;
        }

        undo() {
            this.edited(this.editor.undo(), 'Undone.');
        }

        redo() {
            this.edited(this.editor.redo(), 'Redone.');
        }

        isDirty() {
            return !!this.canvas && !this.editor.matches(this.saved);
        }

        /* The calibration saved on the server now (maybe from another tab). */
        async resetToSaved() {
            try {
                const response = await fetch(this.routes.heartbeat, { headers: { 'Accept': 'application/json' } });
                const body = await response.json();
                const saved = body.gates?.[this.camera.camera_role]?.calibration;
                if (!response.ok || !saved) {
                    throw new Error();
                }
                this.saved = saved;
                const changed = this.editor.replace(saved);
                this.edited(changed, changed ? 'Loaded the saved zone and line (Undo brings your changes back).' : 'This is already the saved zone and line.');
            } catch (error) {
                this.message('Could not load the saved calibration. Try again.');
            }
        }

        message(text) {
            this.messageValue.textContent = text;
        }

        updateSummary() {
            const editor = this.editor;
            const count = editor.mask.length;

            this.maskValue.textContent = editor.closed
                ? `${count}-point zone`
                : (count ? `Drawing: ${count} point${count === 1 ? '' : 's'}` : 'No zone yet');
            this.lineValue.textContent = editor.line ? 'Line drawn' : 'No line yet';
            this.directionValue.textContent = editor.line ? 'IN = the side the arrow points to (click it to flip)' : 'Draw a line first';
            this.doneButton.disabled = editor.closed || count < 3;
            this.undoButton.disabled = !editor.canUndo();
            this.redoButton.disabled = !editor.canRedo();
            const dirty = this.isDirty();
            this.unsavedBar.hidden = !dirty;
            this.element.classList.toggle('is-dirty', dirty);

            const { errors, warnings } = editor.problems();
            this.problemsList.innerHTML = '';
            [...errors.map((text) => ['error', text]), ...warnings.map((text) => ['warning', text])].forEach(([kind, text]) => {
                const item = document.createElement('li');
                item.className = `calibration-problem is-${kind}`;
                item.textContent = text;
                this.problemsList.appendChild(item);
            });
            this.problemsList.hidden = errors.length + warnings.length === 0;
            this.saveButton.disabled = errors.length > 0;
            this.saveButton.title = errors[0] || '';
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
                const details = [crossing.time, crossing.type_label || 'Vehicle', `track #${crossing.track_id ?? '—'}`];
                if (crossing.confidence !== null && crossing.confidence !== undefined) {
                    details.push(Number(crossing.confidence).toFixed(2));
                }
                if (crossing.reason) {
                    details.push(crossing.reason);
                }
                item.appendChild(document.createTextNode(` ${details.join(' · ')}`));
                item.dataset.crossingId = String(crossing.id);
                // A crossing that just happened stands out for a few seconds.
                const id = String(crossing.id);
                if (!this.crossingSeenAt.has(id)) {
                    this.crossingSeenAt.set(id, Date.now());
                }
                item.classList.toggle('is-new', Date.now() - this.crossingSeenAt.get(id) < NEW_CROSSING_MS);
                this.crossingList.appendChild(item);
            });
        }

        async saveCalibration() {
            const { errors } = this.editor.problems();
            if (errors.length) {
                this.message(errors[0]);
                return;
            }

            this.saving = true;
            this.saveButton.disabled = true;
            this.saveButton.textContent = 'Saving...';
            const data = this.editor.serialize();

            try {
                const response = await putJson(this.routes.save, { camera_id: this.camera.id, ...data });
                const camera = response.camera || {};
                this.camera = { ...this.camera, ...camera };
                this.saved = { mask: camera.calibration_mask ?? data.calibration_mask, line: camera.calibration_line ?? data.calibration_line };
                this.lastSaveAt = Date.now();
                // Same shapes as on screen (kept, with their undo history).
                this.editor.replace(this.saved);
                this.message(response.message || `${this.camera.camera_name} calibration saved.`);
                this.confirmApplied(this.saved);
            } catch (error) {
                this.message(error.message || 'Calibration save failed.');
                window.ui?.toast(error.message || 'Calibration save failed.', 'error');
            } finally {
                this.saving = false;
                this.saveButton.textContent = 'Save Calibration';
                this.updateSummary();
                this.render();
            }
        }

        /*
         * The detector re-reads the calibration every frame (no restart). Its
         * overlay endpoint answers with the zone and line it reads, so the
         * toast says so once they match what was saved.
         */
        async confirmApplied(saved) {
            const url = this.video.dataset.overlayUrl;
            const same = (a, b) => JSON.stringify(new Calib.Editor(a).serialize()) === JSON.stringify(new Calib.Editor(b).serialize());

            for (let attempt = 0; url && attempt < 6; attempt++) {
                try {
                    const response = await fetch(url, { cache: 'no-store' });
                    const overlay = await response.json();
                    if (overlay.ready && same({ mask: overlay.zone, line: overlay.line }, saved)) {
                        window.ui?.toast(`${this.camera.role_label}: saved. The detector is using the new zone and line now.`, 'success');
                        return;
                    }
                } catch (error) {
                    break; // the detector is not reachable from this browser
                }
                await new Promise((resolve) => window.setTimeout(resolve, 500));
            }
            window.ui?.toast(`${this.camera.role_label}: saved. The detector picks it up within a few seconds (no restart needed).`, 'success');
        }

        /* Saved in another tab while nothing is being edited here: show it. */
        applySavedFromServer(saved, sentAt) {
            if (!saved || this.saving || sentAt < this.lastSaveAt || this.editor.drag || this.isDirty()) {
                return;
            }
            if (!this.editor.matches(saved)) {
                this.saved = saved;
                this.editor.load(saved);
                this.edited(true, 'The calibration was changed and saved somewhere else; showing it now.');
            }
        }

        render() {
            if (!this.ctx) {
                return;
            }

            this.ctx.clearRect(0, 0, this.cssWidth, this.cssHeight);
            if (!this.shown) {
                return;
            }
            Calib.draw(this.ctx, this.editor, this.view(), {
                touch: this.touch,
                drawingMask: this.editor.tool === 'mask' && !this.editor.closed,
            });
        }
    }

    document.querySelectorAll('[data-calibration-camera]').forEach((element) => {
        const role = element.dataset.role;
        cards[role] = new CalibrationCard(element, payload.cameras[role], payload.routes);
    });
    // For checks from the browser console and the end-to-end test.
    window.calibrationCards = cards;

    /* Ctrl/⌘+Z undo, Ctrl/⌘+Shift+Z (or Ctrl+Y) redo, on the card used last. */
    document.addEventListener('keydown', (event) => {
        const typing = event.target.closest?.('input, textarea, select, [contenteditable="true"]');
        if (typing || !(event.ctrlKey || event.metaKey)) {
            return;
        }
        const key = event.key.toLowerCase();
        const editable = Object.values(cards).filter((card) => card.canvas);
        const card = activeCard?.canvas ? activeCard : (editable.length === 1 ? editable[0] : null);
        if (!card || (key !== 'z' && key !== 'y')) {
            return;
        }
        event.preventDefault();
        if (key === 'y' || event.shiftKey) {
            card.redo();
        } else {
            card.undo();
        }
    });

    /* Leaving with unsaved changes: the browser asks first. */
    window.addEventListener('beforeunload', (event) => {
        if (Object.values(cards).some((card) => card.isDirty())) {
            event.preventDefault();
            event.returnValue = '';
        }
    });

    /* Status, last picture and recent crossings from the detector, every few seconds. */
    async function sendCalibrationHeartbeat() {
        const sentAt = Date.now();
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
                    if (!Object.values(cards).some((other) => other.isDirty())) {
                        window.location.reload();
                        return;
                    }
                    card.message('The camera of this gate changed. Save or discard your changes, then reload the page.');
                    continue;
                }
                if (card.canvas) {
                    card.applySnapshot(gate.snapshot_url, gate.snapshot_at);
                    card.applyConnection(gate.connection);
                    card.applySavedFromServer(gate.calibration, sentAt);
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
