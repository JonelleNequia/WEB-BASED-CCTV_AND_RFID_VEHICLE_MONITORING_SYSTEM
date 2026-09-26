{{-- UI Phase 1: small KPI (label + number). "metric" keeps live-update hooks working. --}}
@props(['label', 'value' => 0, 'hint' => null, 'tone' => null, 'href' => null, 'metric' => null])

@php($tag = $href ? 'a' : 'div')
<{{ $tag }} @if ($href) href="{{ $href }}" @endif {{ $attributes->class(['stat', 'stat-'.$tone => $tone, 'stat-link' => $href]) }}>
    <span class="stat-label">{{ $label }}</span>
    <strong class="stat-value" @if ($metric) data-dashboard-metric="{{ $metric }}" @endif>{{ $value }}</strong>
    @if ($hint || isset($detail))
        <span class="stat-hint">{{ $detail ?? $hint }}</span>
    @endif
</{{ $tag }}>
