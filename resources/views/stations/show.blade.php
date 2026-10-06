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
                    <span class="station-kicker">Gate kiosk · IN and OUT</span>
                    <h1>{{ $stationLabel }}</h1>
                </div>
                <div class="station-status-stack">
                    <span class="station-clock" data-station-clock>{{ \App\Support\DisplayTime::datetimeSeconds(now()) }}</span>
                    {{-- Small status line (details in Settings › System Status). --}}
                    <span class="station-status-line">
                        <span class="station-status-chip {{ ($cameraStatus['camera_running'] ?? false) ? 'is-online' : 'is-standby' }}" data-camera-status-chip>
                            {{ ($cameraStatus['camera_running'] ?? false) ? 'Camera' : 'Camera offline' }}
                        </span>
                        <span class="station-status-chip {{ ($detectorStatus['service_running'] ?? false) ? 'is-online' : 'is-standby' }}" data-detector-status-chip>
                            {{ ($detectorStatus['service_running'] ?? false) ? 'Detector' : 'Detector off' }}
                        </span>
                    </span>
                </div>
            </div>

            <div class="station-frame-stage">
                {{-- Live view work: the camera's main stream over WebRTC (go2rtc). --}}
                <x-live-video :gate="$location" :mjpeg="$streamUrl" page="kiosk" class="station-frame" data-station-frame :alt="$stationLabel.' live CCTV feed'" />
                {{-- UI Phase 4: small placeholder when there is no picture (one line + a link). --}}
                @include('partials.feed-offline', ['attributes' => 'data-frame-message'])
            </div>

            <div class="station-video-footer">
                <span>{{ $camera['camera_name'] }}</span>
            </div>
        </section>

        <aside class="station-log-pane">
            {{-- UI Phase 4: the latest vehicle at this gate, readable from a distance (plate, category, IN/OUT, time). --}}
            @php($latest = collect($logs)->firstWhere('gate', $location))
            @php($look = \App\Support\MovementRow::resultLook($latest))
            <section class="scan-result is-{{ $look }}" data-scan-result aria-live="assertive" aria-atomic="true">
                <strong class="scan-result-word" data-scan-word>{{ $latest ? $latest['direction_label'] : 'READY' }}</strong>
                <div class="scan-result-body">
                    <span class="scan-result-title" data-scan-title>{{ $latest ? $latest['plate_number'] : 'Waiting for the next vehicle' }}</span>
                    <span class="scan-result-detail" data-scan-detail>{{ $latest['category_label'] ?? '' }}</span>
                </div>
                <time class="scan-result-time" data-scan-time>{{ $latest ? \App\Support\DisplayTime::time($latest['event_time'] ?? null) : '' }}</time>
            </section>

            <div class="station-log-header">
                <h2>Recent activity</h2>
            </div>

            <div class="station-log-list" data-station-log-list>
                {{-- UI Phase 4: plate, category, IN/OUT and time; colored per UI Phase 3. --}}
                @forelse ($logs as $log)
                    <article @class(['station-log-item', 'station-log-compact', 'is-alert' => ($log['tone'] ?? '') === 'critical'])>
                        <span class="station-log-badge tone-{{ $log['tone'] ?? 'neutral' }}">{{ $log['direction_label'] }}</span>
                        <strong>{{ $log['plate_number'] }}</strong>
                        <span class="station-log-type">{{ $log['category_label'] ?? '' }}</span>
                        <time class="station-log-time">{{ \App\Support\DisplayTime::time($log['event_time'] ?? null) }}</time>
                    </article>
                @empty
                    <div class="station-log-empty" data-station-log-empty>No vehicles have passed yet</div>
                @endforelse
            </div>
        </aside>
    </main>

    {{-- Banner for anomalies and lost or disabled tags. --}}
    <div class="station-alert" data-station-alert hidden role="alert">
        <strong data-station-alert-title>Needs attention</strong>
        <span data-station-alert-message></span>
        <button type="button" class="station-alert-close" data-station-alert-close aria-label="Dismiss">&times;</button>
    </div>

    @php($stationPayload = [
        'location' => $location,
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
    <script src="{{ asset('js/ui.js') }}"></script>
    <script src="{{ asset('vendor/hls/hls.light.min.js') }}"></script>
    <script src="{{ asset('js/live-video.js') }}"></script>
    <script src="{{ asset('js/station-kiosk.js') }}"></script>
</body>
</html>
