@extends('layouts.app')

@section('title', 'System Status | PHILCST Vehicle Monitoring')
@section('page-title', 'System Status')
@section('page-description', 'Admin view for camera service health.')

@section('content')
    <section class="hero-panel hero-panel-compact">
        <div class="hero-panel-copy">
            <span class="panel-kicker">Admin</span>
            <h3>Camera Status and Integration Health</h3>
            <div class="inline-status-list">
                <span class="chip chip-brand">Camera Status</span>
                <span class="chip chip-soft">Advanced Access</span>
            </div>
        </div>

        <div class="hero-panel-actions">
            <a href="{{ route('stations.entrance') }}" class="button button-secondary">Entrance Station</a>
            <a href="{{ route('stations.exit') }}" class="button button-secondary">Exit Station</a>
            <a href="{{ route('settings.index') }}" class="button button-primary">Settings</a>
        </div>
    </section>

    <div class="page-grid cards-4">
        <article class="stat-card {{ ($runtime['service_running'] ?? false) ? 'stat-card-success' : 'stat-card-warning' }}">
            <span class="stat-card-label">Camera Status Service</span>
            <strong>{{ ($runtime['service_running'] ?? false) ? 'Running' : 'Standby' }}</strong>
            <p>{{ $runtime['auto_start_message'] ?? $runtime['service_message'] }}</p>
        </article>

        <article class="stat-card">
            <span class="stat-card-label">Last Update</span>
            <strong>{{ !empty($runtime['updated_at']) ? \Carbon\Carbon::parse($runtime['updated_at'])->format('M d, Y h:i:s A') : 'No update yet' }}</strong>
            <p>Latest camera service heartbeat.</p>
        </article>

        <article class="stat-card">
            <span class="stat-card-label">Entrance Crossings</span>
            <strong>{{ $runtime['cameras']['entrance']['crossings_logged'] ?? 0 }}</strong>
            <p>Detected entrance crossings.</p>
        </article>

        <article class="stat-card">
            <span class="stat-card-label">Exit Crossings</span>
            <strong>{{ $runtime['cameras']['exit']['crossings_logged'] ?? 0 }}</strong>
            <p>Detected exit crossings.</p>
        </article>
    </div>

    <div class="camera-grid">
        @foreach (['entrance', 'exit'] as $role)
            @php($cameraStatus = $runtime['cameras'][$role] ?? null)
            <article class="camera-card">
                <div class="camera-card-head">
                    <div>
                        <h4>{{ ucfirst($role) }} Camera</h4>
                        <p>{{ $cameraStatus['camera_name'] ?? ucfirst($role).' Camera' }}</p>
                    </div>
                    <span class="badge {{ ($cameraStatus['camera_running'] ?? false) ? 'badge-matched' : 'badge-secondary' }}">
                        {{ ($cameraStatus['camera_running'] ?? false) ? 'Running' : 'Standby' }}
                    </span>
                </div>

                <div class="camera-detail-grid">
                    <div>
                        <span>Detection Ready</span>
                        <strong>{{ ($cameraStatus['detection_ready'] ?? false) ? 'Yes' : 'No' }}</strong>
                    </div>
                    <div>
                        <span>Calibration Ready</span>
                        <strong>{{ ($cameraStatus['calibration_ready'] ?? false) ? 'Yes' : 'No' }}</strong>
                    </div>
                    <div>
                        <span>Processed Frames</span>
                        <strong>{{ $cameraStatus['processed_frames'] ?? 0 }}</strong>
                    </div>
                    <div>
                        <span>Detections</span>
                        <strong>{{ $cameraStatus['detections_seen'] ?? 0 }}</strong>
                    </div>
                    <div>
                        <span>Retry Count</span>
                        <strong>{{ $cameraStatus['retry_count'] ?? 0 }}</strong>
                    </div>
                    <div>
                        <span>Last Capture</span>
                        <strong>{{ !empty($cameraStatus['last_capture_time']) ? \Carbon\Carbon::parse($cameraStatus['last_capture_time'])->format('M d, Y h:i:s A') : 'No capture yet' }}</strong>
                    </div>
                    <div class="span-full">
                        <span>Message</span>
                        <strong>{{ $cameraStatus['last_error'] ?: 'No additional message.' }}</strong>
                    </div>
                </div>
            </article>
        @endforeach
    </div>
@endsection
