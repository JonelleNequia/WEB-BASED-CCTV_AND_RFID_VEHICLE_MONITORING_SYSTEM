<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $stationLabel }} | PHILCST Vehicle Monitoring</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head>
<body class="station-kiosk-body station-kiosk-{{ $location }}">
    <input
        type="text"
        class="station-rfid-input"
        data-rfid-input
        autocomplete="off"
        inputmode="none"
        aria-label="{{ $stationLabel }} RFID scanner input"
    >

    <main class="station-kiosk-shell">
        <section class="station-video-pane">
            <div class="station-video-topbar">
                <div>
                    <span class="station-kicker">Camera {{ $location === 'entrance' ? '1' : '2' }}</span>
                    <h1>{{ $stationLabel }}</h1>
                </div>
                <div class="station-status-stack">
                    <span class="station-clock" data-station-clock>{{ now()->format('M d, Y h:i:s A') }}</span>
                    <span class="station-status-chip {{ ($cameraStatus['camera_running'] ?? false) ? 'is-online' : 'is-standby' }}" data-camera-status-chip>
                        {{ ($cameraStatus['camera_running'] ?? false) ? 'Live' : 'Standby' }}
                    </span>
                </div>
            </div>

            <div class="station-frame-stage">
                <img
                    src="{{ $streamUrl }}"
                    alt="{{ $stationLabel }} live CCTV feed"
                    data-station-frame
                    data-frame-stream="{{ $streamUrl }}"
                >
                <video
                    data-browser-frame
                    class="is-hidden"
                    autoplay
                    muted
                    playsinline
                ></video>
                <div class="station-frame-fallback" data-frame-fallback>
                    Waiting for {{ strtolower($stationLabel) }} live stream
                </div>
            </div>

            <div class="station-video-footer">
                <span>{{ $camera['camera_name'] }}</span>
                <span data-camera-source>{{ strtoupper($camera['source_type']) }} | {{ $camera['source_value'] }}</span>
                <span data-camera-frames>{{ $cameraStatus['processed_frames'] ?? 0 }} frames</span>
                <span data-camera-detections>{{ $cameraStatus['active_detections'] ?? 0 }} active / {{ $cameraStatus['detections_seen'] ?? 0 }} detections</span>
                <span data-rfid-status>RFID Ready</span>
            </div>
        </section>

        <aside class="station-log-pane">
            <div class="station-log-header">
                <div>
                    <span class="station-kicker">{{ $eventType }} Logs</span>
                    <h2>Recent Activity</h2>
                </div>
                <span class="station-status-chip {{ ($detectorStatus['service_running'] ?? false) ? 'is-online' : 'is-standby' }}" data-detector-status-chip>
                    {{ ($detectorStatus['service_running'] ?? false) ? 'Detector Ready' : 'Detector Standby' }}
                </span>
            </div>

            <div class="station-log-list" data-station-log-list>
                @forelse ($logs as $log)
                    <article class="station-log-item">
                        <div class="station-log-main">
                            <strong>{{ $log['event_type'] }} - {{ $log['plate_number'] }}</strong>
                            <span>{{ $log['verification_label'] }} | {{ $log['resulting_state'] }}</span>
                            <small>{{ $log['display_time'] }}</small>
                        </div>
                        <span class="station-log-badge">{{ $log['event_type'] }}</span>
                    </article>
                @empty
                    <div class="station-log-empty" data-station-log-empty>No {{ $eventType }} logs yet</div>
                @endforelse
            </div>
        </aside>
    </main>

    @php($stationPayload = [
        'location' => $location,
        'eventType' => $eventType,
        'camera' => $camera,
        'cameraStatus' => $cameraStatus,
        'detectorStatus' => $detectorStatus,
        'streamUrl' => $streamUrl,
        'logs' => $logs,
        'routes' => [
            'state' => route('stations.state', $location),
            'rfidScan' => route('stations.rfid-scan', $location),
        ],
    ])
    <script id="station-kiosk-data" type="application/json">{!! json_encode($stationPayload, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) !!}</script>
    <script src="{{ asset('js/browser-camera-common.js') }}"></script>
    <script src="{{ asset('js/station-kiosk.js') }}"></script>
</body>
</html>
