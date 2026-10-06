{{-- Live view work: what the browsers measured on the live view (WebRTC from go2rtc, or the basic MJPEG view). --}}
@php($live = app(\App\Services\Go2rtcService::class)->report())
<section class="panel" data-live-view-status>
    <div class="panel-header panel-header-modern">
        <div>
            <h2 class="panel-title">Live view</h2>
            <p class="field-help">
                @if (! $live['enabled'])
                    The full-quality live view (WebRTC) is not available on this PC; pages use the basic view.
                @elseif ($live['running'])
                    Live view service running{{ $live['cpu'] !== null ? ' · CPU '.$live['cpu'].'% of one core' : '' }}. The camera's video is passed to the browser as it is (not re-encoded).
                @else
                    The live view service is starting. Pages use the basic view until it runs.
                @endif
            </p>
        </div>
    </div>
    <div class="table-responsive">
        <table>
            <thead>
                <tr><th>Gate</th><th>Shown as</th><th>Picture</th><th>Frames / s</th><th>Delay in the browser</th><th>Measured</th></tr>
            </thead>
            <tbody>
                @foreach ($live['gates'] as $gate)
                    @forelse ($gate['measured'] as $row)
                        <tr>
                            @if ($loop->first)
                                <td rowspan="{{ count($gate['measured']) }}">
                                    {{ $gate['label'] }}
                                    @if ($gate['codecs'] && collect($gate['codecs'])->filter()->isNotEmpty())
                                        <div class="table-subtext">Camera video: {{ collect($gate['codecs'])->filter()->map(fn ($codec, $stream) => ($stream === 'main' ? 'main ' : 'sub ').str_replace('H26', 'H.26', $codec))->implode(' · ') }}</div>
                                    @endif
                                </td>
                            @endif
                            <td>{{ $row['label'] }}</td>
                            <td>{{ ! empty($row['width']) ? $row['width'].' × '.$row['height'] : '—' }}</td>
                            <td>{{ $row['fps'] ?? '—' }}</td>
                            <td>{{ isset($row['delay_ms']) ? $row['delay_ms'].' ms' : '—' }}</td>
                            <td><x-datetime :value="$row['at']" format="time" />{{ ! empty($row['page']) ? ' · '.$row['page'] : '' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td>{{ $gate['label'] }}</td>
                            <td colspan="5" class="text-muted">{{ $gate['webrtc'] ? 'No measurement yet: open the Gate Monitor.' : 'No camera stream for the full-quality view (basic view only).' }}</td>
                        </tr>
                    @endforelse
                @endforeach
            </tbody>
        </table>
    </div>
    <p class="field-help">Delay in the browser = network + video buffer + decoding. The camera adds its own encoding time (about 0.1–0.3 s) on top.</p>
</section>
