@extends('layouts.app')

@section('title', 'Live Monitoring | PHILCST Parking Monitoring')
@section('page-title', 'Live Monitoring')
@section('page-description', 'Live camera view for guest monitoring and visual observation.')

@section('content')
    <section class="hero-panel hero-panel-compact">
        <div class="hero-panel-copy">
            <span class="panel-kicker">Guest Flow</span>
            <h3>Live entrance and exit camera observation</h3>
            <div class="inline-status-list">
                <span class="chip chip-brand">Camera Status {{ $cameraSummary['connected'] }}/{{ $cameraSummary['total'] }}</span>
                <span class="chip chip-soft">Visual monitoring support</span>
            </div>
        </div>

        <div class="hero-panel-actions">
            <a href="{{ route('guest-observations.index') }}" class="button button-primary">Guest Monitoring</a>
            <a href="{{ route('portals.show', 'entrance') }}" class="button button-secondary">Entrance Station</a>
            <a href="{{ route('portals.show', 'exit') }}" class="button button-secondary">Exit Station</a>
        </div>
    </section>

    <section class="panel">
        <div class="panel-header">
            <div>
                <div class="panel-title-row">
                    <h3>Live Camera Feed</h3>
                    @include('layouts.partials.help', [
                        'label' => 'Live feed help',
                        'text' => 'Use this page for live visual monitoring and guest observation support.',
                    ])
                </div>
            </div>
        </div>

        <div class="camera-grid">
            @foreach ($cameras as $role => $camera)
                <article class="camera-card" data-monitor-camera data-role="{{ $role }}">
                    <div class="camera-card-head">
                        <div>
                            <h4>{{ $camera['role_label'] }}</h4>
                            <p>{{ $camera['camera_name'] }}</p>
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
                            <span>Camera Source</span>
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
            @endforeach
        </div>
    </section>

    <section class="panel">
        <div class="panel-header">
            <div>
                <h3>Recent Guest Observations</h3>
            </div>
            <a href="{{ route('guest-observations.index') }}" class="button button-secondary button-sm">Open Guest Monitoring</a>
        </div>

        @if ($recentGuestObservations->isEmpty())
            <div class="empty-state">
                <h4>No guest observations yet</h4>
                <p>Create a guest record to start monitoring history.</p>
            </div>
        @else
            <div class="event-stream">
                @foreach ($recentGuestObservations as $observation)
                    <article class="stream-item">
                        <img src="{{ $observation->snapshot_url }}" alt="Guest vehicle snapshot" class="thumb thumb-sm">
                        <div>
                            <strong>{{ $observation->plate_text ?: 'No plate' }} • {{ strtoupper($observation->observation_source) }}</strong>
                            <p>{{ ucfirst($observation->location) }} • {{ trim(($observation->vehicle_color ?: '').' '.($observation->vehicle_type ?: 'N/A')) }}</p>
                            <small>{{ $observation->observed_at->format('M d, Y h:i A') }}</small>
                        </div>
                    </article>
                @endforeach
            </div>
        @endif
    </section>

    @php($monitoringPayload = [
        'cameras' => $cameras,
        'routes' => [
            'state' => route('camera-browser.state'),
        ],
    ])
    <script id="camera-monitoring-data" type="application/json">{!! json_encode($monitoringPayload, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) !!}</script>
@endsection

@push('scripts')
    <script src="{{ asset('js/browser-camera-common.js') }}"></script>
    <script src="{{ asset('js/monitoring-page.js') }}"></script>
@endpush
