@extends('layouts.app')

@section('title', 'Review Queue | PHILCST Vehicle Access Monitoring')
@section('page-title', 'Review Queue')
@section('page-description', 'Check EXIT records that need human confirmation before closing the movement.')

@section('content')
    <section class="hero-panel hero-panel-compact">
        <div class="hero-panel-copy">
            <span class="panel-kicker">Follow-up</span>
            <h3>EXIT records waiting for review</h3>
        </div>

        <div class="hero-panel-actions">
            <a href="{{ route('vehicle-events.index') }}" class="button button-secondary">Event Logs</a>
        </div>
    </section>

    <section class="panel">
        <div class="panel-header">
            <div>
                <div class="panel-title-row">
                    <h3>Review Items</h3>
                    @include('layouts.partials.help', [
                        'label' => 'Explain review queue',
                        'text' => 'These EXIT records have a possible match, but the score is not strong enough to close the movement automatically.',
                    ])
                </div>
            </div>
            <span class="chip chip-soft">{{ $events->total() }} item(s)</span>
        </div>

        @forelse ($events as $event)
            <article class="review-card">
                <div class="review-images">
                    <img src="{{ $event->vehicle_image_url }}" alt="Vehicle image" class="thumb thumb-large">
                    <img src="{{ $event->plate_image_url }}" alt="Plate image" class="thumb thumb-large">
                </div>

                <div class="review-copy">
                    <div class="button-row">
                        <span class="badge badge-manual-review">Review Needed</span>
                        <span class="chip chip-soft">Score {{ $event->match_score }}</span>
                    </div>

                    <div class="detail-list compact">
                        <div><span>Exit Log</span><strong>#{{ $event->id }}</strong></div>
                        <div><span>Plate</span><strong>{{ $event->plate_text }}</strong></div>
                        <div><span>Vehicle</span><strong>{{ $event->vehicle_color }} {{ $event->vehicle_type }}</strong></div>
                        <div><span>Camera</span><strong>{{ $event->camera?->camera_name ?? 'N/A' }}</strong></div>
                        <div><span>Time</span><strong>{{ $event->event_time->format('M d, Y h:i A') }}</strong></div>
                        <div><span>Score</span><strong>{{ $event->match_score }}</strong></div>
                    </div>

                    <div class="subpanel">
                        <h4>Best Entry Match</h4>
                        @if ($event->matchedEntry)
                            <p>
                                Entry #{{ $event->matchedEntry->id }} |
                                {{ $event->matchedEntry->plate_text }} |
                                {{ $event->matchedEntry->vehicle_color }} {{ $event->matchedEntry->vehicle_type }} |
                                {{ $event->matchedEntry->event_time->format('M d, Y h:i A') }}
                            </p>
                        @else
                            <p>No entry match is attached to this review item.</p>
                        @endif
                    </div>

                    <div class="button-row">
                        <form method="POST" action="{{ route('manual-review.mark-matched', $event) }}">
                            @csrf
                            @method('PATCH')
                            <button type="submit" class="button button-primary button-sm">Confirm Match</button>
                        </form>

                        <form method="POST" action="{{ route('manual-review.mark-unmatched', $event) }}">
                            @csrf
                            @method('PATCH')
                            <button type="submit" class="button button-secondary button-sm">Mark Unmatched</button>
                        </form>

                        <a href="{{ route('vehicle-events.show', $event) }}" class="button button-secondary button-sm">Open Details</a>
                    </div>
                </div>
            </article>
        @empty
            <div class="empty-state">
                <h4>No review items</h4>
                <p>All current EXIT records are already matched or unmatched.</p>
            </div>
        @endforelse

        @include('layouts.partials.pagination', ['paginator' => $events])
    </section>
@endsection
