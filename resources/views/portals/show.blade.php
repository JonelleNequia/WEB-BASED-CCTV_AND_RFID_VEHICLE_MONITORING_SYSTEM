@extends('layouts.portal')

@section('title', $portalLabel.' | PHILCST Vehicle Access Monitoring')
@section('page-title', $portalLabel)
@section('portal-kicker', 'PHILCST '.ucfirst($location).' Station')
@section('page-description', ucfirst($location).' RFID station with the latest access result and optional camera support.')

@section('content')
    @php($readerName = $location === 'entrance' ? $settings['entrance_rfid_reader_name'] : $settings['exit_rfid_reader_name'])
    @php($latestLinkedEvent = $latestScan ? $latestScan->correlatedVehicleEvent : $latestEvent)
    @php($latestResultLabel = $latestScan
        ? ($latestScan->verification_status === 'verified'
            ? $latestScan->resolvedEventTypeLabel.' recorded'
            : ($latestScan->verification_status === 'non_recurring_category'
                ? 'Use guest monitoring'
                : 'Check vehicle'))
        : 'Waiting for scan')
    @php($latestResultBadge = $latestScan
        ? ($latestScan->verification_status === 'verified' ? 'badge-matched' : 'badge-unmatched')
        : 'badge-secondary')

    <section class="panel portal-operations-panel">
        <div class="panel-header">
            <div>
                <div class="panel-title-row">
                    <h3>{{ ucfirst($location) }} Operation</h3>
                    @include('layouts.partials.help', [
                        'label' => 'Explain station operation',
                        'text' => 'For registered recurring vehicles, scan results are state-based: outside becomes ENTRY and inside becomes EXIT, even on the same station.',
                    ])
                </div>
            </div>
            <div class="inline-status-list">
                <span class="chip chip-brand">RFID Active</span>
                <span class="chip chip-soft">Camera Optional</span>
            </div>
        </div>

        <div class="portal-station-grid">
            <section class="panel portal-action-panel">
                <div class="panel-header">
                    <div>
                        <h3>Record RFID Scan</h3>
                        <p>{{ $readerName }}</p>
                    </div>
                </div>

                <form method="POST" action="{{ route('rfid-scans.store') }}" class="stack-form">
                    @csrf
                    <input type="hidden" name="scan_location" value="{{ $location }}">

                    <div class="form-grid portal-form-grid">
                        <div class="field span-full">
                            <label for="vehicle_rfid_tag_id">Registered Tag</label>
                            <select id="vehicle_rfid_tag_id" name="vehicle_rfid_tag_id">
                                <option value="">Select a registered tag</option>
                                @foreach ($registeredTags as $tag)
                                    <option value="{{ $tag->id }}">{{ $tag->tag_uid }} | {{ $tag->vehicle?->plate_number ?? 'No vehicle linked' }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="field span-full">
                            <label for="tag_uid">Or Enter Tag UID</label>
                            <input id="tag_uid" type="text" name="tag_uid" placeholder="UNKNOWN-TAG-0001">
                        </div>

                        <div class="field">
                            <label for="reader_name">Reader Name</label>
                            <input id="reader_name" type="text" name="reader_name" value="{{ $readerName }}">
                        </div>

                        <div class="field">
                            <label for="scan_time">Scan Time</label>
                            <input id="scan_time" type="datetime-local" name="scan_time" value="{{ now()->format('Y-m-d\TH:i') }}">
                        </div>

                        <div class="field span-full">
                            <label for="notes">Remarks</label>
                            <textarea id="notes" name="notes" rows="3" placeholder="Optional remarks"></textarea>
                        </div>
                    </div>

                    <div class="button-row">
                        <button type="submit" class="button button-primary button-lg">Record RFID Scan</button>
                    </div>
                </form>
            </section>

            <div class="portal-summary-stack">
                <section class="panel">
                    <div class="panel-header">
                        <div>
                            <h3>Current Time</h3>
                        </div>
                    </div>
                    <div class="station-clock">
                        <strong data-live-clock>{{ now()->format('M d, Y h:i:s A') }}</strong>
                        <span>{{ ucfirst($location) }} station</span>
                    </div>
                </section>

                <section class="panel">
                    <div class="panel-header">
                        <div>
                            <h3>Latest Result</h3>
                        </div>
                        <span class="badge {{ $latestResultBadge }}">
                            {{ $latestResultLabel }}
                        </span>
                    </div>

                    <div class="detail-list">
                        <div><span>Latest Tag</span><strong>{{ $latestScan?->tag_uid ?? 'No scan yet' }}</strong></div>
                        <div><span>Vehicle</span><strong>{{ $latestScan?->vehicle?->plate_number ?? 'Unknown vehicle' }}</strong></div>
                        <div><span>Category</span><strong>{{ $latestScan?->vehicle?->category ? ucfirst(str_replace('_', ' ', $latestScan->vehicle->category)) : 'N/A' }}</strong></div>
                        <div><span>Event Type</span><strong>{{ $latestScan?->resolvedEventTypeLabel ?? 'N/A' }}</strong></div>
                        <div><span>Current State</span><strong>{{ $latestScan?->resultingStateLabel ?? 'N/A' }}</strong></div>
                        <div><span>RFID Result</span><strong>{{ $latestScan?->verificationLabel ?? 'Waiting for scan' }}</strong></div>
                        <div><span>Event Log</span><strong>{{ $latestLinkedEvent ? '#'.$latestLinkedEvent->id : 'No linked log yet' }}</strong></div>
                    </div>
                </section>

                <section class="panel">
                    <div class="panel-header">
                        <div>
                            <h3>Vehicle Information</h3>
                        </div>
                    </div>

                    <div class="detail-list">
                        <div><span>Plate</span><strong>{{ $latestScan?->vehicle?->plate_number ?? 'Unknown' }}</strong></div>
                        <div><span>Owner</span><strong>{{ $latestScan?->vehicle?->vehicle_owner_name ?? 'N/A' }}</strong></div>
                        <div><span>Vehicle</span><strong>{{ $latestScan?->vehicle?->vehicle_type ?? 'No registered data' }}</strong></div>
                        <div><span>Reader</span><strong>{{ $latestScan?->reader_name ?? $readerName }}</strong></div>
                    </div>
                </section>
            </div>
        </div>
    </section>

    <div class="portal-stage-grid">
        <section class="panel">
            <div class="panel-header">
                <div>
                    <div class="panel-title-row">
                        <h3>Recent {{ ucfirst($location) }} Activity</h3>
                        @include('layouts.partials.help', [
                            'label' => 'Explain recent station activity',
                            'text' => 'This combines recent scans and the linked vehicle movement so the operator can confirm the latest actions quickly.',
                        ])
                    </div>
                </div>
                <a href="{{ route('vehicle-events.index') }}" class="button button-secondary button-sm">Event Logs</a>
            </div>

            @if ($recentRfidScans->isEmpty())
                <div class="empty-state">
                    <h4>No {{ $location }} activity yet</h4>
                    <p>Use the scan form above to start this station.</p>
                </div>
            @else
                <div class="event-stream">
                    @foreach ($recentRfidScans as $scan)
                        <article class="stream-item">
                            <div>
                                <strong>{{ $scan->tag_uid }}</strong>
                                <p>
                                    {{ $scan->vehicle?->plate_number ?? 'Unknown vehicle' }}
                                    • {{ $scan->resolvedEventTypeLabel }}
                                    • {{ $scan->resultingStateLabel }}
                                </p>
                                <small>{{ $scan->scan_time->format('M d, Y h:i A') }}</small>
                            </div>
                            <span class="badge badge-{{ $scan->verificationBadgeClass }}">{{ $scan->verificationLabel }}</span>
                        </article>
                    @endforeach
                </div>
            @endif
        </section>

        <section class="panel portal-feed-panel">
            <div class="panel-header">
                <div>
                    <div class="panel-title-row">
                        <h3>Optional Camera View</h3>
                        @include('layouts.partials.help', [
                            'label' => 'Explain optional camera view',
                            'text' => 'This live feed supports observation and visual confirmation. The station can still operate when camera preview is unavailable.',
                        ])
                    </div>
                </div>
                <a href="{{ route('monitoring.index') }}" class="button button-secondary button-sm">Camera Monitoring</a>
            </div>

            <article class="camera-card" data-portal-camera>
                <div class="camera-card-head">
                    <div>
                        <h4>{{ $camera['camera_name'] }}</h4>
                        <p>{{ strtoupper($camera['source_type']) }} | {{ $camera['source_value'] }}</p>
                    </div>
                    <div class="camera-status-chip">
                        <span class="camera-status-dot {{ $camera['last_connection_status'] === 'connected' ? 'is-connected' : 'is-error' }}" data-status-dot></span>
                        <strong data-status-value>{{ $camera['last_connection_status'] === 'connected' ? 'Connected' : 'Not connected' }}</strong>
                    </div>
                </div>

                <div class="camera-stage">
                    <video class="camera-video is-hidden" data-video autoplay muted playsinline></video>
                    <div class="camera-fallback" data-fallback>Not connected</div>
                </div>

                <div class="camera-detail-grid">
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
                        <strong data-status-value>{{ $camera['last_connection_status'] === 'connected' ? 'Connected' : 'Not connected' }}</strong>
                    </div>
                    <div>
                        <span>Last Seen</span>
                        <strong data-last-seen-value>{{ $camera['last_connected_at_display'] }}</strong>
                    </div>
                    <div class="span-full">
                        <span>Message</span>
                        <strong data-message-value>{{ $camera['last_connection_message'] }}</strong>
                    </div>
                </div>
            </article>
        </section>
    </div>

    @php($portalPayload = [
        'camera' => $camera,
        'routes' => [
            'state' => route('camera-browser.state'),
        ],
    ])
    <script id="portal-camera-data" type="application/json">{!! json_encode($portalPayload, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) !!}</script>
@endsection

@push('scripts')
    <script src="{{ asset('js/browser-camera-common.js') }}"></script>
    <script src="{{ asset('js/portal-page.js') }}"></script>
@endpush
