{{-- UI Phase 5: shows whether a live page is up to date (Live / Updating / Offline). --}}
<span {{ $attributes->class('live-indicator') }} data-live-indicator data-state="updating" role="status" aria-live="polite">
    <span class="live-dot" aria-hidden="true"></span>
    <span data-live-label>Connecting…</span>
</span>
