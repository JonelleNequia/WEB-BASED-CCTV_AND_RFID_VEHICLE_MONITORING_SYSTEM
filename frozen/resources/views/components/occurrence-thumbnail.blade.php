@props(['url' => null])
@if ($url)
    <a href="{{ $url }}" class="occurrence-thumb" target="_blank" rel="noopener" aria-label="View occurrence snapshot">
        <img src="{{ $url }}" class="thumb thumb-xs" alt="Vehicle occurrence snapshot" loading="lazy">
    </a>
@endif
