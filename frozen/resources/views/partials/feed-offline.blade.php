{{-- UI Phase 4: a camera with no picture: an icon, one line and "Check connection" (admins). --}}
<div class="feed-offline" {!! $attributes ?? '' !!} role="status" hidden>
    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3.3 2.3a1 1 0 0 0-1.4 1.4L4.2 6H4a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h11.2l4.5 4.5a1 1 0 0 0 1.4-1.4zM16 15.2 6.8 6H14a2 2 0 0 1 2 2zm2-1.4V9.6l3.4-2.3A1 1 0 0 1 23 8.1v7.8a1 1 0 0 1-1.6.8z"/></svg>
    <span data-feed-offline-text>{{ $text ?? 'Camera offline' }}</span>
    @if (auth()->user()?->isAdmin())
        <a href="{{ route('settings.index', ['tab' => 'status']) }}">Check connection</a>
    @endif
</div>
