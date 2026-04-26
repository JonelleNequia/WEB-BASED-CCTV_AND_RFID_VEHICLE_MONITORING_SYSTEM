@extends('layouts.app')

@section('title', 'Event Logs | PHILCST Vehicle Access Monitoring')
@section('page-title', 'Event Logs')
@section('page-description', 'Review RFID-based vehicle logs, incomplete records, and support entries from one searchable list.')

@section('content')
    <section class="hero-panel hero-panel-compact">
        <div class="hero-panel-copy">
            <span class="panel-kicker">Central Record</span>
            <h3>Vehicle access logs</h3>
            <div class="inline-status-list">
                <span class="chip chip-brand">RFID-first logs</span>
                <span class="chip chip-soft">Camera support remains visible when linked</span>
            </div>
        </div>

        <div class="hero-panel-actions">
            <a href="{{ route('portals.show', 'entrance') }}" class="button button-secondary">Entrance Station</a>
            <a href="{{ route('portals.show', 'exit') }}" class="button button-secondary">Exit Station</a>
            <a href="{{ route('vehicle-registry.index') }}" class="button button-secondary">Registry</a>
            <a href="{{ route('guest-observations.index') }}" class="button button-secondary">Guest Monitoring</a>
            @if (auth()->user()?->isAdmin())
                <a href="{{ route('incomplete-records.index') }}" class="button button-secondary">Incomplete Records</a>
                <a href="{{ route('manual-review.index') }}" class="button button-secondary">Review Queue</a>
            @endif
            <a href="{{ route('vehicle-events.create') }}" class="button button-primary">Quick Manual Log</a>
        </div>
    </section>

    <section class="panel">
        <div class="panel-header">
            <div>
                <div class="panel-title-row">
                    <h3>Filters</h3>
                    @include('layouts.partials.help', [
                        'label' => 'Explain log filters',
                        'text' => 'Filter by plate, log type, workflow status, or date range. Incomplete records and review items remain searchable here.',
                    ])
                </div>
            </div>
        </div>

        <form method="GET" action="{{ route('vehicle-events.index') }}" class="form-grid filter-grid">
            <div class="field">
                <label for="plate_text">Plate</label>
                <input id="plate_text" type="text" name="plate_text" value="{{ $filters['plate_text'] ?? '' }}" placeholder="ABC-1234">
            </div>

            <div class="field">
                <label for="event_type">Log Type</label>
                <select id="event_type" name="event_type">
                    <option value="">All</option>
                    <option value="ENTRY" @selected(($filters['event_type'] ?? '') === 'ENTRY')>ENTRY</option>
                    <option value="EXIT" @selected(($filters['event_type'] ?? '') === 'EXIT')>EXIT</option>
                </select>
            </div>

            <div class="field">
                <label for="match_status">Status</label>
                <select id="match_status" name="match_status">
                    <option value="">All</option>
                    @foreach (['pending_details', 'open', 'closed', 'matched', 'manual_review', 'unmatched'] as $status)
                        <option value="{{ $status }}" @selected(($filters['match_status'] ?? '') === $status)>
                            {{ $status === 'pending_details' ? 'Incomplete records' : str_replace('_', ' ', ucfirst($status)) }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="field">
                <label for="date_from">From</label>
                <input id="date_from" type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}">
            </div>

            <div class="field">
                <label for="date_to">To</label>
                <input id="date_to" type="date" name="date_to" value="{{ $filters['date_to'] ?? '' }}">
            </div>

            <div class="field field-actions">
                <div class="button-row">
                    <button type="submit" class="button button-primary">Apply</button>
                    <a href="{{ route('vehicle-events.index') }}" class="button button-secondary">Reset</a>
                    <a href="{{ route('vehicle-events.export.csv', request()->query()) }}" class="button button-secondary">Export CSV</a>
                    <button type="button" class="button button-secondary" onclick="window.print()">Print</button>
                </div>
            </div>
        </form>
    </section>

    <section class="panel">
        <div class="panel-header">
            <div>
                <div class="panel-title-row">
                    <h3>Event Logs</h3>
                    @include('layouts.partials.help', [
                        'label' => 'Explain vehicle logs table',
                        'text' => 'RFID-linked records are the normal operational path. Camera-linked details appear only when available for support.',
                    ])
                </div>
            </div>
            <span class="chip chip-soft">{{ $events->total() }} total</span>
        </div>

        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Preview</th>
                        <th>Type</th>
                        <th>Plate</th>
                        <th>Vehicle</th>
                        <th>Category</th>
                        <th>Source</th>
                        <th>Station / Camera</th>
                        <th>State</th>
                        <th>Time</th>
                        <th>Status</th>
                        <th>Match</th>
                        <th></th>
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
                            <td>{{ $event->plate_text ?: 'Incomplete record' }}</td>
                            <td>
                                <strong>{{ $event->vehicle_color ?: 'Pending color' }} {{ $event->display_vehicle_type }}</strong>
                                <div class="table-subtext">{{ $event->vehicle?->plate_number ? 'Registry: '.$event->vehicle->plate_number : 'No registry link' }}</div>
                            </td>
                            <td>{{ $event->vehicle_category ? ucfirst(str_replace('_', ' ', $event->vehicle_category)) : 'N/A' }}</td>
                            <td>
                                <strong>{{ $event->event_origin_label }}</strong>
                                <div class="table-subtext">{{ $event->rfidScanLog?->tag_uid ? 'RFID: '.$event->rfidScanLog->tag_uid : 'No RFID tag linked' }}</div>
                            </td>
                            <td>
                                <strong>{{ $event->camera?->camera_name ?? 'No camera linked' }}</strong>
                                <div class="table-subtext">{{ $event->roi_name ?: 'No station label' }}</div>
                            </td>
                            <td>{{ $event->resulting_state_label }}</td>
                            <td>{{ $event->event_time->format('M d, Y h:i A') }}</td>
                            <td>
                                <span class="badge badge-{{ $event->status_badge_class }}">
                                    {{ $event->display_status === 'pending_details' ? 'Incomplete Record' : str_replace('_', ' ', ucfirst($event->display_status)) }}
                                </span>
                            </td>
                            <td>{{ $event->match_display }}</td>
                            <td>
                                <a href="{{ route('vehicle-events.show', $event) }}" class="button button-secondary button-sm">
                                    {{ $event->event_status === 'pending_details' && auth()->user()?->isAdmin() ? 'Complete' : 'View' }}
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="13" class="table-empty">No vehicle logs matched the current filters.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @include('layouts.partials.pagination', ['paginator' => $events])
    </section>
@endsection
