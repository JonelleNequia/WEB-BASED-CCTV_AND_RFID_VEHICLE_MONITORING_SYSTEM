@extends('layouts.app')

@section('title', 'Incomplete Records | PHILCST Parking Monitoring')
@section('page-title', 'Incomplete Records')
@section('page-description', 'Records that still need operator completion before final logging.')

@section('content')
    <section class="hero-panel hero-panel-compact">
        <div class="hero-panel-copy">
            <span class="panel-kicker">Admin Queue</span>
            <h3>Complete missing record details</h3>
        </div>

        <div class="hero-panel-actions">
            <a href="{{ route('vehicle-events.index') }}" class="button button-secondary">Event Logs</a>
            <a href="{{ route('manual-review.index') }}" class="button button-secondary">Review Queue</a>
        </div>
    </section>

    <section class="panel">
        <div class="panel-header">
            <div>
                <div class="panel-title-row">
                    <h3>Incomplete Records</h3>
                    @include('layouts.partials.help', [
                        'label' => 'Incomplete records help',
                        'text' => 'Open each record to add plate and vehicle details, then save completion.',
                    ])
                </div>
            </div>
            <span class="chip chip-soft">{{ $events->total() }} item(s)</span>
        </div>

        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Preview</th>
                        <th>Type</th>
                        <th>Detected Vehicle</th>
                        <th>Camera</th>
                        <th>Time</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($events as $event)
                        <tr>
                            <td>#{{ $event->id }}</td>
                            <td>
                                @if ($event->has_visual_evidence)
                                    <img src="{{ $event->vehicle_image_url }}" alt="Vehicle preview" class="thumb thumb-sm">
                                @else
                                    <span class="thumb thumb-sm thumb-empty">No image</span>
                                @endif
                            </td>
                            <td>{{ $event->event_type }}</td>
                            <td>{{ $event->display_vehicle_type }}</td>
                            <td>{{ $event->camera?->camera_name ?? 'No camera linked' }}</td>
                            <td>{{ $event->event_time->format('M d, Y h:i A') }}</td>
                            <td>
                                <a href="{{ route('vehicle-events.show', $event) }}" class="button button-primary button-sm">Complete</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="table-empty">No incomplete records right now.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @include('layouts.partials.pagination', ['paginator' => $events])
    </section>
@endsection
