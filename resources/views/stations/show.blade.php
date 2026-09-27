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
                    {{ ($detectorStatus['service_running'] ?? false) ? 'Detector Ready' : 'Detector Standby' }}
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

    {{-- Phase 4: red banner for anomalies and lost/disabled pass alerts. --}}
    <div class="station-alert" data-station-alert hidden role="alert">
        <strong data-station-alert-title>Needs attention</strong>
        <span data-station-alert-message></span>
        <button type="button" class="station-alert-close" data-station-alert-close aria-label="Dismiss">&times;</button>
    </div>

    @if ($location === 'entrance')
        {{-- Phase 4: Issue Guest Pass pop-up (opens when an available pass is tapped). --}}
        <div class="station-modal" data-issue-modal hidden>
            <form class="station-modal-card" data-issue-form novalidate>
                <input type="hidden" name="guest_observation_id" data-issue-field="observation_id">
                <div class="station-modal-head">
                    <div>
                        <span class="station-kicker">Issue Guest Pass</span>
                        <h2 data-issue-pass-label>Guest Pass</h2>
                    </div>
                    <button type="button" class="station-modal-close" data-issue-cancel aria-label="Cancel">&times;</button>
                </div>

                <div class="station-modal-body">
                    <img class="station-modal-snapshot" data-issue-snapshot alt="Entrance snapshot">

                    <div class="station-modal-fields">
                        <label>Plate Number <small>(from camera, editable)</small>
                            <input type="text" name="plate" data-issue-field="plate" autocomplete="off">
                        </label>
                        <label>Driver Name
                            <input type="text" name="driver_name" autocomplete="off">
                        </label>
                        <label>Vehicle Type
                            <input type="text" name="vehicle_type" data-issue-field="vehicle_type" placeholder="Car, Van, Motorcycle" autocomplete="off">
                        </label>
                        <label>Color
                            <input type="text" name="color" data-issue-field="color" autocomplete="off">
                        </label>
                        <label>Purpose
                            <input type="text" name="purpose" placeholder="Delivery, visit" autocomplete="off">
                        </label>
                        <label>Destination
                            <input type="text" name="destination" placeholder="Registrar, Admin Office" autocomplete="off">
                        </label>
                        <label>ID Presented <span data-issue-id-required>*</span>
                            <input type="text" name="id_presented" placeholder="Driver's License" autocomplete="off">
                        </label>
                        <label>Valid For
                            <select name="valid_minutes" data-issue-valid>
                                <option value="60">1 hour</option>
                                <option value="120">2 hours</option>
                                <option value="240">4 hours</option>
                                <option value="480">8 hours</option>
                                <option value="720">12 hours</option>
                            </select>
                        </label>
                    </div>
                </div>

                <p class="station-modal-error" data-issue-error hidden></p>

                <div class="station-modal-actions">
                    <button type="button" class="station-button station-button-secondary" data-issue-cancel>Cancel</button>
                    <button type="submit" class="station-button">Issue Pass &amp; Record Entry</button>
                </div>
            </form>
        </div>
    @else
        {{-- Phase 4: Exit reminder when a guest pass is returned. --}}
        <div class="station-modal" data-card-return-modal hidden>
            <div class="station-modal-card station-modal-card-sm">
                <div class="station-modal-head">
                    <div>
                        <span class="station-kicker">Guest Exit</span>
                        <h2 data-card-return-label>Collect the guest pass</h2>
                    </div>
                </div>
                <div class="station-modal-body station-modal-body-stack">
                    <p>Collect the card and return the guest's ID.</p>
                    <div class="station-log-detail-grid">
                        <div><span>Plate</span><strong data-card-return-plate>N/A</strong></div>
                        <div><span>Driver</span><strong data-card-return-driver>N/A</strong></div>
                        <div><span>Return ID</span><strong data-card-return-id>None</strong></div>
                    </div>
                </div>
                <div class="station-modal-actions">
                    <button type="button" class="station-button" data-card-returned>Card returned</button>
                </div>
            </div>
        </div>
    @endif

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
