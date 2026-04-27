@extends('layouts.app')

@section('title', 'Dashboard | PHILCST Parking Monitoring')
@section('page-title', 'Dashboard')
@section('page-description', 'Operational overview of campus vehicle access and monitoring.')

@section('content')
    <section class="hero-panel hero-panel-compact">
        <div class="hero-panel-copy">
            <span class="panel-kicker">Operations</span>
            <h3>Campus parking monitoring dashboard</h3>
        </div>

        <div class="hero-panel-actions">
            <a href="{{ route('rfid-scans.index') }}" class="button button-primary">RFID Desk</a>
            <a href="{{ route('vehicle-registry.index') }}" class="button button-secondary">Vehicle Registry</a>
            <a href="{{ route('guest-observations.index') }}" class="button button-secondary">Guest Monitoring</a>
            <a href="{{ route('monitoring.index') }}" class="button button-secondary">Live Monitoring</a>
        </div>
    </section>

    <div class="page-grid cards-6">
        <article class="stat-card stat-card-brand">
            <span class="stat-card-label">Vehicles Inside Campus</span>
            <strong>{{ $vehiclesInside }}</strong>
            <p>Current registered vehicles marked inside.</p>
        </article>

        <article class="stat-card stat-card-brand-soft">
            <span class="stat-card-label">Total Entries Today</span>
            <strong>{{ $entriesToday }}</strong>
            <p>State-based entry logs from RFID scans.</p>
        </article>

        <article class="stat-card stat-card-success">
            <span class="stat-card-label">Total Exits Today</span>
            <strong>{{ $exitsToday }}</strong>
            <p>State-based exit logs from RFID scans.</p>
        </article>

        <article class="stat-card stat-card-warning">
            <span class="stat-card-label">Guest Observations Today</span>
            <strong>{{ $guestObservationsToday }}</strong>
            <p>Guest monitoring records for today.</p>
        </article>

        <article class="stat-card stat-card-success">
            <span class="stat-card-label">Camera Status</span>
            <strong>{{ $cameraSummary['connected'] }}/{{ $cameraSummary['total'] }}</strong>
            <p>Connected camera feeds.</p>
        </article>
    </div>

    <section class="panel">
        <div class="panel-header">
            <div>
                <h3>Frequent Entry Ranking</h3>
            </div>
            <a href="{{ route('vehicle-events.index', ['event_type' => 'ENTRY']) }}" class="button button-secondary button-sm">Open Entry Logs</a>
        </div>

        @if ($frequentEntryVehicles->isEmpty())
            <div class="empty-state">
                <h4>No registered entry logs yet</h4>
                <p>Registered vehicle rankings will appear after RFID ENTRY scans.</p>
            </div>
        @else
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Rank</th>
                            <th>Plate</th>
                            <th>Owner</th>
                            <th>Category</th>
                            <th>Total Entries</th>
                            <th>Today</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($frequentEntryVehicles as $vehicle)
                            <tr>
                                <td><strong>#{{ $loop->iteration }}</strong></td>
                                <td><strong>{{ $vehicle->plate_number }}</strong></td>
                                <td>{{ $vehicle->vehicle_owner_name ?: 'N/A' }}</td>
                                <td>{{ ucfirst(str_replace('_', ' ', $vehicle->category)) }}</td>
                                <td><strong>{{ $vehicle->total_entries_count }}</strong></td>
                                <td>{{ $vehicle->entries_today_count_from_logs }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <div class="page-grid two-column">
        <section class="panel">
            <div class="panel-header">
                <div>
                    <h3>Recent RFID Scans</h3>
                </div>
                <a href="{{ route('rfid-scans.index') }}" class="button button-secondary button-sm">Open RFID Desk</a>
            </div>

            @forelse ($recentRfidScans as $scan)
                <article class="stream-item">
                    <div>
                        <strong>{{ $scan->tag_uid }} • {{ $scan->resolvedEventTypeLabel }}</strong>
                        <p>
                            {{ $scan->vehicle?->plate_number ?? 'Unknown vehicle' }}
                            • {{ $scan->scanLocationLabel }}
                            • State: {{ $scan->resultingStateLabel }}
                        </p>
                        <small>{{ $scan->scan_time->format('M d, Y h:i A') }}</small>
                    </div>
                    <span class="badge badge-{{ $scan->verificationBadgeClass }}">{{ $scan->verificationLabel }}</span>
                </article>
            @empty
                <div class="empty-state">
                    <h4>No RFID scans yet</h4>
                    <p>Start scanning from the RFID Desk.</p>
                </div>
            @endforelse
        </section>

        <section class="panel">
            <div class="panel-header">
                <div>
                    <h3>Recent Event Logs</h3>
                </div>
                <a href="{{ route('vehicle-events.index') }}" class="button button-secondary button-sm">Open Event Logs</a>
            </div>

            @forelse ($latestEvents as $event)
                <article class="stream-item">
                    @if ($event->has_visual_evidence)
                        <img src="{{ $event->vehicle_image_url }}" alt="Vehicle thumbnail" class="thumb thumb-sm">
                    @else
                        <span class="thumb thumb-sm thumb-empty">No image</span>
                    @endif

                    <div>
                        <strong>{{ $event->event_type }} • {{ $event->plate_text ?: 'Incomplete record' }}</strong>
                        <p>{{ $event->event_origin_label }} • {{ $event->display_vehicle_type }}</p>
                        <small>{{ $event->event_time->format('M d, Y h:i A') }}</small>
                    </div>

                    <span class="badge badge-{{ $event->status_badge_class }}">
                        {{ $event->display_status === 'pending_details' ? 'Incomplete' : str_replace('_', ' ', ucfirst($event->display_status)) }}
                    </span>
                </article>
            @empty
                <div class="empty-state">
                    <h4>No vehicle logs yet</h4>
                    <p>Event logs will appear after scans and manual entries.</p>
                </div>
            @endforelse
        </section>
    </div>

    <section class="panel">
        <div class="panel-header">
            <div>
                <h3>Shortcuts</h3>
            </div>
        </div>

        <div class="button-row">
            <a href="{{ route('vehicle-registry.index') }}" class="button button-secondary">Vehicle Registry</a>
            <a href="{{ route('rfid-scans.index') }}" class="button button-secondary">RFID Desk</a>
            <a href="{{ route('monitoring.index') }}" class="button button-secondary">Live Monitoring</a>
            <a href="{{ route('guest-observations.index') }}" class="button button-secondary">Guest Monitoring</a>
            <a href="{{ route('vehicle-events.index') }}" class="button button-secondary">Event Logs</a>
            <a href="{{ route('reports.index') }}" class="button button-secondary">Reports</a>
        </div>
    </section>
@endsection
