{{-- UI Phase 2: Gate Monitor. Every gate side by side; the kiosks open full screen from here. --}}
@extends('layouts.app')

@section('title', 'Gate Monitor | PHILCST Vehicle Monitoring')

@section('content')
    <x-page-header title="Gate Monitor">
        <x-slot:meta>
            <span data-gate-detector>
                <x-badge :tone="$detectorRunning ? 'success' : 'warning'" :label="$detectorRunning ? 'Detector running' : 'Detector starting'" />
            </span>
            <x-live-indicator />
            {{-- Live view work: boxes are drawn over the video, and can be hidden. --}}
            <label class="overlay-toggle"><input type="checkbox" data-overlay-toggle checked> Show detection boxes</label>
        </x-slot:meta>
    </x-page-header>

    <div class="gate-grid">
        @foreach ($gates as $location => $gate)
            @php($latest = $gate['logs'][0] ?? null)
            @php($cameraLive = (bool) ($gate['camera_status']['camera_running'] ?? false))
            <section class="gate-card" data-gate="{{ $location }}">
                <header class="gate-card-head">
                    <h2>{{ $gate['short_label'] }}</h2>
                    <div class="gate-card-actions">
                        <span data-gate-camera>
                            <x-badge :tone="$cameraLive ? 'success' : 'critical'" :label="$cameraLive ? 'Live' : 'Offline'" />
                        </span>
                        <a href="{{ $gate['kiosk_url'] }}" target="_blank" rel="noopener" class="button button-secondary button-sm">Open {{ $gate['short_label'] }} Kiosk</a>
                    </div>
                </header>

                <div @class(['gate-feed', 'is-offline' => ! $cameraLive])>
                    {{-- Live view work: the camera's main stream over WebRTC (go2rtc). --}}
                    <x-live-video :gate="$location" :mjpeg="$gate['stream_url']" page="gate-monitor" class="gate-feed-video" :alt="$gate['short_label'].' live camera'" />
                    @include('partials.feed-offline', ['attributes' => 'data-gate-feed-offline'])
                </div>

                {{-- UI Phase 4: the latest vehicle at this gate, big (plate, category, IN/OUT, time). --}}
                <div class="gate-latest result-{{ \App\Support\MovementRow::resultLook($latest) }}" data-gate-latest>
                    @if ($latest)
                        <span class="gate-latest-direction">{{ $latest['direction_label'] }}</span>
                        <div class="gate-latest-text">
                            <strong>{{ $latest['plate_number'] }}</strong>
                            <span>{{ $latest['category_label'] ?? '' }}</span>
                        </div>
                        <time>{{ \App\Support\DisplayTime::time($latest['event_time'] ?? null) }}</time>
                    @else
                        <p class="gate-latest-empty">No vehicles have passed yet</p>
                    @endif
                </div>

                <div class="gate-logs">
                    <span class="gate-section-label">Recent activity</span>
                    <ul data-gate-logs>
                        @forelse ($gate['logs'] as $log)
                            <li>
                                <span class="badge badge-tone-{{ $log['tone'] }}">{{ $log['direction_label'] }}</span>
                                <strong>{{ $log['plate_number'] }}</strong>
                                <span class="text-muted">{{ $log['category_label'] ?? '' }}</span>
                                <time>{{ \App\Support\DisplayTime::time($log['event_time']) }}</time>
                            </li>
                        @empty
                            <li class="gate-logs-empty">No activity yet.</li>
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
    <script src="{{ asset('js/gate-monitor.js') }}?v={{ filemtime(public_path('js/gate-monitor.js')) }}"></script>
@endpush
