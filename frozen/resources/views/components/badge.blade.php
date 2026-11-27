{{-- UI Phase 1: one status color system (see App\Support\StatusBadge). --}}
@props(['status' => null, 'label' => null, 'tone' => null])

@php
    $resolvedTone = $tone ?? \App\Support\StatusBadge::tone($status);
    $text = trim((string) $slot) !== '' ? $slot : ($label ?? \App\Support\StatusBadge::label($status));
@endphp

<span {{ $attributes->class(['badge', 'badge-tone-'.$resolvedTone]) }}>{{ $text }}</span>
