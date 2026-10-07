{{--
    B3 (Settings › Advanced › Manual setup): for a technician, only when a
    device cannot be added from the Gates tab: camera address and login,
    stream options, the reader type and a manual reader address, and the
    integration key. The camera password is never sent back to the page.
--}}
<section class="panel">
    <form method="POST" action="{{ route('settings.update') }}" class="stack-form" id="settings-form">
        @csrf
        @method('PUT')
        <input type="hidden" name="section" value="manual">

            <section class="subpanel">
                <div class="panel-title-row">
                    <h4>Camera Sources</h4>
                    <p class="field-help">Network cameras are added from the <a href="{{ route('settings.index', ['tab' => 'gates']) }}">Gates</a> tab and found automatically by their MAC address, on any network. A source typed here is only for a camera that cannot be found automatically.</p>
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
                                <p class="field-help">Stream and snapshot options appear when a camera is added from the <a href="{{ route('settings.index', ['tab' => 'gates']) }}">Gates</a> tab.</p>
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

                            @if ($managed)
                                {{-- Camera source work: the device is the source; its address follows the camera. --}}
                                @php
                                    $options = (array) $assignment->options;
                                @endphp
                                <p class="field-help" data-camera-automatic>
                                    Automatic: found by its MAC address {{ $assignment->device->mac }}{{ $assignment->device->ip ? ' (now at '.$assignment->device->ip.')' : '' }}.
                                    Stream paths {{ ($options['paths_from'] ?? null) === 'onvif' ? 'read from the camera (ONVIF)' : 'of its brand' }}:
                                    main {{ $options['paths']['main'] ?? '—' }}, sub {{ $options['paths']['sub'] ?? '—' }}.
                                </p>
                            @else
                            <details class="advanced-section" @if ($errors->hasAny(["camera_configs.$role.source_type", "camera_configs.$role.source_value"]) || in_array($sourceType, ['rtsp', 'url'], true)) open @endif>
                                <summary>Advanced: manual source</summary>
                                <p class="field-help field-warning">Only for a camera that cannot be added from the Gates tab. A typed address does not follow the camera when its address or the network changes.</p>
                                <div class="form-grid">
                                    <div class="field">
                                        <label for="{{ $role }}_source_type">Source</label>
                                        <select id="{{ $role }}_source_type" name="camera_configs[{{ $role }}][source_type]" required>
                                            <option value="none" @selected(! in_array($sourceType, ['rtsp', 'url'], true))>No camera</option>
                                            <option value="rtsp" @selected($sourceType === 'rtsp')>RTSP address</option>
                                            <option value="url" @selected($sourceType === 'url')>Other stream URL</option>
                                        </select>
                                        @error("camera_configs.$role.source_type")
                                            <span class="field-error">{{ $message }}</span>
                                        @enderror
                                    </div>

                                    <div class="field">
                                        <label for="{{ $role }}_source_value">Address</label>
                                        <input
                                            id="{{ $role }}_source_value"
                                            type="text"
                                            name="camera_configs[{{ $role }}][source_value]"
                                            value="{{ old("camera_configs.$role.source_value", $camera['source_value']) }}"
                                            placeholder="rtsp://camera-address:554/stream-path"
                                            autocomplete="off"
                                        >
                                        <span class="field-help">The full rtsp:// address of the camera stream, without the login (the login is below).</span>
                                        @error("camera_configs.$role.source_value")
                                            <span class="field-error">{{ $message }}</span>
                                        @enderror
                                    </div>

                                    <div class="field span-full">
                                        <label for="{{ $role }}_snapshot_source">Snapshot source (optional)</label>
                                        <input id="{{ $role }}_snapshot_source" type="text" name="camera_configs[{{ $role }}][snapshot_source_value]"
                                               value="{{ old("camera_configs.$role.snapshot_source_value", $camera['snapshot_source_value'] ?? '') }}" autocomplete="off">
                                        <span class="field-help">Full-resolution rtsp:// stream opened only when a vehicle is detected. Leave blank to use the live frame.</span>
                                    </div>
                                </div>
                            </details>
                            @endif
                        </article>
                    @endforeach
                </div>
            </section>

            {{-- Moved from Gates & Readers: reader type and a manual reader address per gate. --}}
            <section class="subpanel">
                <div class="panel-title-row">
                    <h4>RFID readers</h4>
                    <p class="field-help">Readers added from the Gates tab need nothing here. Use a manual address only when the reader cannot be found on the network.</p>
                </div>
                <div class="camera-grid">
                    @foreach ($gates as $gate)
                        @php
                            $field = fn (string $name) => "gates[{$gate->code}][{$name}]";
                            $old = fn (string $name, $default) => old("gates.{$gate->code}.{$name}", $default);
                            $readerType = $old('reader_type', $gate->reader_type);
                            $manual = (string) $old('reader_manual', $gate->reader_manual ? '1' : '0') === '1';
                            $transport = $old('reader_transport', $gate->reader_transport ?: 'tcp');
                        @endphp
                        <article class="camera-card" data-gate-card="{{ $gate->code }}">
                            <div class="camera-card-head">
                                <div>
                                    <h4>{{ $gate->name }}</h4>
                                    <p>{{ $gate->readerDisplayName() }}</p>
                                </div>
                            </div>
                            <input type="hidden" name="{{ $field('name') }}" value="{{ $gate->name }}">
                            <div class="field">
                                <label for="gate_{{ $gate->code }}_reader_type">Reader type</label>
                                <select id="gate_{{ $gate->code }}_reader_type" name="{{ $field('reader_type') }}">
                                    @foreach (\App\Models\Gate::READER_TYPES as $value => $text)
                                        <option value="{{ $value }}" @selected($readerType === $value)>{{ $text }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <label class="checkbox-row">
                                <input type="hidden" name="{{ $field('reader_manual') }}" value="0">
                                <input type="checkbox" name="{{ $field('reader_manual') }}" value="1" @checked($manual)>
                                Use this manual reader address
                            </label>
                            <div class="form-grid">
                                <div class="field">
                                    <label for="gate_{{ $gate->code }}_reader_ip">Reader IP</label>
                                    <input id="gate_{{ $gate->code }}_reader_ip" type="text" name="{{ $field('reader_ip') }}" value="{{ $old('reader_ip', $gate->reader_ip) }}" inputmode="decimal" autocomplete="off">
                                    @error("gates.{$gate->code}.reader_ip")<span class="field-error">{{ $message }}</span>@enderror
                                </div>
                                <div class="field">
                                    <label for="gate_{{ $gate->code }}_reader_port">Port</label>
                                    <input id="gate_{{ $gate->code }}_reader_port" type="number" name="{{ $field('reader_port') }}" value="{{ $old('reader_port', $gate->reader_port) }}" min="1" max="65535">
                                    @error("gates.{$gate->code}.reader_port")<span class="field-error">{{ $message }}</span>@enderror
                                </div>
                                <div class="field">
                                    <label for="gate_{{ $gate->code }}_reader_transport">Protocol</label>
                                    <select id="gate_{{ $gate->code }}_reader_transport" name="{{ $field('reader_transport') }}">
                                        <option value="tcp" @selected($transport === 'tcp')>TCP</option>
                                        <option value="udp" @selected($transport === 'udp')>UDP</option>
                                    </select>
                                </div>
                            </div>
                        </article>
                    @endforeach
                </div>
            </section>

            {{-- Phase 6: used by the Python detector and the device service; it lives in .env. --}}
            <section class="subpanel">
                <div class="panel-title-row">
                    <h4>Integration key</h4>
                </div>
                <div class="field">
                    <label for="python_api_key">Shared integration key</label>
                    <input id="python_api_key" type="text" value="{{ $detectorKeySet ? 'Set in .env (DETECTOR_API_KEY)' : 'Not set: add DETECTOR_API_KEY to .env' }}" readonly>
                    <span class="field-help">Used by the Python detector, the device service and RFID readers. Change it in .env, then restart the server.</span>
                </div>
            </section>

        <div class="button-row button-row-end">
            <button type="submit" class="button button-primary">Save</button>
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
