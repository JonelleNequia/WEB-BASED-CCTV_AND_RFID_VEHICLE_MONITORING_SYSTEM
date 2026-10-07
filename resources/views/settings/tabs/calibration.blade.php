

    {{-- Calibration editor: one line per step. --}}
    <ol class="calibration-steps">
        <li><strong>Zone:</strong> with <em>Zone (ROI)</em>, click around the road where vehicles pass; click point 1 or press Done to close it.</li>
        <li><strong>Adjust:</strong> drag a point to move it, the "+" on an edge to add one, the inside to move the whole zone; right-click a point (or select it and press Delete) to remove it.</li>
        <li><strong>Line:</strong> with <em>Trigger line</em>, drag across the road inside the zone; drag its ends or its middle to adjust it.</li>
        <li><strong>Direction:</strong> the <strong>IN</strong> arrow must point into campus; click it (or Flip IN direction) to turn it around.</li>
        <li><strong>Save</strong>, then drive through once and check "Recent crossings" below the video. Undo: Ctrl/⌘+Z · Redo: Ctrl/⌘+Shift+Z.</li>
    </ol>

    {{-- Detector debug view: raw detections, zone/line as used, track IDs, counters. --}}
    <form method="POST" action="{{ route('calibration.debug') }}" class="calibration-debug-toggle">
        @csrf
        <input type="hidden" name="enabled" value="{{ ($debugOverlay ?? false) ? '0' : '1' }}">
        <div>
            <strong>Detector debug view</strong>
            <span class="field-help">Shows every raw detection (class, confidence, box), the zone and trigger line as the detector uses them, track IDs with their last positions, and counters (detections per frame, in zone, line crossings, detection FPS) on the live video. Turn it off after checking.</span>
        </div>
        <button type="submit" class="button {{ ($debugOverlay ?? false) ? 'button-primary' : 'button-secondary' }} button-sm" aria-pressed="{{ ($debugOverlay ?? false) ? 'true' : 'false' }}">
            {{ ($debugOverlay ?? false) ? 'Debug view is ON · Turn off' : 'Turn on debug view' }}
        </button>
    </form>

    <div class="camera-grid">
        @foreach ($cameras as $role => $camera)
            @php($connection = $camera['connection'])
            <article class="camera-card camera-card-calibration" data-calibration-camera data-role="{{ $role }}" data-has-camera="{{ $camera['has_camera'] ? '1' : '0' }}">
                <div class="camera-card-head">
                    <div>
                        <h4>{{ $camera['role_label'] }}</h4>
                        <p>{{ $camera['camera_name'] }}</p>
                    </div>
                    {{-- Calibration work: same detector status as System status. --}}
                    <x-badge :tone="$connection['tone']" :label="$connection['label']" data-status-badge />
                </div>

                @if ($camera['has_camera'])
                    <div class="calibration-controls-panel">
                        <div class="button-row camera-toolbar" role="toolbar" aria-label="{{ $camera['role_label'] }} calibration tools">
                            <span class="calibration-tool-group" role="group" aria-label="Draw">
                                <button type="button" class="button button-secondary button-sm" data-tool="mask" aria-pressed="false">Zone (ROI)</button>
                                <button type="button" class="button button-secondary button-sm" data-tool="line" aria-pressed="false">Trigger line</button>
                            </span>
                            <button type="button" class="button button-secondary button-sm" data-done disabled>Done</button>
                            <button type="button" class="button button-secondary button-sm" data-flip-direction>Flip IN direction</button>
                            <span class="calibration-tool-group" role="group" aria-label="History">
                                <button type="button" class="button button-secondary button-sm" data-undo disabled title="Undo (Ctrl/⌘+Z)">Undo</button>
                                <button type="button" class="button button-secondary button-sm" data-redo disabled title="Redo (Ctrl/⌘+Shift+Z)">Redo</button>
                            </span>
                            <button type="button" class="button button-secondary button-sm" data-reset title="Load the calibration the detector uses now">Reset to saved</button>
                            <button type="button" class="button button-secondary button-sm" data-clear>Clear</button>
                            <button type="button" class="button button-primary button-sm" data-save>Save Calibration</button>
                        </div>
                        <div class="calibration-unsaved" data-unsaved hidden>
                            <span>Unsaved changes: the detector still uses the saved zone and line.</span>
                            <button type="button" class="link-button" data-discard>Discard changes</button>
                        </div>
                        <label class="calibration-detection-toggle">
                            {{-- Off by default; remembered on this browser. Boxes and tracks only, not the saved zone/line. --}}
                            <input type="checkbox" data-overlay-toggle data-overlay-key="calibration.overlay" data-overlay-default="off">
                            Show detection (vehicle boxes and crossings on the live video)
                        </label>
                        <ul class="calibration-problems" data-problems hidden></ul>
                    </div>
                @endif

                <div class="camera-stage camera-stage-calibration">
                    @if ($camera['has_camera'])
                        {{-- Calibration work: the gate's own camera, the same live stream as its kiosk and Gate Monitor (no browser camera). --}}
                        <x-live-video :gate="$role" :mjpeg="$camera['stream_url']" :overlay="true" overlay-key="calibration.overlay" overlay-default="off" :shapes="false" page="calibration"
                                      class="camera-video" data-video :alt="$camera['role_label'].' live camera'" />
                        {{-- The detector's last saved picture: shown while the camera is offline, so the zone can still be drawn. --}}
                        <img class="camera-video camera-last-picture" data-last-picture alt="{{ $camera['role_label'] }} last picture"
                             @if ($camera['snapshot_url']) src="{{ $camera['snapshot_url'] }}" @endif hidden>
                        <canvas class="camera-overlay" data-calibration-canvas tabindex="0" aria-label="{{ $camera['role_label'] }} zone and trigger line"></canvas>
                        <div class="calibration-point-menu" data-point-menu role="menu" hidden>
                            <button type="button" role="menuitem" data-remove-point>Remove point</button>
                        </div>
                        <span class="calibration-picture-badge" data-picture-badge hidden></span>
                        <div class="camera-fallback" data-fallback-wrapper>
                            <div class="camera-fallback-copy">
                                <span class="camera-fallback-kicker">Camera</span>
                                <strong data-fallback>{{ $connection['label'] }}</strong>
                                <p data-fallback-detail>{{ $connection['state'] === 'connected' ? 'Opening the live view…' : $connection['reason'] }}</p>
                            </div>
                        </div>
                    @else
                        <div class="camera-fallback" data-no-camera>
                            <div class="camera-fallback-copy">
                                <span class="camera-fallback-kicker">Camera</span>
                                <strong>This gate has no camera yet.</strong>
                                <p>Add the gate's camera first, then draw its zone and line here.</p>
                                <a href="{{ route('settings.index', ['tab' => 'devices', 'gate' => $role, 'role' => 'camera']) }}" class="button button-primary button-sm" data-add-camera>+ Add camera</a>
                                {{-- Testing: this PC's webcam until the CCTV is ready. --}}
                                <form method="POST" action="{{ route('settings.gate.camera.webcam', $role) }}">
                                    @csrf
                                    <input type="hidden" name="enabled" value="1">
                                    <button type="submit" class="link-button calibration-webcam-link">or use this PC's webcam for testing</button>
                                </form>
                            </div>
                        </div>
                    @endif
                </div>

                {{-- Phase 2: the last crossings at this gate with the direction the detector worked out. --}}
                <div class="calibration-crossings">
                    <strong>Recent crossings</strong> <span class="field-help">updates by itself</span>
                    <ul class="calibration-crossing-list" data-crossings>
                        @forelse ($recentCrossings[$role] ?? [] as $crossing)
                            <li data-crossing-id="{{ $crossing['id'] }}">
                                <span class="badge {{ in_array($crossing['direction'], ['IN', 'OUT'], true) ? 'badge-open' : 'badge-manual-review' }}">{{ $crossing['direction_label'] }}</span>
                                {{ $crossing['time'] }} · {{ $crossing['type_label'] }} · track #{{ $crossing['track_id'] ?? '—' }}{{ $crossing['confidence'] !== null ? ' · '.number_format($crossing['confidence'], 2) : '' }}{{ $crossing['reason'] ? ' · '.$crossing['reason'] : '' }}
                            </li>
                        @empty
                            <li class="field-help">No crossing recorded yet.</li>
                        @endforelse
                    </ul>
                </div>
                <div class="camera-detail-grid calibration-detail-grid">
                    <div>
                        <span>Status</span>
                        <strong data-status-value>{{ $connection['label'] }} · {{ $connection['reason'] }}</strong>
                    </div>
                    <div>
                        <span>Polygon ROI</span>
                        <strong data-mask-value>{{ $camera['calibration_mask'] ? count($camera['calibration_mask']).'-point zone saved' : 'No zone yet' }}</strong>
                    </div>
                    <div>
                        <span>Trigger Line</span>
                        <strong data-line-value>{{ $camera['calibration_line'] ? 'Line saved' : 'No line yet' }}</strong>
                    </div>
                    <div>
                        <span>IN direction</span>
                        <strong data-direction-value>{{ $camera['calibration_line'] ? 'IN = the side the arrow points to' : 'Draw a line first' }}</strong>
                    </div>
                    <div>
                        <span>Message</span>
                        <strong data-message-value>{{ $camera['has_camera'] ? 'Draw the zone, then the line, then save.' : 'Add a camera to calibrate this gate.' }}</strong>
                    </div>
                </div>

            </article>
        @endforeach
    </div>

    @php($calibrationPayload = [
        'cameras' => $cameras,
        'routes' => [
            'save' => route('calibration.update'),
            'heartbeat' => route('calibration.heartbeat'),
        ],
    ])
    <script id="camera-calibration-data" type="application/json">{!! json_encode($calibrationPayload, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) !!}</script>

@push('scripts')
    {{-- ?v=: a changed script is never taken from the browser's cache. --}}
    <script src="{{ asset('js/calibration-editor.js') }}?v={{ filemtime(public_path('js/calibration-editor.js')) }}"></script>
    <script src="{{ asset('js/calibration-page.js') }}?v={{ filemtime(public_path('js/calibration-page.js')) }}"></script>
@endpush
