@extends('layouts.app')

@section('title', 'Camera Calibration | PHILCST Vehicle Monitoring')
@section('page-title', 'Camera Calibration')

@section('content')
    <x-page-header title="Camera Calibration" />

    <p class="field-help">Pick a camera, click point by point to draw the detection zone, then draw the trigger line, then save. The detector logs a vehicle when it crosses the line inside the zone.</p>

    <div class="camera-grid">
        @foreach ($cameras as $role => $camera)
            <article class="camera-card camera-card-calibration" data-calibration-camera data-role="{{ $role }}">
                <div class="camera-card-head">
                    <div>
                        <h4>{{ $camera['role_label'] }}</h4>
                        <p>{{ $camera['camera_name'] }}</p>
                    </div>
                    <span class="badge badge-secondary" data-status-badge>{{ ucfirst(str_replace('_', ' ', $camera['last_connection_status'])) }}</span>
                </div>

                <div class="calibration-controls-panel">
                    <div class="form-grid">
                        <div class="field">
                            <label for="{{ $role }}_stream_select">Calibration Stream</label>
                            <select id="{{ $role }}_stream_select" data-device-select>
                                <option value="{{ $camera['stream_url'] }}">{{ $camera['role_label'] }} MJPEG Stream</option>
                            </select>
                        </div>
                    </div>

                    <div class="button-row camera-toolbar">
                        <button type="button" class="button button-secondary button-sm" data-tool="mask">Draw Polygon ROI</button>
                        <button type="button" class="button button-secondary button-sm" data-tool="line">Draw Trigger Line</button>
                        <button type="button" class="button button-secondary button-sm" data-clear>Clear</button>
                        <button type="button" class="button button-primary button-sm" data-save>Save Calibration</button>
                    </div>
                </div>

                <div class="camera-stage camera-stage-calibration">
                    <img
                        class="camera-video"
                        data-video
                        data-stream-url="{{ $camera['stream_url'] }}"
                        src="{{ $camera['stream_url'] }}"
                        alt="{{ $camera['role_label'] }} calibration stream"
                    >
                    <canvas class="camera-overlay" data-overlay></canvas>
                    <div class="camera-fallback" data-fallback-wrapper>
                        <div class="camera-fallback-copy">
                            <span class="camera-fallback-kicker">Camera</span>
                            <strong data-fallback>Not connected</strong>
                            <p data-fallback-detail>Allow camera access to begin calibration.</p>
                        </div>
                    </div>
                </div>

                <div class="camera-detail-grid calibration-detail-grid">
                    <div>
                        <span>Source</span>
                        <strong data-source-value>{{ $camera['source_type'] }} | {{ $camera['source_value'] }}</strong>
                    </div>
                    <div>
                        <span>Stream URL</span>
                        <strong data-browser-value>{{ $camera['stream_url'] }}</strong>
                    </div>
                    <div>
                        <span>Status</span>
                        <strong data-status-value>{{ ucfirst(str_replace('_', ' ', $camera['last_connection_status'])) }}</strong>
                    </div>
                    <div>
                        <span>Polygon ROI</span>
                        <strong data-mask-value>{{ $camera['calibration_mask'] ? 'Mask saved' : 'No mask yet' }}</strong>
                    </div>
                    <div>
                        <span>Trigger Line</span>
                        <strong data-line-value>{{ $camera['calibration_line'] ? 'Line saved' : 'No line yet' }}</strong>
                    </div>
                    <div>
                        <span>Message</span>
                        <strong data-message-value>{{ $camera['last_connection_message'] }}</strong>
                    </div>
                </div>
            </article>
        @endforeach
    </div>

    @php($calibrationPayload = [
        'cameras' => $cameras,
        'routes' => [
            'save' => route('calibration.update'),
            'state' => route('camera-browser.state'),
            'heartbeat' => route('calibration.heartbeat'),
        ],
    ])
    <script id="camera-calibration-data" type="application/json">{!! json_encode($calibrationPayload, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) !!}</script>
@endsection

@push('scripts')
    <script src="{{ asset('js/browser-camera-common.js') }}"></script>
    <script src="{{ asset('js/calibration-page.js') }}"></script>
@endpush
