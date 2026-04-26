@php
    $label = $label ?? 'More information';
    $position = $position ?? 'right';
@endphp

<span class="help-popover help-popover-{{ $position }}">
    <button type="button" class="help-popover-button" aria-label="{{ $label }}">
        <span aria-hidden="true">i</span>
    </button>
    <span class="help-popover-card" role="tooltip">
        @isset($title)
            <strong>{{ $title }}</strong>
        @endisset
        <span>{{ $text }}</span>
    </span>
</span>
