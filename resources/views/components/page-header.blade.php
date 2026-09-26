{{-- UI Phase 1: one compact line. Title left, actions (primary button) right. --}}
@props(['title', 'back' => null, 'backLabel' => 'Back'])

<header {{ $attributes->class('page-header') }}>
    <div class="page-header-title">
        @if ($back)
            <a href="{{ $back }}" class="page-header-back" aria-label="{{ $backLabel }}">&larr;</a>
        @endif
        <h1>{{ $title }}</h1>
        @isset($meta)
            <span class="page-header-meta">{{ $meta }}</span>
        @endisset
    </div>

    @isset($actions)
        <div class="page-header-actions">{{ $actions }}</div>
    @endisset
</header>
