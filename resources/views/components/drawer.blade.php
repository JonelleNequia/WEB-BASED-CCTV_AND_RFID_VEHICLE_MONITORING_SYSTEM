{{--
    UI Phase 1: side drawer for add/edit forms. Open with a button that has
    data-drawer-open="{{ id }}"; closes on Esc, backdrop or data-drawer-close.
    :open="true" opens it on load (e.g. after a validation error).
--}}
@props(['id', 'title', 'open' => false, 'size' => 'md'])

<div id="{{ $id }}" class="drawer drawer-{{ $size }}" data-drawer @if ($open) data-drawer-autoopen @endif hidden>
    <div class="drawer-backdrop" data-drawer-close></div>
    <aside class="drawer-panel" role="dialog" aria-modal="true" aria-labelledby="{{ $id }}-title" tabindex="-1">
        <header class="drawer-head">
            <h2 id="{{ $id }}-title">{{ $title }}</h2>
            <button type="button" class="icon-button" data-drawer-close aria-label="Close">&times;</button>
        </header>

        <div {{ $attributes->class('drawer-body') }}>
            {{ $slot }}
        </div>

        @isset($footer)
            <footer class="drawer-foot">{{ $footer }}</footer>
        @endisset
    </aside>
</div>
