{{--
    Live view work: one live video (public/js/live-video.js): WebRTC from
    go2rtc (main stream, not re-encoded), then HLS, then the detector's MJPEG.
    Detection boxes are drawn on the canvas, not in the video.
--}}
@props(['gate', 'mjpeg', 'overlay' => true, 'page' => null, 'alt' => 'Live camera',
         // Calibration: its own on/off choice (off by default) and boxes only.
         'overlayKey' => null, 'overlayDefault' => null, 'shapes' => true])

@php
    $go2rtc = app(\App\Services\Go2rtcService::class);
    $webrtc = $go2rtc->enabled() && array_key_exists($gate, $go2rtc->streams());
@endphp
<div {{ $attributes->class('live-video') }}
     data-live-video
     data-gate="{{ $gate }}"
     data-webrtc="{{ $webrtc ? '1' : '0' }}"
     data-webrtc-url="{{ route('live.webrtc', $gate) }}"
     data-hls-url="{{ route('live.hls', ['file' => 'stream.m3u8', 'src' => $gate]) }}"
     data-mjpeg-url="{{ $mjpeg }}"
     data-overlay-url="{{ str_replace('/stream/', '/overlay/', (string) $mjpeg) }}"
     data-overlay="{{ $overlay ? '1' : '0' }}"
     @if ($overlayKey) data-overlay-key="{{ $overlayKey }}" @endif
     @if ($overlayDefault) data-overlay-default="{{ $overlayDefault }}" @endif
     @unless ($shapes) data-overlay-shapes="0" @endunless
     data-stats-url="{{ route('live.stats', $gate) }}"
     @if ($page) data-page="{{ $page }}" @endif>
    <video muted autoplay playsinline disablepictureinpicture aria-label="{{ $alt }}"></video>
    <img alt="{{ $alt }}" hidden>
    @if ($overlay)
        <canvas data-live-overlay aria-hidden="true"></canvas>
    @endif
    <span class="live-mode" data-live-mode></span>
</div>

@once
    @push('scripts')
        <script src="{{ asset('vendor/hls/hls.light.min.js') }}"></script>
        <script src="{{ asset('js/live-video.js') }}"></script>
    @endpush
@endonce
