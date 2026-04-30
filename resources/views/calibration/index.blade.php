@extends('layouts.app')

@section('title', 'Camera Calibration | PHILCST Parking Monitoring')
@section('page-title', 'Camera Calibration')
@section('page-description', 'Admin setup for YOLOv8 detection zones, ROI masks, trigger lines, and camera device assignments.')

@section('content')
    <section class="hero-panel hero-panel-compact">
        <div class="hero-panel-copy">
            <span class="panel-kicker">Admin Setup</span>
            <h3>YOLOv8 ROI and trigger-line calibration</h3>
            <div class="inline-status-list">
                <span class="chip chip-brand">Entrance + Exit</span>
                <span class="chip chip-soft">Saved locally</span>
            </div>
        </div>

        <div class="hero-panel-actions">
            <a href="{{ route('settings.index') }}" class="button button-secondary">System Settings</a>
            <a href="{{ route('stations.entrance') }}" class="button button-primary">Entrance Station</a>
            <a href="{{ route('stations.exit') }}" class="button button-primary">Exit Station</a>
        </div>
    </section>

    <section class="panel calibration-help">
        <div class="panel-header">
            <div>
                <h3>Calibration Workflow</h3>
                <p>Select a browser camera, draw the ROI mask first, draw the trigger line second, then save calibration. The Python detector uses these shapes to decide when a vehicle crossing becomes a system event.</p>
            </div>
        </div>
    </section>

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
                            <label for="{{ $role }}_device_select">Browser Camera</label>
                            <select id="{{ $role }}_device_select" data-device-select>
                                <option value="">Loading camera sources...</option>
                            </select>
                        </div>
                    </div>

                    <div class="button-row camera-toolbar">
                        <button type="button" class="button button-secondary button-sm" data-tool="mask">Draw ROI Mask</button>
                        <button type="button" class="button button-secondary button-sm" data-tool="line">Draw Trigger Line</button>
                        <button type="button" class="button button-secondary button-sm" data-clear>Clear</button>
                        <button type="button" class="button button-primary button-sm" data-save>Save Calibration</button>
                    </div>
                </div>

                <div class="camera-stage camera-stage-calibration">
                    <video class="camera-video is-hidden" data-video autoplay muted playsinline></video>
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
                        <span>Browser Device</span>
                        <strong data-browser-value>{{ $camera['browser_label'] ?: 'No saved browser device' }}</strong>
                    </div>
                    <div>
                        <span>Status</span>
                        <strong data-status-value>{{ ucfirst(str_replace('_', ' ', $camera['last_connection_status'])) }}</strong>
                    </div>
                    <div>
                        <span>ROI Mask</span>
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
        ],
    ])
    <script id="camera-calibration-data" type="application/json">{!! json_encode($calibrationPayload, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) !!}</script>
@endsection

@push('scripts')
    <script src="{{ asset('js/browser-camera-common.js') }}"></script>
    <script src="{{ asset('js/calibration-page.js') }}"></script>
@endpush
