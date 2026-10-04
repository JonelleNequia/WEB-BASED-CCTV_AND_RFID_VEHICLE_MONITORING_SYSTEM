{{-- Live-latency work: measured pipeline (refreshed every few seconds).
     UI Phase 3: one OK / Delayed / Offline line per gate; the measurements are
     in "Advanced diagnostics" (closed by default, stays open across refreshes). --}}
<section class="panel" data-pipeline-metrics data-url="{{ route('settings.status.metrics') }}">
    <div class="panel-header panel-header-modern">
        <h2 class="panel-title">Live video</h2>
    </div>

    <ul class="gate-state-list">
        @foreach ($report['gates'] as $gate)
            <li>
                <x-badge :tone="$gate['tone']" :label="$gate['state_label']" />
                <strong>{{ $gate['label'] }}</strong>
                <span class="text-muted">{{ $gate['reason'] }}</span>
            </li>
        @endforeach
    </ul>

    <details class="advanced-section" data-advanced-diagnostics>
        <summary>Advanced diagnostics</summary>
        <p class="text-muted">Measured by the detector (average and 95th percentile of recent frames).
            CPU {{ $report['cpu']['process'] ?? '—' }}% of one core ({{ $report['cpu']['cores'] ?? '—' }} cores).</p>

        <ul class="devices-warnings">
            @foreach ($report['findings'] as $finding)
                <li><x-badge tone="neutral" label="Note" /><span>{{ $finding }}</span></li>
            @endforeach
        </ul>

    @if ($report['cameras'] === [])
        <x-empty-state title="No measurements yet" text="They appear a few seconds after the detector starts." />
    @else
        <div class="table-responsive">
            <table class="devices-table">
                <thead>
                    <tr><th></th>@foreach ($report['cameras'] as $camera)<th>{{ $camera['label'] }}</th>@endforeach</tr>
                </thead>
                <tbody>
                    <tr><td>Live stream</td>@foreach ($report['cameras'] as $camera)<td>{{ $camera['resolution'] }} · {{ $camera['decoder'] }}</td>@endforeach</tr>
                    <tr><td>Camera frames / s</td>@foreach ($report['cameras'] as $camera)<td>{{ $camera['capture_fps'] ?? '—' }}</td>@endforeach</tr>
                    <tr><td>Live view frames / s (made · sent)</td>@foreach ($report['cameras'] as $camera)<td>{{ $camera['published_fps'] ?? '—' }} · {{ $camera['sent_fps'] ?? '—' }}</td>@endforeach</tr>
                    <tr><td>Detections / s</td>@foreach ($report['cameras'] as $camera)<td>{{ $camera['detection_fps'] ?? '—' }}</td>@endforeach</tr>
                    @php
                        $steps = collect($report['cameras'])->flatMap(fn ($camera) => array_keys($camera['steps']))->unique();
                    @endphp
                    @foreach ($steps as $step)
                        <tr>
                            <td>{{ $step }} (ms)</td>
                            @foreach ($report['cameras'] as $camera)
                                <td>@if (isset($camera['steps'][$step])){{ $camera['steps'][$step][0] }} <span class="text-muted">/ {{ $camera['steps'][$step][1] }}</span>@else — @endif</td>
                            @endforeach
                        </tr>
                    @endforeach
                    <tr><td>Decoder behind camera (ms)</td>@foreach ($report['cameras'] as $camera)<td>{{ $camera['backlog'] ?? '—' }}</td>@endforeach</tr>
                    <tr><td>Decoded → sent to browser (ms)</td>@foreach ($report['cameras'] as $camera)<td>{{ $camera['pipeline'][0] ?? '—' }} <span class="text-muted">/ {{ $camera['pipeline'][1] ?? '—' }}</span></td>@endforeach</tr>
                    <tr><td>Frame age when detection ends (ms)</td>@foreach ($report['cameras'] as $camera)<td>{{ $camera['detection_age'][0] ?? '—' }} <span class="text-muted">/ {{ $camera['detection_age'][1] ?? '—' }}</span></td>@endforeach</tr>
                    <tr><td>Live JPEG size (KB)</td>@foreach ($report['cameras'] as $camera)<td>{{ $camera['jpeg_kb'] ?? '—' }}</td>@endforeach</tr>
                    <tr><td>Full-resolution snapshots</td>@foreach ($report['cameras'] as $camera)<td>{{ $camera['hires'] }}</td>@endforeach</tr>
                </tbody>
            </table>
        </div>
    @endif
    </details>
</section>
