{{-- UI Phase 2: Gate Monitor. Entrance and Exit side by side; the kiosks open full screen from here. --}}
@extends('layouts.app')

@section('title', 'Gate Monitor | PHILCST Vehicle Monitoring')

@section('content')
    <x-page-header title="Gate Monitor">
        <x-slot:meta>
            <span data-gate-detector>
                <x-badge :status="$detectorRunning ? 'online' : 'standby'" :label="$detectorRunning ? 'Detector running' : 'Detector standby'" />
            </span>
        </x-slot:meta>
    </x-page-header>

    <div class="gate-grid">
        @foreach ($gates as $location => $gate)
            @php($scan = $gate['latest_scan'])
            <section class="gate-card" data-gate="{{ $location }}">
                <header class="gate-card-head">
                    <div>
                        <h2>{{ $gate['short_label'] }}</h2>
                        <span class="text-muted">{{ $gate['label'] }}</span>
                    </div>
                    <div class="gate-card-actions">
                        <span data-gate-camera>
                            <x-badge :status="($gate['camera_status']['camera_running'] ?? false) ? 'online' : 'standby'" :label="($gate['camera_status']['camera_running'] ?? false) ? 'Live' : 'Standby'" />
                        </span>
                        <a href="{{ $gate['kiosk_url'] }}" target="_blank" rel="noopener" class="button button-secondary button-sm">Open {{ $gate['short_label'] }} Kiosk</a>
                    </div>
                </header>

                <div class="gate-feed">
                    <img src="{{ $gate['stream_url'] }}" alt="{{ $gate['short_label'] }} live camera" data-gate-feed data-stream="{{ $gate['stream_url'] }}">
                    <span class="gate-feed-fallback">Waiting for camera…</span>
                </div>

                <div class="gate-latest" data-gate-latest>
                    <span class="gate-section-label">Latest scan</span>
                    @if ($scan)
                        <div class="gate-latest-row">
                            <div>
                                <strong data-latest-title>{{ $scan['title'] }}</strong>
                                <span class="text-muted" data-latest-subtitle>{{ $scan['subtitle'] }}</span>
                            </div>
                            <span data-latest-badge><x-badge :tone="$scan['tone']" :label="$scan['result']" /></span>
                        </div>
                        <small class="text-muted" data-latest-time>{{ $scan['time'] }}@if ($scan['note']) · {{ $scan['note'] }}@endif</small>
                    @else
                        <p class="text-muted" data-latest-empty>No scans at this gate yet.</p>
                    @endif
                </div>

                <div class="gate-logs">
                    <span class="gate-section-label">Recent activity</span>
                    <ul data-gate-logs>
                        @forelse ($gate['logs'] as $log)
                            <li>
                                <strong>{{ $log['plate_number'] }}</strong>
                                <span class="text-muted">{{ $log['verification_label'] }}</span>
                                <time>{{ \App\Support\DisplayTime::time($log['event_time']) }}</time>
                            </li>
                        @empty
                            <li class="text-muted">No activity yet.</li>
                        @endforelse
                    </ul>
                </div>
            </section>
        @endforeach
    </div>

    <script id="gate-monitor-data" type="application/json">{!! json_encode([
        'stateUrl' => route('gates.state'),
    ], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) !!}</script>
@endsection

@push('scripts')
    <script src="{{ asset('js/gate-monitor.js') }}"></script>
@endpush
