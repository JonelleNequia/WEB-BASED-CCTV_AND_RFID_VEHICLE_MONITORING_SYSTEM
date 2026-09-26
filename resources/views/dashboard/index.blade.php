@extends('layouts.app')

@section('title', 'Dashboard | PHILCST Vehicle Monitoring')
@section('page-title', 'Dashboard')

@section('content')
    {{-- UI Phase 1: compact header and one row of small KPIs. --}}
    <x-page-header title="Dashboard" />

    <x-stat-row data-dashboard-metrics>
        <x-stat label="Inside Campus" :value="$vehiclesInside" metric="vehicles_inside" tone="brand"
                :hint="($rfidStats['registered_inside'] ?? 0).' registered · '.($rfidStats['guests_inside'] ?? 0).' guests'" />
        <x-stat label="Entered Today" :value="$totalVehiclesEnteredToday" metric="total_vehicles_entered_today" />
        <x-stat label="Exited Today" :value="$totalVehiclesExitedToday" metric="total_vehicles_exited_today" />
        <x-stat label="Registered Scans" :value="$rfidStats['registered_scans_today'] ?? 0" metric="registered_scans_today" hint="Verified RFID today" />
        <x-stat label="Active Guests" :value="$activeGuests" metric="active_guests" :href="route('guest-passes.index')" />
        <x-stat label="Overstay" :value="$overstayGuests" metric="overstay_guests" tone="warning" :href="route('guest-passes.index', ['status' => 'overstay'])" />
        <x-stat label="No-pass Alerts" :value="$noPassAlertsToday" metric="no_pass_alerts_today" tone="danger" :href="route('vehicle-events.index', ['log_type' => 'no_pass_alert'])">
            <x-slot:detail><span data-dashboard-metric="pass_alerts_today">{{ $passAlertsToday }}</span> lost/disabled pass scans</x-slot:detail>
        </x-stat>
        <div class="stat">
            <span class="stat-label">Cameras</span>
            <strong class="stat-value"><span data-dashboard-metric="camera_connected">{{ $cameraSummary['connected'] }}</span>/<span data-dashboard-metric="camera_total">{{ $cameraSummary['total'] }}</span></strong>
            <span class="stat-hint">Connected feeds</span>
        </div>
    </x-stat-row>

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
