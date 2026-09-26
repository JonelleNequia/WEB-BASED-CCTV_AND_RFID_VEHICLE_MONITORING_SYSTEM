{{-- UI Phase 1: centered dialog (confirmations, image preview). Same open/close hooks as <x-drawer>. --}}
@props(['id', 'title', 'open' => false, 'size' => 'md'])

<div id="{{ $id }}" class="drawer modal modal-{{ $size }}" data-drawer @if ($open) data-drawer-autoopen @endif hidden>
    <div class="drawer-backdrop" data-drawer-close></div>
    <div class="drawer-panel" role="dialog" aria-modal="true" aria-labelledby="{{ $id }}-title" tabindex="-1">
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
    </div>
</div>
