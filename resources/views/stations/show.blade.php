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
                    <span class="station-clock" data-station-clock>{{ \App\Support\DisplayTime::datetimeSeconds(now()) }}</span>
                    <span class="station-status-chip {{ ($cameraStatus['camera_running'] ?? false) ? 'is-online' : 'is-standby' }}" data-camera-status-chip>
                        {{ ($cameraStatus['camera_running'] ?? false) ? 'Live' : 'Offline' }}
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
                {{-- Why there is no picture (detector off, camera login, unreachable). --}}
                <p class="frame-message" data-frame-message role="status" hidden></p>
            </div>

            <div class="station-video-footer">
                <span>{{ $camera['camera_name'] }}</span>
                <span data-camera-source>{{ $camera['source_display'] }}</span>
                <span data-camera-frames>{{ $cameraStatus['processed_frames'] ?? 0 }} frames</span>
                <span data-camera-detections>{{ $cameraStatus['active_detections'] ?? 0 }} active / {{ $cameraStatus['detections_seen'] ?? 0 }} detections</span>
                <span data-rfid-status>RFID Ready</span>
            </div>
        </section>

        <aside class="station-log-pane">
            {{-- UI Phase 4: big scan result the guard can read from a distance. --}}
            <section class="scan-result is-idle" data-scan-result aria-live="assertive" aria-atomic="true">
                <span class="scan-result-icon" data-scan-icon aria-hidden="true">•</span>
                <div class="scan-result-body">
                    <strong class="scan-result-word" data-scan-word>READY</strong>
                    <span class="scan-result-title" data-scan-title>Tap a tag on the reader</span>
                    <span class="scan-result-detail" data-scan-detail></span>
                </div>
                <time class="scan-result-time" data-scan-time></time>
            </section>

            <div class="station-log-header">
                <div>
                    <span class="station-kicker">Shared Station Logs</span>
                    <h2>Recent Activity</h2>
                </div>
                <span class="station-status-chip {{ ($detectorStatus['service_running'] ?? false) ? 'is-online' : 'is-standby' }}" data-detector-status-chip>
                    {{ ($detectorStatus['service_running'] ?? false) ? 'Detector Ready' : 'Detector Off' }}
                </span>
            </div>

            <div class="station-log-list" data-station-log-list>
                {{-- UI Phase 4: one short line per log (plate, type, time). --}}
                @forelse ($logs as $log)
                    <article class="station-log-item station-log-compact">
                        <span class="station-log-badge">{{ $log['event_type'] }}</span>
                        <strong>{{ $log['plate_number'] }}</strong>
                        <span class="station-log-type">{{ $log['verification_label'] }}</span>
                        <time class="station-log-time">{{ \App\Support\DisplayTime::time($log['event_time'] ?? null) }}</time>
                    </article>
                @empty
                    <div class="station-log-empty" data-station-log-empty>No station logs yet</div>
                @endforelse
            </div>
        </aside>
    </main>

    {{-- Phase 4: red banner for anomalies and lost/disabled tag alerts. --}}
    <div class="station-alert" data-station-alert hidden role="alert">
        <strong data-station-alert-title>Needs attention</strong>
        <span data-station-alert-message></span>
        <button type="button" class="station-alert-close" data-station-alert-close aria-label="Dismiss">&times;</button>
    </div>

    @php($stationPayload = [
        'location' => $location,
        'eventType' => $eventType,
        'logLabel' => 'station logs',
        'camera' => $camera,
        'cameraStatus' => $cameraStatus,
        'detectorStatus' => $detectorStatus,
        'streamUrl' => $streamUrl,
        'logs' => $logs,
        'routes' => [
            'state' => route('stations.state', $location),
            'recentLogs' => route('api.recent-station-logs', ['location' => $location, 'limit' => 14]),
            'rfidScan' => route('stations.rfid-scan', $location),
        ],
    ])
    <script id="station-kiosk-data" type="application/json">{!! json_encode($stationPayload, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) !!}</script>
    <script src="{{ asset('js/ui.js') }}"></script>
    <script src="{{ asset('js/station-kiosk.js') }}"></script>
</body>
</html>
