{{--
    UI Phase 1: tabs synced with the URL (?tab=vehicles) so Back and shared
    links work. $tabs = ['vehicles' => 'Vehicles', ...] or
    ['vehicles' => ['label' => 'Vehicles', 'count' => 3]].
--}}
@props(['tabs' => [], 'active' => null, 'param' => 'tab'])

@php
    $active ??= array_key_first($tabs);
@endphp
<nav {{ $attributes->class('tabs') }} aria-label="Sections">
    @foreach ($tabs as $key => $tab)
        @php
            $label = is_array($tab) ? $tab['label'] : $tab;
            $count = is_array($tab) ? ($tab['count'] ?? null) : null;
            $href = is_array($tab) && isset($tab['href'])
                ? $tab['href']
                : request()->url().'?'.http_build_query([$param => $key]);
        @endphp
        <a href="{{ $href }}" class="tab {{ $key === $active ? 'is-active' : '' }}" @if ($key === $active) aria-current="page" @endif>
            {{ $label }}
            @if ($count !== null)
                <span class="tab-count">{{ $count }}</span>
            @endif
        </a>
    @endforeach
</nav>
