@extends('layouts.app')

@section('title', 'Live Monitor | PHILCST Parking Monitoring')
@section('page-title', 'Live Monitor')
@section('page-description', 'Guard command center for AI-assisted CCTV overlays, RFID status, and real-time vehicle activity.')

@section('content')
    @php($isAdmin = auth()->user()?->isAdmin() === true)

    <section class="hero-panel hero-panel-compact">
        <div class="hero-panel-copy">
            <span class="panel-kicker">Station Operations</span>
            <h3>AI-assisted live vehicle monitoring</h3>
            <div class="inline-status-list">
                <span class="chip chip-brand">Camera Status {{ $cameraSummary['connected'] }}/{{ $cameraSummary['total'] }}</span>
                <span class="chip chip-soft" data-detector-running>{{ ($detectorStatus['service_running'] ?? false) ? 'Detector Ready' : 'Detector Standby' }}</span>
            </div>
        </div>

        <div class="hero-panel-actions">
            <a href="{{ route('rfid-scans.index') }}" class="button button-primary">RFID Desk</a>
            <a href="{{ route('vehicle-events.index') }}" class="button button-secondary">Event Logs</a>
            @if ($isAdmin)
                <a href="{{ route('calibration.index') }}" class="button button-secondary">Camera Calibration</a>
            @endif
        </div>
    </section>

    <section class="monitor-command-grid">
        <div class="monitor-main-stack">
            <section class="panel monitor-feed-panel">
                <div class="panel-header">
                    <div>
                        <div class="panel-title-row">
                            <h3>Intelligent CCTV Feed</h3>
                            @include('layouts.partials.help', [
                                'label' => 'Explain intelligent feed',
                                'text' => 'The Python YOLOv8 service draws vehicle boxes and verification labels before the frame is shown here.',
                            ])
                        </div>
                    </div>
                    <span class="badge {{ ($detectorStatus['service_running'] ?? false) ? 'badge-matched' : 'badge-secondary' }}" data-detector-status-badge>
                        {{ ($detectorStatus['service_running'] ?? false) ? 'Running' : 'Standby' }}
                    </span>
                </div>

                <div class="monitor-feed-grid">
                    @foreach ($cameras as $role => $camera)
                        <article class="monitor-feed-card" data-monitor-frame-card data-role="{{ $role }}">
                            <div class="monitor-feed-head">
                                <div>
                                    <strong>{{ $camera['role_label'] }}</strong>
                                    <span>{{ $camera['camera_name'] }}</span>
                                </div>
                                <span class="badge badge-secondary" data-detector-{{ $role }}-status>
                                    {{ ($detectorStatus['cameras'][$role]['camera_running'] ?? false) ? 'Ready' : 'Standby' }}
                                </span>
                            </div>

                            <div class="monitor-frame-stage">
                                <img
                                    src="{{ asset('camera/'.$role.'_annotated_frame.jpg') }}?v={{ now()->timestamp }}"
                                    alt="{{ $camera['role_label'] }} AI overlay feed"
                                    data-live-frame
                                    data-frame-base="{{ asset('camera/'.$role.'_annotated_frame.jpg') }}"
                                >
                                <div class="monitor-frame-fallback" data-frame-fallback>
                                    Waiting for detector frame
                                </div>
                            </div>

                            <div class="camera-detail-grid">
                                <div>
                                    <span>Camera Source</span>
                                    <strong>{{ $camera['source_type'] }} | {{ $camera['source_value'] }}</strong>
                                </div>
                                <div>
                                    <span>Calibration</span>
                                    <strong data-detector-{{ $role }}-ready>{{ ($detectorStatus['cameras'][$role]['calibration_ready'] ?? false) ? 'Ready' : 'Needs setup' }}</strong>
                                </div>
                                <div>
                                    <span>Frames</span>
                                    <strong data-detector-{{ $role }}-frames>{{ $detectorStatus['cameras'][$role]['processed_frames'] ?? 0 }}</strong>
                                </div>
                                <div>
                                    <span>Detections</span>
                                    <strong data-detector-{{ $role }}-detections>{{ $detectorStatus['cameras'][$role]['detections_seen'] ?? 0 }}</strong>
                                </div>
                                <div>
                                    <span>Crossings</span>
                                    <strong data-detector-{{ $role }}-crossings>{{ $detectorStatus['cameras'][$role]['crossings_logged'] ?? 0 }}</strong>
                                </div>
                                <div>
                                    <span>Browser Status</span>
                                    <strong>{{ $camera['last_connection_status'] === 'connected' ? 'Connected' : 'Not connected' }}</strong>
                                </div>
                            </div>
                        </article>
                    @endforeach
                </div>
            </section>

            <section class="panel">
                <div class="panel-header">
                    <div>
                        <h3>Detector Status</h3>
                        <p data-detector-message>{{ $detectorStatus['auto_start_message'] ?? $detectorStatus['service_message'] ?? 'Waiting for detector status.' }}</p>
                    </div>
                    <span class="table-subtext" data-detector-updated>
                        {{ ! empty($detectorStatus['updated_at']) ? \Carbon\Carbon::parse($detectorStatus['updated_at'])->format('M d, Y h:i:s A') : 'No update yet' }}
                    </span>
                </div>
            </section>
        </div>

        <aside class="panel monitor-activity-panel">
            <div class="panel-header">
                <div>
                    <h3>Recent Activities</h3>
                    <p>ENTRY, EXIT, and GUEST records update automatically.</p>
                </div>
            </div>

            <div class="event-stream monitor-activity-stream" data-activity-stream>
                @forelse ($recentActivities as $activity)
                    <article class="stream-item">
                        <div>
                            <strong>{{ $activity['title'] }}</strong>
                            <p>{{ $activity['subtitle'] }}</p>
                            <small>{{ $activity['display_time'] }}</small>
                        </div>
                        <span class="badge badge-{{ $activity['badge'] }}">{{ $activity['kind'] }}</span>
                    </article>
                @empty
                    <div class="empty-state" data-activity-empty>
                        <h4>No activity yet</h4>
                        <p>Vehicle movements will appear after RFID, CCTV, or guest records are logged.</p>
                    </div>
                @endforelse
            </div>
        </aside>
    </section>

    @php($monitoringPayload = [
        'cameras' => $cameras,
        'detectorStatus' => $detectorStatus,
        'activities' => $recentActivities,
        'routes' => [
            'liveState' => route('monitoring.live-state'),
        ],
    ])
    <script id="camera-monitoring-data" type="application/json">{!! json_encode($monitoringPayload, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) !!}</script>
@endsection

@push('scripts')
    <script src="{{ asset('js/monitoring-page.js') }}"></script>
@endpush
