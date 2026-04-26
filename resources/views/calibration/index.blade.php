@extends('layouts.app')

@section('title', 'Calibration | PHILCST Vehicle Access Monitoring')
@section('page-title', 'Calibration')
@section('page-description', 'Set the live camera view, mask, and line for camera support.')

@section('content')
    <section class="hero-panel hero-panel-compact">
        <div class="hero-panel-copy">
            <span class="panel-kicker">Camera Setup</span>
            <h3>Entrance and exit calibration</h3>
            <div class="inline-status-list">
                <span class="chip chip-brand">Camera Support</span>
                <span class="chip chip-soft">Used for observation and optional trigger setup</span>
            </div>
        </div>

        <div class="hero-panel-actions">
            <a href="{{ route('monitoring.index') }}" class="button button-secondary">Camera Monitoring</a>
            <a href="{{ route('settings.index') }}" class="button button-primary">Settings</a>
        </div>
    </section>

    <section class="panel">
        <div class="panel-header">
            <div>
                <div class="panel-title-row">
                    <h3>Camera Calibration</h3>
                    @include('layouts.partials.help', [
                        'label' => 'Explain calibration',
                        'text' => 'Choose the camera source, draw the masked area, draw the line, then save. This supports camera setup without changing RFID-first operation.',
                    ])
                </div>
            </div>
        </div>

        <div class="camera-grid">
            @foreach ($cameras as $role => $camera)
                <article class="camera-card camera-card-calibration" data-calibration-camera data-role="{{ $role }}">
                    <div class="camera-card-head">
                        <div class="camera-card-heading">
                            <h4>{{ $camera['role_label'] }}</h4>
                            <p>{{ $camera['camera_name'] }}</p>
                            <small>{{ $camera['source_type'] }} | {{ $camera['source_value'] }}</small>
                        </div>
                        <span class="badge badge-secondary" data-status-badge>Not connected</span>
                    </div>

                    <div class="camera-stage camera-stage-calibration">
                        <video class="camera-video is-hidden" data-video autoplay muted playsinline></video>
                        <canvas class="camera-overlay" data-overlay></canvas>
                        <div class="camera-fallback">
                            <div class="camera-fallback-copy">
                                <span class="camera-fallback-kicker">Camera unavailable</span>
                                <strong data-fallback>Not connected</strong>
                                <p data-fallback-detail>Allow browser access or reconnect the selected device.</p>
                            </div>
                        </div>
                    </div>

                    <div class="calibration-controls-panel">
                        <div class="field">
                            <label>Camera Source</label>
                            <select data-device-select></select>
                        </div>

                        <div class="camera-toolbar">
                            <button type="button" class="button button-secondary button-tool is-active" data-tool="mask">Mask</button>
                            <button type="button" class="button button-secondary button-tool" data-tool="line">Line</button>
                            <button type="button" class="button button-subtle-danger" data-clear>Clear</button>
                            <button type="button" class="button button-primary" data-save>Save</button>
                        </div>
                    </div>

                    <div class="camera-detail-grid calibration-detail-grid">
                        <div>
                            <span>Status</span>
                            <strong data-status-value>{{ $camera['last_connection_status'] === 'connected' ? 'Connected' : 'Not connected' }}</strong>
                        </div>
                        <div>
                            <span>Source</span>
                            <strong data-source-value>{{ $camera['source_type'] }} | {{ $camera['source_value'] }}</strong>
                        </div>
                        <div>
                            <span>Browser Device</span>
                            <strong data-browser-value>{{ $camera['browser_label'] ?: 'No saved browser device' }}</strong>
                        </div>
                        <div>
                            <span>Message</span>
                            <strong data-message-value>{{ $camera['last_connection_message'] }}</strong>
                        </div>
                        <div>
                            <span>Mask</span>
                            <strong data-mask-value>{{ $camera['calibration_mask'] ? 'Saved' : 'Not set' }}</strong>
                        </div>
                        <div>
                            <span>Line</span>
                            <strong data-line-value>{{ $camera['calibration_line'] ? 'Saved' : 'Not set' }}</strong>
                        </div>
                    </div>
                </article>
            @endforeach
        </div>
    </section>

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
