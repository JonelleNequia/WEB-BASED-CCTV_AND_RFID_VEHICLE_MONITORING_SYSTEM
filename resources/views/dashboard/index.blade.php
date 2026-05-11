@extends('layouts.app')

@section('title', 'Dashboard | PHILCST Vehicle Monitoring')
@section('page-title', 'Dashboard')
@section('page-description', 'Operational overview of campus vehicle access and monitoring.')

@section('content')
    <section class="hero-panel hero-panel-compact">
        <div class="hero-panel-copy">
            <span class="panel-kicker">Operations</span>
            <h3>Campus vehicle monitoring dashboard</h3>
        </div>

        <div class="hero-panel-actions">
            <a href="{{ route('stations.entrance') }}" class="button button-primary">Entrance Station</a>
            <a href="{{ route('stations.exit') }}" class="button button-primary">Exit Station</a>
            <a href="{{ route('vehicle-registry.index') }}" class="button button-secondary">Vehicle Registry</a>
            <a href="{{ route('settings.index') }}" class="button button-secondary">Settings</a>
        </div>
    </section>

    <div class="page-grid cards-6" data-dashboard-metrics>
        <article class="stat-card stat-card-brand">
            <span class="stat-card-label">Total Vehicles Entered</span>
            <strong data-dashboard-metric="total_vehicles_entered_today">{{ $totalVehiclesEnteredToday }}</strong>
            <p>Registered ENTRY logs plus guest entrance observations today.</p>
        </article>

        <article class="stat-card stat-card-success">
            <span class="stat-card-label">Total Vehicles Exited</span>
            <strong data-dashboard-metric="total_vehicles_exited_today">{{ $totalVehiclesExitedToday }}</strong>
            <p>Registered EXIT logs plus guest exit observations today.</p>
        </article>

        <article class="stat-card stat-card-brand-soft">
            <span class="stat-card-label">Vehicles Inside Campus</span>
            <strong data-dashboard-metric="vehicles_inside">{{ $vehiclesInside }}</strong>
            <p>Current registered vehicles marked inside.</p>
        </article>

        <article class="stat-card stat-card-brand">
            <span class="stat-card-label">Registered Scans Today</span>
            <strong data-dashboard-metric="registered_scans_today">{{ $rfidStats['registered_scans_today'] ?? 0 }}</strong>
            <p>Verified RFID scans from recurring vehicles today.</p>
        </article>

        <article class="stat-card stat-card-warning">
            <span class="stat-card-label">Guest Observations Today</span>
            <strong data-dashboard-metric="guest_observations_today">{{ $guestObservationsToday }}</strong>
            <p>Guest monitoring records for today.</p>
        </article>

        <article class="stat-card stat-card-success">
            <span class="stat-card-label">Camera Status</span>
            <strong><span data-dashboard-metric="camera_connected">{{ $cameraSummary['connected'] }}</span>/<span data-dashboard-metric="camera_total">{{ $cameraSummary['total'] }}</span></strong>
            <p>Connected camera feeds.</p>
        </article>
    </div>

    <section class="panel">
        <div class="panel-header">
            <div>
                <h3>Traffic Summary</h3>
            </div>
            <a href="{{ route('vehicle-events.index', ['period' => 'month']) }}" class="button button-secondary button-sm">Open Monthly Logs</a>
        </div>

        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>Timeframe</th>
                        <th>Entered</th>
                        <th>Exited</th>
                        <th>Registered Scans</th>
                        <th>Guest Observations</th>
                    </tr>
                </thead>
                <tbody data-dashboard-traffic-summary>
                    @foreach ($trafficSummary as $period => $summary)
                        <tr data-dashboard-period="{{ $period }}">
                            <td><strong>{{ $summary['label'] }}</strong></td>
                            <td data-dashboard-period-metric="entries">{{ $summary['entries'] }}</td>
                            <td data-dashboard-period-metric="exits">{{ $summary['exits'] }}</td>
                            <td data-dashboard-period-metric="registered_scans">{{ $summary['registered_scans'] }}</td>
                            <td data-dashboard-period-metric="guest_observations">{{ $summary['guest_observations'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    <section class="panel">
        <div class="panel-header">
            <div>
                <h3>Frequent Entry Ranking</h3>
            </div>
            <a href="{{ route('vehicle-events.index', ['event_type' => 'ENTRY']) }}" class="button button-secondary button-sm">Open Entry Logs</a>
        </div>

        <div class="table-responsive" data-dashboard-ranking-table>
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
                <tbody data-dashboard-ranking>
                    @foreach ($frequentEntryVehicles as $vehicle)
                        <tr>
                            <td><strong>#{{ $loop->iteration }}</strong></td>
                            <td><strong>{{ $vehicle->plate_number }}</strong></td>
                            <td>{{ $vehicle->vehicle_owner_name ?: 'N/A' }}</td>
                            <td>{{ ucfirst(str_replace('_', ' ', $vehicle->category)) }}</td>
                            <td><strong>{{ $vehicle->ranking_total_entries_count ?? $vehicle->total_entries_count }}</strong></td>
                            <td>{{ $vehicle->ranking_entries_today_count ?? $vehicle->entries_today_count_from_logs }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    <div class="page-grid two-column">
        <section class="panel">
            <div class="panel-header panel-header-modern">
                <div>
                    <h3>Recent RFID Scans</h3>
                </div>
                <a href="{{ route('rfid-scans.index') }}" class="button button-secondary button-sm">Open RFID Desk</a>
            </div>

            <div class="panel-scroll-area" data-dashboard-stream="rfid">
                @forelse ($recentRfidScans as $scan)
                    <article class="stream-item stream-item-compact">
                        <div>
                            <strong>{{ $scan['title'] }}</strong>
                            <p>{{ $scan['summary'] }}</p>
                            <small>{{ $scan['display_time'] }}</small>
                        </div>
                        <span class="badge badge-{{ $scan['badge_class'] }}">{{ $scan['badge_label'] }}</span>
                    </article>
                @empty
                    <div class="empty-state">
                        <h4>No RFID scans yet</h4>
                        <p>Start scanning from the RFID Desk.</p>
                    </div>
                @endforelse
            </div>
        </section>

        <section class="panel">
            <div class="panel-header panel-header-modern">
                <div>
                    <h3>Recent Event Logs</h3>
                </div>
                <a href="{{ route('vehicle-events.index') }}" class="button button-secondary button-sm">Open Event Logs</a>
            </div>

            <div class="panel-scroll-area" data-dashboard-stream="events">
                @forelse ($latestEvents as $event)
                    <article class="stream-item stream-item-compact">
                        <div>
                            <strong>{{ $event['title'] }}</strong>
                            <p>{{ $event['summary'] }}</p>
                            <small>{{ $event['display_time'] }}</small>
                        </div>

                        <span class="badge badge-{{ $event['badge_class'] }}">{{ $event['badge_label'] }}</span>
                    </article>
                @empty
                    <div class="empty-state">
                        <h4>No vehicle logs yet</h4>
                        <p>Event logs will appear after scans and manual entries.</p>
                    </div>
                @endforelse
            </div>
        </section>
    </div>

    <script id="dashboard-live-data" type="application/json">{!! json_encode([
        'routes' => [
            'liveState' => route('dashboard.live-state'),
        ],
    ], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) !!}</script>
@endsection

@push('scripts')
    <script src="{{ asset('js/dashboard-live.js') }}"></script>
@endpush
