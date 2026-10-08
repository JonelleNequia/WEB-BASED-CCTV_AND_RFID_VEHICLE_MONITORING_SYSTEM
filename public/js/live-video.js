/*
 * Live view work: one player for the Gate Monitor, the kiosks and Calibration.
 *
 * 1. WebRTC through go2rtc: the camera's MAIN stream, full resolution and
 *    frame rate, not re-encoded (the offer goes through a signed-in route).
 * 2. HLS from go2rtc when WebRTC does not connect (hls.js, bundled).
 * 3. The detector's MJPEG stream as the last fallback.
 *
 * Detection boxes, the zone and the line are drawn on a canvas over the
 * video from the detector's /overlay/{gate} JSON (not burned into the video),
 * when "Show detection boxes" is on. The player reports what it measures
 * (resolution, frames per second, delay) for Settings › System status.
 *
 * <div data-live-video data-gate data-webrtc-url data-hls-url data-mjpeg-url
 *      data-overlay-url data-stats-url data-webrtc="1" data-overlay="1">
 *   <video muted playsinline></video> <img hidden> <canvas data-live-overlay></canvas>
 * </div>
 * Events on the container: live:mode {mode}, live:ready, live:error.
 */
(function () {
    const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content || '';
    const OVERLAY_KEY = 'live.overlay';
    const WEBRTC_TIMEOUT_MS = 8000;
    const HLS_TIMEOUT_MS = 12000;
    // No new picture for this long while the page shows the video: the
    // stream froze (or went black) although the connection still looks
    // up, e.g. the camera dropped and came back. Connect again.
    const STALL_MS = 8000;
    // On the basic view, try the full-quality view again now and then.
    const UPGRADE_AFTER_MS = 120000;
    const players = [];

    /*
     * Shown unless switched off on this browser. A page can keep its own
     * choice (data-overlay-key) with another default (data-overlay-default),
     * e.g. Calibration: off until "Show detection" is ticked.
     */
    function overlayWanted(key, fallback) {
        const stored = (() => {
            try {
                return localStorage.getItem(key || OVERLAY_KEY);
            } catch (error) {
                return null;
            }
        })();

        return (stored || fallback || 'on') !== 'off';
    }

    class LivePlayer {
        constructor(root) {
            this.root = root;
            this.video = root.querySelector('video');
            this.img = root.querySelector('img');
            this.canvas = root.querySelector('[data-live-overlay]');
            this.badge = root.querySelector('[data-live-mode]');
            this.gate = root.dataset.gate;
            this.mode = null;
            this.pc = null;
            this.hls = null;
            this.overlayTimer = null;
            this.statsTimer = null;
            this.frameCount = 0;
            this.lastStats = null;
            this.lastReport = 0;
            this.retries = 0;
            this.stopped = false;
            this.overlayData = null;
            this.progressMark = null;
            this.lastProgressAt = Date.now();
            this.modeSince = Date.now();
            this.stalls = 0;
            this.pictureAt = 0;
            this.mjpegFailed = false;
            this.start();
            this.watchdog = window.setInterval(() => this.checkStall(), 2000);
            this.syncOverlay();
            window.addEventListener('resize', () => this.drawOverlay());
        }

        activeElement() {
            return this.mode === 'mjpeg' ? this.img : this.video;
        }

        emit(name, detail) {
            this.root.dispatchEvent(new CustomEvent(name, { detail: detail || {}, bubbles: true }));
        }

        setMode(mode) {
            this.mode = mode;
            this.modeSince = Date.now();
            this.video.removeAttribute('poster');
            this.root.dataset.mode = mode;
            this.video.hidden = mode === 'mjpeg';
            this.img.hidden = mode !== 'mjpeg';
            if (this.badge) {
                this.badge.textContent = { webrtc: 'Live · full quality', hls: 'Live · HLS', mjpeg: 'Live · basic' }[mode] || '';
            }
            this.emit('live:mode', { mode });
        }

        async start() {
            if (this.connecting) {
                return;
            }
            this.connecting = true;
            this.keepLastFrame();
            this.teardown();
            this.progressMark = null;
            this.lastProgressAt = Date.now();
            try {
                if (this.root.dataset.webrtc === '1' && window.RTCPeerConnection) {
                    if (await this.tryWebRTC()) {
                        return;
                    }
                    if (await this.tryHLS()) {
                        return;
                    }
                }
                this.startMJPEG();
            } finally {
                this.connecting = false;
            }
        }

        /*
         * While it connects again, the last picture stays on screen (as the
         * video's poster) with "Reconnecting…", instead of a black box.
         */
        keepLastFrame() {
            if ((this.mode !== 'webrtc' && this.mode !== 'hls') || !this.video.videoWidth) {
                return;
            }
            try {
                const canvas = document.createElement('canvas');
                canvas.width = this.video.videoWidth;
                canvas.height = this.video.videoHeight;
                canvas.getContext('2d').drawImage(this.video, 0, 0);
                this.video.poster = canvas.toDataURL('image/jpeg', 0.7);
            } catch (error) {
                // No poster: the box stays dark for the few seconds it takes.
            }
            if (this.badge) {
                this.badge.textContent = 'Reconnecting…';
            }
        }

        /* Something that grows while new pictures arrive (null: cannot tell). */
        async progress() {
            if (this.mode === 'webrtc' && this.pc) {
                let frames = null;
                (await this.pc.getStats()).forEach((item) => {
                    if (item.type === 'inbound-rtp' && item.kind === 'video') {
                        frames = item.framesDecoded ?? null;
                    }
                });
                return frames;
            }
            if (this.mode === 'hls') {
                return Math.round(this.video.currentTime * 10);
            }
            return null;
        }

        async checkStall() {
            const now = Date.now();
            // Another tab (the browser slows it down) or busy connecting. A video
            // hidden by the page keeps decoding, so it is still checked.
            if (this.stopped || this.connecting || document.hidden) {
                this.lastProgressAt = now;
                return;
            }
            if (this.mode === 'mjpeg') {
                if (this.root.dataset.webrtc === '1' && now - this.modeSince > UPGRADE_AFTER_MS) {
                    this.modeSince = now;
                    this.start();
                }
                return;
            }
            let mark = null;
            try {
                mark = await this.progress();
            } catch (error) {
                mark = null;
            }
            if (mark === null) {
                return;
            }
            if (mark !== this.progressMark) {
                this.progressMark = mark;
                this.lastProgressAt = now;
                this.pictureAt = now;
                return;
            }
            if (now - this.lastProgressAt > STALL_MS) {
                this.stalls += 1;
                this.root.dataset.stalls = String(this.stalls);
                this.emit('live:stalled', { mode: this.mode, stalls: this.stalls });
                this.start();
            }
        }

        /*
         * True while new pictures arrive (or the basic view shows one).
         * Pages show "Camera offline" only when this is false: the detector's
         * own status can be down (restarting, its stream hiccuped) while the
         * camera's live view is fine, and the picture must not vanish then.
         */
        hasPicture() {
            if (this.mode === 'mjpeg') {
                return !this.mjpegFailed && this.img.naturalWidth > 0;
            }
            return this.pictureAt > 0 && Date.now() - this.pictureAt < STALL_MS + 3000;
        }

        /* Connect again (camera back, new address); ignored while connecting. */
        reconnect() {
            this.start();
        }

        teardown() {
            if (this.pc) {
                this.pc.close();
                this.pc = null;
            }
            if (this.hls) {
                this.hls.destroy();
                this.hls = null;
            }
            window.clearInterval(this.statsTimer);
        }

        /* ---------- WebRTC ---------- */

        async tryWebRTC() {
            const pc = new RTCPeerConnection({ iceServers: [] });
            this.pc = pc;
            pc.addTransceiver('video', { direction: 'recvonly' });
            const stream = new MediaStream();
            pc.ontrack = (event) => {
                stream.addTrack(event.track);
                this.video.srcObject = stream;
            };

            try {
                const offer = await pc.createOffer();
                await pc.setLocalDescription(offer);
                await this.iceGathered(pc, 1500);
                const response = await fetch(this.root.dataset.webrtcUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/sdp', Accept: 'application/sdp', 'X-CSRF-TOKEN': csrf() },
                    body: pc.localDescription.sdp,
                });
                if (!response.ok) {
                    throw new Error('no answer');
                }
                await pc.setRemoteDescription({ type: 'answer', sdp: await response.text() });
                await this.firstFrame(WEBRTC_TIMEOUT_MS);
            } catch (error) {
                pc.close();
                this.pc = null;
                return false;
            }

            this.setMode('webrtc');
            this.pictureAt = Date.now();
            this.emit('live:ready');
            pc.onconnectionstatechange = () => {
                // The camera or the network dropped: try again (WebRTC first).
                // "disconnected" often recovers by itself, so wait a little first.
                if (pc.connectionState === 'failed' && this.pc === pc && !this.stopped) {
                    window.setTimeout(() => this.start(), 2000);
                } else if (pc.connectionState === 'disconnected' && this.pc === pc) {
                    window.setTimeout(() => {
                        if (this.pc === pc && pc.connectionState !== 'connected') {
                            this.start();
                        }
                    }, 5000);
                }
            };
            this.statsTimer = window.setInterval(() => this.webrtcStats(), 3000);
            return true;
        }

        iceGathered(pc, timeout) {
            if (pc.iceGatheringState === 'complete') {
                return Promise.resolve();
            }
            return new Promise((resolve) => {
                const done = () => {
                    if (pc.iceGatheringState === 'complete') {
                        resolve();
                    }
                };
                pc.addEventListener('icegatheringstatechange', done);
                window.setTimeout(resolve, timeout);
            });
        }

        firstFrame(timeout) {
            return new Promise((resolve, reject) => {
                const timer = window.setTimeout(() => reject(new Error('timeout')), timeout);
                const ready = () => {
                    window.clearTimeout(timer);
                    resolve();
                };
                this.video.addEventListener('loadeddata', ready, { once: true });
                this.video.play().catch(() => {});
            });
        }

        async webrtcStats() {
            if (!this.pc) {
                return;
            }
            const report = await this.pc.getStats();
            let video = null;
            let rtt = null;
            report.forEach((item) => {
                if (item.type === 'inbound-rtp' && item.kind === 'video') {
                    video = item;
                } else if (item.type === 'candidate-pair' && item.nominated && item.currentRoundTripTime !== undefined) {
                    rtt = item.currentRoundTripTime;
                }
            });
            if (!video) {
                return;
            }
            // Over the last few seconds (not since the start): network (half
            // the round trip) + jitter buffer + decode. The camera's own
            // encoding time is not visible to the browser.
            const previous = this.lastStats || {};
            const delta = (key) => (video[key] ?? 0) - (previous[key] ?? 0);
            const emitted = delta('jitterBufferEmittedCount');
            const decoded = delta('framesDecoded');
            const buffer = emitted > 0 ? (delta('jitterBufferDelay') / emitted) * 1000 : null;
            const decode = decoded > 0 && video.totalDecodeTime !== undefined ? (delta('totalDecodeTime') / decoded) * 1000 : 0;
            const fps = video.framesPerSecond
                ?? (previous.timestamp ? decoded / ((video.timestamp - previous.timestamp) / 1000) : null);
            this.lastStats = video;
            this.report({
                mode: 'webrtc', width: video.frameWidth, height: video.frameHeight, fps,
                delay_ms: buffer === null ? null : buffer + decode + (rtt ? (rtt * 1000) / 2 : 0),
            });
        }

        /* ---------- HLS ---------- */

        async tryHLS() {
            const url = this.root.dataset.hlsUrl;
            if (!url) {
                return false;
            }
            this.video.srcObject = null;
            try {
                if (window.Hls && window.Hls.isSupported()) {
                    this.hls = new window.Hls({ lowLatencyMode: true, liveSyncDuration: 1, maxLiveSyncPlaybackRate: 1.5 });
                    this.hls.loadSource(url);
                    this.hls.attachMedia(this.video);
                } else if (this.video.canPlayType('application/vnd.apple.mpegurl')) {
                    this.video.src = url;
                } else {
                    return false;
                }
                await this.firstFrame(HLS_TIMEOUT_MS);
            } catch (error) {
                this.teardown();
                this.video.removeAttribute('src');
                return false;
            }
            this.setMode('hls');
            this.pictureAt = Date.now();
            this.emit('live:ready');
            this.countFrames();
            this.statsTimer = window.setInterval(() => {
                const fps = this.frameCount / 3;
                this.frameCount = 0;
                this.report({ mode: 'hls', width: this.video.videoWidth, height: this.video.videoHeight, fps, delay_ms: this.hls?.latency ? this.hls.latency * 1000 : null });
            }, 3000);
            return true;
        }

        countFrames() {
            if (!this.video.requestVideoFrameCallback) {
                return;
            }
            const tick = () => {
                this.frameCount += 1;
                if (this.mode === 'hls') {
                    this.video.requestVideoFrameCallback(tick);
                }
            };
            this.video.requestVideoFrameCallback(tick);
        }

        /* ---------- MJPEG (last fallback) ---------- */

        startMJPEG() {
            const url = this.root.dataset.mjpegUrl;
            this.video.srcObject = null;
            this.setMode('mjpeg');
            if (!url) {
                this.emit('live:error');
                return;
            }
            this.img.onload = () => {
                this.mjpegFailed = false;
                this.pictureAt = Date.now();
                this.emit('live:ready');
                this.report({ mode: 'mjpeg', width: this.img.naturalWidth, height: this.img.naturalHeight });
            };
            this.img.onerror = () => {
                this.mjpegFailed = true;
                this.emit('live:error');
                // The detector may be starting: try the best mode again later.
                window.setTimeout(() => {
                    if (this.mode === 'mjpeg' && !this.stopped) {
                        this.start();
                    }
                }, 8000);
            };
            this.img.src = `${url}${url.includes('?') ? '&' : '?'}t=${Date.now()}`;
        }

        report(stats) {
            const now = Date.now();
            this.root.dataset.width = stats.width || '';
            this.root.dataset.height = stats.height || '';
            this.root.dataset.fps = stats.fps ? stats.fps.toFixed(1) : '';
            this.root.dataset.delayMs = stats.delay_ms ? Math.round(stats.delay_ms) : '';
            if (!this.root.dataset.statsUrl || now - this.lastReport < 15000) {
                return;
            }
            this.lastReport = now;
            const body = { ...stats, page: this.root.dataset.page || null };
            Object.keys(body).forEach((key) => (body[key] === null || body[key] === undefined || Number.isNaN(body[key])) && delete body[key]);
            if (body.fps) {
                body.fps = Math.round(body.fps * 10) / 10;
            }
            if (body.delay_ms) {
                body.delay_ms = Math.round(body.delay_ms);
            }
            fetch(this.root.dataset.statsUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
                body: JSON.stringify(body),
            }).catch(() => {});
        }

        /* ---------- detection overlay ---------- */

        syncOverlay() {
            const on = this.root.dataset.overlay === '1' && overlayWanted(this.root.dataset.overlayKey, this.root.dataset.overlayDefault)
                && this.canvas && this.root.dataset.overlayUrl;
            window.clearInterval(this.overlayTimer);
            if (!on) {
                this.overlayData = null;
                this.drawOverlay();
                return;
            }
            const poll = async () => {
                if (document.hidden) {
                    return;
                }
                try {
                    const response = await fetch(this.root.dataset.overlayUrl, { cache: 'no-store' });
                    this.overlayData = await response.json();
                } catch (error) {
                    this.overlayData = null;
                }
                this.drawOverlay();
            };
            poll();
            this.overlayTimer = window.setInterval(poll, 200);
        }

        /* Where the picture is inside the box (object-fit: contain). */
        contentRect(width, height) {
            const media = this.activeElement();
            const naturalWidth = media.videoWidth || media.naturalWidth || 16;
            const naturalHeight = media.videoHeight || media.naturalHeight || 9;
            const scale = Math.min(width / naturalWidth, height / naturalHeight);
            return { x: (width - naturalWidth * scale) / 2, y: (height - naturalHeight * scale) / 2, w: naturalWidth * scale, h: naturalHeight * scale };
        }

        drawOverlay() {
            if (!this.canvas) {
                return;
            }
            const width = this.canvas.clientWidth;
            const height = this.canvas.clientHeight;
            this.canvas.width = width * (window.devicePixelRatio || 1);
            this.canvas.height = height * (window.devicePixelRatio || 1);
            const ctx = this.canvas.getContext('2d');
            ctx.setTransform(window.devicePixelRatio || 1, 0, 0, window.devicePixelRatio || 1, 0, 0);
            ctx.clearRect(0, 0, width, height);
            const data = this.overlayData;
            if (!data || !data.ready) {
                return;
            }
            const r = this.contentRect(width, height);
            const px = (x, y) => [r.x + x * r.w, r.y + y * r.h];

            // Calibration draws its own zone and line (being edited): boxes only there.
            const shapes = this.root.dataset.overlayShapes !== '0';
            if (shapes && Array.isArray(data.zone) && data.zone.length > 2) {
                ctx.beginPath();
                data.zone.forEach((point, index) => {
                    const [x, y] = px(point.x, point.y);
                    index ? ctx.lineTo(x, y) : ctx.moveTo(x, y);
                });
                ctx.closePath();
                ctx.strokeStyle = 'rgba(111, 75, 174, 0.7)';
                ctx.lineWidth = 1.5;
                ctx.stroke();
            }
            if (shapes && data.line) {
                const [x1, y1] = px(data.line.x1, data.line.y1);
                const [x2, y2] = px(data.line.x2, data.line.y2);
                ctx.beginPath();
                ctx.moveTo(x1, y1);
                ctx.lineTo(x2, y2);
                ctx.strokeStyle = '#f59e0b';
                ctx.lineWidth = 3;
                ctx.stroke();
            }
            ctx.font = '600 13px system-ui, sans-serif';
            (data.tracks || []).forEach((track) => {
                const [x1, y1] = px(track.box[0], track.box[1]);
                const [x2, y2] = px(track.box[2], track.box[3]);
                ctx.strokeStyle = track.color;
                ctx.lineWidth = 3;
                ctx.strokeRect(x1, y1, x2 - x1, y2 - y1);
                const text = track.label;
                const textWidth = ctx.measureText(text).width + 10;
                ctx.fillStyle = track.color;
                ctx.fillRect(x1, Math.max(0, y1 - 20), textWidth, 20);
                ctx.fillStyle = '#ffffff';
                ctx.fillText(text, x1 + 5, Math.max(14, y1 - 6));
            });
            const latest = (data.crossings || []).filter((item) => item.seconds_ago < 3).pop();
            if (latest && latest.direction) {
                ctx.font = '800 28px system-ui, sans-serif';
                ctx.fillStyle = 'rgba(17, 24, 39, 0.75)';
                ctx.fillRect(r.x + 12, r.y + 12, 92, 44);
                ctx.fillStyle = '#ffffff';
                ctx.fillText(latest.direction, r.x + 24, r.y + 45);
            }
        }
    }

    function mountAll() {
        document.querySelectorAll('[data-live-video]').forEach((root) => {
            if (!root.liveVideo) {
                root.liveVideo = new LivePlayer(root);
                players.push(root.liveVideo);
            }
        });
    }

    // "Show detection boxes on live view" (remembered on this browser).
    document.addEventListener('change', (event) => {
        const toggle = event.target.closest('[data-overlay-toggle]');
        if (!toggle) {
            return;
        }
        try {
            localStorage.setItem(toggle.dataset.overlayKey || OVERLAY_KEY, toggle.checked ? 'on' : 'off');
        } catch (error) {
            // Not remembered in private mode.
        }
        players.forEach((player) => player.syncOverlay());
    });

    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('[data-overlay-toggle]').forEach((toggle) => {
            toggle.checked = overlayWanted(toggle.dataset.overlayKey, toggle.dataset.overlayDefault);
        });
        mountAll();
    });

    window.LiveVideo = { mountAll, players };
})();
