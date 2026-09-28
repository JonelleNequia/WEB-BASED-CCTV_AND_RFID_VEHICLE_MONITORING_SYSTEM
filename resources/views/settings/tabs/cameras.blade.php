{{--
    UI Phase 2: one form per Settings tab, each with its own Save button.
    Plug-and-detect: network cameras are assigned in Stations & Readers ›
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
                    <p class="field-help">Network cameras are found automatically. Assign them in <a href="{{ route('settings.index', ['tab' => 'stations']) }}">Stations &amp; Readers › Devices</a>.</p>
                </div>

                <div class="camera-grid">
                    @foreach (['entrance' => 'Entrance Camera', 'exit' => 'Exit Camera'] as $role => $label)
                        @php
                            $camera = $cameraConfigs[$role];
                            $assignment = $cameraAssignments[$role] ?? null;
                            $managed = $assignment?->device !== null;
                            $sourceType = old("camera_configs.$role.source_type", $camera['source_type']);
                        @endphp
                        <article class="camera-card">
                            <div class="camera-card-head">
                                <div>
                                    <h4>{{ $label }}</h4>
                                    <p>{{ $camera['source_display'] }}</p>
                                </div>
                                @if ($managed)
                                    <x-badge tone="success" :label="'Assigned · '.($assignment->device->name ?: $assignment->device->ip)" />
                                @else
                                    <x-badge tone="neutral" label="Manual source" />
                                @endif
                            </div>

                            {{-- Live preview with the detector's own reason when there is no video. --}}
                            @php
                                $live = $cameraLive['cameras'][$role] ?? [];
                                $liveReason = ! ($cameraLive['service_running'] ?? false)
                                    ? 'Detector not running yet. It starts by itself.'
                                    : (($live['camera_running'] ?? false) ? null : ($live['last_error'] ?? 'Connecting to the camera…'));
                            @endphp
                            <div class="camera-preview">
                                <img src="{{ $live['stream_url'] ?? '' }}" alt="{{ $label }} live preview" data-camera-preview loading="lazy">
                                @if ($liveReason)
                                    <p class="frame-message">{{ $liveReason }}</p>
                                @endif
                            </div>

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
                                    <p class="field-help">Managed by the assigned camera: the address follows it automatically when its IP changes. Unassign it in Stations &amp; Readers to type a source by hand.</p>
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

        <div class="button-row button-row-end">
            <button type="submit" class="button button-primary">Save Cameras</button>
        </div>
    </form>
</section>

@push('scripts')
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
