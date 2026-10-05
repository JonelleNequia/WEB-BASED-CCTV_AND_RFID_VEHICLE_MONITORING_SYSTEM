{{--
    UI Phase 2: one form per Settings tab, each with its own Save button.
    Plug-and-detect: network cameras are assigned in Gates & Readers ›
    Devices; the manual source moved to "Advanced". The password is never
    sent back to the page.
--}}
<section class="panel">
    <form method="POST" action="{{ route('settings.update') }}" class="stack-form" id="settings-form">
        @csrf
        @method('PUT')
        <input type="hidden" name="section" value="cameras">

            <section class="subpanel">
                <div class="panel-title-row">
                    <h4>Camera Sources</h4>
                    <p class="field-help">Network cameras are found automatically. Assign them in <a href="{{ route('settings.index', ['tab' => 'stations']) }}">Gates &amp; Readers › Devices</a>.</p>
                </div>

                <div class="camera-grid">
                    {{-- Phase 1: one camera per gate. --}}
                    @foreach ($cameraConfigs as $role => $camera)
                        @php
                            $label = ($camera['gate_name'] ?? \App\Models\Gate::labelFor($role)).' Camera';
                            $assignment = $cameraAssignments[$role] ?? null;
                            $managed = $assignment?->device !== null;
                            $sourceType = old("camera_configs.$role.source_type", $camera['source_type']);
                        @endphp
                        {{-- UI Phase 3: every gate's card has the same parts: live state badge,
                             one "Source" line (assigned device or manual address), preview, stream. --}}
                        @php
                            $live = $cameraLive['cameras'][$role] ?? [];
                            $liveReason = ! ($cameraLive['service_running'] ?? false)
                                ? 'Detector not running yet. It starts by itself.'
                                : (($live['camera_running'] ?? false) ? null : ($live['last_error'] ?? 'Connecting to the camera…'));
                        @endphp
                        <article class="camera-card">
                            <div class="camera-card-head">
                                <div>
                                    <h4>{{ $label }}</h4>
                                    <p>Source: {{ $managed ? 'assigned device · '.($assignment->device->name ?: $assignment->device->ip) : 'manual address' }}</p>
                                </div>
                                <x-badge :tone="$liveReason ? 'critical' : 'success'" :label="$liveReason ? 'Offline' : 'OK'" />
                            </div>
                            <p class="field-help one-line" title="{{ $camera['source_display'] }}">{{ $camera['source_display'] }}</p>
                            <div class="camera-preview">
                                <img src="{{ $live['stream_url'] ?? '' }}" alt="{{ $label }} live preview" data-camera-preview loading="lazy">
                                @if ($liveReason)
                                    <p class="frame-message">{{ $liveReason }}</p>
                                @endif
                            </div>

                            {{-- Live-latency work: which stream feeds the live view and detection. --}}
                            @if ($managed)
                                @php
                                    $options = (array) $assignment->options;
                                    $liveStream = old("camera_streams.$role.stream", $options['stream'] ?? 'sub');
                                    $snapshots = old("camera_streams.$role.snapshots", ($options['snapshots'] ?? false) ? '1' : '0') === '1';
                                @endphp
                                <div class="form-grid">
                                    <div class="field">
                                        <label for="{{ $role }}_live_stream">Live view &amp; detection stream</label>
                                        <select id="{{ $role }}_live_stream" name="camera_streams[{{ $role }}][stream]">
                                            <option value="sub" @selected($liveStream === 'sub')>Sub stream (low delay, recommended)</option>
                                            <option value="main" @selected($liveStream === 'main')>Main stream (sharper, about 0.4 s more delay)</option>
                                        </select>
                                    </div>
                                    <div class="field">
                                        <span class="field-label">Snapshots</span>
                                        <label class="checkbox-row">
                                            <input type="hidden" name="camera_streams[{{ $role }}][snapshots]" value="0">
                                            <input type="checkbox" name="camera_streams[{{ $role }}][snapshots]" value="1" @checked($snapshots)>
                                            Full-resolution frame from the main stream when a vehicle is detected
                                        </label>
                                    </div>
                                </div>

                                {{-- Live-latency work: camera encoder settings over ONVIF (applied only after confirming). --}}
                                <details class="advanced-section" data-encoder
                                         data-preview-url="{{ route('settings.cameras.encoder', $role) }}"
                                         data-optimize-url="{{ route('settings.cameras.encoder.optimize', $role) }}">
                                    <summary>Camera settings (frame rate, key frames, bitrate)</summary>
                                    <p class="field-help">Recommended for live monitoring: H.264, 15 fps, a key frame every second. Read from the camera; nothing changes until you confirm.</p>
                                    <div data-encoder-table><p class="text-muted">Loading…</p></div>
                                    <div class="button-row">
                                        <button type="button" class="button button-secondary button-sm" data-encoder-apply disabled>Optimize camera settings</button>
                                    </div>
                                </details>
                            @else
                                <p class="field-help">Stream and snapshot options appear when a camera is assigned in <a href="{{ route('settings.index', ['tab' => 'stations']) }}">Gates &amp; Readers › Devices</a>.</p>
                            @endif

                            <div class="form-grid">
                                <div class="field span-full">
                                    <label for="{{ $role }}_camera_name">Camera Label</label>
                                    <input id="{{ $role }}_camera_name" type="text" name="camera_configs[{{ $role }}][camera_name]" value="{{ old("camera_configs.$role.camera_name", $camera['camera_name']) }}" required>
                                    @error("camera_configs.$role.camera_name")
                                        <span class="field-error">{{ $message }}</span>
                                    @enderror
                                </div>

                                <div class="field">
                                    <label for="{{ $role }}_source_username">Camera Username</label>
                                    <input id="{{ $role }}_source_username" type="text" name="camera_configs[{{ $role }}][source_username]" value="{{ old("camera_configs.$role.source_username", $camera['source_username']) }}" autocomplete="off">
                                    @error("camera_configs.$role.source_username")
                                        <span class="field-error">{{ $message }}</span>
                                    @enderror
                                </div>

                                <div class="field">
                                    <label for="{{ $role }}_source_password">Camera Password</label>
                                    <input id="{{ $role }}_source_password" type="password" name="camera_configs[{{ $role }}][source_password]" value="" autocomplete="new-password"
                                           placeholder="{{ $camera['has_password'] ? 'Saved (encrypted)' : 'Not set' }}">
                                    @error("camera_configs.$role.source_password")
                                        <span class="field-error">{{ $message }}</span>
                                    @enderror
                                </div>
                            </div>

                            <p class="field-help">Leave the password blank to keep the saved one.
                                @if ($camera['has_password'])
                                    <label class="checkbox-row">
                                        <input type="checkbox" name="camera_configs[{{ $role }}][clear_password]" value="1"> Remove the saved password
                                    </label>
                                @endif
                            </p>

                            <details class="advanced-section" @if ($errors->hasAny(["camera_configs.$role.source_type", "camera_configs.$role.source_value"])) open @endif>
                                <summary>Advanced: manual source</summary>
                                @if ($managed)
                                    <p class="field-help">Managed by the assigned camera: the address follows it automatically when its IP changes. Unassign it in Gates &amp; Readers to type a source by hand.</p>
                                @else
                                    <p class="field-help">Only for a USB webcam or a camera that cannot be detected.</p>
                                @endif
                                <div class="form-grid">
                                    <div class="field">
                                        <label for="{{ $role }}_source_type">Source Type</label>
                                        @if ($managed)
                                            <input type="hidden" name="camera_configs[{{ $role }}][source_type]" value="{{ $camera['source_type'] }}">
                                        @endif
                                        <select id="{{ $role }}_source_type" @unless ($managed) name="camera_configs[{{ $role }}][source_type]" @endunless required @disabled($managed)>
                                            <option value="webcam" @selected($sourceType === 'webcam')>Webcam</option>
                                            <option value="rtsp" @selected($sourceType === 'rtsp')>RTSP</option>
                                            <option value="url" @selected($sourceType === 'url')>URL</option>
                                        </select>
                                        @error("camera_configs.$role.source_type")
                                            <span class="field-error">{{ $message }}</span>
                                        @enderror
                                    </div>

                                    <div class="field">
                                        <label for="{{ $role }}_source_value">Source Value</label>
                                        <input
                                            id="{{ $role }}_source_value"
                                            type="text"
                                            name="camera_configs[{{ $role }}][source_value]"
                                            value="{{ old("camera_configs.$role.source_value", $camera['source_value']) }}"
                                            required
                                            @readonly($managed)
                                        >
                                        <span class="field-help">Webcam: a number such as 0. RTSP: the full rtsp:// address of the camera stream.</span>
                                        @error("camera_configs.$role.source_value")
                                            <span class="field-error">{{ $message }}</span>
                                        @enderror
                                    </div>

                                    @unless ($managed)
                                        <div class="field span-full">
                                            <label for="{{ $role }}_snapshot_source">Snapshot source (optional)</label>
                                            <input id="{{ $role }}_snapshot_source" type="text" name="camera_configs[{{ $role }}][snapshot_source_value]"
                                                   value="{{ old("camera_configs.$role.snapshot_source_value", $camera['snapshot_source_value'] ?? '') }}" autocomplete="off">
                                            <span class="field-help">Full-resolution rtsp:// stream opened only when a vehicle is detected. Leave blank to use the live frame.</span>
                                        </div>
                                    @endunless

                                    <div class="field span-full">
                                        <label for="{{ $role }}_browser_device">Saved Browser Device</label>
                                        <input id="{{ $role }}_browser_device" type="text" value="{{ $camera['browser_label'] ?: 'No saved browser device yet.' }}" readonly>
                                    </div>
                                </div>
                            </details>
                        </article>
                    @endforeach
                </div>
            </section>

            {{-- Live-latency work: tuning shared by both cameras. --}}
            <section class="subpanel">
                <div class="panel-title-row">
                    <h4>Live view performance</h4>
                    <p class="field-help">Lower values mean less delay and CPU. Measurements are in Settings › System Status.</p>
                </div>
                <div class="form-grid">
                    <div class="field">
                        <label for="perf_stream_fps">Live view FPS</label>
                        <input id="perf_stream_fps" type="number" name="perf_stream_fps" min="1" max="30" step="1" value="{{ old('perf_stream_fps', $settings['perf_stream_fps']) }}">
                    </div>
                    <div class="field">
                        <label for="perf_stream_width">Live view width (px)</label>
                        <input id="perf_stream_width" type="number" name="perf_stream_width" min="320" max="1920" step="16" value="{{ old('perf_stream_width', $settings['perf_stream_width']) }}">
                    </div>
                    <div class="field">
                        <label for="perf_jpeg_quality">Live view JPEG quality</label>
                        <input id="perf_jpeg_quality" type="number" name="perf_jpeg_quality" min="30" max="95" value="{{ old('perf_jpeg_quality', $settings['perf_jpeg_quality']) }}">
                    </div>
                    <div class="field">
                        <label for="perf_detection_fps">Detection runs per second</label>
                        <input id="perf_detection_fps" type="number" name="perf_detection_fps" min="1" max="25" step="1" value="{{ old('perf_detection_fps', $settings['perf_detection_fps']) }}">
                    </div>
                    <div class="field">
                        <label for="perf_yolo_imgsz">Detection input size</label>
                        <select id="perf_yolo_imgsz" name="perf_yolo_imgsz">
                            @foreach ([320, 384, 416, 480, 512, 640] as $size)
                                <option value="{{ $size }}" @selected((string) old('perf_yolo_imgsz', $settings['perf_yolo_imgsz']) === (string) $size)>{{ $size }}{{ $size === 480 ? ' (recommended)' : '' }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="field">
                        <label for="perf_yolo_device">Detection runs on</label>
                        <select id="perf_yolo_device" name="perf_yolo_device">
                            @foreach (['auto' => 'Automatic (GPU if available)', 'cpu' => 'CPU', 'mps' => 'Apple GPU (Mac)', 'cuda:0' => 'NVIDIA GPU'] as $value => $text)
                                <option value="{{ $value }}" @selected(old('perf_yolo_device', $settings['perf_yolo_device']) === $value)>{{ $text }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="field span-full">
                        <label class="checkbox-row">
                            <input type="hidden" name="perf_roi_crop" value="0">
                            <input type="checkbox" name="perf_roi_crop" value="1" @checked(old('perf_roi_crop', $settings['perf_roi_crop']) === '1')>
                            Detect only inside the calibrated zone (faster, sharper for small zones)
                        </label>
                        <label class="checkbox-row">
                            <input type="hidden" name="perf_hires_on_trigger" value="0">
                            <input type="checkbox" name="perf_hires_on_trigger" value="1" @checked(old('perf_hires_on_trigger', $settings['perf_hires_on_trigger']) === '1')>
                            Use full-resolution frames for alert snapshots and plate reading
                        </label>
                    </div>
                </div>
            </section>

            {{-- A2 (detection): how the vehicle type is decided (moves to Advanced in the new Settings). --}}
            <section class="subpanel">
                <div class="panel-title-row">
                    <h4>Vehicle type</h4>
                    <p class="field-help">Types: Car (sedan, SUV, AUV, pickup, van), Motorcycle (motorcycle, e-bike, tricycle), Truck/Bus (truck, bus, jeepney). Every frame of a vehicle votes; the camera sees vehicles from behind, so a wide, low back counts as a Car.</p>
                </div>
                <div class="form-grid">
                    <div class="field">
                        <label for="perf_type_truck_min_height">Truck/Bus: back at least this tall (% of the zone)</label>
                        <input id="perf_type_truck_min_height" type="number" name="perf_type_truck_min_height" min="20" max="95" step="1" value="{{ old('perf_type_truck_min_height', $settings['perf_type_truck_min_height']) }}">
                        <span class="field-help">Lower it if real trucks are saved as Car; raise it if cars are saved as Truck/Bus.</span>
                    </div>
                    <div class="field">
                        <label for="perf_type_car_min_aspect">Car: back at least this wide (width ÷ height)</label>
                        <input id="perf_type_car_min_aspect" type="number" name="perf_type_car_min_aspect" min="0.8" max="2.5" step="0.05" value="{{ old('perf_type_car_min_aspect', $settings['perf_type_car_min_aspect']) }}">
                        <span class="field-help">A truck's back is about as tall as it is wide (below this); a car's is wider.</span>
                    </div>
                    <div class="field">
                        <label for="perf_type_model">Second check model</label>
                        <select id="perf_type_model" name="perf_type_model">
                            <option value="yolov8n.pt" @selected(old('perf_type_model', $settings['perf_type_model']) === 'yolov8n.pt')>Fast (yolov8n)</option>
                            <option value="yolov8s.pt" @selected(old('perf_type_model', $settings['perf_type_model']) === 'yolov8s.pt')>More accurate (yolov8s, recommended)</option>
                        </select>
                    </div>
                    <div class="field span-full">
                        <label class="checkbox-row">
                            <input type="hidden" name="perf_type_second_pass" value="0">
                            <input type="checkbox" name="perf_type_second_pass" value="1" @checked(old('perf_type_second_pass', $settings['perf_type_second_pass']) === '1')>
                            Check the type again on the sharpest full-size picture of each vehicle
                        </label>
                    </div>
                </div>
            </section>

            {{-- A3 (detection): when a vehicle counts (moves to Advanced in the new Settings). --}}
            <section class="subpanel">
                <div class="panel-title-row">
                    <h4>Counting</h4>
                    <p class="field-help">A vehicle counts once, when it is clearly past the trigger line. Stopping, rocking or backing up on the line and parked vehicles are not counted.</p>
                </div>
                <div class="form-grid">
                    <div class="field">
                        <label for="perf_cross_margin">Past the line by (% of the zone's height)</label>
                        <input id="perf_cross_margin" type="number" name="perf_cross_margin" min="0" max="30" step="1" value="{{ old('perf_cross_margin', $settings['perf_cross_margin']) }}">
                        <span class="field-help">Raise it if a vehicle stopping on the line is counted twice.</span>
                    </div>
                    <div class="field">
                        <label for="perf_cross_min_points">Seen at least (times)</label>
                        <input id="perf_cross_min_points" type="number" name="perf_cross_min_points" min="1" max="10" step="1" value="{{ old('perf_cross_min_points', $settings['perf_cross_min_points']) }}">
                        <span class="field-help">Lower it if fast vehicles are missed.</span>
                    </div>
                    <div class="field">
                        <label for="perf_cross_min_move">Moved at least (% of the zone's height)</label>
                        <input id="perf_cross_min_move" type="number" name="perf_cross_min_move" min="0" max="60" step="1" value="{{ old('perf_cross_min_move', $settings['perf_cross_min_move']) }}">
                        <span class="field-help">Keeps parked vehicles from being counted.</span>
                    </div>
                </div>
            </section>

        <div class="button-row button-row-end">
            <button type="submit" class="button button-primary">Save Cameras</button>
        </div>
    </form>
</section>

@push('scripts')
    <script>
        // Camera encoder settings: load on open, apply only after confirmation.
        (function () {
            const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
            document.querySelectorAll('[data-encoder]').forEach(function (box) {
                const table = box.querySelector('[data-encoder-table]');
                const apply = box.querySelector('[data-encoder-apply]');
                let preview = null;

                function cell(text) {
                    const td = document.createElement('td');
                    td.textContent = text;
                    return td;
                }

                async function load() {
                    table.textContent = 'Loading…';
                    apply.disabled = true;
                    try {
                        const response = await fetch(box.dataset.previewUrl, { headers: { Accept: 'application/json' } });
                        preview = await response.json();
                        if (!response.ok) {
                            table.textContent = preview.message || 'The camera settings could not be read.';
                            return;
                        }
                        const tableNode = document.createElement('table');
                        tableNode.className = 'devices-table';
                        const head = document.createElement('tr');
                        ['Stream', 'Frame rate', 'Key frame every', 'Bitrate (kbps)'].forEach((label) => {
                            const th = document.createElement('th');
                            th.textContent = label;
                            head.append(th);
                        });
                        tableNode.append(head);
                        preview.encoders.forEach(function (encoder) {
                            const tr = document.createElement('tr');
                            const change = (key, unit) => encoder.current[key] === encoder.proposed[key]
                                ? `${encoder.current[key]}${unit}` : `${encoder.current[key]}${unit} → ${encoder.proposed[key]}${unit}`;
                            tr.append(
                                cell(`${encoder.resolution} (${encoder.encoding})`),
                                cell(change('fps', ' fps')),
                                cell(change('gov', ' frames')),
                                cell(change('bitrate', ''))
                            );
                            tableNode.append(tr);
                        });
                        table.replaceChildren(tableNode);
                        apply.disabled = false;
                    } catch (error) {
                        table.textContent = 'The camera settings could not be read.';
                    }
                }

                box.addEventListener('toggle', function () {
                    if (box.open && !preview) {
                        load();
                    }
                });

                apply.addEventListener('click', async function () {
                    const lines = (preview?.encoders || []).map((e) => `${e.resolution}: ${e.current.fps}→${e.proposed.fps} fps, key frame ${e.current.gov}→${e.proposed.gov}, ${e.current.bitrate}→${e.proposed.bitrate} kbps`);
                    if (!window.confirm(`Change the camera settings?\n\n${lines.join('\n')}\n\nThe live view pauses for a few seconds while the camera restarts its streams.`)) {
                        return;
                    }
                    apply.disabled = true;
                    try {
                        const response = await fetch(box.dataset.optimizeUrl, {
                            method: 'POST',
                            headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
                        });
                        const result = await response.json();
                        window.ui.toast(result.message, response.ok ? 'success' : 'error', { timeout: 8000 });
                        preview = null;
                        load();
                    } catch (error) {
                        window.ui.toast('The camera could not be updated.', 'error');
                        apply.disabled = false;
                    }
                });
            });
        })();
    </script>
    <script>
        // Retry a preview that failed to load (e.g. while the detector starts).
        document.querySelectorAll('[data-camera-preview]').forEach(function (img) {
            const base = img.getAttribute('src');
            img.addEventListener('error', function () {
                window.setTimeout(function () {
                    img.src = base + (base.includes('?') ? '&' : '?') + 'retry=' + Date.now();
                }, 5000);
            });
        });
    </script>
@endpush
