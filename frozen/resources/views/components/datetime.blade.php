{{-- UI Phase 1: one date/time format (App\Support\DisplayTime, Asia/Manila). --}}
@props(['value' => null, 'format' => 'datetime', 'fallback' => '—'])

@php($time = \App\Support\DisplayTime::parse($value))
@if ($time)
    <time datetime="{{ $time->toIso8601String() }}" {{ $attributes }}>{{ match ($format) {
        'date' => \App\Support\DisplayTime::date($time),
        'time' => \App\Support\DisplayTime::time($time),
        'seconds' => \App\Support\DisplayTime::datetimeSeconds($time),
        default => \App\Support\DisplayTime::datetime($time),
    } }}</time>
@else
    <span {{ $attributes->class('text-muted') }}>{{ $fallback }}</span>
@endif
