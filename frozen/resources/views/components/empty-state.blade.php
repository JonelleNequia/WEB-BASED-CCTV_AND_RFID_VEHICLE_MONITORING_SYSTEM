{{-- UI Phase 1: shared empty state with an optional action. --}}
@props(['title' => 'No records yet', 'text' => null])

<div {{ $attributes->class('empty-block') }}>
    <strong>{{ $title }}</strong>
    @if ($text)
        <p>{{ $text }}</p>
    @endif
    @if (trim((string) $slot) !== '')
        <div class="empty-block-actions">{{ $slot }}</div>
    @endif
</div>
