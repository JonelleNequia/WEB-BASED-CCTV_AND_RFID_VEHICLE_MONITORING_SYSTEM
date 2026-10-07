

    <p class="field-help">On a gate's camera, click point by point to draw the detection zone, then draw the trigger line, then save. The detector logs a vehicle when it crosses the line inside the zone.</p>
    <p class="field-help">Each gate records IN and OUT. The <strong>IN</strong> arrow on the line shows the direction of a vehicle coming into campus; use <strong>Flip IN direction</strong> if it points the wrong way, then save. Check it below: drive through once and look at "Recent crossings".</p>

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
                        <div class="button-row camera-toolbar">
                            <button type="button" class="button button-secondary button-sm" data-tool="mask">Draw Polygon ROI</button>
                            <button type="button" class="button button-secondary button-sm" data-tool="line">Draw Trigger Line</button>
                            <button type="button" class="button button-secondary button-sm" data-flip-direction>Flip IN direction</button>
                            <button type="button" class="button button-secondary button-sm" data-clear>Clear</button>
                            <button type="button" class="button button-primary button-sm" data-save>Save Calibration</button>
                        </div>
                    </div>
                @endif

                <div class="camera-stage camera-stage-calibration">
                    @if ($camera['has_camera'])
                        {{-- Calibration work: the gate's own camera, the same live stream as its kiosk and Gate Monitor (no browser camera). --}}
                        <x-live-video :gate="$role" :mjpeg="$camera['stream_url']" :overlay="false" page="calibration"
                                      class="camera-video" data-video :alt="$camera['role_label'].' live camera'" />
                        {{-- The detector's last saved picture: shown while the camera is offline, so the zone can still be drawn. --}}
                        <img class="camera-video camera-last-picture" data-last-picture alt="{{ $camera['role_label'] }} last picture"
                             @if ($camera['snapshot_url']) src="{{ $camera['snapshot_url'] }}" @endif hidden>
                        <canvas class="camera-overlay" data-overlay></canvas>
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

                {{-- Phase 2: the last crossings at this gate with the direction the detector worked out. --}}
                <div class="calibration-crossings">
                    <strong>Recent crossings</strong>
                    <ul class="calibration-crossing-list" data-crossings>
                        @forelse ($recentCrossings[$role] ?? [] as $crossing)
                            <li>
                                <span class="badge {{ in_array($crossing['direction'], ['IN', 'OUT'], true) ? 'badge-open' : 'badge-manual-review' }}">{{ $crossing['direction_label'] }}</span>
                                {{ $crossing['time'] }} · track #{{ $crossing['track_id'] ?? '—' }}{{ $crossing['confidence'] !== null ? ' · '.number_format($crossing['confidence'], 2) : '' }}{{ $crossing['reason'] ? ' · '.$crossing['reason'] : '' }}
                            </li>
                        @empty
                            <li class="field-help">No crossing recorded yet.</li>
                        @endforelse
                    </ul>
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
    <script src="{{ asset('js/calibration-page.js') }}"></script>
@endpush
